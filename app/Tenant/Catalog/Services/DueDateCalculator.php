<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Services;

use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Catalog\Models\PaymentTerm;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Due dates derived from the terms, with the derivation shown - PF-066.
 *
 * ## Why the derivation is returned and not just the date
 *
 * "Due 2026-04-30" is a date somebody will dispute, and the dispute is
 * usually about the method rather than the arithmetic: the customer counted
 * 30 days from the invoice and got 2026-04-24. Returning "end of the invoice
 * month (2026-03-31) plus 30 days = 2026-04-30, moved from Friday to Sunday"
 * ends the conversation in one line.
 *
 * It also makes the difference between `net 30` and `end_of_month + 30`
 * visible, which is the difference nobody notices until an aging report
 * disagrees with the customer's own ledger by up to a month.
 *
 * ## Non-business days
 *
 * Friday and Saturday are the weekend across much of the Gulf. Hard-coding
 * Saturday and Sunday produces due dates on days the bank is shut, in exactly
 * the markets this is built for - so the weekend is a per-entity list of
 * weekday numbers, and the holidays are rows, because Eid moves against the
 * Gregorian calendar and its observed dates are announced rather than
 * computed.
 */
final class DueDateCalculator
{
    /** ISO-8601 weekday numbers, Monday = 1. */
    private const DEFAULT_WEEKEND = [5, 6];

    /**
     * One due date, or several for instalment terms.
     *
     * @return array{
     *     due_dates: list<array{
     *         sequence: int, date: string, proportion: string,
     *         amount: ?string, derivation: string,
     *     }>,
     *     discount: ?array{percent: string, until: string, amount: ?string},
     *     terms: string,
     * }
     */
    public function for(
        PaymentTerm $terms,
        Carbon $documentDate,
        string $entityId,
        ?Money $total = null,
    ): array {
        $policy = $this->policyFor($entityId);

        $schedule = $terms->kind === 'instalments'
            ? $this->instalments($terms, $documentDate, $policy, $total)
            : [$this->single($terms, $documentDate, $policy, $total)];

        return [
            'due_dates' => $schedule,
            'discount' => $this->discount($terms, $documentDate, $policy, $total),
            'terms' => (string) $terms->code,
        ];
    }

    /**
     * The one date a non-instalment term produces.
     *
     * @param  array{weekend: list<int>, adjustment: string, holidays: list<string>}  $policy
     * @return array{sequence: int, date: string, proportion: string, amount: ?string, derivation: string}
     */
    private function single(
        PaymentTerm $terms,
        Carbon $documentDate,
        array $policy,
        ?Money $total,
    ): array {
        [$date, $derivation] = $this->derive(
            kind: (string) $terms->kind,
            days: (int) $terms->days,
            dayOfMonth: $terms->day_of_month === null ? null : (int) $terms->day_of_month,
            monthsForward: (int) $terms->months_forward,
            documentDate: $documentDate,
        );

        [$adjusted, $moved] = $this->toBusinessDay($date, $policy);

        return [
            'sequence' => 1,
            'date' => $adjusted->toDateString(),
            'proportion' => '1',
            'amount' => $total === null ? null : (string) $total->amount,
            'derivation' => $derivation.$moved,
        ];
    }

    /**
     * Several dates, with the residual on the last one.
     *
     * ## Why the residual goes to the LAST instalment
     *
     * Three equal instalments of a 1,000.00 invoice are 333.33, 333.33 and
     * 333.34. Something has to carry the extra cent, and the alternatives
     * are worse: spreading it invents fractions of a cent, and dropping it
     * invoices 999.99 and leaves a cent nobody ever chases.
     *
     * The last one, because it is the instalment a customer queries least
     * and the one an allocation reaches last - so a part payment never has
     * to decide what to do with a rounding difference.
     *
     * @param  array{weekend: list<int>, adjustment: string, holidays: list<string>}  $policy
     * @return list<array{sequence: int, date: string, proportion: string, amount: ?string, derivation: string}>
     */
    private function instalments(
        PaymentTerm $terms,
        Carbon $documentDate,
        array $policy,
        ?Money $total,
    ): array {
        $parts = $terms->instalments()->orderBy('sequence')->get();

        if ($parts->isEmpty()) {
            // A term that claims instalments and has none would otherwise
            // produce an invoice with no due date at all.
            return [$this->single($terms, $documentDate, $policy, $total)];
        }

        $amounts = $total === null
            ? null
            : $total->allocate(
                $parts->map(static fn (object $part): string => (string) $part->proportion)->all(),
                RoundingPolicy::halfUp(Scale::Amount),
            );

        $schedule = [];

        foreach ($parts as $index => $part) {
            [$date, $derivation] = $this->derive(
                kind: (string) $part->kind,
                days: (int) $part->days,
                dayOfMonth: $part->day_of_month === null ? null : (int) $part->day_of_month,
                monthsForward: (int) $part->months_forward,
                documentDate: $documentDate,
            );

            [$adjusted, $moved] = $this->toBusinessDay($date, $policy);

            $schedule[] = [
                'sequence' => (int) $part->sequence,
                'date' => $adjusted->toDateString(),
                'proportion' => (string) $part->proportion,
                'amount' => $amounts === null ? null : (string) $amounts[$index]->amount,
                'derivation' => sprintf(
                    'instalment %d of %d (%s of the total): %s%s',
                    (int) $part->sequence,
                    $parts->count(),
                    (string) $part->proportion,
                    $derivation,
                    $moved,
                ),
            ];
        }

        return $schedule;
    }

    /**
     * The arithmetic, and a sentence saying what it did.
     *
     * @return array{0: Carbon, 1: string}
     */
    private function derive(
        string $kind,
        int $days,
        ?int $dayOfMonth,
        int $monthsForward,
        Carbon $documentDate,
    ): array {
        return match ($kind) {
            'immediate' => [
                $documentDate->copy(),
                sprintf('due on receipt (%s)', $documentDate->toDateString()),
            ],

            'net' => [
                $documentDate->copy()->addDays($days),
                sprintf('%s plus %d days', $documentDate->toDateString(), $days),
            ],

            /*
             | End of month THEN add days, in that order.
             |
             | The other order - add 30 days then take the month end -
             | produces a date up to a month later and is a different
             | commercial term. Both are written as "EOM 30" in practice,
             | which is why the derivation says which was used.
             */
            'end_of_month' => [
                $documentDate->copy()->endOfMonth()->startOfDay()->addDays($days),
                sprintf(
                    'end of the invoice month (%s) plus %d days',
                    $documentDate->copy()->endOfMonth()->toDateString(),
                    $days,
                ),
            ],

            /*
             | A fixed day of a later month.
             |
             | `day()` on a month that is too short silently rolls into the
             | next month - the 31st of February becomes the 3rd of March -
             | so the day is clamped to the month's own length. A term of
             | "the 31st" in February means the 28th, not the 3rd of March.
             */
            'fixed_day' => (function () use ($documentDate, $dayOfMonth, $monthsForward): array {
                $target = $documentDate->copy()->addMonthsNoOverflow($monthsForward);
                $day = min($dayOfMonth ?? 1, $target->daysInMonth);
                $date = $target->day($day)->startOfDay();

                return [
                    $date,
                    sprintf(
                        'day %d of %s%s',
                        $dayOfMonth ?? 1,
                        $target->format('F Y'),
                        $day === $dayOfMonth ? '' : sprintf(' (clamped to %d, the month\'s length)', $day),
                    ),
                ];
            })(),

            default => [
                $documentDate->copy()->addDays($days),
                sprintf('%s plus %d days', $documentDate->toDateString(), $days),
            ],
        };
    }

    /**
     * Move a date off a weekend or holiday, per the entity's policy.
     *
     * @param  array{weekend: list<int>, adjustment: string, holidays: list<string>}  $policy
     * @return array{0: Carbon, 1: string}
     */
    private function toBusinessDay(Carbon $date, array $policy): array
    {
        if ($policy['adjustment'] === 'none' || ! $this->isClosed($date, $policy)) {
            return [$date, ''];
        }

        $original = $date->copy();
        $step = $policy['adjustment'] === 'backward' ? -1 : 1;
        $moved = $date->copy();

        /*
         | Bounded at 14 days.
         |
         | The weekend CHECK already forbids every day being a weekend, so
         | this cannot loop forever on the policy alone - but a fortnight of
         | consecutive holidays would, and an unbounded loop in a due-date
         | calculation is a request that never returns.
         */
        for ($i = 0; $i < 14; $i++) {
            $moved->addDays($step);

            if (! $this->isClosed($moved, $policy)) {
                return [$moved, sprintf(
                    ', moved %s from %s (%s) to %s',
                    $policy['adjustment'],
                    $original->toDateString(),
                    $original->format('l'),
                    $moved->toDateString(),
                )];
            }
        }

        // Fourteen closed days in a row is a configuration problem, not a
        // date problem. The unadjusted date is returned, said plainly.
        return [$original, ', left on a non-business day: 14 consecutive closed days from here'];
    }

    /** @param array{weekend: list<int>, adjustment: string, holidays: list<string>} $policy */
    private function isClosed(Carbon $date, array $policy): bool
    {
        return in_array($date->isoWeekday(), $policy['weekend'], true)
            || in_array($date->toDateString(), $policy['holidays'], true);
    }

    /**
     * @param  array{weekend: list<int>, adjustment: string, holidays: list<string>}  $policy
     * @return array{percent: string, until: string, amount: ?string}|null
     */
    private function discount(
        PaymentTerm $terms,
        Carbon $documentDate,
        array $policy,
        ?Money $total,
    ): ?array {
        if ($terms->discount_percent === null || $terms->discount_days === null) {
            return null;
        }

        /*
         | The discount window goes through the business-day policy too.
         |
         | A window that ends on a day the bank is shut is a window the
         | customer cannot use. It moves in the policy's direction, which for
         | the usual `forward` means the window is slightly longer - the
         | right way to be wrong, since the alternative takes away a discount
         | that was offered.
         */
        [$until] = $this->toBusinessDay(
            $documentDate->copy()->addDays((int) $terms->discount_days),
            $policy,
        );

        return [
            'percent' => (string) $terms->discount_percent,
            'until' => $until->toDateString(),
            'amount' => $total === null ? null : (string) $total->multipliedBy(
                $this->asFactor((string) $terms->discount_percent),
                RoundingPolicy::halfUp(Scale::Amount),
            )->amount,
        ];
    }

    /**
     * A percentage as a decimal factor: `2` becomes `0.02`.
     *
     * One method, because dividing by 100 is where a percentage turns into
     * a factor and doing it in two places is how one of them ends up
     * dividing by 10. Fourteen decimals so a third-of-a-percent term does
     * not lose anything before it reaches the amount scale.
     */
    private function asFactor(string $percent): string
    {
        return (string) BigDecimal::of($percent)->dividedBy('100', 14, RoundingMode::HalfUp);
    }

    /**
     * @return array{weekend: list<int>, adjustment: string, holidays: list<string>}
     */
    private function policyFor(string $entityId): array
    {
        $row = DB::connection('tenant')->table('business_day_policies')
            ->where('entity_id', $entityId)
            ->first();

        /** @var list<int> $weekend */
        $weekend = $row === null
            ? self::DEFAULT_WEEKEND
            : array_map('intval', (array) json_decode((string) $row->weekend_days, true));

        return [
            'weekend' => $weekend,
            'adjustment' => $row === null ? 'forward' : (string) $row->adjustment,
            'holidays' => DB::connection('tenant')->table('holidays')
                ->where('entity_id', $entityId)
                ->pluck('date')
                ->map(static fn (mixed $d): string => Carbon::parse((string) $d)->toDateString())
                ->all(),
        ];
    }
}
