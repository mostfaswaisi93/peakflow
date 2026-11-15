<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Models\RecognitionPlanRow;
use App\Tenant\Sales\Services\Crediting;
use App\Tenant\Sales\Services\Issuer;
use App\Tenant\Sales\Services\RevenueRecognition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deferring revenue and releasing it as it is earned - PF-107, IFRS 15.
 *
 * ## The case that breaks naive implementations
 *
 * A credit note against a partially recognised invoice. Three months of a
 * twelve-month contract are revenue and nine are still a liability; a credit
 * note that takes the whole amount out of revenue overstates the reversal by
 * three months of income, and one that takes it all out of the deferral
 * overstates revenue by the same. Both look right on the face of the credit
 * note, and both leave the deferred revenue control account unreconcilable in
 * opposite directions.
 *
 * So the headline check credits a partially recognised contract and reads the
 * three account balances afterwards.
 */
final class RevenueRecognitionRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['revenue recognition (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'an immediate line posts straight to revenue' => fn (): array => $this->immediate(),
                'a deferred line posts to the liability and plans its release' => fn (): array => $this->deferred(),
                'the plan is weighted by the days each month carries' => fn (): array => $this->weighting(),
                'a straight-line basis with no service period is refused' => fn (): array => $this->noPeriod(),
                'the run releases what is due and nothing else' => fn (): array => $this->theRun(),
                'a run covering two months posts one entry per month' => fn (): array => $this->onePerPeriod(),
                'running it twice posts once' => fn (): array => $this->rerunnable(),
                'it refuses a period that does not accept postings' => fn (): array => $this->closedPeriod(),
                'crediting a partly recognised invoice unwinds both halves' => fn (): array => $this->theHardCase(),
                'the roll-forward reconciles to the control account' => fn (): array => $this->rollForward(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function immediate(): array
    {
        $violations = [];
        $invoice = $this->issue();

        $entry = $this->entryFor($invoice);

        if ($entry === null) {
            return ['the invoice posted nothing'];
        }

        if ($this->movementOn($entry, $this->revenueAccountId) !== '-1000.0000') {
            $violations[] = 'an ordinary invoice did not credit revenue, which is the shape that must '.
                'not need configuring: goods delivered, work done, a thing sold';
        }

        if ($this->movementOn($entry, $this->roleAccount('deferred_revenue')) !== null) {
            $violations[] = 'an ordinary invoice deferred revenue';
        }

        if (RecognitionPlanRow::query()->where('invoice_id', (string) $invoice->getKey())->exists()) {
            $violations[] = 'an immediate line wrote a recognition plan, which is a row somebody has '.
                'to release for revenue that is already earned';
        }

        return $violations;
    }

    /** @return list<string> */
    public function deferred(): array
    {
        $violations = [];
        $invoice = $this->issueDeferred();

        $entry = $this->entryFor($invoice);

        if ($entry === null) {
            return ['the invoice posted nothing'];
        }

        if ($this->movementOn($entry, $this->roleAccount('deferred_revenue')) !== '-1200.0000') {
            $violations[] = sprintf(
                'a deferred line credited the liability by %s and should credit 1200.0000. Money '.
                'invoiced for work not yet done is a contract liability under IFRS 15, and crediting '.
                'revenue now overstates income in a period that may be closed, reported and filed by '.
                'the time anybody notices',
                $this->movementOn($entry, $this->roleAccount('deferred_revenue')) ?? 'nothing',
            );
        }

        if ($this->movementOn($entry, $this->revenueAccountId) !== null) {
            $violations[] = 'a deferred line credited revenue as well';
        }

        $plan = RecognitionPlanRow::query()
            ->where('invoice_id', (string) $invoice->getKey())
            ->orderBy('recognise_on')
            ->get();

        if ($plan->count() !== 12) {
            $violations[] = sprintf(
                'a twelve-month contract planned %d releases',
                $plan->count(),
            );
        }

        $planned = Money::zero('SAR');

        foreach ($plan as $row) {
            $planned = $planned->plus($row->money());
        }

        if ((string) $planned->amount !== '1200.0000') {
            $violations[] = sprintf(
                'the plan adds to %s and the line was 1200.0000. A cent left over sits in the '.
                'deferral forever, and a control account that never quite reaches zero is one people '.
                'stop reconciling',
                (string) $planned->amount,
            );
        }

        $first = $plan->first();

        if ($first !== null && ! str_contains((string) $first->note, 'month 1 of 12')) {
            $violations[] = sprintf(
                'the first plan row reads "%s"; "month 1 of 12" is what an auditor holding the report '.
                'needs',
                (string) $first->note,
            );
        }

        /*
         | And the release date is the END of the month.
         |
         | Recognising on the 1st recognises work not yet done, which is the
         | same error as crediting revenue at issue, one month at a time.
         */
        if ($first !== null && (string) $first->recognise_on?->toDateString() !== '2026-05-31') {
            $violations[] = sprintf(
                'the first release is dated %s and a May service month is earned by the 31st',
                (string) $first->recognise_on?->toDateString(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function weighting(): array
    {
        $violations = [];

        // A contract from the 20th of May to the 19th of August: eleven days
        // in its first month, nineteen in its last.
        $invoice = $this->issueDeferred(
            from: '2026-05-20',
            until: '2026-08-19',
            net: '930.0000',
        );

        $plan = RecognitionPlanRow::query()
            ->where('invoice_id', (string) $invoice->getKey())
            ->orderBy('recognise_on')
            ->get();

        if ($plan->count() !== 4) {
            $violations[] = sprintf('a contract spanning four calendar months planned %d releases', $plan->count());

            return $violations;
        }

        $amounts = $plan->map(static fn (RecognitionPlanRow $row): string => (string) $row->amount)->all();

        if ($amounts[0] === $amounts[1]) {
            $violations[] = sprintf(
                'the first month (eleven days) and the second (thirty) are both %s. Twelve equal '.
                'twelfths would recognise a month of revenue in eleven days',
                $amounts[0],
            );
        }

        $total = Money::zero('SAR');

        foreach ($plan as $row) {
            $total = $total->plus($row->money());
        }

        if ((string) $total->amount !== '930.0000') {
            $violations[] = sprintf('the weighted plan adds to %s and should add to 930.0000', (string) $total->amount);
        }

        return $violations;
    }

    /** @return list<string> */
    public function noPeriod(): array
    {
        $draft = $this->draft();
        $draft->lines()->update(['recognition' => 'straight_line', 'service_from' => null, 'service_until' => null]);

        try {
            app(Issuer::class)->issue($draft->refresh(), $this->entity(), $this->actorId(), $this->decision);

            return ['a straight-line line with no service period was issued. A schedule over an '.
                'unknown period is one nobody can compute, and defaulting it to a month would '.
                'recognise a year of support in four weeks'];
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'service period')
                ? []
                : ['the refusal is about something else: '.mb_substr($e->getMessage(), 0, 140)];
        }
    }

    /** @return list<string> */
    public function theRun(): array
    {
        $violations = [];
        $invoice = $this->issueDeferred();

        $report = app(RevenueRecognition::class)->recognise(
            $this->entity(),
            CarbonImmutable::parse('2026-05-31'),
        );

        if ($report['rows'] !== 1) {
            $violations[] = sprintf(
                '%d rows were recognised to the end of May and one month is due',
                $report['rows'],
            );
        }

        $entry = $report['entry'] === null
            ? null
            : DB::connection('tenant')->table('journal_entries')->where('id', $report['entry'])->first();

        if ($entry === null) {
            return [...$violations, 'the run posted no entry'];
        }

        if ((string) $entry->posting_rule !== 'sales.revenue_recognised') {
            $violations[] = sprintf('the run posted through "%s"', (string) $entry->posting_rule);
        }

        $released = Money::of($report['recognised'], 'SAR');

        if ($this->movementOn((object) ['id' => $report['entry']], $this->revenueAccountId) !== '-'.$released->amount) {
            $violations[] = 'the entry did not credit revenue by what it says it recognised';
        }

        if ($this->movementOn((object) ['id' => $report['entry']], $this->roleAccount('deferred_revenue')) !== (string) $released->amount) {
            $violations[] = 'the entry did not debit the deferral by the same amount';
        }

        /*
         | And the tax code is NOT on the release.
         |
         | Releasing a deferral is not a supply: the tax was declared when
         | the document was issued, and a tax code here would make the
         | taxable amount appear twice in a return assembled from codes.
         */
        $withCode = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $report['entry'])
            ->whereNotNull('tax_code_id')
            ->count();

        if ($withCode !== 0) {
            $violations[] = 'the recognition entry carries a tax code, which would put the taxable '.
                'amount in a return twice: once at issue and once here';
        }

        // The later months are untouched.
        $stillPlanned = RecognitionPlanRow::query()
            ->where('invoice_id', (string) $invoice->getKey())
            ->where('status', 'planned')
            ->count();

        if ($stillPlanned !== 11) {
            $violations[] = sprintf('%d months remain planned and eleven should', $stillPlanned);
        }

        return $violations;
    }

    /**
     * One entry per period, not one per run - PF-107.
     *
     * ## Why this check exists
     *
     * Measured: collapsing the run into a single entry left every other
     * check in this suite green. The amounts were right, the rule was
     * right, the balances were right - and two months of revenue sat in
     * one. June would be overstated by May's revenue and May understated by
     * the same, in accounts that may already have been reported.
     *
     * @return list<string>
     */
    public function onePerPeriod(): array
    {
        $violations = [];
        $this->issueDeferred();
        $this->openPeriodsThrough('2026-07-31');

        $report = app(RevenueRecognition::class)->recognise(
            $this->entity(),
            CarbonImmutable::parse('2026-07-31'),
        );

        if (\count($report['entries']) !== 3) {
            $violations[] = sprintf(
                'a run covering May, June and July posted %d entr(ies). Revenue belongs to the month '.
                'it was earned in: posting them together overstates the last month by the others and '.
                'understates those - in accounts that may already have been reported',
                \count($report['entries']),
            );

            return $violations;
        }

        $periods = array_column($report['entries'], 'period');

        if (\count(array_unique($periods)) !== 3) {
            $violations[] = 'the entries are not in three different periods: '.implode(', ', $periods);
        }

        foreach ($report['entries'] as $entry) {
            $row = DB::connection('tenant')->table('journal_entries')
                ->where('id', $entry['entry'])
                ->first();

            if ($row === null) {
                $violations[] = sprintf('entry %s does not exist', (string) $entry['entry']);

                continue;
            }

            $month = CarbonImmutable::parse((string) $row->accounting_date)->format('Y-m');

            if (! str_contains((string) $entry['period'], $month)) {
                $violations[] = sprintf(
                    'an entry for period %s is dated %s, so the revenue landed in a month it was not '.
                    'earned in',
                    (string) $entry['period'],
                    (string) $row->accounting_date,
                );
            }
        }

        $entries = DB::connection('tenant')->table('journal_entries')
            ->where('posting_rule', 'sales.revenue_recognised')
            ->count();

        if ($entries !== 3) {
            $violations[] = sprintf('%d recognition entries exist and three months were released', $entries);
        }

        return $violations;
    }

    /** @return list<string> */
    public function rerunnable(): array
    {
        $violations = [];
        $this->issueDeferred();

        $first = app(RevenueRecognition::class)->recognise($this->entity(), CarbonImmutable::parse('2026-05-31'));
        $second = app(RevenueRecognition::class)->recognise($this->entity(), CarbonImmutable::parse('2026-05-31'));

        if ($second['rows'] !== 0) {
            $violations[] = sprintf(
                'the second run recognised %d row(s) again. A reporting command that double-posts is '.
                'worse than one that fails, because the figures look plausible',
                $second['rows'],
            );
        }

        if ($second['skipped'] < 1) {
            $violations[] = 'the second run does not say what it skipped, so a run that produced '.
                'nothing cannot be told from one that found nothing';
        }

        if ($second['entry'] !== null) {
            $violations[] = 'the second run posted an entry for nothing';
        }

        $entries = DB::connection('tenant')->table('journal_entries')
            ->where('posting_rule', 'sales.revenue_recognised')
            ->count();

        if ($entries !== 1) {
            $violations[] = sprintf('%d recognition entries exist after two runs', $entries);
        }

        return $violations;
    }

    /** @return list<string> */
    public function closedPeriod(): array
    {
        $this->issueDeferred();

        $period = app(FiscalCalendarGenerator::class)->periodFor($this->entityId, Carbon::parse('2026-05-31'));
        app(PeriodStateMachine::class)->transition($period, PeriodState::SoftClosed, 'closed for a recognition check');

        try {
            app(RevenueRecognition::class)->recognise($this->entity(), CarbonImmutable::parse('2026-05-31'));

            return ['revenue was recognised into a soft-closed period. Recognition posts a journal '.
                'entry like any other: the period that refuses a sale refuses this too'];
        } catch (Throwable $e) {
            /*
             | The wording matters, and this is why.
             |
             | Measured: with the service's own period check removed, the
             | ledger's `Poster` refused it anyway - defence in depth - and
             | a check that accepted either message stayed green. The
             | difference is what the person reading it is told: "revenue
             | cannot be recognised into it" says which run to adjust, and
             | the ledger's refusal says a posting failed.
             */
            return str_contains($e->getMessage(), 'recognised into it')
                ? []
                : ['the refusal did not come from the recognition run, so the reader is told a '.
                    'posting failed rather than which run to adjust: '.mb_substr($e->getMessage(), 0, 140)];
        }
    }

    /** @return list<string> */
    public function theHardCase(): array
    {
        $violations = [];
        $invoice = $this->issueDeferred();

        // Three months recognised: 300 of 1,200 is revenue, 900 is still a
        // liability.
        $this->openPeriodsThrough('2026-07-31');
        app(RevenueRecognition::class)->recognise($this->entity(), CarbonImmutable::parse('2026-07-31'));

        $recognised = RecognitionPlanRow::query()
            ->where('invoice_id', (string) $invoice->getKey())
            ->where('status', 'recognised')
            ->sum('amount');

        if ((string) $recognised === '0.0000') {
            return ['nothing was recognised, so this check is not testing what it claims'];
        }

        $revenueBefore = $this->balanceOn($this->revenueAccountId);
        $deferralBefore = $this->balanceOn($this->roleAccount('deferred_revenue'));

        $credit = app(Issuer::class)->issue(
            app(Crediting::class)->full($invoice->refresh(), $this->date),
            $this->entity(), $this->actorId(), $this->decision,
        );

        $entry = $this->entryFor($credit);

        if ($entry === null) {
            return ['the credit note posted nothing'];
        }

        $revenueTakenBack = $this->movementOn($entry, $this->revenueAccountId);
        $deferralTakenBack = $this->movementOn($entry, $this->roleAccount('deferred_revenue'));

        if ($revenueTakenBack !== (string) Money::of((string) $recognised, 'SAR')->amount) {
            $violations[] = sprintf(
                'the credit note took %s out of revenue and %s had been recognised. Taking the whole '.
                '1,200 out of revenue overstates the reversal by the months that were earned',
                $revenueTakenBack ?? 'nothing',
                (string) Money::of((string) $recognised, 'SAR')->amount,
            );
        }

        $expectedDeferral = Money::of('1200.0000', 'SAR')->minus(Money::of((string) $recognised, 'SAR'));

        if ($deferralTakenBack !== (string) $expectedDeferral->amount) {
            $violations[] = sprintf(
                'the credit note took %s out of the deferral and %s was still deferred. Taking the '.
                'whole amount from the liability overstates revenue by what was earned',
                $deferralTakenBack ?? 'nothing',
                (string) $expectedDeferral->amount,
            );
        }

        /*
         | And the balances end where they should.
         |
         | Which is the only claim worth making: both halves can look
         | defensible on the face of the credit note while the control
         | account is unreconcilable.
         */
        if ($this->balanceOn($this->roleAccount('deferred_revenue')) !== '0.0000') {
            $violations[] = sprintf(
                'the deferral stands at %s after a full credit and should be zero (it was %s)',
                $this->balanceOn($this->roleAccount('deferred_revenue')) ?? 'nothing',
                $deferralBefore ?? 'nothing',
            );
        }

        if ($this->balanceOn($this->revenueAccountId) !== '0.0000') {
            $violations[] = sprintf(
                'revenue stands at %s after a full credit and should be zero (it was %s)',
                $this->balanceOn($this->revenueAccountId) ?? 'nothing',
                $revenueBefore ?? 'nothing',
            );
        }

        /*
         | And no later run releases revenue for a credited document.
         |
         | Otherwise the figures stay plausible, the deferral goes negative
         | in eight months, and whoever finds it is reconciling a control
         | account against a credit note from last year.
         */
        $this->openPeriodsThrough('2027-06-30');
        $after = app(RevenueRecognition::class)->recognise($this->entity(), CarbonImmutable::parse('2027-06-30'));

        if ($after['rows'] !== 0) {
            $violations[] = sprintf(
                'a later run released %d row(s) for a credited invoice',
                $after['rows'],
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function rollForward(): array
    {
        $violations = [];
        $this->issueDeferred();

        $this->openPeriodsThrough('2026-06-30');
        app(RevenueRecognition::class)->recognise($this->entity(), CarbonImmutable::parse('2026-06-30'));

        $report = app(RevenueRecognition::class)->rollForward(
            $this->entityId,
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-30'),
            'SAR',
        );

        foreach (['opening', 'additions', 'recognised', 'closing', 'control_account', 'difference'] as $field) {
            if (! \array_key_exists($field, $report)) {
                $violations[] = sprintf('the roll-forward has no %s', $field);
            }
        }

        if (($report['difference'] ?? '') !== '0.0000') {
            $violations[] = sprintf(
                'the schedule says %s is deferred and the control account says %s, a difference of '.
                '%s. A deferred revenue schedule that does not tie to the ledger is a spreadsheet',
                (string) $report['closing'],
                (string) $report['control_account'],
                (string) $report['difference'],
            );
        }

        if ((string) $report['recognised'] === '0.0000') {
            $violations[] = 'the roll-forward reports nothing recognised in a window where two months '.
                'were';
        }

        if ((string) $report['additions'] === '0.0000') {
            $violations[] = 'the roll-forward reports no additions in the window the contract was '.
                'invoiced in';
        }

        /*
         | And the two sides are read independently.
         |
         | Deriving the control account from the schedule is the shape of a
         | reconciliation that is always clean - and the only way to tell is
         | to break one side and see the difference appear.
         */
        DB::connection('tenant')->table('revenue_recognition_plan')
            ->where('entity_id', $this->entityId)
            ->where('status', 'planned')
            ->limit(1)
            ->update(['amount' => '1.0000']);

        $broken = app(RevenueRecognition::class)->rollForward(
            $this->entityId,
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-30'),
            'SAR',
        );

        if ((string) $broken['difference'] === '0.0000') {
            $violations[] = 'editing the schedule did not change the difference, so the control '.
                'account figure is derived from the schedule rather than read from the ledger';
        }

        return $violations;
    }

    /**
     * Open every period from the fixture month to a date.
     *
     * A generated calendar's periods start `future`, which does not accept
     * postings - correctly, and it means a recognition run two months ahead
     * has nowhere to post until somebody opens the months. That is what a
     * business does at each month end; here it is fixture setup.
     */
    private function openPeriodsThrough(string $date): void
    {
        $cursor = CarbonImmutable::parse($this->date->toDateString())->startOfMonth();
        $target = CarbonImmutable::parse($date)->startOfMonth();

        while ($cursor->lte($target)) {
            $period = app(FiscalCalendarGenerator::class)->periodFor(
                $this->entityId,
                Carbon::parse($cursor->toDateString()),
            );

            if ($period->state === PeriodState::Future) {
                app(PeriodStateMachine::class)->transition(
                    $period,
                    PeriodState::Open,
                    'opened for a revenue recognition check',
                );
            }

            $cursor = $cursor->addMonthNoOverflow();
        }
    }

    private function issueDeferred(
        string $from = '2026-05-01',
        string $until = '2027-04-30',
        string $net = '1200.0000',
    ): Invoice {
        $draft = $this->draft();

        $draft->lines()->update([
            'recognition' => 'straight_line',
            'service_from' => $from,
            'service_until' => $until,
            'net_amount' => $net,
            'unit_price' => $net,
        ]);

        $tax = (string) Money::of($net, 'SAR')->multipliedBy(
            '0.15',
            \App\Support\Money\RoundingPolicy::halfUp(\App\Support\Money\Scale::Amount),
        )->amount;

        $draft->taxBreakdown()->update(['taxable_amount' => $net, 'tax_amount' => $tax]);
        $draft->update([
            'net_amount' => $net,
            'tax_amount' => $tax,
            'gross_amount' => (string) Money::of($net, 'SAR')->plus(Money::of($tax, 'SAR'))->amount,
            'payable_amount' => (string) Money::of($net, 'SAR')->plus(Money::of($tax, 'SAR'))->amount,
        ]);

        return app(Issuer::class)->issue(
            $draft->refresh(), $this->entity(), $this->actorId(), $this->decision,
        );
    }

    private function entryFor(Invoice $invoice): ?object
    {
        return DB::connection('tenant')->table('journal_entries')
            ->where('source_id', (string) $invoice->getKey())
            ->first();
    }

    private function roleAccount(string $role, ?string $jurisdiction = null): ?string
    {
        $id = DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', $role)
            ->when($jurisdiction !== null, fn ($q) => $q->where('jurisdiction', $jurisdiction))
            ->value('account_id');

        return $id === null ? null : (string) $id;
    }

    private function movementOn(object $entry, ?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $sum = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $entry->id)
            ->where('account_id', $accountId)
            ->sum('amount');

        return $sum === null || (string) $sum === '0'
            ? null
            : (string) Money::of((string) $sum, 'SAR')->amount;
    }

    private function balanceOn(?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $sum = DB::connection('tenant')->table('journal_lines')
            ->where('entity_id', $this->entityId)
            ->where('account_id', $accountId)
            ->sum('amount');

        return (string) Money::of((string) ($sum ?? '0'), 'SAR')->amount;
    }
}
