<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * Which statement a line belongs to - PF-184.
 *
 * The two that are built from the chart's own classification. The statement
 * of changes in equity and the statement of cash flows are derived from
 * movements rather than from balances, so an account does not belong to
 * them: they read the journal.
 */
enum FinancialStatement: string
{
    case FinancialPosition = 'financial_position';

    case ProfitOrLoss = 'profit_or_loss';

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::FinancialPosition => $locale === 'ar' ? 'قائمة المركز المالي' : 'Statement of financial position',
            self::ProfitOrLoss => $locale === 'ar' ? 'قائمة الأرباح أو الخسائر' : 'Statement of profit or loss',
        };
    }
}
