<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Catalog\Services\PriceResolver;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Enums\Proration;
use App\Tenant\Sales\Exceptions\ScheduleRefused;
use App\Tenant\Sales\Models\RecurringSchedule;
use App\Tenant\Sales\Models\RecurringScheduleLine;
use App\Tenant\Sales\Recurrence\RecurrenceRule;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Services\TaxCalculator;
use App\Tenant\Tax\Services\TaxRounding;
use App\Tenant\Tax\TaxableLine;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recurring schedules: drafting, previewing, activating - PF-105.
 *
 * ## Nothing is activated without a preview
 *
 * `preview()` returns the next twelve occurrences with their dates and their
 * amounts, computed the same way the generator will compute them - the same
 * proration, the same price resolution, the same tax. A recurring schedule is
 * the one object in a billing system that acts on its own for years, and
 * "activate and see what happens next month" is how a business finds out it
 * mis-set a rule by being told by a customer.
 *
 * The preview is a method rather than a screen for the same reason the quote
 * comparison is: a preview computed differently from the generator is worse
 * than none, because it is believed.
 */
final class RecurringSchedules
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly TaxCalculator $calculator,
        private readonly TaxRounding $rounding,
        private readonly PriceResolver $prices,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * A schedule, in draft, with its rule validated now.
     *
     * @param  list<array{description: string, quantity: string, unit_price?: string|null, item_id?: string|null, item_code?: string|null, unit_code?: string, tax_code_id?: string|null, tax_rate?: string, tax_category?: string, revenue_account_id?: string|null, service_from?: string|null, service_until?: string|null}>  $lines
     *
     * @throws ScheduleRefused
     */
    public function draft(
        Entity $entity,
        Party $customer,
        string $rrule,
        CarbonImmutable $startsOn,
        array $lines,
        ?CarbonImmutable $endsOn = null,
        ?int $occurrenceLimit = null,
        Proration $proration = Proration::None,
        ?string $timezone = null,
        ?string $priceListId = null,
        ?string $profile = null,
        int $leadDays = 0,
    ): RecurringSchedule {
        if (! $customer->is_customer) {
            throw ScheduleRefused::notACustomer((string) $customer->code);
        }

        if ($lines === []) {
            throw ScheduleRefused::ofNothing();
        }

        if ($endsOn !== null && $occurrenceLimit !== null) {
            throw ScheduleRefused::twoEndConditions();
        }

        if ($endsOn !== null && $endsOn->lt($startsOn)) {
            throw ScheduleRefused::endsBeforeItStarts($endsOn->toDateString(), $startsOn->toDateString());
        }

        // Parsed now, so the refusal lands on the person writing the rule
        // rather than on a cron job at one in the morning.
        $rule = RecurrenceRule::parse($rrule);

        $timezone ??= (string) ($entity->timezone ?? config('app.timezone', 'UTC'));

        if (! \in_array($timezone, timezone_identifiers_list(), true)) {
            throw ScheduleRefused::anUnknownTimezone($timezone);
        }

        $currency = (string) ($customer->default_currency ?? $entity->functional_currency);
        $profile ??= (string) config('jurisdictions.default_profile', 'generic');

        return DB::connection('tenant')->transaction(function () use (
            $entity, $customer, $rule, $startsOn, $lines, $endsOn, $occurrenceLimit,
            $proration, $timezone, $priceListId, $profile, $leadDays, $currency
        ): RecurringSchedule {
            $schedule = RecurringSchedule::create([
                'entity_id' => (string) $entity->getKey(),
                'reference' => $this->numbers->next(
                    (string) $entity->getKey(),
                    'recurring_schedule',
                    'RS',
                    Carbon::parse($startsOn->toDateString()),
                ),
                'party_id' => (string) $customer->getKey(),
                'status' => 'draft',
                'rrule' => $rule->text,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'occurrence_limit' => $occurrenceLimit,
                'timezone' => $timezone,
                'currency' => $currency,
                'proration' => $proration->value,
                'price_list_id' => $priceListId,
                'profile' => $profile,
                'lead_days' => $leadDays,
            ]);

            foreach ($lines as $index => $line) {
                RecurringScheduleLine::create([
                    'entity_id' => (string) $entity->getKey(),
                    'recurring_schedule_id' => (string) $schedule->getKey(),
                    'line_no' => $index + 1,
                    'item_id' => $line['item_id'] ?? null,
                    'item_code' => $line['item_code'] ?? null,
                    'description' => (string) $line['description'],
                    'unit_code' => (string) ($line['unit_code'] ?? 'C62'),
                    'quantity' => (string) $line['quantity'],
                    'unit_price' => $line['unit_price'] ?? null,
                    'revenue_account_id' => $line['revenue_account_id'] ?? null,
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'tax_category' => (string) ($line['tax_category'] ?? 'S'),
                    'tax_rate' => (string) ($line['tax_rate'] ?? '0'),
                    'service_from' => $line['service_from'] ?? null,
                    'service_until' => $line['service_until'] ?? null,
                ]);
            }

            return $schedule->refresh();
        });
    }

    /**
     * The next occurrences, with what each would bill - PF-105.
     *
     * @return array{
     *     rule: string,
     *     describes: string,
     *     timezone: string,
     *     occurrences: list<array{date: string, net: string, tax: string, gross: string, lines: list<array{description: string, quantity: string, unit_price: string, net: string, basis: string, price_from: string|null}>}>,
     *     ends: string,
     * }
     */
    public function preview(RecurringSchedule $schedule, int $limit = 12): array
    {
        $dates = $schedule->upcoming($limit);
        $occurrences = [];

        foreach ($dates as $date) {
            $occurrences[] = $this->quote($schedule, $date);
        }

        $rule = $schedule->rule();

        return [
            'rule' => (string) $schedule->rrule,
            'describes' => $rule->describe('en'),
            'timezone' => (string) $schedule->timezone,
            'occurrences' => $occurrences,
            'ends' => match (true) {
                $schedule->ends_on !== null => 'on '.$schedule->ends_on->toDateString(),
                $schedule->occurrence_limit !== null => sprintf('after %d occurrences', (int) $schedule->occurrence_limit),
                $rule->count !== null => sprintf('after %d occurrences (from the rule)', $rule->count),
                $rule->until !== null => 'on '.$rule->until->toDateString().' (from the rule)',
                default => 'never - it is open-ended',
            },
        ];
    }

    /**
     * What one occurrence would bill, priced as the generator will price it.
     *
     * @return array{date: string, net: string, tax: string, gross: string, lines: list<array<string, mixed>>}
     */
    public function quote(RecurringSchedule $schedule, CarbonImmutable $date): array
    {
        $currency = (string) $schedule->currency;
        $period = $this->periodOf($schedule, $date);
        $lines = [];
        $taxable = [];

        foreach ($schedule->lines()->get() as $line) {
            $resolved = $this->priceFor($schedule, $line, $date);
            $full = Money::ofRounded(
                (string) BigDecimal::of((string) $line->quantity)
                    ->multipliedBy($resolved['unit_price'])
                    ->toScale(8, RoundingMode::HalfUp),
                $currency,
                \App\Support\Money\RoundingPolicy::halfUp(\App\Support\Money\Scale::Amount),
            );

            $prorated = $schedule->proration->applyTo(
                $full,
                $period['start'],
                $period['end'],
                CarbonImmutable::parse((string) $schedule->starts_on?->toDateString()),
                $schedule->ends_on === null
                    ? null
                    : CarbonImmutable::parse((string) $schedule->ends_on->toDateString()),
            );

            if ($prorated['amount']->isZero()) {
                continue;
            }

            $lines[] = [
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'unit_code' => (string) $line->unit_code,
                'unit_price' => (string) $resolved['unit_price'],
                'net' => (string) $prorated['amount']->amount,

                // The basis, on the line, because "prorated 12/31 days from
                // 2026-03-20" is the difference between a question and a
                // dispute.
                'basis' => (string) $prorated['basis'],
                'price_from' => $resolved['derivation'],
                'tax_code_id' => $line->tax_code_id,
                'tax_category' => (string) $line->tax_category,
                'tax_rate' => (string) $line->tax_rate,
                'revenue_account_id' => $line->revenue_account_id,
                'item_id' => $line->item_id,
                'item_code' => $line->item_code,
                'service_from' => $period['start']->toDateString(),
                'service_until' => $period['end']->toDateString(),
            ];

            $taxable[] = TaxableLine::of(
                $prorated['amount'],
                TaxCategory::from((string) $line->tax_category),
                (string) $line->tax_rate,
            );
        }

        if ($taxable === []) {
            return [
                'date' => $date->toDateString(),
                'net' => '0.0000',
                'tax' => '0.0000',
                'gross' => '0.0000',
                'lines' => [],
            ];
        }

        $breakdown = $this->calculator->compute(
            $taxable,
            $this->rounding->policyFor((string) ($schedule->profile ?? 'generic'), $currency),
        );

        return [
            'date' => $date->toDateString(),
            'net' => (string) $breakdown->net->amount,
            'tax' => (string) $breakdown->tax->amount,
            'gross' => (string) $breakdown->gross()->amount,
            'lines' => $lines,
            'breakdown' => $breakdown,
        ];
    }

    /** @throws ScheduleRefused */
    public function activate(RecurringSchedule $schedule): RecurringSchedule
    {
        if ((string) $schedule->status === 'ended') {
            throw ScheduleRefused::alreadyEnded((string) $schedule->reference);
        }

        if ($schedule->lines()->count() === 0) {
            throw ScheduleRefused::ofNothing();
        }

        // Parsed again: a rule edited while the schedule was in draft has to
        // be refused here rather than at one in the morning.
        $schedule->rule();

        return DB::connection('tenant')->transaction(function () use ($schedule): RecurringSchedule {
            $schedule->update(['status' => 'active']);

            $this->audit->record(
                category: 'sales',
                action: 'recurring_schedule.activated',
                subjectType: 'recurring_schedule',
                subjectId: (string) $schedule->getKey(),
                subjectLabel: (string) $schedule->reference,
                after: [
                    'rule' => (string) $schedule->rrule,
                    'timezone' => (string) $schedule->timezone,
                    'next' => $schedule->upcoming(1)[0]?->toDateString(),
                ],
                entityId: (string) $schedule->entity_id,
                partyId: (string) $schedule->party_id,
            );

            return $schedule->refresh();
        });
    }

    public function pause(RecurringSchedule $schedule, string $reason): RecurringSchedule
    {
        return DB::connection('tenant')->transaction(function () use ($schedule, $reason): RecurringSchedule {
            $schedule->update(['status' => 'paused', 'ended_reason' => $reason]);

            $this->audit->record(
                category: 'sales',
                action: 'recurring_schedule.paused',
                subjectType: 'recurring_schedule',
                subjectId: (string) $schedule->getKey(),
                subjectLabel: (string) $schedule->reference,
                reason: $reason,
                entityId: (string) $schedule->entity_id,
                partyId: (string) $schedule->party_id,
            );

            return $schedule->refresh();
        });
    }

    public function end(RecurringSchedule $schedule, string $reason): RecurringSchedule
    {
        return DB::connection('tenant')->transaction(function () use ($schedule, $reason): RecurringSchedule {
            $schedule->update([
                'status' => 'ended',
                'ended_at' => now(),
                'ended_reason' => $reason,
            ]);

            return $schedule->refresh();
        });
    }

    /**
     * The service period an occurrence covers.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function periodOf(RecurringSchedule $schedule, CarbonImmutable $date): array
    {
        $rule = $schedule->rule();
        $next = $rule->next(
            CarbonImmutable::parse((string) $schedule->starts_on?->toDateString()),
            $date,
        );

        /*
         | The period runs from this occurrence to the day before the next.
         |
         | Which makes a monthly schedule's period a month and a quarterly
         | one's a quarter, without the schedule having to say so twice - and
         | the last occurrence of a rule with no successor covers the same
         | span as the one before it rather than a day.
         */
        if ($next !== null) {
            return ['start' => $date, 'end' => $next->subDay()];
        }

        $previous = $rule->occurrences(
            CarbonImmutable::parse((string) $schedule->starts_on?->toDateString()),
            2,
        );

        $span = isset($previous[1])
            ? $previous[0]->diffInDays($previous[1])
            : 30;

        return ['start' => $date, 'end' => $date->addDays(max(1, $span) - 1)];
    }

    /**
     * A line's price, from the template or from the price list.
     *
     * @return array{unit_price: string, derivation: string|null}
     */
    private function priceFor(
        RecurringSchedule $schedule,
        RecurringScheduleLine $line,
        CarbonImmutable $date,
    ): array {
        if (! $line->resolvesItsPrice()) {
            return [
                'unit_price' => (string) $line->unit_price,
                'derivation' => 'fixed on the schedule',
            ];
        }

        if ($line->item_id === null) {
            throw ScheduleRefused::aLineWithNoPriceAndNoItem((int) $line->line_no);
        }

        /*
         | Resolved at the OCCURRENCE date - PF-105.
         |
         | Which is what makes a price change on the item reach the schedule:
         | a contract tied to a price list bills at the price in force on the
         | day, and resolving at the schedule's start date would bill 2024's
         | price in 2027. A fixed price on the line is how a contract with a
         | fixed price is expressed, and the two are distinguishable.
         */
        try {
            $resolved = $this->prices->resolve(
                (string) $line->item_id,
                (string) $schedule->currency,
                Carbon::parse($date->toDateString()),
                (string) $schedule->party_id,
                null,
                (string) $line->quantity,
            );

            return [
                'unit_price' => (string) $resolved['unit_price'],
                'derivation' => (string) $resolved['derivation'],
            ];
        } catch (Throwable $e) {
            throw ScheduleRefused::noPriceOnThatDate(
                (int) $line->line_no,
                $date->toDateString(),
                $e->getMessage(),
            );
        }
    }
}
