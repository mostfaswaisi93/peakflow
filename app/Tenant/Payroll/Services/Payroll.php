<?php

declare(strict_types=1);

namespace App\Tenant\Payroll\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Payroll\Exceptions\PayrollRefused;
use App\Tenant\Payroll\Posting\Rules\PayrollPostedRule;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Running the payroll, through the same controls as everything else - PF-256.
 *
 * ## The run posts; the payment run pays
 *
 * {@see post()} writes one journal entry for the period - gross and the
 * employer's contributions as costs, the net and the deductions as
 * liabilities - and creates one approved `bill` per employee for their
 * NET pay.
 *
 * PF-174's payment run then selects those bills like any other payable:
 * maker-checker, beneficiary verification, duplicate detection,
 * segregation of duties, all of it. Nothing in `PaymentRuns` knows
 * payroll exists, which is exactly the point - a separate path for
 * payroll is a separate path ROUND the controls, and payroll is the
 * largest regular outflow most businesses have.
 *
 * ## Changing a salary changes the NEXT payment
 *
 * A schedule holds the components; a run snapshots them onto its lines.
 * So "what did we pay them in March" is answered from the March run and
 * not from a schedule that has moved three times since - which is the
 * same decision the sales side makes about tax treatment and the
 * catalogue makes about price.
 *
 * ## Everything here needs visibility claimed first
 *
 * The tables are invisible without it, so a method that forgot to claim
 * would read an empty set rather than leak - which fails loudly and in
 * the right direction. {@see PayrollVisibility}.
 */
final class Payroll
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly Poster $poster,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Put a salary on the record. Nobody is paid from a draft.
     *
     * @param  list<array{code: string, name_ar: string, name_en: string, kind: string, amount: string, liability_role?: string|null}>  $components
     * @return array<string, mixed>
     *
     * @throws PayrollRefused
     */
    public function draftSchedule(
        string $entityId,
        string $partyId,
        string $currency,
        int $payDay,
        Carbon $startsOn,
        array $components,
        string $userId,
        string $frequency = 'monthly',
    ): array {
        $party = DB::connection('tenant')->table('parties')->where('id', $partyId)->first();

        if ($party === null || ! $party->is_employee) {
            throw PayrollRefused::anEmployeeWhoIsNotOne($partyId);
        }

        if ($components === []) {
            throw PayrollRefused::because('A salary schedule with no components pays nothing.');
        }

        $earnings = array_filter($components, static fn (array $c): bool => $c['kind'] === 'earning');

        if ($earnings === []) {
            throw PayrollRefused::because(
                'A salary schedule needs at least one earning. Deductions and contributions are '.
                'computed against pay, and a schedule of deductions alone would take money off nothing.',
            );
        }

        $id = (string) Str::ulid();
        $reference = $this->numbers->next($entityId, 'salary_schedule', 'SAL', $startsOn);

        DB::connection('tenant')->transaction(function () use (
            $id, $entityId, $partyId, $reference, $currency, $frequency, $payDay, $startsOn, $components, $userId
        ): void {
            DB::connection('tenant')->table('salary_schedules')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'party_id' => $partyId,
                'reference' => $reference,
                'currency' => $currency,
                'frequency' => $frequency,
                'pay_day' => $payDay,
                'starts_on' => $startsOn->toDateString(),
                'state' => 'draft',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($components as $component) {
                DB::connection('tenant')->table('salary_components')->insert([
                    'id' => (string) Str::ulid(),
                    'entity_id' => $entityId,
                    'schedule_id' => $id,
                    'code' => (string) $component['code'],
                    'name_ar' => (string) $component['name_ar'],
                    'name_en' => (string) $component['name_en'],
                    'kind' => (string) $component['kind'],
                    'liability_role' => $component['liability_role'] ?? null,
                    'amount' => (string) $component['amount'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return ['id' => $id, 'reference' => $reference, 'state' => 'draft'];
    }

    /** @throws PayrollRefused */
    public function approveSchedule(string $scheduleId, string $userId): void
    {
        $schedule = $this->scheduleOr404($scheduleId);

        if ((string) $schedule->state !== 'draft') {
            throw PayrollRefused::because(sprintf(
                'Salary schedule %s is %s, so there is nothing to approve.',
                (string) $schedule->reference,
                (string) $schedule->state,
            ));
        }

        if ((string) $schedule->created_by === $userId) {
            throw PayrollRefused::because(
                'The person who set a salary cannot approve it. Somebody who can do both can give '.
                'themselves a rise and pay it, and the payment run would see nothing unusual.',
            );
        }

        DB::connection('tenant')->table('salary_schedules')->where('id', $scheduleId)->update([
            'state' => 'active',
            'approved_by' => $userId,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'payroll',
            action: 'schedule.approved',
            subjectType: 'salary_schedule',
            subjectId: $scheduleId,
            subjectLabel: (string) $schedule->reference,
            before: ['state' => 'draft'],
            after: ['state' => 'active'],
            entityId: (string) $schedule->entity_id,
            partyId: (string) $schedule->party_id,
        );
    }

    /**
     * Change a salary. The next payment changes; the history does not.
     *
     * @throws PayrollRefused
     */
    public function restate(
        string $scheduleId,
        string $code,
        string $amount,
        string $reason,
        string $userId,
    ): void {
        $schedule = $this->scheduleOr404($scheduleId);

        if (mb_strlen(trim($reason)) < 10) {
            throw PayrollRefused::because(
                'Changing somebody\'s pay needs its reason in a sentence - a promotion, an annual '.
                'review, a correction. The three are read very differently a year later.',
            );
        }

        $component = DB::connection('tenant')->table('salary_components')
            ->where('schedule_id', $scheduleId)
            ->where('code', $code)
            ->first();

        if ($component === null) {
            throw PayrollRefused::because(sprintf('That schedule has no component "%s".', $code));
        }

        /*
         | The component is updated, and that is deliberate.
         |
         | The HISTORY lives on the payroll lines, which snapshot the
         | components as they were when the run was posted. A product
         | that versioned the components as well would have two records
         | of the same past and no rule for which to believe - and the
         | run is the one that was paid.
         */
        DB::connection('tenant')->table('salary_components')
            ->where('id', (string) $component->id)
            ->update(['amount' => $amount, 'updated_at' => now()]);

        $this->audit->record(
            category: 'payroll',
            action: 'salary.restated',
            subjectType: 'salary_schedule',
            subjectId: $scheduleId,
            subjectLabel: sprintf('%s %s', (string) $schedule->reference, $code),
            before: ['amount' => (string) $component->amount],
            after: ['amount' => $amount],
            reason: trim($reason),
            entityId: (string) $schedule->entity_id,
            partyId: (string) $schedule->party_id,
        );
    }

    /**
     * Work out a period's pay from the active schedules.
     *
     * @return array<string, mixed>
     *
     * @throws PayrollRefused
     */
    public function prepare(
        string $entityId,
        string $currency,
        Carbon $periodStart,
        Carbon $periodEnd,
        Carbon $payOn,
        string $userId,
    ): array {
        $schedules = DB::connection('tenant')->table('salary_schedules')
            ->where('entity_id', $entityId)
            ->where('currency', $currency)
            ->where('state', 'active')
            ->where('starts_on', '<=', $periodEnd->toDateString())
            ->where(function ($q) use ($periodStart): void {
                $q->whereNull('ends_on')->orWhere('ends_on', '>=', $periodStart->toDateString());
            })
            ->orderBy('reference')
            ->get();

        if ($schedules->isEmpty()) {
            throw PayrollRefused::because(sprintf(
                'No active salary schedule covers %s to %s in %s, so there is nobody to pay.',
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
                $currency,
            ));
        }

        $runId = (string) Str::ulid();
        $number = $this->numbers->next($entityId, 'payroll_run', 'PAY', $payOn);

        $totals = [
            'gross' => Money::zero($currency),
            'deductions' => Money::zero($currency),
            'employer' => Money::zero($currency),
            'net' => Money::zero($currency),
        ];

        $byRole = [
            'payroll_tax_payable' => Money::zero($currency),
            'social_insurance_payable' => Money::zero($currency),
        ];

        $lines = [];

        foreach ($schedules as $schedule) {
            $components = DB::connection('tenant')->table('salary_components')
                ->where('schedule_id', (string) $schedule->id)
                ->orderBy('code')
                ->get();

            $gross = Money::zero($currency);
            $deductions = Money::zero($currency);
            $employer = Money::zero($currency);
            $snapshot = [];

            foreach ($components as $component) {
                $amount = Money::of((string) $component->amount, $currency);

                match ((string) $component->kind) {
                    'earning' => $gross = $gross->plus($amount),
                    'deduction' => $deductions = $deductions->plus($amount),
                    default => $employer = $employer->plus($amount),
                };

                if ((string) $component->kind !== 'earning') {
                    $role = (string) $component->liability_role;
                    $byRole[$role] = $byRole[$role]->plus($amount);
                }

                $snapshot[] = [
                    'code' => (string) $component->code,
                    'name_en' => (string) $component->name_en,
                    'kind' => (string) $component->kind,
                    'amount' => (string) $amount->amount,
                    'liability_role' => $component->liability_role === null ? null : (string) $component->liability_role,
                ];
            }

            $net = $gross->minus($deductions);

            if ($net->isNegative() || $net->isZero()) {
                throw PayrollRefused::because(sprintf(
                    'Schedule %s works out to a net of %s. A zero or negative net is either an unpaid '.
                    'month, which somebody has to record as a decision, or a miscalculation - and a run '.
                    'full of them looks exactly like a run that worked.',
                    (string) $schedule->reference,
                    (string) $net->toPresentation(),
                ));
            }

            $lines[] = [
                'id' => (string) Str::ulid(),
                'entity_id' => $entityId,
                'run_id' => $runId,
                'party_id' => (string) $schedule->party_id,
                'schedule_id' => (string) $schedule->id,
                'gross' => (string) $gross->amount,
                'deductions' => (string) $deductions->amount,
                'employer' => (string) $employer->amount,
                'net' => (string) $net->amount,
                'components' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $totals['gross'] = $totals['gross']->plus($gross);
            $totals['deductions'] = $totals['deductions']->plus($deductions);
            $totals['employer'] = $totals['employer']->plus($employer);
            $totals['net'] = $totals['net']->plus($net);
        }

        DB::connection('tenant')->transaction(function () use (
            $runId, $entityId, $number, $periodStart, $periodEnd, $payOn, $currency, $totals, $lines, $userId
        ): void {
            DB::connection('tenant')->table('payroll_runs')->insert([
                'id' => $runId,
                'entity_id' => $entityId,
                'number' => $number,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'pay_on' => $payOn->toDateString(),
                'currency' => $currency,
                'state' => 'draft',
                'gross_total' => (string) $totals['gross']->amount,
                'deduction_total' => (string) $totals['deductions']->amount,
                'employer_total' => (string) $totals['employer']->amount,
                'net_total' => (string) $totals['net']->amount,
                'prepared_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('tenant')->table('payroll_lines')->insert($lines);
        });

        /*
         | Recorded, so the duty pair can fire - PF-256.
         |
         | `set_then_approve_payroll` matches `payroll.manage` against
         | `payroll.approve` on one object. A preparation nobody recorded
         | makes it a declared gap: the rule exists, the screen says it
         | is enforced, and one person can do both.
         */
        $this->audit->record(
            category: 'payroll',
            action: 'run.prepared',
            subjectType: 'payroll_run',
            subjectId: $runId,
            subjectLabel: sprintf('%s %s', $number, (string) $totals['net']->amount),
            after: [
                'gross' => (string) $totals['gross']->amount,
                'deductions' => (string) $totals['deductions']->amount,
                'employer' => (string) $totals['employer']->amount,
                'net' => (string) $totals['net']->amount,
                'people' => \count($lines),
            ],
            entityId: $entityId,
        );

        return [
            'id' => $runId,
            'number' => $number,
            'state' => 'draft',
            'gross' => (string) $totals['gross']->amount,
            'deductions' => (string) $totals['deductions']->amount,
            'employer' => (string) $totals['employer']->amount,
            'net' => (string) $totals['net']->amount,
            'by_role' => array_map(static fn (Money $m): string => (string) $m->amount, $byRole),
            'people' => \count($lines),
            'why' => sprintf(
                '%d person(s), %s gross, %s deducted, %s net, and %s the employer owes on top. The net '.
                'goes out as bills through the ordinary payment run - maker-checker and all.',
                \count($lines),
                (string) $totals['gross']->toPresentation(),
                (string) $totals['deductions']->toPresentation(),
                (string) $totals['net']->toPresentation(),
                (string) $totals['employer']->toPresentation(),
            ),
        ];
    }

    /** @throws PayrollRefused */
    public function approve(string $runId, string $userId): void
    {
        $run = $this->runOr404($runId);

        if ((string) $run->state !== 'draft') {
            throw PayrollRefused::because(sprintf('Run %s is %s.', (string) $run->number, (string) $run->state));
        }

        if ((string) $run->prepared_by === $userId) {
            throw PayrollRefused::because(
                'The person who prepared the payroll cannot approve it. This is the largest regular '.
                'outflow in most businesses, and one person who can do both can add a name to it.',
            );
        }

        DB::connection('tenant')->table('payroll_runs')->where('id', $runId)->update([
            'state' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'payroll',
            action: 'run.approved',
            subjectType: 'payroll_run',
            subjectId: $runId,
            subjectLabel: sprintf('%s %s', (string) $run->number, (string) $run->net_total),
            before: ['state' => 'draft'],
            after: ['state' => 'approved'],
            entityId: (string) $run->entity_id,
        );
    }

    /**
     * Post the run, and raise the bills the payment run will pay.
     *
     * @return array<string, mixed>
     *
     * @throws PayrollRefused
     */
    public function post(string $runId, string $userId): array
    {
        $run = $this->runOr404($runId);

        if ((string) $run->state !== 'approved') {
            throw PayrollRefused::because(sprintf(
                'Run %s is %s. Posting a payroll that nobody approved would put the largest regular '.
                'outflow in the business into the books on one person\'s word.',
                (string) $run->number,
                (string) $run->state,
            ));
        }

        $entity = Entity::query()->findOrFail((string) $run->entity_id);
        $currency = (string) $run->currency;

        $lines = DB::connection('tenant')->table('payroll_lines')
            ->where('run_id', $runId)
            ->orderBy('id')
            ->get();

        $byRole = $this->liabilitiesOf($runId, $currency);

        return DB::connection('tenant')->transaction(function () use (
            $run, $runId, $entity, $currency, $lines, $byRole, $userId
        ): array {
            $narration = sprintf(
                'Payroll %s, %s to %s',
                (string) $run->number,
                (string) $run->period_start,
                (string) $run->period_end,
            );

            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: PayrollPostedRule::KEY,
                event: [
                    'gross' => (string) $run->gross_total,
                    'employer' => (string) $run->employer_total,
                    'net' => (string) $run->net_total,
                    'payroll_tax' => (string) $byRole['payroll_tax_payable']->amount,
                    'social_insurance' => (string) $byRole['social_insurance_payable']->amount,
                    'narration' => $narration,
                ],
                accountingDate: Carbon::parse((string) $run->pay_on),
                sourceType: 'payroll_run',
                sourceId: $runId,
                narration: $narration,
            );

            /*
             | One bill per employee, for the NET, already approved.
             |
             | Approved because the payroll run's own maker-checker IS
             | the approval: asking a second person to approve each
             | employee's bill would be the same control twice, and the
             | second one would be clicked through.
             |
             | The bills are what the payment run selects, which is how
             | payroll goes out through the same path as everything else
             | rather than round it.
             */
            $bills = [];

            foreach ($lines as $line) {
                $billId = (string) Str::ulid();

                /*
                 | The run's number and the employee, as the document
                 | reference.
                 |
                 | A salary bill has no supplier document - there is no
                 | invoice from an employee - so the reference is ours:
                 | `PAY-2026/00001/<employee>`. It has to be unique per
                 | party, because `bills` holds a unique index on the
                 | normalised key, and it has to be recognisable on a
                 | remittance advice because that is what the employee
                 | sees on their statement.
                 */
                $reference = sprintf(
                    '%s/%s',
                    (string) $run->number,
                    mb_substr((string) $line->party_id, -6),
                );

                DB::connection('tenant')->table('bills')->insert([
                    'id' => $billId,
                    'entity_id' => (string) $run->entity_id,
                    'party_id' => (string) $line->party_id,
                    'supplier_document_number' => $reference,
                    'supplier_document_key' => \App\Support\Text\DocumentNumber::comparable($reference),
                    'supplier_document_date' => (string) $run->pay_on,
                    /*
                     | The tax point and the posting date are the pay
                     | date, because that is the only date a salary has.
                     | There is no supplier invoice with a date of its
                     | own to disagree with.
                     */
                    'tax_point' => (string) $run->pay_on,
                    'posting_date' => (string) $run->pay_on,
                    'currency' => $currency,
                    'net_amount' => (string) $line->net,
                    'tax_amount' => '0.0000',
                    'gross_amount' => (string) $line->net,
                    'payable_amount' => (string) $line->net,
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::connection('tenant')->table('payroll_lines')
                    ->where('id', (string) $line->id)
                    ->update(['bill_id' => $billId, 'updated_at' => now()]);

                $bills[] = $billId;
            }

            DB::connection('tenant')->table('payroll_runs')->where('id', $runId)->update([
                'state' => 'posted',
                'journal_entry_id' => (string) $entry->getKey(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'payroll',
                action: 'run.posted',
                subjectType: 'payroll_run',
                subjectId: $runId,
                subjectLabel: sprintf('%s %s', (string) $run->number, (string) $run->net_total),
                before: ['state' => 'approved'],
                after: ['state' => 'posted', 'bills' => \count($bills)],
                entityId: (string) $run->entity_id,
            );

            return [
                'state' => 'posted',
                'journal_entry_id' => (string) $entry->getKey(),
                'bills' => $bills,
                'why' => sprintf(
                    'Posted, and %d bill(s) raised for the net. They go out through the ordinary '.
                    'payment run with maker-checker and beneficiary verification - a separate path for '.
                    'payroll would be a separate path round the controls.',
                    \count($bills),
                ),
            ];
        });
    }

    /**
     * What a run owes to each authority.
     *
     * @return array<string, Money>
     */
    private function liabilitiesOf(string $runId, string $currency): array
    {
        $byRole = [
            'payroll_tax_payable' => Money::zero($currency),
            'social_insurance_payable' => Money::zero($currency),
        ];

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT c->>'liability_role' AS role,
                   sum((c->>'amount')::numeric)::text AS total
            FROM payroll_lines l
            CROSS JOIN LATERAL jsonb_array_elements(l.components) c
            WHERE l.run_id = ?
              AND c->>'liability_role' IS NOT NULL
            GROUP BY c->>'liability_role'
        SQL, [$runId]);

        foreach ($rows as $row) {
            $byRole[(string) $row->role] = Money::of((string) $row->total, $currency);
        }

        return $byRole;
    }

    /** @throws PayrollRefused */
    private function scheduleOr404(string $scheduleId): object
    {
        $schedule = DB::connection('tenant')->table('salary_schedules')->where('id', $scheduleId)->first();

        if ($schedule === null) {
            throw PayrollRefused::because(
                'There is no salary schedule with that id - or this session has not claimed payroll '.
                'visibility, in which case the rows are invisible rather than missing.',
            );
        }

        return $schedule;
    }

    /** @throws PayrollRefused */
    private function runOr404(string $runId): object
    {
        $run = DB::connection('tenant')->table('payroll_runs')->where('id', $runId)->first();

        if ($run === null) {
            throw PayrollRefused::because(
                'There is no payroll run with that id - or this session has not claimed payroll '.
                'visibility, in which case the rows are invisible rather than missing.',
            );
        }

        return $run;
    }
}
