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
