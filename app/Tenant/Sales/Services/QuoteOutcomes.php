<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Tenant\Sales\Enums\QuoteState;
use App\Tenant\Sales\Exceptions\QuoteRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceReference;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Sales\Models\Quote;
use App\Tenant\Sales\Models\SalesOrder;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\Services\TaxRounding;
use App\Tenant\Tax\TaxableLine;
use App\Tenant\Tenancy\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What an accepted quote becomes - PF-089.
 *
 * ## Why this is a tenant's decision and not a product one
 *
 * Both ways of working are real. A contractor quotes, takes the order and
 * invoices on delivery, so the order is where the commitment lives and the
 * credit exposure depends on it. A studio quotes and invoices on acceptance,
 * and an order step in between is a screen somebody clicks through that
 * means nothing.
 *
 * `sales.order_step` decides, and the quote records what it became - because
 * "what happened to that quote" is the question somebody asks six months
 * later, and a quote that is merely `accepted` cannot answer it.
 */
final class QuoteOutcomes
{
    public function __construct(
        private readonly SalesOrders $orders,
        private readonly TaxCalculator $calculator,
        private readonly TaxRounding $rounding,
        private readonly Settings $settings,
    ) {}

    /**
     * Turn an accepted quote into whatever this entity's flow says.
     *
     * @throws QuoteRefused
     */
    public function realise(Quote $quote, ?Carbon $date = null): SalesOrder|Invoice
    {
        if ($quote->status !== QuoteState::Accepted) {
            throw QuoteRefused::theTransition($quote->label(), $quote->status, QuoteState::Accepted);
        }

        if ($quote->outcome_id !== null) {
            throw QuoteRefused::alreadyTurnedInto($quote->label(), (string) $quote->outcome_type);
        }

        return $this->settings->bool('sales.order_step', (string) $quote->entity_id)
            ? $this->orders->fromQuote($quote, $date)
            : $this->invoiceDirectly($quote, $date);
    }

    /**
     * An invoice draft straight from the quote, for entities with no order step.
     *
     * The invoice is a DRAFT: accepting a quote is the customer's act and
     * issuing an invoice is the business's, and collapsing the two would
     * issue a legal document - with a number from a gapless series, a
     * posting and a place in a hash chain - at the moment somebody clicked a
     * link on a web page.
     */
    private function invoiceDirectly(Quote $quote, ?Carbon $date): Invoice
    {
        $date ??= Carbon::now();
        $currency = (string) $quote->currency;

        return DB::connection('tenant')->transaction(function () use ($quote, $date, $currency): Invoice {
            $lines = $quote->lines()->get();

            $breakdown = $this->calculator->compute(
                $lines->map(static fn (object $line): TaxableLine => TaxableLine::of(
                    \App\Support\Money\Money::of((string) $line->net_amount, $currency),
                    TaxCategory::from((string) $line->tax_category),
                    (string) $line->tax_rate,
                ))->all(),
                $this->rounding->policyFor((string) ($quote->profile ?? 'generic'), $currency),
            );

            $invoice = Invoice::create([
                'entity_id' => (string) $quote->entity_id,
                'series_key' => 'INV',
                'document_type' => '380',
                'status' => 'draft',
                'party_id' => (string) $quote->party_id,
                'issue_date' => $date->toDateString(),
                'tax_point' => $date->toDateString(),
                'currency' => $currency,
                'net_amount' => (string) $breakdown->net->amount,
                'tax_amount' => (string) $breakdown->tax->amount,
                'gross_amount' => (string) $breakdown->gross()->amount,
                'payable_amount' => (string) $breakdown->gross()->amount,
                'presentation_exponent' => (int) $quote->presentation_exponent,
                'profile' => $quote->profile,
            ]);

            foreach ($lines as $index => $line) {
                InvoiceLine::create([
                    'entity_id' => (string) $quote->entity_id,
                    'invoice_id' => (string) $invoice->getKey(),
                    'line_no' => $index + 1,
                    'item_id' => $line->item_id,
                    'item_code' => $line->item_code,
                    'description' => (string) $line->description,
                    'unit_code' => (string) $line->unit_code,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'net_amount' => (string) $line->net_amount,
                    'revenue_account_id' => $line->revenue_account_id,
                    'tax_code_id' => $line->tax_code_id,
                    'supply_type' => \App\Tenant\Sales\SupplyTypes::of(
                        \is_string($line->supply_type ?? null) ? (string) $line->supply_type : null,
                        $line->item_id === null ? null : (string) $line->item_id,
                    ),
                    'tax_category' => (string) $line->tax_category,
                    'tax_rate' => (string) $line->tax_rate,
                    'tax_point' => $date->toDateString(),
                    /*
                     | Not stated on a draft - PF-078.
                     |
                     | This used to write `'domestic'` as a literal, which
                     | satisfied the validator's `place_of_supply_unresolved`
                     | gate with the very fiction the gate exists to catch.
                     | `Issuer` asks the rule book at issue and writes both
                     | columns from the decision; until then the honest value
                     | is "not decided yet".
                     */
                    'place_of_supply' => null,
                    'place_of_supply_rule' => null,
                ]);
            }

            foreach ($breakdown->entries as $entry) {
                InvoiceTaxBreakdownRow::create([
                    'entity_id' => (string) $quote->entity_id,
                    'invoice_id' => (string) $invoice->getKey(),
                    'category' => $entry->category->value,
                    'rate' => $entry->ratePercent,
                    'taxable_amount' => (string) $entry->taxableAmount->amount,
                    'tax_amount' => (string) $entry->taxAmount->amount,
                    'exemption_reason_code' => $entry->reasonCode,
                    'exemption_reason_text' => $entry->reasonText,
                    'report_box' => 'standard_sales',
                ]);
            }

            InvoiceReference::create([
                'entity_id' => (string) $quote->entity_id,
                'invoice_id' => (string) $invoice->getKey(),
                'kind' => 'contract',
                'reference' => $quote->label(),
                'reference_date' => $quote->issue_date?->toDateString(),
            ]);

            $quote->update(['outcome_type' => 'invoice', 'outcome_id' => (string) $invoice->getKey()]);

            return $invoice->refresh();
        });
    }
}
