<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Enums;

use Carbon\CarbonImmutable;

/**
 * How often a registration files - PF-085.
 *
 * ## A return period is not an accounting period
 *
 * Monthly VAT on an entity that closes quarterly is completely normal, and so
 * is quarterly VAT on an entity that closes monthly. Deriving one from the
 * other is the mistake that makes a return period unfileable: the figures are
 * right and the window is wrong, so the return either includes a month that
 * belongs to the next one or omits one that belonged to the last.
 *
 * Some regimes also set the frequency from turnover, so it changes for a
 * business that grows - which is another reason it belongs to the
 * registration rather than to the calendar.
 */
enum FilingFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Annual => 12,
        };
    }

    /**
     * The periods of one calendar year, as half-open day ranges.
     *
     * Calendar months rather than an offset year, deliberately: a VAT return
     * period is a calendar window in every regime shipped here, even where the
     * entity's financial year is not. A regime whose return year is offset
     * would be a frequency case of its own rather than an argument here.
     *
     * @return list<array{label: string, from: CarbonImmutable, until: CarbonImmutable}>
     */
    public function periodsIn(int $year): array
    {
        $periods = [];
        $step = $this->months();

        for ($month = 1; $month <= 12; $month += $step) {
            $from = CarbonImmutable::create($year, $month, 1);

            \assert($from instanceof CarbonImmutable);

            $until = $from->addMonths($step);

            $periods[] = [
                'label' => $this->label($year, $month),
                'from' => $from,
                // Half-open: the first day that is NOT in the period, so a
                // successor's start is its predecessor's end and nothing
                // anywhere does arithmetic on "the day before".
                'until' => $until,
            ];
        }

        return $periods;
    }

    /** `2026-05`, `2026-Q2`, `2026`. */
    public function label(int $year, int $month): string
    {
        return match ($this) {
            self::Monthly => sprintf('%04d-%02d', $year, $month),
            self::Quarterly => sprintf('%04d-Q%d', $year, intdiv($month - 1, 3) + 1),
            self::Annual => sprintf('%04d', $year),
        };
    }

    public function describe(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::Monthly => $arabic ? 'شهري' : 'monthly',
            self::Quarterly => $arabic ? 'ربع سنوي' : 'quarterly',
            self::Annual => $arabic ? 'سنوي' : 'annual',
        };
    }
}
