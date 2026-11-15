<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Tenant\Ledger\Models\Period;
use App\Tenant\Sales\InvoiceFinding;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * What would prevent this draft being issued - PF-092.
 *
 * ## Every finding at once, continuously
 *
 * Not one at a time and not at the end. A form that validates on submit makes
 * somebody fix one thing, press the button, and find the next - and the last
 * one surfaces on the day of a deadline. Everything this can see comes back in
 * one call, addressed by field.
 *
 * ## Why validation is not the same as the database's refusals
 *
 * The constraints in PF-091 are the floor: they stop a document that is
 * internally impossible. This is the ceiling: a document that is perfectly
 * storable and would still be wrong to issue, because the period is closed,
 * the customer has no registration for a reverse charge, or the export has no
 * proof of exit.
 *
 * The two overlap on purpose. A validator that trusted the constraints would
 * report nothing until the write failed, and a constraint that trusted the
 * validator would be a comment.
 */
final class InvoiceValidator
{
    public function __construct(private readonly ReturnPeriods $returnPeriods) {}

    /**
     * @return list<InvoiceFinding>
     */
    public function validate(Invoice $invoice, Entity $entity): array
    {
        $findings = [];

        $lines = $invoice->lines()->get();

        if ($lines->isEmpty()) {
            $findings[] = InvoiceFinding::blocking(
                'lines',
                'no_lines',
                'An invoice with no lines has nothing to charge for.',
                'لا يمكن إصدار فاتورة بلا سطور.',
            );
        }

        foreach ($lines as $line) {
            foreach ($this->checkLine($line) as $finding) {
                $findings[] = $finding;
            }
        }

        foreach ($this->checkTotals($invoice, $lines->all()) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->checkTheParty($invoice, $lines->all()) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->checkTheCalendar($invoice, $entity) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->checkTheCurrency($invoice, $entity) as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * @param  list<InvoiceFinding>  $findings
     * @return list<InvoiceFinding>
     */
    public function blocking(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (InvoiceFinding $finding): bool => $finding->blocking,
        ));
    }

    /** @return list<InvoiceFinding> */
    private function checkLine(InvoiceLine $line): array
    {
        $findings = [];
        $field = 'lines.'.$line->line_no;

        if ($line->tax_code_id === null) {
            $findings[] = InvoiceFinding::blocking(
                $field.'.tax_code_id',
                'line_without_a_tax_code',
                'This line has no tax code, so its tax cannot reach a return: a return is assembled '.
                'from codes, and a line without one is computed and then dropped.',
                'هذا السطر بلا رمز ضريبي، فلا تصل ضريبته إلى الإقرار.',
            );
        }

        if ($line->tax_point === null) {
            $findings[] = InvoiceFinding::blocking(
                $field.'.tax_point',
                'line_without_a_tax_point',
                'This line has no tax point, so nothing decides which return period it falls into.',
                'هذا السطر بلا تاريخ استحقاق ضريبي، فلا شيء يحدد فترة الإقرار التي يقع فيها.',
            );
        }

        if ($line->place_of_supply === null || $line->place_of_supply_rule === null) {
            $findings[] = InvoiceFinding::blocking(
                $field.'.place_of_supply',
                'place_of_supply_unresolved',
                'The place of supply has not been resolved for this line, so which jurisdiction\'s tax '.
                'applies is unknown - and a default would issue a document in a jurisdiction nobody chose.',
                'لم يُحدَّد مكان التوريد لهذا السطر، فلا يُعرف أي ولاية ضريبية تنطبق.',
            );
        }

        $category = $line->tax_category;

        if ($category->requiresExemptionReason() && $category !== TaxCategory::Standard) {
            $breakdown = $line->invoice?->taxBreakdown()
                ->where('category', $category->value)
                ->whereNotNull('exemption_reason_code')
                ->exists();

            if ($breakdown !== true) {
                $findings[] = InvoiceFinding::blocking(
                    $field.'.tax_category',
                    'exemption_reason_missing',
                    sprintf(
                        'A %s line needs an exemption reason in the tax breakdown - EN 16931 BT-121. An '.
                        'invoice that says 0%% with no reason is an invoice that cannot be defended.',
                        $category->value,
                    ),
                    sprintf('سطر بالفئة %s يحتاج سبب إعفاء في تفصيل الضريبة.', $category->value),
                );
            }
        }

        if ($category->requiresEvidence()) {
            $findings[] = InvoiceFinding::advisory(
                $field.'.evidence',
                'evidence_required',
                sprintf(
                    'A %s line requires evidence on record before the treatment can be claimed. The '.
                    'gate is PF-080; attaching the evidence to a document is Phase 05\'s remaining work.',
                    $category->value,
                ),
                sprintf('سطر بالفئة %s يتطلب إثباتاً مسجلاً قبل المطالبة بالمعاملة.', $category->value),
            );
        }

        return $findings;
    }

    /**
     * @param  list<InvoiceLine>  $lines
     * @return list<InvoiceFinding>
     */
    private function checkTotals(Invoice $invoice, array $lines): array
    {
        $findings = [];

        $net = BigDecimal::zero();

        foreach ($lines as $line) {
            $net = $net->plus($line->net_amount);
        }

        $documentAdjustments = BigDecimal::zero();

        foreach ($invoice->allowancesAndCharges()->whereNull('invoice_line_id')->get() as $adjustment) {
            $amount = BigDecimal::of((string) $adjustment->amount);
            $documentAdjustments = $adjustment->is_charge
                ? $documentAdjustments->plus($amount)
                : $documentAdjustments->minus($amount);
        }

        $expected = $net->plus($documentAdjustments)->toScale(4);

        if (! $expected->isEqualTo(BigDecimal::of($invoice->net_amount))) {
            $findings[] = InvoiceFinding::blocking(
                'net_amount',
                'net_does_not_match_the_lines',
                sprintf(
                    'The net is %s and the lines plus document adjustments come to %s. A document whose '.
                    'header disagrees with its own lines is one nobody can reconcile to anything.',
                    $invoice->net_amount,
                    (string) $expected,
                ),
                'الصافي في الترويسة لا يطابق مجموع السطور.',
            );
        }

        $breakdownTax = BigDecimal::zero();
        $breakdownTaxable = BigDecimal::zero();

        foreach ($invoice->taxBreakdown()->get() as $row) {
            $breakdownTax = $breakdownTax->plus($row->tax_amount);
            $breakdownTaxable = $breakdownTaxable->plus($row->taxable_amount);
        }

        if (! $breakdownTax->toScale(4)->isEqualTo(BigDecimal::of($invoice->tax_amount))) {
            $findings[] = InvoiceFinding::blocking(
                'tax_amount',
                'breakdown_does_not_sum_to_the_tax',
                sprintf(
                    'The tax total is %s and the breakdown sums to %s - EN 16931 BR-CO-14 requires them '.
                    'to agree, and a receiving system checks it before a person does.',
                    $invoice->tax_amount,
                    (string) $breakdownTax->toScale(4),
                ),
                'إجمالي الضريبة لا يساوي مجموع تفصيل الضريبة.',
            );
        }

        if ($invoice->taxBreakdown()->count() === 0 && $lines !== []) {
            $findings[] = InvoiceFinding::blocking(
                'tax_breakdown',
                'no_breakdown',
                'The document has lines and no VAT breakdown. BG-23 is mandatory, and a receiving '.
                'system rejects a document without it.',
                'المستند يحتوي سطوراً بلا تفصيل ضريبي.',
            );
        }

        return $findings;
    }

    /**
     * @param  list<InvoiceLine>  $lines
     * @return list<InvoiceFinding>
     */
    private function checkTheParty(Invoice $invoice, array $lines): array
    {
        $findings = [];
        $party = $invoice->party;

        if ($party === null) {
            return [InvoiceFinding::blocking(
                'party_id',
                'no_customer',
                'The document has no customer.',
                'المستند بلا عميل.',
            )];
        }

        if (! (bool) $party->is_customer) {
            $findings[] = InvoiceFinding::blocking(
                'party_id',
                'party_is_not_a_customer',
                'This party is not marked as a customer. Invoicing a supplier record is how a payment '.
                'ends up chasing the wrong side of a relationship.',
                'هذا الطرف غير مُعلَّم كعميل.',
            );
        }

        if ($party->isMerged()) {
            $findings[] = InvoiceFinding::blocking(
                'party_id',
                'party_was_merged',
                'This party has been merged into another record, so the document would be addressed to '.
                'a party nobody maintains.',
                'تم دمج هذا الطرف في سجل آخر.',
            );
        }

        /*
         | A reverse charge needs the customer's registration - PF-079.
         |
         | Checked here as well as at the moment of issue, because the person
         | editing can fix it now and cannot fix it then: at issue the only
         | options are to abandon the document or to change its tax treatment,
         | and the second is what people do.
         */
        $reverseCharged = array_filter(
            $lines,
            static fn (InvoiceLine $line): bool => $line->tax_category === TaxCategory::ReverseCharge,
        );

        if ($reverseCharged !== []) {
            $jurisdiction = (string) (reset($reverseCharged)->place_of_supply ?? '');
            $taxPoint = $invoice->tax_point ?? $invoice->issue_date;

            $registration = $jurisdiction === '' || $taxPoint === null
                ? null
                : $party->registrationIn($jurisdiction, $taxPoint);

            if ($registration === null) {
                $findings[] = InvoiceFinding::blocking(
                    'party_id.registration',
                    'reverse_charge_without_a_registration',
                    sprintf(
                        'This document reverse-charges the tax and the customer has no %s registration '.
                        'valid on the tax point, so there is nobody to account for it. A reverse charge '.
                        'issued anyway is a taxable supply with no tax on it.',
                        $jurisdiction === '' ? 'relevant' : $jurisdiction,
                    ),
                    'المستند يحوّل الضريبة إلى العميل ولا يوجد تسجيل سارٍ له في تاريخ الاستحقاق.',
                );
            }
        }

        return $findings;
    }

    /** @return list<InvoiceFinding> */
    private function checkTheCalendar(Invoice $invoice, Entity $entity): array
    {
        $findings = [];
        $entityId = (string) $entity->getKey();

        $period = Period::query()
            ->where('entity_id', $entityId)
            ->whereDate('starts_on', '<=', $invoice->issue_date)
            ->whereDate('ends_on', '>=', $invoice->issue_date)
            ->first();

        if ($period === null) {
            $findings[] = InvoiceFinding::blocking(
                'issue_date',
                'no_accounting_period',
                'No accounting period covers the issue date, so the posting has nowhere to go.',
                'لا توجد فترة محاسبية تغطي تاريخ الإصدار.',
            );
        } elseif (! $period->state->acceptsPostings()) {
            $findings[] = InvoiceFinding::blocking(
                'issue_date',
                'period_is_not_open',
                sprintf(
                    'The %s period is %s, so this document cannot be posted into it. Issuing it with a '.
                    'later date instead moves a supply between periods, which is a decision rather than '.
                    'a workaround.',
                    (string) $period->label,
                    $period->state->value,
                ),
                sprintf('الفترة %s حالتها %s، فلا يمكن الترحيل إليها.', (string) $period->label, $period->state->value),
            );
        }

        /*
         | And the RETURN period, which is a different calendar - PF-085.
         |
         | A document whose tax point falls in a filed return is refused
         | rather than moved into the next one: quietly reporting a supply a
         | quarter late mis-states both returns.
         */
        $taxPoint = $invoice->tax_point;

        if ($taxPoint !== null) {
            $jurisdiction = (string) $invoice->lines()->value('place_of_supply');

            if ($jurisdiction !== '') {
                try {
                    $this->returnPeriods->assertReportable($entityId, $jurisdiction, $taxPoint);
                } catch (\Throwable $e) {
                    $findings[] = InvoiceFinding::blocking(
                        'tax_point',
                        'return_period_will_not_take_it',
                        $e->getMessage(),
                        'فترة الإقرار لا تقبل هذا المستند.',
                    );
                }
            }
        }

        return $findings;
    }

    /** @return list<InvoiceFinding> */
    private function checkTheCurrency(Invoice $invoice, Entity $entity): array
    {
        $findings = [];

        $enabled = DB::connection('tenant')->table('entity_currencies')
            ->where('entity_id', (string) $entity->getKey())
            ->where('currency', $invoice->currency)
            ->where('enabled', true)
            ->exists();

        if (! $enabled) {
            $findings[] = InvoiceFinding::blocking(
                'currency',
                'currency_is_not_enabled',
                sprintf(
                    '%s is not enabled for this entity. A document in a currency nobody enabled has no '.
                    'rate policy behind it, so its translation is whatever the first fetch returns.',
                    $invoice->currency,
                ),
                sprintf('العملة %s غير مُمكَّنة لهذا الكيان.', $invoice->currency),
            );
        }

        if ($invoice->currency !== $entity->functional_currency && $invoice->fx_rate === null) {
            $findings[] = InvoiceFinding::blocking(
                'fx_rate',
                'foreign_currency_without_a_rate',
                sprintf(
                    'The document is in %s and the entity reports in %s, so it needs a rate - with its '.
                    'source and date, frozen on the document.',
                    $invoice->currency,
                    (string) $entity->functional_currency,
                ),
                'المستند بعملة أجنبية بلا سعر تحويل مثبَّت.',
            );
        }

        return $findings;
    }
}
