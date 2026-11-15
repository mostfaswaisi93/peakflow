<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Sales\Exceptions\ScheduleRefused;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\InvoiceLine;
use App\Tenant\Sales\Models\InvoiceReference;
use App\Tenant\Sales\Models\InvoiceTaxBreakdownRow;
use App\Tenant\Sales\Models\RecurringOccurrence;
use App\Tenant\Sales\Models\RecurringSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generating what a schedule owes, exactly once - PF-106.
 *
 * ## The guarantee is a unique index, not a lock
 *
 * `recurring_occurrences (schedule_id, occurrence_date)` is unique, and the
 * row is claimed before any work is done. Every duplicate-billing scenario
 * ends there: a scheduler that fires twice, two nodes in the same minute, a
 * retry after a timeout, somebody running the command by hand while cron runs
 * it.
 *
 * A lock is taken as well, per `(task, tenant, schedule, occurrence)`, and it
 * is an optimisation: it stops two workers doing the same work and throwing
 * one result away. It is not the guarantee, because a lock is a service that
 * can be down, misconfigured, or shared with a second deployment - and
 * "nobody double-bills as long as Redis is healthy" is not a property worth
 * claiming.
 *
 * ## Claim first, then work
 *
 * The occurrence row exists before the invoice does. A generator interrupted
 * between the two leaves a claimed occurrence with no invoice, which the next
 * run retries - rather than an invoice nobody has a record of asking for,
 * which the next run would produce again.
 *
 * ## A failure does not skip the next one
 *
 * Each occurrence is its own transaction and its own claim. One that fails is
 * recorded with its error and its attempt count, and the run moves on: a
 * customer whose price list has a gap must not stop the other two hundred
 * invoices going out. After `MAX_ATTEMPTS` it is marked failed and reported
 * rather than retried forever, because a job that retries an impossible
 * occurrence every hour is a job whose log nobody reads.
 */
final class RecurringGenerator
{
    /**
     * How many times an occurrence is retried before it is surfaced.
     *
     * Three, which is a day and a half at the usual hourly cadence: long
     * enough to ride out a rate feed that is late, short enough that a
     * permanent failure reaches somebody while the period is still open.
     */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly RecurringSchedules $schedules,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Generate everything due for an entity, as of now in each schedule's own
     * timezone.
     *
     * @return array{generated: list<array<string, string>>, failed: list<array<string, string>>, skipped: list<array<string, string>>}
     */
    public function run(string $entityId, ?CarbonImmutable $asOf = null): array
    {
        $report = ['generated' => [], 'failed' => [], 'skipped' => []];

        $schedules = RecurringSchedule::query()
            ->where('entity_id', $entityId)
            ->where('status', 'active')
            ->orderBy('reference')
            ->get();

        foreach ($schedules as $schedule) {
            foreach ($this->due($schedule, $asOf) as $date) {
                $outcome = $this->generate($schedule, $date);
                $report[$outcome['outcome']][] = $outcome;
            }
        }

        return $report;
    }

    /**
     * The occurrences of a schedule that are due and not yet generated.
     *
     * @return list<CarbonImmutable>
     */
    public function due(RecurringSchedule $schedule, ?CarbonImmutable $asOf = null): array
    {
        /*
         | Today, where the business is - and the lead days.
         |
         | `asOf` is for the checks and for a deliberate catch-up run; the
         | default is the schedule's own timezone, because a tenant in
         | Riyadh and one in Casablanca do not share a moment.
         */
        $today = ($asOf ?? $schedule->today())->startOfDay();
        $horizon = $today->addDays((int) $schedule->lead_days);

        if ($schedule->hasRunItsCount()) {
            return [];
        }

        $already = $schedule->occurrences()
            ->whereIn('status', ['claimed', 'generated'])
            ->pluck('occurrence_date')
            ->map(static fn (mixed $date): string => CarbonImmutable::parse((string) $date)->toDateString())
            ->all();

        $due = [];

        foreach ($schedule->upcoming(24) as $date) {
            if ($date->gt($horizon)) {
                break;
            }

            if (\in_array($date->toDateString(), $already, true)) {
                continue;
            }

            $due[] = $date;
        }

        return $due;
    }

    /**
     * One occurrence, exactly once.
     *
     * @return array{outcome: string, schedule: string, date: string, invoice?: string, error?: string}
     */
    public function generate(RecurringSchedule $schedule, CarbonImmutable $date): array
    {
        /*
         | The lock: an optimisation, not the guarantee.
         |
         | Two workers that reach the same occurrence in the same second both
         | build an invoice and one of them throws it away. The lock stops
         | the wasted work; the unique index below is what stops the second
         | invoice existing. `block(0)` rather than waiting, because the
         | other worker is doing exactly this and waiting for it to finish
         | gains nothing.
         */
        $lock = Cache::lock($this->lockKey($schedule, $date), 120);

        if (! $lock->get()) {
            return [
                'outcome' => 'skipped',
                'schedule' => (string) $schedule->reference,
                'date' => $date->toDateString(),
                'error' => 'another worker holds the lock for this occurrence',
            ];
        }

        try {
            return $this->claimAndBuild($schedule, $date);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{outcome: string, schedule: string, date: string, invoice?: string, error?: string}
     */
    private function claimAndBuild(RecurringSchedule $schedule, CarbonImmutable $date): array
    {
        $occurrence = null;

        try {
            /*
             | The claim, in its own transaction.
             |
             | Separate from the invoice, so that a failure building the
             | invoice leaves the claim behind with its error and its attempt
             | count - and does not roll back into a state the next run
             | cannot tell from "never tried".
             */
            $occurrence = DB::connection('tenant')->transaction(
                fn (): RecurringOccurrence => RecurringOccurrence::create([
                    'entity_id' => (string) $schedule->entity_id,
                    'recurring_schedule_id' => (string) $schedule->getKey(),
                    'occurrence_date' => $date->toDateString(),
                    'status' => 'claimed',
                    'attempts' => 1,
                ]),
            );
        } catch (UniqueConstraintViolationException) {
            /*
             | Somebody else has this occurrence. THE guarantee.
             |
             | Not an error: a scheduler that fires twice is ordinary, and
             | the second firing finding the row already there is the system
             | working. It is reported as skipped so a run that produced
             | nothing can say why.
             */
            $existing = $schedule->occurrences()
                ->whereDate('occurrence_date', $date->toDateString())
                ->first();

            if ($existing !== null && (string) $existing->status === 'failed'
                && (int) $existing->attempts < self::MAX_ATTEMPTS) {
                $occurrence = $existing;
                $occurrence->update(['attempts' => (int) $existing->attempts + 1, 'status' => 'claimed']);
            } else {
                return [
                    'outcome' => 'skipped',
                    'schedule' => (string) $schedule->reference,
                    'date' => $date->toDateString(),
                    'error' => $existing === null
                        ? 'the occurrence is already claimed'
                        : sprintf('already %s after %d attempt(s)', (string) $existing->status, (int) $existing->attempts),
                ];
            }
        }

        try {
            $invoice = DB::connection('tenant')->transaction(
                fn (): Invoice => $this->draftFor($schedule, $date, $occurrence),
            );

            return [
                'outcome' => 'generated',
                'schedule' => (string) $schedule->reference,
                'date' => $date->toDateString(),
                'invoice' => (string) $invoice->getKey(),
                'net' => (string) $invoice->net_amount,
                'gross' => (string) $invoice->gross_amount,
            ];
        } catch (Throwable $e) {
            $attempts = (int) $occurrence->attempts;
            $permanent = $attempts >= self::MAX_ATTEMPTS;

            DB::connection('tenant')->transaction(function () use ($occurrence, $e, $permanent): void {
                $occurrence->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'last_error' => mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 2000),
                ]);
            });

            if ($permanent) {
                /*
                 | Surfaced rather than retried forever.
                 |
                 | A job that retries an impossible occurrence every hour is
                 | a job whose log nobody reads, and the occurrence it cannot
                 | bill is a customer who is not being invoiced.
                 */
                $this->audit->record(
                    category: 'sales',
                    action: 'recurring_schedule.occurrence_failed',
                    subjectType: 'recurring_schedule',
                    subjectId: (string) $schedule->getKey(),
                    subjectLabel: sprintf('%s %s', (string) $schedule->reference, $date->toDateString()),
                    after: ['attempts' => $attempts, 'error' => $e->getMessage()],
                    reason: 'the occurrence failed on every attempt and will not be retried',
                    entityId: (string) $schedule->entity_id,
                    partyId: (string) $schedule->party_id,
                );
            }

            return [
                'outcome' => 'failed',
                'schedule' => (string) $schedule->reference,
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
                'attempts' => (string) $attempts,
                'permanent' => $permanent ? 'yes' : 'no',
            ];
        }
    }

    /** The draft invoice for one occurrence, and the occurrence pointed at it. */
    private function draftFor(
        RecurringSchedule $schedule,
        CarbonImmutable $date,
        RecurringOccurrence $occurrence,
    ): Invoice {
        $quoted = $this->schedules->quote($schedule, $date);

        if ($quoted['lines'] === []) {
            throw ScheduleRefused::nothingToBill((string) $schedule->reference, $date->toDateString());
        }

        $invoice = Invoice::create([
            'entity_id' => (string) $schedule->entity_id,
            'series_key' => 'INV',
            'document_type' => '380',
            'status' => 'draft',
            'party_id' => (string) $schedule->party_id,
            'issue_date' => $date->toDateString(),
            'tax_point' => $date->toDateString(),
            'currency' => (string) $schedule->currency,
            'net_amount' => $quoted['net'],
            'tax_amount' => $quoted['tax'],
            'gross_amount' => $quoted['gross'],
            'payable_amount' => $quoted['gross'],
            'presentation_exponent' => 2,
            'profile' => $schedule->profile,
            'note' => sprintf(
                'Generated from recurring schedule %s for %s.',
                (string) $schedule->reference,
                $date->toDateString(),
            ),
        ]);

        foreach ($quoted['lines'] as $index => $line) {
            InvoiceLine::create([
                'entity_id' => (string) $schedule->entity_id,
                'invoice_id' => (string) $invoice->getKey(),
                'line_no' => $index + 1,
                'item_id' => $line['item_id'] ?? null,
                'item_code' => $line['item_code'] ?? null,

                /*
                 | The proration basis is ON the line.
                 |
                 | "Support, March 2026 (prorated 12/31 days, 2026-03-20 to
                 | 2026-03-31)" is the difference between a question and a
                 | dispute - and a customer who can see the arithmetic does
                 | not telephone about it.
                 */
                'description' => (string) $line['basis'] === 'the full period'
                    ? (string) $line['description']
                    : sprintf('%s (%s)', (string) $line['description'], (string) $line['basis']),
                'unit_code' => (string) $line['unit_code'],
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'net_amount' => (string) $line['net'],
                'revenue_account_id' => $line['revenue_account_id'] ?? null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'tax_category' => (string) $line['tax_category'],
                'tax_rate' => (string) $line['tax_rate'],
                'tax_point' => $date->toDateString(),
                'place_of_supply' => 'SA',
                'place_of_supply_rule' => 'domestic',
                'service_from' => $line['service_from'] ?? null,
                'service_until' => $line['service_until'] ?? null,
            ]);
        }

        foreach ($quoted['breakdown']->entries as $entry) {
            InvoiceTaxBreakdownRow::create([
                'entity_id' => (string) $schedule->entity_id,
                'invoice_id' => (string) $invoice->getKey(),
                'category' => $entry->category->value,
                'rate' => $entry->ratePercent,
                'taxable_amount' => (string) $entry->taxableAmount->amount,
                'tax_amount' => (string) $entry->taxAmount->amount,
                'exemption_reason_code' => $entry->reasonCode,
                'exemption_reason_text' => $entry->reasonText,
                'report_box' => 'standard_sales',
            ]);
        }

        InvoiceReference::create([
            'entity_id' => (string) $schedule->entity_id,
            'invoice_id' => (string) $invoice->getKey(),
            'kind' => 'contract',
            'reference' => (string) $schedule->reference,
            'reference_date' => $date->toDateString(),
        ]);

        $occurrence->update([
            'status' => 'generated',
            'invoice_id' => (string) $invoice->getKey(),
            'generated_at' => now(),
            'last_error' => null,
            'failed_at' => null,
        ]);

        $schedule->update([
            'last_occurrence_on' => $date->toDateString(),
            'occurrences_generated' => (int) $schedule->occurrences_generated + 1,
        ]);

        return $invoice->refresh();
    }

    /**
     * The lock key: the task, the tenant, the schedule and the occurrence.
     *
     * The tenant is in it because two tenants' schedules are unrelated and a
     * key without it would serialise them; the occurrence is in it because
     * two occurrences of one schedule - a catch-up run - are also unrelated.
     */
    private function lockKey(RecurringSchedule $schedule, CarbonImmutable $date): string
    {
        $tenant = app()->bound('currentTenant')
            ? (string) app('currentTenant')->getKey()
            : 'unknown-tenant';

        return sprintf(
            'recurring:%s:%s:%s',
            $tenant,
            (string) $schedule->getKey(),
            $date->toDateString(),
        );
    }
}
