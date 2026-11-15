<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Recurrence;

use Carbon\CarbonImmutable;

/**
 * A subset of RFC 5545 `RRULE`, parsed and refused by name - PF-105.
 *
 * ## Why a subset, and why it says so
 *
 * "The last working day of every quarter" has to be expressible rather than
 * approximated, and that needs real recurrence rules: a `frequency` column
 * and a `day_of_month` column cannot say it. The full specification is large -
 * `BYWEEKNO`, `BYYEARDAY`, `WKST` interactions, `BYSETPOS` over arbitrary
 * expansions - and most of it never appears in a billing schedule.
 *
 * So this implements the parts that do, and **refuses the rest by name**. A
 * rule nobody can express is a conversation; a rule silently approximated is
 * an invoice on the wrong day that somebody has to find.
 *
 * ## What is supported
 *
 *   FREQ        DAILY | WEEKLY | MONTHLY | YEARLY
 *   INTERVAL    a positive integer, default 1
 *   COUNT       a positive integer
 *   UNTIL       a date, inclusive, in the schedule's own timezone
 *   BYMONTH     1-12, repeatable
 *   BYMONTHDAY  1-31 and -1 (the last day), repeatable
 *   BYDAY       MO TU WE TH FR SA SU, optionally with an ordinal
 *               (1MO, 2TU, -1FR), repeatable
 *   BYSETPOS    1 or -1, over the days a period's BYDAY/BYMONTHDAY produce
 *
 * `COUNT` and `UNTIL` are mutually exclusive, as the specification requires.
 *
 * ## What is deliberately not supported
 *
 * `BYWEEKNO`, `BYYEARDAY`, `BYHOUR`, `BYMINUTE`, `BYSECOND`, `WKST` and
 * `BYSETPOS` values other than ±1. Each is refused with its own name in the
 * message, so somebody reading the refusal knows whether to rewrite the rule
 * or to ask for the part to be built.
 *
 * Times are not supported at all: a billing occurrence is a date, and the
 * time it is generated at belongs to the scheduler and the tenant's timezone,
 * not to the rule.
 *
 * ## One deliberate divergence from the specification: BYMONTHDAY clamps
 *
 * RFC 5545 skips impossible dates, so `BYMONTHDAY=31` produces nothing in
 * February and a monthly schedule silently misses four months a year. For a
 * calendar event that is right - there is no 31st of February to meet on.
 * For billing it is wrong: a subscription billed "on the 31st" is billed
 * every month, and the customer who signed up on the 31st of January does
 * not get February free.
 *
 * So the day is clamped to the month's length, the same way
 * `DueDateCalculator` clamps a fixed-day payment term - and a check asserts
 * it, because a divergence from a standard that nobody wrote down is a bug
 * somebody will fix.
 */
final readonly class RecurrenceRule
{
    private const WEEKDAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    private const SUPPORTED = [
        'FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYMONTH', 'BYMONTHDAY', 'BYDAY', 'BYSETPOS',
    ];

    /**
     * @param  list<int>  $byMonth
     * @param  list<int>  $byMonthDay
     * @param  list<array{ordinal: int|null, weekday: int}>  $byDay
     */
    private function __construct(
        public string $frequency,
        public int $interval,
        public ?int $count,
        public ?CarbonImmutable $until,
        public array $byMonth,
        public array $byMonthDay,
        public array $byDay,
        public ?int $bySetPos,
        public string $text,
    ) {}

    /** @throws RecurrenceRefused */
    public static function parse(string $rrule): self
    {
        $text = trim($rrule);

        if ($text === '') {
            throw RecurrenceRefused::empty();
        }

        // `RRULE:` is the property name in an iCalendar file; a stored rule
        // may or may not carry it, and both are accepted.
        $body = preg_replace('/^RRULE:/i', '', $text) ?? $text;

        $parts = [];

        foreach (explode(';', $body) as $pair) {
            if (trim($pair) === '') {
                continue;
            }

            if (! str_contains($pair, '=')) {
                throw RecurrenceRefused::malformed($pair);
            }

            [$name, $value] = explode('=', $pair, 2);
            $name = strtoupper(trim($name));

            if (! \in_array($name, self::SUPPORTED, true)) {
                throw RecurrenceRefused::unsupportedPart($name, self::SUPPORTED);
            }

            $parts[$name] = trim($value);
        }

        $frequency = strtoupper((string) ($parts['FREQ'] ?? ''));

        if (! \in_array($frequency, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            throw RecurrenceRefused::unsupportedFrequency($frequency);
        }

        if (isset($parts['COUNT']) && isset($parts['UNTIL'])) {
            throw RecurrenceRefused::countAndUntil();
        }

        $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;

        if ($interval < 1) {
            throw RecurrenceRefused::aNonPositiveInterval((string) $parts['INTERVAL']);
        }

        $count = isset($parts['COUNT']) ? (int) $parts['COUNT'] : null;

        if ($count !== null && $count < 1) {
            throw RecurrenceRefused::aNonPositiveCount((string) $parts['COUNT']);
        }

        $until = null;

        if (isset($parts['UNTIL'])) {
            $until = self::date((string) $parts['UNTIL']);
        }

        $byMonth = [];

        foreach (self::list($parts['BYMONTH'] ?? '') as $month) {
            $value = (int) $month;

            if ($value < 1 || $value > 12) {
                throw RecurrenceRefused::aMonthOutOfRange($month);
            }

            $byMonth[] = $value;
        }

        $byMonthDay = [];

        foreach (self::list($parts['BYMONTHDAY'] ?? '') as $day) {
            $value = (int) $day;

            /*
             | -1 only, not -2 or -3.
             |
             | "The last day of the month" is a billing term people use.
             | "The second-to-last day" is not, and supporting it means
             | supporting every negative value and testing the February
             | cases for each.
             */
            if ($value === 0 || $value > 31 || $value < -1) {
                throw RecurrenceRefused::aMonthDayOutOfRange($day);
            }

            $byMonthDay[] = $value;
        }

        $byDay = [];

        foreach (self::list($parts['BYDAY'] ?? '') as $token) {
            $byDay[] = self::weekday($token);
        }

        $bySetPos = null;

        if (isset($parts['BYSETPOS'])) {
            $bySetPos = (int) $parts['BYSETPOS'];

            if (! \in_array($bySetPos, [1, -1], true)) {
                throw RecurrenceRefused::anUnsupportedSetPos((string) $parts['BYSETPOS']);
            }

            if ($byDay === [] && $byMonthDay === []) {
                throw RecurrenceRefused::setPosWithNothingToSelect();
            }
        }

        if ($frequency === 'DAILY' && ($byDay !== [] || $byMonthDay !== [])) {
            /*
             | Refused rather than silently filtered.
             |
             | `FREQ=DAILY;BYDAY=MO` is legal in the specification and means
             | "every day, keep the Mondays" - which is `FREQ=WEEKLY`
             | written confusingly. Somebody writing it means the weekly
             | rule, and producing a different schedule from the one they
             | meant is the failure this class exists to avoid.
             */
            throw RecurrenceRefused::dailyWithASelector();
        }

        return new self(
            $frequency,
            $interval,
            $count,
            $until,
            $byMonth,
            $byMonthDay,
            $byDay,
            $bySetPos,
            $text,
        );
    }

    /**
     * The occurrences from a start date, inclusive, at most `$limit` of them.
     *
     * The start date is the schedule's anchor: `FREQ=MONTHLY` from the 15th
     * means the 15th of each month unless a selector says otherwise, which
     * is what the specification's `DTSTART` does.
     *
     * @return list<CarbonImmutable>
     */
    public function occurrences(CarbonImmutable $start, int $limit = 12, ?CarbonImmutable $after = null): array
    {
        $found = [];
        $emitted = 0;
        $period = $start->startOfDay();
        $guard = 0;

        /*
         | Bounded at 4,000 iterations of the frequency.
         |
         | A rule whose selectors never match - `FREQ=YEARLY;BYMONTH=2;
         | BYMONTHDAY=30` - would otherwise loop forever. Four thousand is
         | eleven years of daily, seventy-six of weekly and three hundred of
         | monthly, which is past the point where a billing schedule means
         | anything.
         */
        while (\count($found) < $limit && $guard++ < 4000) {
            foreach ($this->datesIn($period, $start) as $date) {
                if ($this->until !== null && $date->gt($this->until)) {
                    return $found;
                }

                if ($date->lt($start)) {
                    continue;
                }

                $emitted++;

                if ($this->count !== null && $emitted > $this->count) {
                    return $found;
                }

                if ($after !== null && ! $date->gt($after->startOfDay())) {
                    continue;
                }

                $found[] = $date;

                if (\count($found) >= $limit) {
                    return $found;
                }
            }

            $period = $this->advance($period);
        }

        return $found;
    }

    /** The next occurrence strictly after a date, or null if the rule has ended. */
    public function next(CarbonImmutable $start, CarbonImmutable $after): ?CarbonImmutable
    {
        return $this->occurrences($start, 1, $after)[0] ?? null;
    }

    /** `the last working day of every quarter`, in words, for a preview. */
    public function describe(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        $every = match ($this->frequency) {
            'DAILY' => $this->interval === 1
                ? ($arabic ? 'كل يوم' : 'every day')
                : ($arabic ? "كل {$this->interval} أيام" : "every {$this->interval} days"),
            'WEEKLY' => $this->interval === 1
                ? ($arabic ? 'كل أسبوع' : 'every week')
                : ($arabic ? "كل {$this->interval} أسابيع" : "every {$this->interval} weeks"),
            'MONTHLY' => $this->interval === 1
                ? ($arabic ? 'كل شهر' : 'every month')
                : ($arabic ? "كل {$this->interval} أشهر" : "every {$this->interval} months"),
            default => $this->interval === 1
                ? ($arabic ? 'كل سنة' : 'every year')
                : ($arabic ? "كل {$this->interval} سنوات" : "every {$this->interval} years"),
        };

        $selectors = [];

        if ($this->byMonth !== []) {
            $months = array_map(
                static fn (int $month): string => CarbonImmutable::create(2000, $month, 1)?->format('F') ?? (string) $month,
                $this->byMonth,
            );

            $selectors[] = ($arabic ? 'في ' : 'in ').implode(', ', $months);
        }

        if ($this->byMonthDay !== []) {
            $selectors[] = \in_array(-1, $this->byMonthDay, true)
                ? ($arabic ? 'في آخر يوم من الشهر' : 'on the last day of the month')
                : ($arabic ? 'في اليوم '.implode('، ', $this->byMonthDay) : 'on day '.implode(', ', $this->byMonthDay));
        }

        if ($this->byDay !== []) {
            $names = array_map(
                static fn (array $day): string => array_search($day['weekday'], self::WEEKDAYS, true) ?: '?',
                $this->byDay,
            );

            $selectors[] = ($arabic ? 'يوم ' : 'on ').implode(', ', $names);
        }

        if ($this->bySetPos === -1) {
            $selectors[] = $arabic ? '(الأخير منها)' : '(the last of them)';
        }

        if ($this->bySetPos === 1) {
            $selectors[] = $arabic ? '(الأول منها)' : '(the first of them)';
        }

        $ends = match (true) {
            $this->count !== null => $arabic
                ? "، {$this->count} مرات"
                : ", {$this->count} times",
            $this->until !== null => $arabic
                ? '، حتى '.$this->until->toDateString()
                : ', until '.$this->until->toDateString(),
            default => '',
        };

        return trim($every.' '.implode(' ', $selectors)).$ends;
    }

    /**
     * The dates this rule produces inside one period.
     *
     * @return list<CarbonImmutable>
     */
    private function datesIn(CarbonImmutable $period, CarbonImmutable $anchor): array
    {
        if ($this->frequency === 'DAILY') {
            return $this->inSeason($period) ? [$period] : [];
        }

        if ($this->frequency === 'WEEKLY') {
            $week = $period->startOfWeek(CarbonImmutable::MONDAY);

            if ($this->byDay === []) {
                return $this->inSeason($period) ? [$period] : [];
            }

            $dates = [];

            foreach ($this->byDay as $day) {
                $date = $week->addDays($day['weekday'] - 1);

                if ($this->inSeason($date)) {
                    $dates[] = $date;
                }
            }

            return $this->select($this->sorted($dates));
        }

        // MONTHLY and YEARLY both expand within a month; YEARLY's BYMONTH
        // filter is what makes it annual.
        $month = $period->startOfMonth();

        if (! $this->inSeason($month)) {
            return [];
        }

        $dates = [];

        foreach ($this->byMonthDay as $day) {
            $dates[] = $day === -1
                ? $month->endOfMonth()->startOfDay()
                : $month->addDays(min($day, $month->daysInMonth) - 1);
        }

        foreach ($this->byDay as $day) {
            $dates = [...$dates, ...$this->weekdaysIn($month, $day)];
        }

        if ($dates === []) {
            // No selector: the anchor's day of the month, clamped - a
            // schedule anchored on the 31st bills on the 28th in February
            // rather than skipping it.
            $dates[] = $month->addDays(min($anchor->day, $month->daysInMonth) - 1);
        }

        return $this->select($this->sorted($dates));
    }

    /**
     * The dates a `BYDAY` token produces in a month.
     *
     * @param  array{ordinal: int|null, weekday: int}  $day
     * @return list<CarbonImmutable>
     */
    private function weekdaysIn(CarbonImmutable $month, array $day): array
    {
        $dates = [];
        $cursor = $month->startOfMonth();

        while ($cursor->month === $month->month) {
            if ($cursor->isoWeekday() === $day['weekday']) {
                $dates[] = $cursor;
            }

            $cursor = $cursor->addDay();
        }

        if ($day['ordinal'] === null) {
            return $dates;
        }

        if ($day['ordinal'] > 0) {
            return isset($dates[$day['ordinal'] - 1]) ? [$dates[$day['ordinal'] - 1]] : [];
        }

        $fromEnd = \count($dates) + $day['ordinal'];

        return isset($dates[$fromEnd]) ? [$dates[$fromEnd]] : [];
    }

    /**
     * `BYSETPOS`, applied to a period's expansion.
     *
     * @param  list<CarbonImmutable>  $dates
     * @return list<CarbonImmutable>
     */
    private function select(array $dates): array
    {
        if ($this->bySetPos === null || $dates === []) {
            return $dates;
        }

        return $this->bySetPos === 1
            ? [$dates[0]]
            : [$dates[\count($dates) - 1]];
    }

    /** `BYMONTH`, which is also what makes a YEARLY rule annual. */
    private function inSeason(CarbonImmutable $date): bool
    {
        return $this->byMonth === [] || \in_array($date->month, $this->byMonth, true);
    }

    private function advance(CarbonImmutable $period): CarbonImmutable
    {
        return match ($this->frequency) {
            'DAILY' => $period->addDays($this->interval),
            'WEEKLY' => $period->addWeeks($this->interval),
            'MONTHLY' => $period->startOfMonth()->addMonthsNoOverflow($this->interval),

            // YEARLY walks month by month so BYMONTH can select inside the
            // year; the interval applies to the year, not to the month.
            default => $this->byMonth === []
                ? $period->addYearsNoOverflow($this->interval)
                : $period->startOfMonth()->addMonthNoOverflow(),
        };
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     * @return list<CarbonImmutable>
     */
    private function sorted(array $dates): array
    {
        $unique = [];

        foreach ($dates as $date) {
            $unique[$date->toDateString()] = $date;
        }

        ksort($unique);

        return array_values($unique);
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    /** @return array{ordinal: int|null, weekday: int} */
    private static function weekday(string $token): array
    {
        if (preg_match('/^(-?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/i', trim($token), $matches) !== 1) {
            throw RecurrenceRefused::anUnknownWeekday($token);
        }

        $ordinal = ($matches[1] ?? '') === '' ? null : (int) $matches[1];

        if ($ordinal !== null && ($ordinal === 0 || $ordinal > 5 || $ordinal < -5)) {
            throw RecurrenceRefused::anOrdinalOutOfRange($token);
        }

        return [
            'ordinal' => $ordinal,
            'weekday' => self::WEEKDAYS[strtoupper($matches[2])],
        ];
    }

    private static function date(string $value): CarbonImmutable
    {
        $clean = strtoupper(trim($value));

        // `20261231`, `20261231T000000Z` and `2026-12-31` all appear in the
        // wild; the time is discarded because an occurrence is a date.
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})(T.*)?$/', $clean, $m) !== 1) {
            throw RecurrenceRefused::anUnreadableUntil($value);
        }

        try {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0)
                ?? throw RecurrenceRefused::anUnreadableUntil($value);
        } catch (\Throwable) {
            throw RecurrenceRefused::anUnreadableUntil($value);
        }
    }
}
