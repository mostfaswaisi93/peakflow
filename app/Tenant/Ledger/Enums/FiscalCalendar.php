<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

use Illuminate\Support\Carbon;

/**
 * How a fiscal year is divided - PF-049.
 *
 * The shape belongs to the YEAR rather than to the entity, because an entity
 * that switches from monthly to 4-4-5 does so at a year boundary and its old
 * years keep their old shape. A report comparing the two says which it used.
 */
enum FiscalCalendar: string
{
    case Monthly = 'monthly';
    case FourWeekly = 'four_weekly';
    case Retail454 = 'retail_454';

    /**
     * The periods of one year, starting from a date.
     *
     * @return list<array{starts_on: string, ends_on: string}>
     */
    public function periodsFrom(Carbon $start): array
    {
        return match ($this) {
            self::Monthly => $this->monthly($start),
            self::FourWeekly => $this->fixedWeeks($start, 13, 4),
            self::Retail454 => $this->retail($start),
        };
    }

    public function periodCount(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::FourWeekly, self::Retail454 => 13,
        };
    }

    /** @return list<array{starts_on: string, ends_on: string}> */
    private function monthly(Carbon $start): array
    {
        $periods = [];

        for ($i = 0; $i < 12; $i++) {
            $from = $start->copy()->addMonthsNoOverflow($i);

            /*
             | `addMonthsNoOverflow` and then `endOfMonth` on the period's own
             | month, not `addMonth` on the end date.
             |
             | A year starting 31 January would otherwise produce a February
             | period ending 3 March: adding a month to 31 January overflows,
             | and the overflow silently moves three days of revenue into the
             | wrong period every year the start date is the 29th or later.
             */
            $periods[] = [
                'starts_on' => $from->toDateString(),
                'ends_on' => $start->copy()->addMonthsNoOverflow($i + 1)->subDay()->toDateString(),
            ];
        }

        return $periods;
    }

    /** @return list<array{starts_on: string, ends_on: string}> */
    private function fixedWeeks(Carbon $start, int $count, int $weeks): array
    {
        $periods = [];
        $cursor = $start->copy();

        for ($i = 0; $i < $count; $i++) {
            $ends = $cursor->copy()->addWeeks($weeks)->subDay();

            $periods[] = [
                'starts_on' => $cursor->toDateString(),
                'ends_on' => $ends->toDateString(),
            ];

            $cursor = $ends->copy()->addDay();
        }

        return $periods;
    }

    /**
     * The 52/53-week retail calendar: quarters of 4, 5 and 4 weeks.
     *
     * Grouping weeks this way is what lets a retailer compare like-for-like
     * weeks across years - a month boundary moves relative to weekends, and
     * a weekend is most of a retail week's takings.
     *
     * @return list<array{starts_on: string, ends_on: string}>
     */
    private function retail(Carbon $start): array
    {
        $pattern = [4, 5, 4, 4, 5, 4, 4, 5, 4, 4, 5, 4];
        $periods = [];
        $cursor = $start->copy();

        foreach ($pattern as $weeks) {
            $ends = $cursor->copy()->addWeeks($weeks)->subDay();

            $periods[] = [
                'starts_on' => $cursor->toDateString(),
                'ends_on' => $ends->toDateString(),
            ];

            $cursor = $ends->copy()->addDay();
        }

        /*
         | The 53rd week.
         |
         | 52 weeks is 364 days, so a retail year drifts a day against the
         | calendar every year and roughly every fifth year needs a 53rd week
         | to catch up. It is added as its own period rather than stretched
         | into the twelfth, so that a year with 53 weeks is visibly a year
         | with 53 weeks rather than a twelfth period that is mysteriously
         | larger than its predecessors.
         */
        $nextYearStart = $start->copy()->addYear();
        $drift = $cursor->diffInDays($nextYearStart, absolute: false);

        if ($drift >= 7) {
            $periods[] = [
                'starts_on' => $cursor->toDateString(),
                'ends_on' => $cursor->copy()->addWeek()->subDay()->toDateString(),
            ];
        }

        return $periods;
    }
}
