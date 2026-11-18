<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * The current / non-current split that IAS 1 requires - PF-184.
 *
 * ## Why it is a property of the LINE and not of the account
 *
 * IAS 1.60 asks for assets and liabilities to be presented current and
 * non-current separately, and 1.66 defines current as realised or settled
 * within twelve months. That is a fact about the item, so in principle an
 * account could hold both - a receivable due in eighteen months sits in the
 * same ledger account as one due next month.
 *
 * This product does not split a single account across the two, and says so
 * rather than pretending: the split is a property of the statement LINE, and
 * a balance that has to be reclassified is reclassified by moving it to an
 * account on the other line. The alternative is a maturity analysis per
 * balance, which needs a due date on every posting, which this ledger does
 * not have and should not acquire for presentation's sake.
 *
 * Equity is neither, and saying so explicitly is the point of the third
 * case: a null would be read as "nobody classified this", which is the one
 * state a statement must distinguish from a deliberate one.
 */
enum Maturity: string
{
    case Current = 'current';

    case NonCurrent = 'non_current';

    /** Equity, and the lines of the statement of profit or loss. */
    case NotApplicable = 'not_applicable';

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::Current => $locale === 'ar' ? 'متداول' : 'Current',
            self::NonCurrent => $locale === 'ar' ? 'غير متداول' : 'Non-current',
            self::NotApplicable => $locale === 'ar' ? 'غير منطبق' : 'Not applicable',
        };
    }
}
