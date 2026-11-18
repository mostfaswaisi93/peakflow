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

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::Assets => $locale === 'ar' ? 'الأصول' : 'Assets',
            self::Liabilities => $locale === 'ar' ? 'الالتزامات' : 'Liabilities',
            self::Equity => $locale === 'ar' ? 'حقوق الملكية' : 'Equity',
            self::Income => $locale === 'ar' ? 'الإيرادات' : 'Income',
            self::Expenses => $locale === 'ar' ? 'المصروفات' : 'Expenses',
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
            self::Liabilities, self::Equity, self::Income => -1,
        };
    }
}
