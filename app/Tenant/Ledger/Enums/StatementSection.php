<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * The headings a statement is built out of - PF-184.
 *
 * Ordered as they are presented, so the statement's shape is the order of
 * these cases and there is no second list to keep in step.
 */
enum StatementSection: string
{
    case Assets = 'assets';

    case Liabilities = 'liabilities';

    case Equity = 'equity';

    case Income = 'income';

    case Expenses = 'expenses';

    /**
     * Presented after profit for the period, and never inside it.
     *
     * IAS 1.82A. An item of other comprehensive income folded into profit
     * overstates the result, and the error never unwinds: the
     * reclassification that would have corrected it is exactly what did
     * not happen.
     */
    case OtherComprehensiveIncome = 'other_comprehensive_income';

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::Assets => $locale === 'ar' ? 'الأصول' : 'Assets',
            self::Liabilities => $locale === 'ar' ? 'الالتزامات' : 'Liabilities',
            self::Equity => $locale === 'ar' ? 'حقوق الملكية' : 'Equity',
            self::Income => $locale === 'ar' ? 'الإيرادات' : 'Income',
            self::Expenses => $locale === 'ar' ? 'المصروفات' : 'Expenses',
            self::OtherComprehensiveIncome => $locale === 'ar'
                ? 'الدخل الشامل الآخر'
                : 'Other comprehensive income',
        };
    }

    /**
     * The sign in which this section's balances are PRESENTED.
     *
     * A liability's natural balance is a credit, which the ledger stores as
     * a negative functional amount; a statement presents it as a positive
     * number under "Liabilities". So the section carries the flip, once,
     * rather than every report doing it - and the one report that forgot
     * would print equity as a negative and still balance, which is the
     * failure nobody spots because the total is right.
     */
    public function presentationSign(): int
    {
        return match ($this) {
            self::Assets, self::Expenses => 1,

            /*
             | OCI is presented at its ledger sign, like income.
             |
             | A gain is a credit and is shown positive; a loss is a debit
             | and is shown in parentheses. Flipping it the other way
             | would present a translation loss as a gain, and the
             | statement of changes in equity - which reads the same
             | figure - would then reconcile to a balance sheet that
             | disagreed with it.
             */
            self::Liabilities, self::Equity, self::Income, self::OtherComprehensiveIncome => -1,
        };
    }
}
