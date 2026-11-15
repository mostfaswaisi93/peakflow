<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use App\Tenant\Ledger\Models\Account;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Tax\Models\TaxCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The document the concurrency workers issue - PF-094.
 *
 * ## Why this is shared with the worker processes
 *
 * The workers are separate OS processes. They boot the framework, resolve the
 * tenant and issue against the same series as everybody else, and the one
 * thing they must not differ on is the document: twenty workers issuing
 * twenty different shapes would prove that the series survives twenty shapes,
 * which is not the question.
 *
 * Everything is looked up by convention rather than passed in, because the
 * argument list is a shell command and a shell command with nine ULIDs in it
 * is a shell command somebody mistypes.
 */
final class Burst
{
    /** A valid, complete, standard-rate draft for the probe's entity. */
    public static function draft(string $entityId, DocumentType $type, Carbon $date): Invoice
    {
        $net = '100.0000';
        $tax = '15.0000';
        $gross = '115.0000';

        $invoice = Invoice::create([
            'entity_id' => $entityId,
            'series_key' => $type->seriesKey(),
            'document_type' => $type->value,
            'status' => 'draft',
            'party_id' => self::customer($entityId),
            'issue_date' => $date->toDateString(),
            'tax_point' => $date->toDateString(),
            'currency' => 'SAR',
            'net_amount' => $net,
            'tax_amount' => $tax,
            'gross_amount' => $gross,
            'payable_amount' => $gross,
            'presentation_exponent' => 2,
        ]);

        InvoiceLine::create([
            'entity_id' => $entityId,
            'invoice_id' => (string) $invoice->getKey(),
            'line_no' => 1,
            'description' => 'One unit of the thing, under contention',
            'unit_code' => 'C62',
            'quantity' => '1.00000000',
            'unit_price' => $net,
            'net_amount' => $net,
            'revenue_account_id' => self::revenueAccount($entityId),
            'tax_code_id' => self::taxCode($entityId),
            'tax_category' => 'S',
            'tax_rate' => '15.000000',
            'tax_point' => $date->toDateString(),
            'place_of_supply' => 'SA',
            'place_of_supply_rule' => 'domestic',
        ]);

        InvoiceTaxBreakdownRow::create([
            'entity_id' => $entityId,
            'invoice_id' => (string) $invoice->getKey(),
            'category' => 'S',
            'rate' => '15.000000',
            'taxable_amount' => $net,
            'tax_amount' => $tax,
            'report_box' => 'standard_sales',
        ]);

        return $invoice->refresh();
    }

    /** @return array<string, mixed> */
    public static function decision(): array
    {
        return [
            'country' => 'SA',
            'rule' => 'domestic',
            'category' => 'S',
            'rate' => '15.000000',
        ];
    }

    /**
     * The first issued invoice with no issued credit note against it.
     *
     * Shared, because the suite and the worker processes must pick the same
     * one: a check that credits invoice 1 while the workers credit invoice 2
     * measures nothing and passes.
     */
    public static function uncreditedInvoice(string $entityId): ?Invoice
    {
        return Invoice::query()
            ->where('entity_id', $entityId)
            ->where('document_type', '380')
            ->where('status', 'issued')
            ->whereNotExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('invoice_references as r')
                ->join('invoices as c', 'c.id', '=', 'r.invoice_id')
                ->whereColumn('r.referenced_invoice_id', 'invoices.id')
                ->where('r.kind', 'credits')
                ->where('c.status', 'issued'))
            ->orderBy('chain_index')
            ->first();
    }

    /**
     * Issue a prepared credit-note draft with one raw `UPDATE`.
     *
     * Deliberately not through `Issuer`: the claim being tested is that the
     * DATABASE refuses an over-credit, and every path through the issuer is
     * serialised by the number series lock before it ever reaches the
     * trigger. A second crediting path - an import, an API endpoint, a
     * correction script - would not be, and the guarantee has to hold for it
     * too.
     *
     * The values are the minimum the CHECK constraints accept. This is not a
     * document anybody would keep; it is the statement a careless second
     * path would run.
     */
    public static function issueRawCredit(string $draftId, string $number): void
    {
        $connection = DB::connection('tenant');

        $draft = Invoice::query()->findOrFail($draftId);
        $credited = Invoice::query()->findOrFail(
            (string) $draft->references()->where('kind', 'credits')->value('referenced_invoice_id'),
        );

        $index = (int) Invoice::query()
            ->where('entity_id', (string) $draft->entity_id)
            ->where('series_key', (string) $draft->series_key)
            ->max('chain_index');

        $canonical = json_encode(['number' => $number], JSON_THROW_ON_ERROR);

        $connection->table('invoices')->where('id', $draftId)->update([
            'status' => 'issued',
            'number' => $number,
            'issued_at' => now(),
            'issued_by' => str_repeat('0', 26),
            'supplier_snapshot' => json_encode($credited->supplier_snapshot, JSON_THROW_ON_ERROR),
            'customer_snapshot' => json_encode($credited->customer_snapshot, JSON_THROW_ON_ERROR),
            'tax_decision_snapshot' => json_encode($credited->tax_decision_snapshot, JSON_THROW_ON_ERROR),
            'canonical' => $canonical,
            'canonical_hash' => hash('sha256', $canonical),
            'previous_hash' => str_repeat('0', 64),
            'chain_index' => $index + 1,
            'updated_at' => now(),
        ]);
    }

    public static function customer(string $entityId): string
    {
        return (string) Party::query()
            ->where('is_customer', true)
            ->whereNull('merged_into_id')
            ->orderBy('code')
            ->value('id');
    }

    public static function taxCode(string $entityId): string
    {
        return (string) TaxCode::query()
            ->where('entity_id', $entityId)
            ->orderBy('code')
            ->value('id');
    }

    public static function revenueAccount(string $entityId): string
    {
        return (string) Account::query()
            ->where('entity_id', $entityId)
            ->where('type', 'revenue')
            ->where('postable', true)
            ->orderBy('code')
            ->value('id');
    }
}
