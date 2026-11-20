<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Enums\PrepaymentTaxPoint;
use App\Tenant\Sales\Exceptions\PrepaymentRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceReference;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Models\TaxCode;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\Services\TaxRounding;
use App\Tenant\Tax\TaxableLine;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Prepayment invoices, and settling them against the final one - PF-099.
 *
 * ## What a prepayment invoice is for
 *
 * Money taken before performance. It is a tax document in most regimes -
 * the tax point is the earlier of payment and invoice - and it is **not** a
 * sale: nothing has been supplied, so the credit is a liability under IFRS 15
 * and not revenue.
 *
 * ## One prepayment, one final invoice
 *
 * A prepayment may be applied to exactly one final invoice, enforced by a
 * primary key in the database. Splitting one across several invoices is a
 * real thing people do, and it is deliberately not modelled here: it needs
 * its own balance per prepayment, its own ordering rules when two invoices
 * compete for the remainder, and a report that shows what is left - which is
 * an allocation ledger, not a column. Asking for the second half of a
 * prepayment back is a credit note, which exists.
 *
 * `applyTo()` says so when it refuses, so the person reading it is not left
 * looking for a setting.
 */
final class Prepayments
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly TaxRounding $rounding,
    ) {}

    public function taxPointRule(string $profile): PrepaymentTaxPoint
    {
        /** @var array<string, string> $map */
        $map = (array) config('jurisdictions.prepayment_tax', []);

        if (! \array_key_exists($profile, $map)) {
            throw PrepaymentRefused::anUndeclaredProfile($profile);
        }

        return PrepaymentTaxPoint::from((string) $map[$profile]);
    }

    /**
     * A prepayment invoice for an agreed amount - UNCL 1001 386.
     *
     * @param  string  $net  the amount before tax, because that is what is agreed
     *
     * @throws PrepaymentRefused
     */
    public function invoice(
        Entity $entity,
        Party $party,
        string $net,
        TaxCode $code,
        ?Carbon $date = null,
        ?string $description = null,
        ?string $profile = null,
    ): Invoice {
        $date ??= Carbon::now();
        $currency = (string) ($party->default_currency ?? $entity->functional_currency);
        $amount = Money::of($net, $currency);

        if ($amount->isZero() || $amount->isNegative()) {
            throw PrepaymentRefused::ofNothing();
        }

        $profile ??= (string) config('jurisdictions.default_profile', 'generic');
        $taxes = $this->taxPointRule($profile)->taxesThePrepayment();

        return DB::connection('tenant')->transaction(function () use (
            $entity, $party, $amount, $code, $date, $description, $currency, $profile, $taxes
        ): Invoice {
            $version = $code->rateOn($date);

            if ($version === null) {
                throw PrepaymentRefused::noRateOnThatDate((string) $code->code, $date->toDateString());
            }

            $rate = $taxes ? (string) $version->percentage() : '0.000000';
            $category = $taxes ? $code->category : TaxCategory::OutOfScope;

            $breakdown = $this->calculator->compute(
                [TaxableLine::of($amount, $category, $rate)],
                $this->rounding->policyFor($profile, $currency),
            );

            $invoice = Invoice::create([
                'entity_id' => (string) $entity->getKey(),
                'series_key' => DocumentType::PrepaymentInvoice->seriesKey(),
                'document_type' => DocumentType::PrepaymentInvoice->value,
                'status' => 'draft',
                'party_id' => (string) $party->getKey(),
                'issue_date' => $date->toDateString(),
                'tax_point' => $date->toDateString(),
                'currency' => $currency,
                'net_amount' => (string) $breakdown->net->amount,
                'tax_amount' => (string) $breakdown->tax->amount,
                'gross_amount' => (string) $breakdown->gross()->amount,
                'payable_amount' => (string) $breakdown->gross()->amount,
                'presentation_exponent' => 2,
                'profile' => $profile,
            ]);

            InvoiceLine::create([
                'entity_id' => (string) $entity->getKey(),
                'invoice_id' => (string) $invoice->getKey(),
                'line_no' => 1,
                'description' => $description ?? 'Prepayment against a future supply',
                'unit_code' => 'C62',
                'quantity' => '1.00000000',
                'unit_price' => (string) $amount->amount,
                'net_amount' => (string) $amount->amount,

                /*
                 | No revenue account, deliberately.
                 |
                 | A prepayment line does not reach revenue: its rule credits
                 | `deferred_revenue`, a control account that the role
                 | resolves, so a revenue account on the line would be a
                 | number nobody reads and somebody would eventually post to.
                 */
                'revenue_account_id' => null,
                'tax_code_id' => (string) $code->getKey(),
                'tax_category' => $category->value,
                'tax_rate' => $rate,
                'tax_point' => $date->toDateString(),

                /*
                 | A prepayment is a supply of SERVICES for placing - PF-078.
                 |
                 | Stated rather than left null, and the reason is the tax
                 | point rather than the goods. A prepayment invoice is
                 | raised because money arrived before performance, and
                 | nothing has been delivered when it is raised - so no rule
                 | about where goods went can apply to it, and the rules
                 | that can are the ones about where a service is supplied.
                 |
                 | The eventual supply may well be goods, and it gets its own
                 | document with its own decision. Calling this one goods
                 | would ask the book where goods went on a date when they
                 | had not gone anywhere, and the answer would be a refusal
                 | for a document that is perfectly issuable.
                 */
                'supply_type' => 'service',
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

            foreach ($breakdown->entries as $entry) {
                InvoiceTaxBreakdownRow::create([
                    'entity_id' => (string) $entity->getKey(),
                    'invoice_id' => (string) $invoice->getKey(),
                    'category' => $entry->category->value,
                    'rate' => $entry->ratePercent,
                    'taxable_amount' => (string) $entry->taxableAmount->amount,
                    'tax_amount' => (string) $entry->taxAmount->amount,
                    'exemption_reason_code' => $entry->reasonCode ?? ($taxes ? null : 'VATEX-OUT-OF-SCOPE'),
                    'exemption_reason_text' => $entry->reasonText ?? ($taxes
                        ? null
                        : 'The prepayment is not a taxable event in this jurisdiction; the supply is taxed on the final invoice'),
                    'report_box' => $taxes ? 'standard_sales' : null,
                ]);
            }

            return $invoice->refresh();
        });
    }

    /**
     * Apply an issued prepayment to a final invoice that is still a draft.
     *
     * @throws PrepaymentRefused
     */
    public function applyTo(Invoice $finalDraft, Invoice $prepayment): Invoice
    {
        if (! $finalDraft->isDraft()) {
            throw PrepaymentRefused::theFinalInvoiceIsIssued((string) $finalDraft->number);
        }

        if ($prepayment->document_type !== DocumentType::PrepaymentInvoice) {
            throw PrepaymentRefused::notAPrepayment((string) $prepayment->number);
        }

        if (! $prepayment->isIssued()) {
            throw PrepaymentRefused::aDraftPrepayment();
        }

        if ((string) $prepayment->party_id !== (string) $finalDraft->party_id) {
            throw PrepaymentRefused::anotherCustomer((string) $prepayment->number);
        }

        if ((string) $prepayment->currency !== (string) $finalDraft->currency) {
            throw PrepaymentRefused::anotherCurrency(
                (string) $prepayment->currency,
                (string) $finalDraft->currency,
            );
        }

        $applied = $this->appliedTo($prepayment);

        if ($applied !== null) {
            throw PrepaymentRefused::alreadyApplied(
                (string) $prepayment->number,
                (string) $applied->number,
            );
        }

        $gross = Money::of((string) $prepayment->gross_amount, (string) $finalDraft->currency);
        $invoiced = Money::of((string) $finalDraft->gross_amount, (string) $finalDraft->currency);

        if ($gross->isGreaterThan($invoiced)) {
            throw PrepaymentRefused::largerThanTheInvoice(
                (string) $gross->amount,
                (string) $invoiced->amount,
            );
        }

        return DB::connection('tenant')->transaction(function () use ($finalDraft, $prepayment, $gross, $invoiced): Invoice {
            $finalDraft->update([
                // EN 16931 BT-113: what has already been paid, shown as a
                // deduction so the document says what is left to pay.
                'prepaid_amount' => (string) $gross->amount,
                'payable_amount' => (string) $invoiced->minus($gross)->amount,
            ]);

            InvoiceReference::create([
                'entity_id' => $finalDraft->entity_id,
                'invoice_id' => (string) $finalDraft->getKey(),
                'kind' => 'prepayment',
                'referenced_invoice_id' => (string) $prepayment->getKey(),
                'reference' => (string) $prepayment->number,
                'reference_date' => $prepayment->issue_date?->toDateString(),
            ]);

            return $finalDraft->refresh();
        });
    }

    /** The issued final invoice a prepayment has been applied to, if any. */
    public function appliedTo(Invoice $prepayment): ?Invoice
    {
        $id = DB::connection('tenant')->table('invoice_prepayment_applications')
            ->where('prepayment_invoice_id', (string) $prepayment->getKey())
            ->value('final_invoice_id');

        return $id === null ? null : Invoice::query()->find((string) $id);
    }

    /** The prepayment a draft or issued final invoice carries, if any. */
    public function on(Invoice $invoice): ?Invoice
    {
        $id = $invoice->references()
            ->where('kind', 'prepayment')
            ->value('referenced_invoice_id');

        return $id === null ? null : Invoice::query()->find((string) $id);
    }
}
