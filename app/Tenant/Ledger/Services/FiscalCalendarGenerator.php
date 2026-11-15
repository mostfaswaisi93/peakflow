<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Tenant\Ledger\Enums\FiscalCalendar;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\FiscalYear;
use App\Tenant\Ledger\Models\Period;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates fiscal years and their periods - PF-049, PF-051.
 *
 * ## Years are generated AHEAD of time
 *
 * A tenant blocked from posting on the second of January because nobody
 * created the year is a support call on the worst possible morning, and the
 * fix - create a year - is one the person on the phone is not allowed to
 * perform. So provisioning creates the current year and the next one, and a
 * scheduled task keeps a year of runway ahead.
 *
 * ## Three calendar shapes, because a year is not twelve months everywhere
 *
 * `monthly`     twelve periods on calendar month boundaries
 * `four_weekly` thirteen periods of 28 days - common where payroll and
 *               reporting are aligned to weeks
 * `retail_454`  the 52/53-week retail calendar: quarters of 4, 5 and 4 weeks,
 *               so that like-for-like weeks compare across years. Some years
 *               have 53 weeks, and the extra one lands in the final period -
 *               a retail calendar that assumes 52 drifts a day a year until
 *               the year ends in the wrong month.
 */
final class FiscalCalendarGenerator
{
    /**
     * Create a year and its periods.
     *
     * Idempotent on the label, so running it twice does not produce two
     * overlapping years - which the database would refuse anyway, with a
     * message about an exclusion constraint rather than about what happened.
     */
    public function generate(
        string $entityId,
        string $label,
        Carbon $startsOn,
        FiscalCalendar $calendar = FiscalCalendar::Monthly,
        bool $withAdjustingPeriod = true,
    ): FiscalYear {
        $existing = FiscalYear::query()
            ->where('entity_id', $entityId)
            ->where('label', $label)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $label,
            $startsOn,
            $calendar,
            $withAdjustingPeriod,
        ): FiscalYear {
            $periods = $calendar->periodsFrom($startsOn->copy()->startOfDay());
            $endsOn = Carbon::parse(end($periods)['ends_on']);

            $year = FiscalYear::create([
                'entity_id' => $entityId,
                'label' => $label,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'calendar' => $calendar->value,
            ]);

            foreach ($periods as $index => $period) {
                Period::create([
                    'entity_id' => $entityId,
                    'fiscal_year_id' => $year->getKey(),
                    'ordinal' => $index + 1,
                    'label' => sprintf('%s-%02d', $label, $index + 1),
                    'starts_on' => $period['starts_on'],
                    'ends_on' => $period['ends_on'],
                    // Every period opens as `future`. Opening one is a
                    // deliberate act, which is what stops December being
                    // posted into in March.
                    'state' => PeriodState::Future->value,
                ]);
            }

            if ($withAdjustingPeriod) {
                /*
                 | Period 13 carries no calendar days of its own: it starts
                 | and ends on the year's last day, so audit adjustments land
                 | after the last month without being re-dated into it.
                 |
                 | It is excluded from the overlap constraint for exactly that
                 | reason, and a date lookup skips it - an adjustment has to
                 | be posted there deliberately.
                 */
                Period::create([
                    'entity_id' => $entityId,
                    'fiscal_year_id' => $year->getKey(),
                    'ordinal' => count($periods) + 1,
                    'label' => sprintf('%s-ADJ', $label),
                    'starts_on' => $endsOn->toDateString(),
                    'ends_on' => $endsOn->toDateString(),
                    'is_adjusting' => true,
                    'state' => PeriodState::Future->value,
                ]);
            }

            return $year;
        });
    }

    /**
     * The period an accounting date belongs to.
     *
     * One indexed query. A date outside every defined period returns null and
     * the caller refuses with a message naming the date - posting it to
     * nothing would make it invisible in every report that bounds by period,
     * which is all of them.
     */
    public function periodFor(string $entityId, Carbon $date): ?Period
    {
        return Period::query()
            ->where('entity_id', $entityId)
            // The adjusting period shares its days with the last month, so a
            // date lookup must never land there by accident.
            ->where('is_adjusting', false)
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();
    }
}
