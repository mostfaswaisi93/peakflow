<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * Which of IAS 7's three activities a movement belongs to - PF-186.
 *
 * ## Why this is derived from the statement line and not a fourth column
 *
 * IAS 7.6 classifies CASH FLOWS, not accounts, and the practical way every
 * system does it is to classify the account whose movement produced the
 * flow. That classification is already on the account: a receivable is
 * working capital because it is a receivable, and property is investing
 * because it is property. Adding a column to say it again would be a second
 * place for the same fact to be wrong, and the two would disagree on the
 * first account somebody edited.
 *
 * ## What this costs, said plainly
 *
 * IAS 7.16 classifies by the NATURE of the transaction, and a presentation
 * line is a proxy for it. The proxy is wrong in cases this product does not
 * yet have: an "other current asset" that is really a short-term
 * investment, or a provision unwound through finance costs. When those
 * arrive the answer is an override on the account that defaults to this -
 * not a different default, because a default nobody can predict is worse
 * than one that is occasionally wrong in a way an accountant can see.
 */
enum CashFlowActivity: string
{
    /**
     * The cash itself, which is the statement's subject and not a flow.
     *
     * Cash and cash equivalents move BECAUSE of the other three. Counting
     * their own movement as an operating flow would double the statement
     * and make it balance, which is the failure that looks like success.
     */
    case Cash = 'cash';

    case Operating = 'operating';

    case Investing = 'investing';

    case Financing = 'financing';

    /**
     * A movement that moved no cash at all - IAS 7.43.
     *
     * Revaluations, translation differences, a provision raised against
     * profit. They are excluded from every activity and LISTED, because an
     * unexplained difference between the two methods is almost always a
     * non-cash item nobody tagged - and a statement that silently dropped
     * them would reconcile by hiding the evidence.
     */
    case NonCash = 'non_cash';

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return match ($this) {
            self::Cash => $locale === 'ar' ? 'النقد وما في حكمه' : 'Cash and cash equivalents',
            self::Operating => $locale === 'ar' ? 'الأنشطة التشغيلية' : 'Operating activities',
            self::Investing => $locale === 'ar' ? 'الأنشطة الاستثمارية' : 'Investing activities',
            self::Financing => $locale === 'ar' ? 'الأنشطة التمويلية' : 'Financing activities',
            self::NonCash => $locale === 'ar' ? 'حركات غير نقدية' : 'Non-cash movements',
        };
    }

    public function reference(): string
    {
        return match ($this) {
            self::Cash => 'IAS 7.7',
            self::Operating => 'IAS 7.13',
            self::Investing => 'IAS 7.16',
            self::Financing => 'IAS 7.17',
            self::NonCash => 'IAS 7.43',
        };
    }

    /** The three that appear as sections on the statement, in order. */
    public static function activities(): array
    {
        return [self::Operating, self::Investing, self::Financing];
    }
}
