<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Money\Money;
use App\Tenant\Sales\Enums\CorrectionMechanism;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Exceptions\CorrectionRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceReference;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\Services\TaxRounding;
use App\Tenant\Tax\TaxableLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Debit notes and corrected invoices - PF-098.
 *
 * ## The mechanism is the jurisdiction's decision, not the user's
 *
 * A credit note leaves the invoice standing and reduces it. A corrected
 * invoice replaces it. Both produce the same balances; what differs is which
 * document the authority expects to receive and match, and issuing the wrong
 * one is not a preference somebody can be talked out of afterwards - it is a
 * document already filed under a procedure the regime does not recognise.
 *
 * So `permitted()` answers from the profile and the sales screens offer only
 * what it returns, and `assertPermitted()` refuses the other one by name at
 * the moment of the write - because anything decided while rendering a page
 * is already stale.
 *
 * ## Why a debit note is not "a credit note with the sign flipped"
 *
 * It charges more for a supply that has already been invoiced: a price that
 * was understated, freight that was left off, a volume rebate that turned out
 * not to apply. It has its own series, its own number, and the invoice's
 * posting shape - receivable up, revenue up, output tax up - which is why it
 * posts through the invoice rule and not through a mirror of it.
 *
 * There is no cap on it, deliberately. A customer can be charged more than
 * once against one invoice for different things, and the only ceiling on what
 * may be charged is what was agreed, which is not a number this system holds.
 */
final class Corrections
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly TaxRounding $rounding,
    ) {}

    /**
     * Which correction mechanisms this profile permits.
     *
     * @return list<DocumentType>
     */
    public function permitted(string $profile): array
    {
        return match ($this->mechanismFor($profile)) {
            CorrectionMechanism::CreditNote => [DocumentType::CreditNote, DocumentType::DebitNote],
            CorrectionMechanism::CorrectedInvoice => [DocumentType::CorrectedInvoice],
            CorrectionMechanism::Both => [
                DocumentType::CreditNote,
                DocumentType::DebitNote,
                DocumentType::CorrectedInvoice,
            ],
        };
    }

    public function mechanismFor(string $profile): CorrectionMechanism
    {
        /** @var array<string, string> $map */
        $map = (array) config('jurisdictions.document_correction', []);

        if (! \array_key_exists($profile, $map)) {
            throw CorrectionRefused::anUnknownProfile($profile);
        }

        return CorrectionMechanism::from((string) $map[$profile]);
    }

    /**
     * Refuse a document type this profile does not recognise.
     *
     * @throws CorrectionRefused
     */
    public function assertPermitted(DocumentType $type, string $profile): void
    {
        if (! \in_array($type, $this->permitted($profile), true)) {
            throw CorrectionRefused::theWrongMechanism(
                $type,
                $profile,
                $this->mechanismFor($profile),
            );
        }
    }

    /**
     * An additional charge against an issued invoice - UNCL 1001 383.
     *
     * @param  list<array{description: string, net: string, tax_code_id?: string|null, tax_rate?: string, tax_category?: string, revenue_account_id?: string|null, quantity?: string, unit_code?: string}>  $charges
     *
     * @throws CorrectionRefused
     */
    public function debitNote(Invoice $invoice, array $charges, ?Carbon $date = null): Invoice
    {
        $this->assertAdjustable($invoice);
        $this->assertPermitted(DocumentType::DebitNote, (string) ($invoice->profile ?? 'generic'));

        if ($charges === []) {
            throw CorrectionRefused::ofNothing();
        }

        $date ??= Carbon::now();
        $currency = (string) $invoice->currency;
        $template = $invoice->lines()->first();

        if ($template === null) {
            throw CorrectionRefused::anInvoiceWithNoLines((string) $invoice->number);
        }

        return DB::connection('tenant')->transaction(function () use (
            $invoice, $charges, $date, $currency, $template
        ): Invoice {
            $lines = [];

            foreach ($charges as $index => $charge) {
                $net = Money::of((string) $charge['net'], $currency);

                if ($net->isZero() || $net->isNegative()) {
                    throw CorrectionRefused::aChargeOfNothing($index + 1);
                }

                $lines[] = [
                    'line_no' => $index + 1,
                    'description' => (string) $charge['description'],
                    'net' => $net,
                    'quantity' => (string) ($charge['quantity'] ?? '1.00000000'),
                    'unit_code' => (string) ($charge['unit_code'] ?? $template->unit_code),
                    'tax_code_id' => $charge['tax_code_id'] ?? $template->tax_code_id,
                    'tax_rate' => (string) ($charge['tax_rate'] ?? $template->tax_rate),
                    'tax_category' => (string) ($charge['tax_category'] ?? $template->tax_category->value),
                    'revenue_account_id' => $charge['revenue_account_id'] ?? $template->revenue_account_id,
                ];
            }

            return $this->write($invoice, DocumentType::DebitNote, 'charges', $lines, $date, $template);
        });
    }

    /**
     * A replacement document carrying the corrected figures - UNCL 1001 384.
     *
     * The lines are the CORRECTED ones, in full: a replacement is not a
     * difference. What the ledger does with it is the posting rule's
     * business, and `sales.corrected_invoice_issued` reverses the original
     * and posts these - both legs in one entry, so the correction is one
     * event in the books rather than two that have to be read together.
     *
     * @param  list<array{description: string, net: string, tax_code_id?: string|null, tax_rate?: string, tax_category?: string, revenue_account_id?: string|null, quantity?: string, unit_code?: string}>  $lines
     *
     * @throws CorrectionRefused
     */
    public function correctedInvoice(Invoice $invoice, array $lines, ?Carbon $date = null): Invoice
    {
        $this->assertAdjustable($invoice);
        $this->assertPermitted(DocumentType::CorrectedInvoice, (string) ($invoice->profile ?? 'generic'));

        if ($lines === []) {
            throw CorrectionRefused::ofNothing();
        }

        $date ??= Carbon::now();
        $currency = (string) $invoice->currency;
        $template = $invoice->lines()->first();

        if ($template === null) {
            throw CorrectionRefused::anInvoiceWithNoLines((string) $invoice->number);
        }

        return DB::connection('tenant')->transaction(function () use (
            $invoice, $lines, $date, $currency, $template
        ): Invoice {
            $prepared = [];

            foreach ($lines as $index => $line) {
                $net = Money::of((string) $line['net'], $currency);

                if ($net->isNegative()) {
                    throw CorrectionRefused::aNegativeLine($index + 1);
                }

                $prepared[] = [
                    'line_no' => $index + 1,
                    'description' => (string) $line['description'],
                    'net' => $net,
                    'quantity' => (string) ($line['quantity'] ?? '1.00000000'),
                    'unit_code' => (string) ($line['unit_code'] ?? $template->unit_code),
                    'tax_code_id' => $line['tax_code_id'] ?? $template->tax_code_id,
                    'tax_rate' => (string) ($line['tax_rate'] ?? $template->tax_rate),
                    'tax_category' => (string) ($line['tax_category'] ?? $template->tax_category->value),
                    'revenue_account_id' => $line['revenue_account_id'] ?? $template->revenue_account_id,
                ];
            }

            return $this->write($invoice, DocumentType::CorrectedInvoice, 'corrects', $prepared, $date, $template);
        });
    }

    /** Has this invoice been replaced? Then it is closed to adjustment. */
    public function replacedBy(Invoice $invoice): ?Invoice
    {
        $id = DB::connection('tenant')->table('invoice_corrections')
            ->where('corrected_invoice_id', (string) $invoice->getKey())
            ->value('correction_invoice_id');

        return $id === null ? null : Invoice::query()->find((string) $id);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function write(
        Invoice $invoice,
        DocumentType $type,
        string $kind,
        array $lines,
        Carbon $date,
        InvoiceLine $template,
    ): Invoice {
        $currency = (string) $invoice->currency;
        $policy = $this->rounding->policyFor((string) ($invoice->profile ?? 'en16931'), $currency);

        $breakdown = $this->calculator->compute(
            array_map(static fn (array $line): TaxableLine => TaxableLine::of(
                $line['net'],
                \App\Tenant\Tax\Enums\TaxCategory::from((string) $line['tax_category']),
                (string) $line['tax_rate'],
            ), $lines),
            $policy,
        );

        $document = Invoice::create([
            'entity_id' => $invoice->entity_id,
            'series_key' => $type->seriesKey(),
            'document_type' => $type->value,
            'status' => 'draft',
            'party_id' => $invoice->party_id,
            'issue_date' => $date->toDateString(),

            /*
             | The adjusting document's own tax point.
             |
             | A correction issued in June to an April invoice belongs in
             | June's return: the supply did not move, and a return already
             | filed is corrected by PF-087's procedure rather than by
             | backdating a document into it.
             */
            'tax_point' => $date->toDateString(),
            'currency' => $currency,
            'fx_rate' => $invoice->fx_rate,
            'fx_source' => $invoice->fx_source,
            'fx_rate_date' => $invoice->fx_rate_date,
            'net_amount' => (string) $breakdown->net->amount,
            'tax_amount' => (string) $breakdown->tax->amount,
            'gross_amount' => (string) $breakdown->gross()->amount,
            'payable_amount' => (string) $breakdown->gross()->amount,
            'presentation_exponent' => $invoice->presentation_exponent,
            'profile' => $invoice->profile,
        ]);

        foreach ($lines as $line) {
            InvoiceLine::create([
                'entity_id' => $invoice->entity_id,
                'invoice_id' => (string) $document->getKey(),
                'line_no' => $line['line_no'],
                'description' => $line['description'],
                'unit_code' => $line['unit_code'],
                'quantity' => $line['quantity'],
                'unit_price' => (string) $line['net']->amount,
                'net_amount' => (string) $line['net']->amount,
                'revenue_account_id' => $line['revenue_account_id'],
                'tax_code_id' => $line['tax_code_id'],
                'tax_category' => $line['tax_category'],
                'tax_rate' => $line['tax_rate'],
                'tax_point' => $date->toDateString(),
                'place_of_supply' => $template->place_of_supply,
                'place_of_supply_rule' => $template->place_of_supply_rule,
            ]);
        }

        foreach ($breakdown->entries as $entry) {
            InvoiceTaxBreakdownRow::create([
                'entity_id' => $invoice->entity_id,
                'invoice_id' => (string) $document->getKey(),
                'category' => $entry->category->value,
                'rate' => $entry->ratePercent,
                'taxable_amount' => (string) $entry->taxableAmount->amount,
                'tax_amount' => (string) $entry->taxAmount->amount,
                'exemption_reason_code' => $entry->reasonCode,
                'exemption_reason_text' => $entry->reasonText,
                'report_box' => (string) $invoice->taxBreakdown()
                    ->where('category', $entry->category->value)
                    ->value('report_box'),
            ]);
        }

        InvoiceReference::create([
            'entity_id' => $invoice->entity_id,
            'invoice_id' => (string) $document->getKey(),
            'kind' => $kind,
            'referenced_invoice_id' => (string) $invoice->getKey(),
            'reference' => (string) $invoice->number,
            'reference_date' => $invoice->issue_date?->toDateString(),
        ]);

        return $document->refresh();
    }

    /** @throws CorrectionRefused */
    private function assertAdjustable(Invoice $invoice): void
    {
        if (! $invoice->isIssued()) {
            throw CorrectionRefused::aDraft();
        }

        if ($invoice->document_type !== DocumentType::Invoice
            && $invoice->document_type !== DocumentType::PartialInvoice) {
            throw CorrectionRefused::notAnInvoice(
                (string) $invoice->number,
                $invoice->document_type->label('en'),
            );
        }

        $replacement = $this->replacedBy($invoice);

        if ($replacement !== null) {
            throw CorrectionRefused::alreadyReplaced(
                (string) $invoice->number,
                (string) $replacement->number,
            );
        }
    }
}
