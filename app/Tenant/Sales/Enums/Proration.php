<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Enums;

use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * How a part-period is charged - PF-105.
 *
 * ## Why the basis is a decision and not a formula
 *
 * A customer who joins on the 20th of a 31-day month has used 12 days of it.
 * Whether they pay 12/31 of the month, 12/30 of it, or the whole month is a
 * commercial decision that different businesses make differently - and the
 * one thing none of them wants is for the system to pick.
 *
 * So it is declared on the schedule, and the basis is written onto the
 * generated line. "Prorated 12/31 days from 2026-03-20" on the invoice is
 * the difference between a question and a dispute.
 */
enum Proration: string
{
    /** The full period, whenever it started. */
    case None = 'none';

    /** By day, over the days the period actually has. */
    case ByDay = 'by_day';

    /**
     * By whole months, part months counting as whole ones.
     *
     * Which sounds like `none` and is not: a schedule starting mid-March and
     * billed quarterly charges one month of the quarter under `by_month` and
     * three under `none`.
     */
    case ByMonth = 'by_month';

    /**
     * The fraction of a period a date range covers, and how it was reached.
     *
     * @return array{factor: string, basis: string}
     */
    public function fractionOf(
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $from,
        ?CarbonImmutable $until = null,
    ): array {
        $start = $from->gt($periodStart) ? $from : $periodStart;
        $end = $until !== null && $until->lt($periodEnd) ? $until : $periodEnd;

        if ($end->lt($start)) {
            return ['factor' => '0', 'basis' => 'outside the period'];
        }

        return match ($this) {
            self::None => ['factor' => '1', 'basis' => 'the full period'],

            self::ByDay => (function () use ($periodStart, $periodEnd, $start, $end): array {
                // Inclusive of both ends: a period from the 1st to the 31st
                // is 31 days, not 30, and a customer billed for "1 to 31"
                // who paid for 30 noticed.
                $periodDays = $periodStart->diffInDays($periodEnd) + 1;
                $usedDays = $start->diffInDays($end) + 1;

                return [
                    'factor' => (string) BigDecimal::of((string) $usedDays)
                        ->dividedBy((string) $periodDays, 12, RoundingMode::HalfUp),
                    'basis' => sprintf(
                        'prorated %d/%d days, %s to %s',
                        $usedDays,
                        $periodDays,
                        $start->toDateString(),
                        $end->toDateString(),
                    ),
                ];
            })(),

            self::ByMonth => (function () use ($periodStart, $periodEnd, $start, $end): array {
                $periodMonths = max(1, $periodStart->diffInMonths($periodEnd->addDay()));
                $usedMonths = max(1, $start->diffInMonths($end->addDay()));

                return [
                    'factor' => (string) BigDecimal::of((string) $usedMonths)
                        ->dividedBy((string) $periodMonths, 12, RoundingMode::HalfUp),
                    'basis' => sprintf(
                        'prorated %d/%d months, %s to %s',
                        $usedMonths,
                        $periodMonths,
                        $start->toDateString(),
                        $end->toDateString(),
                    ),
                ];
            })(),
        };
    }

    /** The amount, prorated, with the basis to put on the line. */
    public function applyTo(
        Money $full,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $from,
        ?CarbonImmutable $until = null,
    ): array {
        $fraction = $this->fractionOf($periodStart, $periodEnd, $from, $until);

        return [
            'amount' => $full->multipliedBy($fraction['factor'], RoundingPolicy::halfUp(Scale::Amount)),
            'basis' => $fraction['basis'],
        ];
    }

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::None => $arabic ? 'الفترة كاملة' : 'the full period',
            self::ByDay => $arabic ? 'بالتناسب اليومي' : 'prorated by day',
            self::ByMonth => $arabic ? 'بالتناسب الشهري' : 'prorated by month',
        };
    }
}
