<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * What an account IS, which decides three things at once - PF-037.
 *
 * The natural sign, whether it closes at year end, and which statement it
 * belongs to are not independent choices. An expense that does not close is
 * not an expense; it is a bug that makes retained earnings wrong by exactly
 * its balance, every year, cumulatively.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /**
     * +1 if a debit increases it, -1 if a credit does.
     *
     * With signed amounts this is presentation only: the storage is already
     * signed, and this says which way round to show it so that a revenue
     * balance reads as a positive number on a P&L rather than as the negative
     * it is in the ledger.
     */
    public function naturalSign(): int
    {
        return match ($this) {
            self::Asset, self::Expense => 1,
            self::Liability, self::Equity, self::Revenue => -1,
        };
    }

    /**
     * Does this account close to retained earnings at year end?
     *
     * Revenue and expense do; the balance sheet carries forward. This single
     * answer is what makes the year-end close derivable rather than
     * configured per account - and a configuration that can be wrong per
     * account will be.
     */
    public function closesAtYearEnd(): bool
    {
        return $this === self::Revenue || $this === self::Expense;
    }

    public function statement(): string
    {
        return $this->closesAtYearEnd() ? 'profit_and_loss' : 'balance_sheet';
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::Asset => $locale === 'ar' ? 'أصول' : 'asset',
            self::Liability => $locale === 'ar' ? 'التزامات' : 'liability',
            self::Equity => $locale === 'ar' ? 'حقوق ملكية' : 'equity',
            self::Revenue => $locale === 'ar' ? 'إيرادات' : 'revenue',
            self::Expense => $locale === 'ar' ? 'مصروفات' : 'expense',
        };
    }
}
