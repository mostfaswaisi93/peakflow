<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * Where a line sits relative to the subtotals - PF-185.
 *
 * ## Why the subtotals are derived and not written down
 *
 * "Gross margin", "operating result" and "profit for the period" are each
 * a sum of the lines above a boundary. Hard-coding them as
 * `revenue - cost_of_sales` is a second definition of the statement's
 * shape, and the first line added to the wrong side of it produces a
 * subtotal that is wrong and a total that is right - which is the hardest
 * arithmetic error to see, because the bottom of the statement still
 * agrees with the ledger.
 *
 * So each line declares which band it is in, the bands are ordered, and a
 * subtotal is the running total at a band boundary. Adding a line puts it
 * in a band; nothing else changes.
 *
 * ## IAS 1 does not define "operating"
 *
 * It requires the line items and permits additional subtotals (1.85), and
 * it deliberately does not define an operating result - because entities
 * disagree about what belongs in one. The band boundaries here are this
 * product's answer, stated once, rather than an answer implied by the
 * order somebody happened to write the cases in.
 */
enum ProfitOrLossBand: string
{
    /** Revenue and cost of sales. The boundary below them is gross margin. */
    case Gross = 'gross';

    /** Other income and the operating expenses. */
    case Operating = 'operating';

    /** Finance costs. The boundary below is profit before tax. */
    case Financing = 'financing';

    case Tax = 'tax';

    /**
     * Not profit at all.
     *
     * IAS 1.82A presents other comprehensive income after profit for the
     * period and separately from it, split by whether the item will ever
     * be reclassified into profit or loss. An OCI item folded into profit
     * overstates the result and the error never unwinds, because the
     * reclassification that would have corrected it never happens.
     */
    case OtherComprehensiveIncome = 'other_comprehensive_income';

    /** The subtotal that CLOSES this band, or null where none does. */
    public function closingSubtotal(): ?string
    {
        return match ($this) {
            self::Gross => 'gross_margin',
            self::Operating => 'operating_result',
            self::Financing => 'profit_before_tax',
            self::Tax => 'profit_for_the_period',
            self::OtherComprehensiveIncome => 'total_comprehensive_income',
        };
    }

    public function subtotalLabel(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::Gross => $locale === 'ar' ? 'مجمل الربح' : 'Gross margin',
            self::Operating => $locale === 'ar' ? 'نتيجة التشغيل' : 'Operating result',
            self::Financing => $locale === 'ar' ? 'الربح قبل الضريبة' : 'Profit before tax',
            self::Tax => $locale === 'ar' ? 'ربح الفترة' : 'Profit for the period',
            self::OtherComprehensiveIncome => $locale === 'ar'
                ? 'إجمالي الدخل الشامل'
                : 'Total comprehensive income',
        };
    }

    public function subtotalReference(): string
    {
        return match ($this) {
            self::Gross, self::Operating => 'IAS 1.85',
            self::Financing => 'IAS 1.82(d)',
            self::Tax => 'IAS 1.81A(a)',
            self::OtherComprehensiveIncome => 'IAS 1.81A(c)',
        };
    }
}
