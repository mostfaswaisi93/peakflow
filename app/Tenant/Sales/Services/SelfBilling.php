<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Money\Money;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\SelfBillingAgreement;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Sales\Exceptions\SelfBillingRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\Services\TaxRounding;
use App\Tenant\Tax\TaxableLine;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The buyer issuing the supplier's invoice, under an agreement - PF-100.
 *
 * ## The type is not available until the agreement is
 *
 * Every regime that permits self-billing requires a prior agreement between
 * the two parties. So `availableFor()` answers from the agreements table and
 * the sales screens offer the type only where it returns true - and the
 * database refuses a `389` whose supplier has no agreement covering its tax
 * point, because a second issuing path would otherwise produce a document
 * the regime does not recognise and the supplier would be the one asked to
 * explain it.
 *
 * ## It posts as a purchase
 *
 * Which is the part that is easy to get backwards, and the reason the
 * posting rule carries a long comment about it rather than this class.
 */
final class SelfBilling
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly TaxRounding $rounding,
    ) {}

    /** The agreement in force with this supplier on a date, if any. */
    public function agreementFor(Party $supplier, Carbon $on): ?SelfBillingAgreement
    {
        return SelfBillingAgreement::query()
            ->where('party_id', (string) $supplier->getKey())
            ->effectiveOn($on->toImmutable())
            ->first();
    }

    public function availableFor(Party $supplier, Carbon $on): bool
    {
        return $supplier->is_supplier && $this->agreementFor($supplier, $on) !== null;
    }

    /**
     * A self-billed invoice on a supplier's behalf - UNCL 1001 389.
     *
     * @param  list<array{description: string, net: string, expense_account_id: string, tax_code_id: string, tax_rate: string, tax_category?: string, quantity?: string, unit_code?: string, place_of_supply?: string}>  $lines
     *
     * @throws SelfBillingRefused
     */
    public function invoice(
        Entity $entity,
        Party $supplier,
        array $lines,
        ?Carbon $date = null,
        ?string $profile = null,
    ): Invoice {
        $date ??= Carbon::now();

        if (! $supplier->is_supplier) {
            throw SelfBillingRefused::notASupplier((string) $supplier->code);
        }

        $agreement = $this->agreementFor($supplier, $date);

        if ($agreement === null) {
            throw SelfBillingRefused::withoutAnAgreement((string) $supplier->code, $date->toDateString());
        }

        if ($lines === []) {
            throw SelfBillingRefused::ofNothing();
        }

        $currency = (string) ($supplier->default_currency ?? $entity->functional_currency);
        $profile ??= (string) config('jurisdictions.default_profile', 'generic');

        return DB::connection('tenant')->transaction(function () use (
            $entity, $supplier, $lines, $date, $currency, $profile, $agreement
        ): Invoice {
            $taxable = [];

            foreach ($lines as $index => $line) {
                $net = Money::of((string) $line['net'], $currency);

                if ($net->isZero() || $net->isNegative()) {
                    throw SelfBillingRefused::aLineOfNothing($index + 1);
                }

                $taxable[] = TaxableLine::of(
                    $net,
                    TaxCategory::from((string) ($line['tax_category'] ?? 'S')),
                    (string) $line['tax_rate'],
                );
            }

            $breakdown = $this->calculator->compute(
                $taxable,
                $this->rounding->policyFor($profile, $currency),
            );

            $invoice = Invoice::create([
                'entity_id' => (string) $entity->getKey(),
                'series_key' => DocumentType::SelfBilledInvoice->seriesKey(),
                'document_type' => DocumentType::SelfBilledInvoice->value,
                'status' => 'draft',
                'party_id' => (string) $supplier->getKey(),
                'issue_date' => $date->toDateString(),
                'tax_point' => $date->toDateString(),
                'currency' => $currency,
                'net_amount' => (string) $breakdown->net->amount,
                'tax_amount' => (string) $breakdown->tax->amount,
                'gross_amount' => (string) $breakdown->gross()->amount,
                'payable_amount' => (string) $breakdown->gross()->amount,
                'presentation_exponent' => 2,
                'profile' => $profile,

                /*
                 | Marked on the document, in both languages, with the
                 | agreement's reference.
                 |
                 | The type code says `389` to a machine. A person reading
                 | the paper needs to know that the buyer issued it and
                 | under which agreement, which is what most regimes require
                 | the document to state.
                 */
                'note' => sprintf(
                    'Self-billed invoice issued by the customer on the supplier\'s behalf under '.
                    'agreement %s of %s. فاتورة ذاتية صادرة عن المشتري بالنيابة عن المورّد بموجب '.
                    'الاتفاقية %s.',
                    $agreement->reference,
                    $agreement->signed_on?->toDateString() ?? '',
                    $agreement->reference,
                ),
            ]);

            foreach ($lines as $index => $line) {
                InvoiceLine::create([
                    'entity_id' => (string) $entity->getKey(),
                    'invoice_id' => (string) $invoice->getKey(),
                    'line_no' => $index + 1,
                    'description' => (string) $line['description'],
                    'unit_code' => (string) ($line['unit_code'] ?? 'C62'),
                    'quantity' => (string) ($line['quantity'] ?? '1.00000000'),
                    'unit_price' => (string) $line['net'],
                    'net_amount' => (string) $line['net'],

                    /*
                     | The column is `revenue_account_id` and this is an
                     | EXPENSE account.
                     |
                     | The name is historical; the validator asserts the
                     | account's type matches the document's side, so a
                     | self-billed document pointing at revenue accounts is
                     | refused rather than posted the wrong way round.
                     */
                    'revenue_account_id' => (string) $line['expense_account_id'],
                    'tax_code_id' => (string) $line['tax_code_id'],
                    'tax_category' => (string) ($line['tax_category'] ?? 'S'),
                    'tax_rate' => (string) $line['tax_rate'],
                    'tax_point' => $date->toDateString(),
                    'place_of_supply' => (string) ($line['place_of_supply'] ?? $entity->country),
                    'place_of_supply_rule' => 'domestic',
                ]);
            }

            foreach ($breakdown->entries as $entry) {
                InvoiceTaxBreakdownRow::create([
                    'entity_id' => (string) $entity->getKey(),
                    'invoice_id' => (string) $invoice->getKey(),
                    'category' => $entry->category->value,
                    'rate' => $entry->ratePercent,
                    'taxable_amount' => (string) $entry->taxableAmount->amount,
                    'tax_amount' => (string) $entry->taxAmount->amount,
                    'exemption_reason_code' => $entry->reasonCode,
                    'exemption_reason_text' => $entry->reasonText,
                    'report_box' => 'standard_purchases',
                ]);
            }

            return $invoice->refresh();
        });
    }
}
