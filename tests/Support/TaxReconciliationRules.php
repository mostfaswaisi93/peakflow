<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Closing\ClosingChecks;
use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Tax\Closing\TaxReconciliationCheck;
use App\Tenant\Tax\Enums\FilingFrequency;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Models\TaxCode;
use App\Tenant\Tax\Models\TaxReconciliationSignoff;
use App\Tenant\Tax\Services\ReturnPeriods;
use App\Tenant\Tax\Services\TaxReconciler;
use App\Tenant\Tax\TaxReconciliation;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The tax control accounts against the return - PF-088.
 *
 * ## The scenario these are built around
 *
 * May, with three things in it: an ordinary sale, an April supply booked in
 * May, and a manual journal straight into the output account at the month end.
 *
 * The first reconciles. The second is a timing difference and is fine - the
 * ledger moves by accounting date and the return is assembled by tax point, so
 * a gap is the two dates doing their jobs. The third is the one that matters:
 * it moves the trial balance, cannot reach a return, and is the easiest thing
 * in the world to do at a month end with a plausible narration to make a
 * number look right.
 *
 * ## Being classified is not being explained
 *
 * The first version of this reconciler classified the manual journal and then
 * reported the period clean, which is worse than not looking at manual
 * journals at all. Only a timing difference explains anything; the rest are
 * named AND still block.
 */
final class TaxReconciliationRules
{
    use \Tests\Support\Concerns\ProvesTheLedger;

    private ?Connection $connection = null;

    private string $entityId = '';

    private ?Entity $entity = null;

    private string $receivableId = '';

    private string $outputId = '';

    private string $revenueId = '';

    private string $journalId = '';

    /** @var array<string, Period> */
    private array $periods = [];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['tax reconciliation (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->withTheLedgerProof($this->runEach([
                'an ordinary period reconciles to nothing' => fn (): array => $this->aCleanPeriod(),
                'a timing difference is named and does not block' => fn (): array => $this->timingIsExplained(),
                'a manual journal into a VAT account is named and blocks' => fn (): array => $this->manualJournalsBlock(),
                'differences are itemised, never netted into one figure' => fn (): array => $this->nothingIsNetted(),
                'an unexplained difference blocks the period close' => fn (): array => $this->theCloseIsBlocked(),
                'a sign-off releases the close and is bounded by its amount' => fn (): array => $this->theSignoffIsBounded(),
                'a sign-off needs a sentence and cannot be edited' => fn (): array => $this->theSignoffIsAFact(),
                'the two sides are computed independently' => fn (): array => $this->theSidesAreIndependent(),
                'each jurisdiction reconciles against its own accounts' => fn (): array => $this->perJurisdiction(),
                'the check runs in ledger:verify, per open period' => fn (): array => $this->itRunsInLedgerVerify(),
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    public function aCleanPeriod(): array
    {
        $this->sell('2026-05-18', '2026-05-20', '1000.0000', '150.0000');

        $report = $this->reconcile('2026-05');

        if (! $report->isClean()) {
            return [sprintf(
                'an ordinary May sale left %s unexplained. The movement is %s and the return %s',
                (string) $report->unexplained()->toPresentation(),
                (string) $report->controlMovement->toPresentation(),
                (string) $report->returnFigure->toPresentation(),
            )];
        }

        if ((string) $report->controlMovement->toPresentation() !== '150.00') {
            return [sprintf(
                'the control movement is %s and should be 150.00 - if it is -150.00 the sign convention '.
                'differs from the generator\'s, which would make every clean period look like a '.
                'difference of twice the output tax',
                (string) $report->controlMovement->toPresentation(),
            )];
        }

        return [];
    }

    /** @return list<string> */
    public function timingIsExplained(): array
    {
        $violations = [];

        $this->sell('2026-05-18', '2026-05-20', '1000.0000', '150.0000');
        // An April supply, booked in May.
        $this->sell('2026-04-28', '2026-05-05', '200.0000', '30.0000');

        $report = $this->reconcile('2026-05');

        if ((string) $report->difference()->toPresentation() !== '30.00') {
            $violations[] = sprintf(
                'the difference is %s and should be 30.00 - the April supply is in May\'s trial balance '.
                'and April\'s return', (string) $report->difference()->toPresentation(),
            );
        }

        if (! isset($report->classes['timing'])) {
            $violations[] = 'the timing difference is not named, so it would land in unexplained and '.
                'block a close every time a month end falls near a weekend';
        } elseif ((string) $report->classes['timing']['amount']->toPresentation() !== '30.00') {
            $violations[] = sprintf(
                'the timing class carries %s and should carry 30.00',
                (string) $report->classes['timing']['amount']->toPresentation(),
            );
        }

        if (! $report->isClean()) {
            $violations[] = sprintf(
                'a timing difference left %s unexplained. The two dates doing their jobs is not an error',
                (string) $report->unexplained()->toPresentation(),
            );
        }

        if ($report->blockingClasses() !== []) {
            $violations[] = 'a timing difference is reported as blocking';
        }

        return $violations;
    }

    /** @return list<string> */
    public function manualJournalsBlock(): array
    {
        $violations = [];

        $this->sell('2026-05-18', '2026-05-20', '1000.0000', '150.0000');
        $this->manualJournal('2026-05-25', '-500.0000');

        $report = $this->reconcile('2026-05');

        if ((string) $report->unexplained()->toPresentation() !== '500.00') {
            $violations[] = sprintf(
                'a manual journal of 500.00 into the output account left %s unexplained. It moves the '.
                'trial balance and cannot reach a return, which is assembled from tax codes - and being '.
                'classified is not the same as being explained',
                (string) $report->unexplained()->toPresentation(),
            );
        }

        if (! isset($report->classes['manual'])) {
            $violations[] = 'the manual journal is not itemised, so nobody is told what to look for';
        }

        $blocking = $report->blockingClasses();

        if (! isset($blocking['manual'])) {
            $violations[] = 'the manual class is not reported as blocking. A reconciliation that '.
                'classifies a 500 riyal journal and then reports itself clean is worse than one that '.
                'does not look at manual journals at all';
        }

        return $violations;
    }

    /** @return list<string> */
    public function nothingIsNetted(): array
    {
        $violations = [];

        $this->sell('2026-05-18', '2026-05-20', '1000.0000', '150.0000');

        /*
         | A timing difference of +500 and a manual journal of -500.
         |
         | They net to nothing. A reconciliation reporting a single figure
         | would say the period is clean, and the manual journal - the one
         | that matters - would never be seen.
         */
        $this->sell('2026-04-28', '2026-05-06', '3333.3333', '500.0000');
        $this->manualJournal('2026-05-25', '500.0000');

        $report = $this->reconcile('2026-05');

        if ((string) $report->difference()->toPresentation() !== '0.00') {
            $violations[] = sprintf(
                'the fixture does not net: the difference is %s and should be 0.00, which is what makes '.
                'this check about netting', (string) $report->difference()->toPresentation(),
            );

            return $violations;
        }

        foreach (['timing' => '500.00', 'manual' => '-500.00'] as $class => $expected) {
            if (! isset($report->classes[$class])) {
                $violations[] = sprintf('the %s class is missing from a netting period', $class);

                continue;
            }

            $actual = (string) $report->classes[$class]['amount']->toPresentation();

            if ($actual !== $expected) {
                $violations[] = sprintf('the %s class carries %s and should carry %s', $class, $actual, $expected);
            }
        }

        if ($report->isClean()) {
            $violations[] = 'a period whose whole difference nets to zero was reported clean, and it '.
                'holds a 500.00 manual journal into the output account. That is the failure this entire '.
                'report exists to prevent';
        }

        return $violations;
    }

    /** @return list<string> */
    public function theCloseIsBlocked(): array
    {
        $violations = [];

        $this->sell('2026-05-18', '2026-05-20', '1000.0000', '150.0000');
        $this->manualJournal('2026-05-25', '-500.0000');

        try {
            app(PeriodStateMachine::class)->transition(
                $this->periods['2026-05'], PeriodState::Closed, 'the month end close',
            );

            $violations[] = 'May closed with 500.00 unexplained. The close is the last moment the '.
                'period can still be posted into: after it, every correction is a restatement';
        } catch (Throwable $e) {
            foreach (['500.00', 'manual', 'sign it off'] as $needle) {
                if (! str_contains($e->getMessage(), $needle)) {
                    $violations[] = sprintf(
                        'the refusal does not mention "%s", so the person closing has to decompose a '.
                        'number to find out what to do: %s', $needle, $e->getMessage(),
                    );
                }
            }
        }

        /*
         | And a clean period still closes.
         |
         | A gate that blocks everything is a gate somebody removes.
         */
        $savepoint = 'rec_'.bin2hex(random_bytes(4));
        $this->connection?->statement('SAVEPOINT '.$savepoint);

        try {
            app(PeriodStateMachine::class)->transition(
                $this->periods['2026-04'], PeriodState::Closed, 'the month end close',
            );
        } catch (Throwable $e) {
            $violations[] = 'a period with nothing unexplained would not close: '.$e->getMessage();
        } finally {
            $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }

        return $violations;
    }

    /** @return list<string> */
    public function theSignoffIsBounded(): array
    {
        $violations = [];

        $this->sell('2026-05-18', '2026-05-20', '1000.0000', '150.0000');
        $this->manualJournal('2026-05-25', '-500.0000');

        // A sign-off for less than the difference does not release it.
        $this->signOff('2026-05', '100.0000');

        try {
            app(PeriodStateMachine::class)->transition(
                $this->periods['2026-05'], PeriodState::Closed, 'the month end close',
            );

            $violations[] = 'a sign-off for 100.00 released a difference of 500.00. A sign-off bounded '.
                'by nothing is a permanently disabled check';
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'sign-off covers')) {
                $violations[] = 'the refusal does not say the sign-off was too small: '.$e->getMessage();
            }
        }

        // And one for the full amount does.
        $this->signOff('2026-05', '500.0000');

        try {
            app(PeriodStateMachine::class)->transition(
                $this->periods['2026-05']->refresh(), PeriodState::Closed, 'the month end close',
            );
        } catch (Throwable $e) {
            $violations[] = 'a sign-off for the full difference did not release the close: '.$e->getMessage();
        }

        return $violations;
    }

    /** @return list<string> */
    public function theSignoffIsAFact(): array
    {
        $violations = [];

        $signoff = $this->signOff('2026-05', '500.0000');

        foreach (['', 'accepted', 'immaterial'] as $reason) {
            $savepoint = 'rec_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                $this->signOff('2026-05', '10.0000', $reason);

                $violations[] = sprintf(
                    'the reason "%s" was accepted. The sign-off is evidence during an audit, and a '.
                    'single word is not evidence', $reason,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        foreach ([
            'widening the amount' => ['accepted_amount' => '99999.0000'],
            'rewriting the reason' => ['reason' => 'Something else entirely, written later on.'],
        ] as $what => $changes) {
            $savepoint = 'rec_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                TaxReconciliationSignoff::query()->whereKey($signoff->getKey())->update($changes);

                $violations[] = sprintf(
                    '%s succeeded. Editing the amount retroactively widens a decision somebody made '.
                    'about a smaller figure, which is exactly what the amount is there to prevent',
                    $what,
                );
            } catch (Throwable) {
                // Correct.
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        return $violations;
    }

    /**
     * The movement and the return figure are read separately.
     *
     * @return list<string>
     */
    public function theSidesAreIndependent(): array
    {
        $violations = [];

        $this->sell('2026-05-18', '2026-05-20', '1000.0000', '150.0000');
        $this->manualJournal('2026-05-25', '-500.0000');

        $report = $this->reconcile('2026-05');

        if ($report->controlMovement->equals($report->returnFigure)) {
            $violations[] = sprintf(
                'the movement and the return figure are both %s on a period that holds a manual '.
                'journal. If one side is derived from the other the reconciliation is always clean, '.
                'which is the shape of a check that compares a figure against itself',
                (string) $report->controlMovement->toPresentation(),
            );
        }

        if ((string) $report->returnFigure->toPresentation() !== '150.00') {
            $violations[] = sprintf(
                'the return side is %s and should be 150.00 - the manual journal has no tax code and '.
                'cannot reach a return', (string) $report->returnFigure->toPresentation(),
            );
        }

        if ((string) $report->controlMovement->toPresentation() !== '650.00') {
            $violations[] = sprintf(
                'the ledger side is %s and should be 650.00 - it includes the manual journal, which is '.
                'the whole point of reading it from the accounts',
                (string) $report->controlMovement->toPresentation(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    public function perJurisdiction(): array
    {
        $violations = [];

        $this->sell('2026-05-18', '2026-05-20', '1000.0000', '150.0000');

        /*
         | A second registration with no accounts and no activity.
         |
         | It must reconcile to nothing rather than reporting the Saudi
         | movement against a Jordanian return - which is what a check that
         | reconciled "the VAT accounts" would do, every month.
         */
        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'JO',
            'scheme' => 'GST',
            'identifier' => 'JO-555',
            'effective_from' => '2020-01-01',
            'is_primary' => false,
            'filing_frequency' => FilingFrequency::Quarterly->value,
        ]);

        \assert($this->entity !== null);

        $jordan = app(TaxReconciler::class)->reconcile(
            $this->entity, 'JO', '2026-05', '2026-05-01', '2026-06-01',
        );

        if (! $jordan->controlMovement->isZero()) {
            $violations[] = sprintf(
                'the Jordanian reconciliation sees %s of movement, which is the Saudi output tax. An '.
                'entity registered in two jurisdictions holds two pairs of control accounts, and a '.
                'check that reconciled "the VAT accounts" would report a difference every month',
                (string) $jordan->controlMovement->toPresentation(),
            );
        }

        if (! $jordan->isClean()) {
            $violations[] = 'a jurisdiction with no activity does not reconcile to nothing';
        }

        return $violations;
    }

    /** @return list<string> */
    public function itRunsInLedgerVerify(): array
    {
        $violations = [];

        $checks = app(ClosingChecks::class);

        if (! $checks->has('tax reconciliation')) {
            $violations[] = 'the tax reconciliation is not registered as a closing check, so nothing '.
                'consults it - the registry is what the ledger asks and the command runs';
        }

        /*
         | And the command runs the registry.
         |
         | Source-inspected rather than executed: `ledger:verify` walks every
         | servable tenant and the point here is the wiring, which is visible
         | in the source and would otherwise be asserted by running a command
         | against the whole cluster.
         */
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/app/Console/Commands/LedgerVerify.php');

        foreach ([
            'the registry' => 'ClosingChecks',
            'the per-period loop' => "where('state', 'open')",
            'the findings' => 'will not close',
        ] as $what => $needle) {
            if (! str_contains($source, $needle)) {
                $violations[] = sprintf('ledger:verify does not reference %s', $what);
            }
        }

        // And the registry really answers for a period with a difference.
        $this->sell('2026-05-18', '2026-05-20', '1000.0000', '150.0000');
        $this->manualJournal('2026-05-25', '-500.0000');

        $reasons = $checks->reasonsToRefuse($this->entityId, '2026-05', '2026-05-01', '2026-06-01');

        if ($reasons === []) {
            $violations[] = 'the registry reports nothing for a period with 500.00 unexplained';
        }

        /*
         | A check that throws is a reason not to close, not a silent pass.
         |
         | Registered alongside the real one so the registry's own handling is
         | exercised: a period whose checks nobody has seen must not close.
         */
        $exploding = new class implements \App\Support\Closing\ClosingCheck
        {
            public function name(): string
            {
                return 'a check that throws';
            }

            public function reasonsToRefuse(string $entityId, string $label, string $from, string $until): array
            {
                throw new \RuntimeException('the report could not be built');
            }
        };

        $isolated = new ClosingChecks();
        $isolated->register($exploding);

        $thrown = $isolated->reasonsToRefuse($this->entityId, '2026-05', '2026-05-01', '2026-06-01');

        if ($thrown === [] || ! str_contains($thrown[0], 'could not be computed')) {
            $violations[] = 'a closing check that throws does not become a reason to refuse. The '.
                'alternative is closing a period whose reconciliation nobody has seen';
        }

        return $violations;
    }

    private function reconcile(string $month): TaxReconciliation
    {
        \assert($this->entity !== null);

        return app(TaxReconciler::class)->reconcile(
            $this->entity,
            'SA',
            $month,
            $month.'-01',
            Carbon::parse($month.'-01')->addMonth()->toDateString(),
        );
    }

    private function signOff(
        string $month,
        string $amount,
        string $reason = 'A manual journal was posted into the output account at the month end and is being traced.',
    ): TaxReconciliationSignoff {
        return TaxReconciliationSignoff::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'SA',
            'period_label' => $month,
            'return_label' => $month,
            'currency' => 'SAR',
            'accepted_amount' => $amount,
            'reason' => $reason,
            'signed_at' => now(),
        ]);
    }

    private function sell(string $taxPoint, string $accountingDate, string $net, string $tax): void
    {
        $code = TaxCode::query()->where('entity_id', $this->entityId)->value('id');
        $gross = (string) \Brick\Math\BigDecimal::of($net)->plus($tax)->toScale(4);

        $this->insertEntry([
            [$this->receivableId, $gross, null, null],
            [$this->revenueId, (string) \Brick\Math\BigDecimal::of($net)->negated()->toScale(4), (string) $code, $taxPoint],
            [$this->outputId, (string) \Brick\Math\BigDecimal::of($tax)->negated()->toScale(4), (string) $code, $taxPoint],
        ], $accountingDate);
    }

    /**
     * A journal straight into the output account, with no tax code.
     *
     * The thing this whole report exists to find.
     */
    private function manualJournal(string $accountingDate, string $amount): void
    {
        $this->insertEntry([
            [$this->outputId, $amount, null, null],
            [$this->revenueId, (string) \Brick\Math\BigDecimal::of($amount)->negated()->toScale(4), null, null],
        ], $accountingDate);
    }

    /** @param  list<array{0: string, 1: string, 2: string|null, 3: string|null}>  $lines */
    private function insertEntry(array $lines, string $accountingDate): void
    {
        $connection = $this->connection;

        \assert($connection !== null);

        $connection->statement("SELECT set_config('peakflow.posting_rule', 'check.document', true)");

        $month = substr($accountingDate, 0, 7);
        $periodId = (string) ($this->periods[$month]?->getKey() ?? '');
        $entryId = (string) Str::ulid();

        $connection->table('journal_entries')->insert([
            'id' => $entryId,
            'entity_id' => $this->entityId,
            'journal_id' => $this->journalId,
            'period_id' => $periodId,
            'number' => 'DOC-'.Str::ulid(),
            'accounting_date' => $accountingDate,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as [$account, $amount, $taxCode, $taxPoint]) {
            $connection->table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'journal_entry_id' => $entryId,
                'accounting_date' => $accountingDate,
                'period_id' => $periodId,
                'account_id' => $account,
                'amount' => $amount,
                'currency' => 'SAR',
                'functional_amount' => $amount,
                'tax_code_id' => $taxCode === '' ? null : $taxCode,
                'tax_point' => $taxPoint,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
        $connection->statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'REC'.random_int(100, 999),
            'legal_name' => 'Tax reconciliation check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $this->entity = $entity;
        $context->use($entity);

        TaxRegistration::create([
            'entity_id' => $this->entityId,
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => '310000000000003',
            'effective_from' => '2020-01-01',
            'is_primary' => true,
            'filing_frequency' => FilingFrequency::Monthly->value,
        ]);

        TaxCode::create([
            'entity_id' => $this->entityId,
            'code' => 'SA-S-15',
            'category' => TaxCategory::Standard->value,
            'jurisdiction' => 'SA',
            'label_ar' => 'النسبة الأساسية',
            'label_en' => 'Standard rate',
            'reporting_boxes' => ['sa' => ['output' => 'standard_sales', 'input' => 'standard_purchases']],
            'active' => true,
        ]);

        app(ReturnPeriods::class)->generateYear($entity, 'SA', 2026);

        foreach (['2026-04', '2026-05'] as $month) {
            $period = app(FiscalCalendarGenerator::class)->periodFor(
                $this->entityId, Carbon::parse($month.'-15'),
            );

            app(PeriodStateMachine::class)->transition(
                $period, PeriodState::Open, 'opened for the reconciliation check',
            );

            $this->periods[$month] = $period;
        }

        $this->journalId = (string) DB::connection('tenant')->table('journals')
            ->where('entity_id', $this->entityId)->where('code', 'GEN')->value('id');

        $this->receivableId = (string) AccountRole::query()
            ->where('entity_id', $this->entityId)->where('role', 'accounts_receivable')->value('account_id');

        $this->outputId = (string) AccountRole::query()
            ->where('entity_id', $this->entityId)->where('role', 'vat_output')
            ->where('jurisdiction', 'SA')->value('account_id');

        $this->revenueId = (string) Account::query()
            ->where('entity_id', $this->entityId)->where('type', 'revenue')
            ->where('postable', true)->orderBy('code')->value('id');
    }

    /**
     * @param  array<string, callable(): list<string>>  $rules
     * @return array<string, list<string>>
     */
    private function runEach(array $rules): array
    {
        $results = [];

        foreach ($rules as $name => $rule) {
            $savepoint = 'recr_'.bin2hex(random_bytes(4));
            $this->connection?->statement('SAVEPOINT '.$savepoint);

            try {
                foreach ($this->periods as $month => $period) {
                    $this->periods[$month] = $period->fresh() ?? $period;
                }

                $results[$name] = $rule();
            } catch (Throwable $e) {
                $results[$name] = ['the check could not run: '.$e->getMessage()];
            } finally {
                $this->connection?->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        return $results;
    }
}
