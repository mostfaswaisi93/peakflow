<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use Brick\Math\BigDecimal;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Enums\PeriodState;
use App\Tenant\Ledger\Exceptions\EntryDoesNotBalance;
use App\Tenant\Ledger\Exceptions\PeriodTransitionRefused;
use App\Tenant\Ledger\Exceptions\YearEndCloseRefused;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\PostingRuleRegistry;
use App\Tenant\Ledger\Posting\RuleDescription;
use App\Tenant\Ledger\Services\BalanceRebuilder;
use App\Tenant\Ledger\Services\ChartTemplate;
use App\Support\Audit\AuditChain;
use App\Tenant\Identity\Services\StepUpGuard;
use App\Support\Authorization\RequiresStepUp;
use App\Tenant\Identity\Services\MfaService;
use App\Support\Mfa\Totp;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Ledger\Services\LedgerFinding;
use App\Tenant\Ledger\Services\LedgerVerifier;
use App\Tenant\Ledger\Services\OpeningBalances;
use App\Tenant\Ledger\Services\PeriodStateMachine;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Ledger\Services\Reverser;
use App\Tenant\Ledger\Services\TrialBalance;
use App\Tenant\Ledger\Services\YearEndClose;
use App\Tenant\Ledger\Services\AdjustingEntries;
use App\Tenant\Ledger\Services\FiscalCalendarGenerator;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the ledger actually refuses - PF-043, PF-044, PF-045.
 *
 * ## Testing a DEFERRED constraint without committing
 *
 * The balanced-entry trigger fires at COMMIT, and these checks run inside a
 * transaction that is rolled back - so the trigger would never fire at all
 * and an unbalanced entry would appear to be accepted.
 *
 * `SET CONSTRAINTS ALL IMMEDIATE` is what resolves that: it forces every
 * deferred constraint to check at that moment. The check therefore exercises
 * the same trigger the same way a commit would, while still leaving nothing
 * behind.
 *
 * Without it this file would be a set of green checks proving nothing, which
 * is the worst possible outcome for the invariant the whole phase exists to
 * guarantee.
 */
final class LedgerEnforcementRules
{
    use \Tests\Support\Concerns\ProvesTheLedger;

    private ?Connection $connection = null;

    private string $entityId = '';

    /** @var array<string, string> account code => id */
    private array $accounts = [];

    private string $journalId = '';

    private string $periodId = '';

    private string $postingDate = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return ['ledger invariants (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');

        $this->connection->beginTransaction();

        try {
            $this->seed();

            return $this->withTheLedgerProof([
                // FIRST, because every check below is meaningless if a guard
                // is switched off - and they would all pass.
                'every ledger guard is enabled' => $this->guardsAreEnabled(),
                'the critical queries prune partitions and have their indexes' => $this->theCriticalQueriesPruneAndIndex(),
                'an entry must balance in the functional currency' => $this->entriesMustBalance(),
                'a header or control account refuses a manual posting' => $this->accountsRefuseWhatTheyShould(),
                'a closed period refuses a posting' => $this->closedPeriodsRefuse(),
                'a posted entry cannot be altered' => $this->postedEntriesAreImmutable(),
                'the chart opens with every required control role' => $this->chartIsUsable(),
                'a posting rule that does not balance is refused by name' => $this->badRulesFailByName(),
                'ledger:verify finds an entry someone unbalanced' => $this->verifyCatchesDamage(),
                'a reversed entry still counts in the trial balance' => $this->reversalsNetOut(),
                "a dimension from another entity cannot reach a line" => $this->dimensionsAreScoped(),
                'a required dimension is refused when absent' => $this->requiredDimensionsBite(),
                'every period transition is recorded, with a reason' => $this->transitionsAreRecorded(),
                'a reopening is flagged and reaches the compliance report' => $this->reopeningsAreVisible(),
                'a year closes once, reversibly, in a reserved journal' => $this->yearEndCloseHolds(),
                'the balance read model follows the journal, and drift is reported' => $this->balancesFollowTheJournal(),
                'a posted entry carries a fingerprint, and a divergence is found' => $this->fingerprintsAreChecked(),
                'the global proof includes the audit chain' => $this->theProofIncludesTheChain(),
                'opening balances are permissioned, reasoned and time-bounded' => $this->openingBalancesAreBounded(),
                'an adjustment lands in period 13, and the statement says so' => $this->adjustmentsAreDeclared(),
                'the balance model and the journal give the same statement' => $this->bothSidesAgree(),
                'a stolen session cannot reopen a closed period' => $this->reopeningNeedsAFreshFactor(),
            ], $this->entityId);
        } catch (Throwable $e) {
            return ['ledger invariants could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * PF-046: the dimension check that cannot be made any other way.
     *
     * ## Why this one matters more than it looks
     *
     * Posting entity A's cost centre onto entity B's line produces a report
     * where a department appears inside a company it is not part of. Both
     * ids are valid ULIDs, the line balances, the account is right, the
     * period is open - nothing else in the system has any reason to object.
     *
     * The only thing that can catch it is a check that compares the value's
     * entity against the line's, and it has to see across entities to do
     * that. The first version could not: `dimension_values` is entity-
     * scoped, so the policy hid the other company's row and the posting was
     * refused as "that value does not exist" - the control held and the
     * message sent the reader looking for the wrong thing.
     *
     * @return list<string>
     */
    public function dimensionsAreScoped(): array
    {
        $violations = [];

        $axisId = $this->axis(1, 'cost_centre_chk');
        $mine = $this->dimensionValue($axisId, 'MINE');

        /*
         | A second entity with its own axis on the same column.
         |
         | The same column number, because that is the realistic shape: two
         | companies in one group both put cost centres on dimension_1, and
         | a value from one looks exactly like a value from the other.
         */
        $context = app(EntityContext::class);
        $other = Entity::create([
            'code' => 'CHX'.random_int(100, 999),
            'legal_name' => 'Other entity',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $context->use($other);
        $theirAxis = $this->axis(1, 'cost_centre_chk', (string) $other->getKey());
        $theirs = $this->dimensionValue($theirAxis, 'THEIRS', (string) $other->getKey());
        $context->use($this->entityId);

        $refusal = $this->writeEntry(
            [['5.2', '100.0000'], ['1.1.1.1', '-100.0000']],
            dimensions: ['dimension_1' => $theirs],
        );

        if ($refusal === null) {
            $violations[] = "another entity's cost centre was accepted on this entity's journal line. Both ".
                'ids are valid, the entry balances and the account is right, so nothing else objects - and '.
                'the report shows a department inside a company it is not part of';
        /*
         | The needle is early in the message on purpose.
         |
         | `writeEntry` truncates a refusal at 240 characters, and the first
         | version of this check looked for a phrase past that point - so a
         | correct refusal was reported as the wrong reason.
         */
        } elseif (! str_contains($refusal, 'belonging to entity')) {
            $violations[] = 'a cross-entity dimension was refused for the wrong reason, which sends the '.
                'reader looking for a missing value rather than a misfiled one: '.$refusal;
        }

        // A value in the wrong column is the same class of mistake.
        $projectAxis = $this->axis(2, 'project_chk');
        $project = $this->dimensionValue($projectAxis, 'ALPHA');

        $wrongColumn = $this->writeEntry(
            [['5.2', '100.0000'], ['1.1.1.1', '-100.0000']],
            dimensions: ['dimension_1' => $project],
        );

        if ($wrongColumn === null) {
            $violations[] = 'a project value was accepted in the cost-centre column. Column and axis are the '.
                'same statement, so this files a project under cost centres and both reports are wrong';
        }

        // And the valid combination must still post.
        $good = $this->writeEntry(
            [['5.2', '100.0000'], ['1.1.1.1', '-100.0000']],
            dimensions: ['dimension_1' => $mine, 'dimension_2' => $project],
        );

        if ($good !== null) {
            $violations[] = 'a line with valid dimensions on both axes was refused: '.$good;
        }

        return $violations;
    }

    /**
     * PF-046: an account that demands an axis gets one.
     *
     * What makes dimensional reporting trustworthy. A cost-centre report is
     * only complete if every line that should carry one does, and a rule
     * enforced in the posting service alone is a rule an import does not
     * know about - the symptom being a report short by whatever came in
     * through the import, found when a department queries their own numbers.
     *
     * @return list<string>
     */
    public function requiredDimensionsBite(): array
    {
        $violations = [];

        $axisId = $this->axis(3, 'department_chk');
        $value = $this->dimensionValue($axisId, 'FINANCE');

        $this->connection->table('account_dimension_requirements')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'account_id' => $this->accounts['5.2'],
            'dimension_axis_id' => $axisId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $without = $this->writeEntry([['5.2', '100.0000'], ['1.1.1.1', '-100.0000']]);

        if ($without === null) {
            $violations[] = 'an account that requires a department accepted a line without one, so a report '.
                'grouped by that axis is short by this line - and the shortfall is found when a department '.
                'queries their own numbers';
        }

        $with = $this->writeEntry(
            [['5.2', '100.0000'], ['1.1.1.1', '-100.0000']],
            dimensions: ['dimension_3' => $value],
        );

        if ($with !== null) {
            $violations[] = 'the same line WITH the required department was also refused: '.$with;
        }

        /*
         | And an account with no requirement is unaffected.
         |
         | A validator that demanded a dimension everywhere would be
         | switched off within a week, which is worse than not having one.
         */
        $unrelated = $this->writeEntry([['1.1.1.1', '100.0000'], ['4.1', '-100.0000']]);

        if ($unrelated !== null) {
            $violations[] = 'an account with no dimension requirement was refused for want of one: '.$unrelated;
        }

        return $violations;
    }

    /**
     * PF-050: a state column cannot answer the question that gets asked.
     *
     * `periods.state` says what a period is now. The question is "who
     * reopened February, when, and what did they say" - the first question
     * in any review of a restated figure - and `closed_at` answers it for
     * the most recent close and loses it on the next one.
     *
     * So the state cannot move without a recorded transition, enforced by a
     * trigger. Which caught three check suites and nine probes opening
     * periods with a bare update, all of which had stopped exercising the
     * state machine's own rules.
     *
     * @return list<string>
     */
    public function transitionsAreRecorded(): array
    {
        $machine = app(PeriodStateMachine::class);
        $violations = [];

        $bare = $this->refusePeriodWrite(['state' => PeriodState::SoftClosed->value]);

        if ($bare === null) {
            $violations[] = 'a period state was changed by a bare UPDATE, so a close or a reopening through '.
                'a console session or a support script leaves nothing to say who did it - and that is '.
                'exactly the change somebody will want explained later';
        }

        // Through the machine, it moves and it is recorded.
        $machine->transition($this->period(), PeriodState::SoftClosed, 'month-end review under way');

        $history = $machine->history($this->periodId);
        $last = $history === [] ? null : $history[count($history) - 1];

        if ($last === null || $last['to'] !== 'soft_closed') {
            $violations[] = 'a transition through the state machine left no history row';
        } elseif ($last['reason'] !== 'month-end review under way') {
            $violations[] = 'the transition history does not record the reason that was given';
        }

        /*
         | A transition the state machine forbids.
         |
         | `soft_closed -> locked` skips `closed`, and locking is terminal:
         | a period locked straight from a soft close has never been through
         | the state in which the figures were finalised.
         */
        try {
            $machine->transition($this->period(), PeriodState::Locked, 'filed');

            $violations[] = 'a period went from soft_closed straight to locked, skipping closed - so it was '.
                'locked irreversibly without ever having been in the state where its figures were finalised';
        } catch (PeriodTransitionRefused) {
            // Correct.
        }

        $machine->transition($this->period(), PeriodState::Closed, 'month-end figures signed off');

        /*
         | And locked is terminal, in the database.
         |
         | Checked on a DIFFERENT period, because locking this one would
         | leave every check after it unable to post - and the state is
         | irreversible by design, so there is no way back.
         */
        $other = $this->secondPeriod();

        if ($other !== null) {
            $machine->transition($other, PeriodState::Closed, 'closed to exercise the lock');
            $machine->transition($other, PeriodState::Locked, 'statutory return filed');

            try {
                $machine->transition($other->refresh(), PeriodState::Open, 'we changed our minds about the filing');

                $violations[] = 'a locked period was reopened. It was locked after a statutory filing, and a '.
                    'figure in a filed return is corrected by an amendment in a later period - not by '.
                    'editing the period it was filed from';
            } catch (PeriodTransitionRefused) {
                // Correct.
            }

            // And not by a bare update either, which is where the guarantee lives.
            $direct = $this->refusePeriodWrite(['state' => 'open'], (string) $other->getKey());

            if ($direct === null) {
                $violations[] = 'a locked period was reopened by a direct UPDATE, so the terminal state is '.
                    'an application convention rather than a guarantee';
            }
        }

        // Leave this period usable for the checks that follow.
        app(PeriodStateMachine::class)->transition(
            $this->period(),
            PeriodState::Open,
            'reopened by the enforcement checks; the checks that follow need a period that accepts postings',
        );

        return $violations;
    }

    /**
     * PF-050: a reopening is flagged, not derived.
     *
     * `closed -> open` is a reopening and `future -> open` is a period
     * starting. Deriving the distinction means every reader of the table
     * reimplements it, and one of them gets it wrong in the direction that
     * hides a reopening from the compliance report.
     *
     * @return list<string>
     */
    public function reopeningsAreVisible(): array
    {
        $machine = app(PeriodStateMachine::class);
        $violations = [];

        $reason = 'the auditor found a misposted accrual of 14,200 and the correction belongs in February';

        $machine->transition($this->period(), PeriodState::Closed, 'month closed');
        $machine->transition($this->period(), PeriodState::Open, $reason);

        $reopenings = $machine->reopenings($this->entityId);

        if ($reopenings === []) {
            $violations[] = 'a reopened period does not appear in the reopenings report. A reopening means a '.
                'reported figure may have changed after it was reported, which is a disclosure question '.
                'under IAS 10 rather than an operational one';
        } else {
            $found = false;

            foreach ($reopenings as $row) {
                if ($row['reason'] === $reason) {
                    $found = true;
                }
            }

            if (! $found) {
                $violations[] = 'the reopenings report does not carry the reason that was given, which is '.
                    'the only part of it an auditor can act on';
            }
        }

        /*
         | A period STARTING is not a reopening.
         |
         | `future -> open` happens to every period and would swamp the
         | report - which is how a report with a real signal in it stops
         | being read.
         */
        $starting = $this->secondPeriod('future');

        if ($starting !== null) {
            $before = count($machine->reopenings($this->entityId));
            $machine->transition($starting, PeriodState::Open, 'the month began');

            if (count($machine->reopenings($this->entityId)) !== $before) {
                $violations[] = 'a period simply starting was counted as a reopening, which swamps the '.
                    'report with every month that has ever begun';
            }
        }

        // A reopening with a one-word reason is refused.
        $machine->transition($this->period(), PeriodState::Closed, 'closed again');

        try {
            $machine->transition($this->period(), PeriodState::Open, 'fix');

            $violations[] = 'a period was reopened with a one-word reason. The review of a restated figure '.
                'happens because something went wrong, with nothing to go on but what somebody typed';
        } catch (PeriodTransitionRefused) {
            // Correct.
        }

        $machine->transition(
            $this->period(),
            PeriodState::Open,
            'reopened by the enforcement checks; the checks that follow need a period that accepts postings',
        );

        return $violations;
    }

    /**
     * PF-052: a year closes once, and the close is an ordinary entry.
     *
     * ## A second close REFUSES; it does not recompute
     *
     * Recomputing looks idempotent and is not. The first close moved the
     * revenue accounts to zero, so a recomputation either sees nothing and
     * posts nothing, or deliberately excludes the close's own entries and
     * posts the same profit again. Both are wrong and one is silent.
     *
     * ## Two bugs this check found
     *
     * The close reopened the adjusting period while the poster resolved
     * December from the entry's date, so it reopened one period and wrote
     * into another - which was closed.
     *
     * And after a reversal the year still read as closed, because the
     * reverser copies `source_type` and `source_id` onto the reversal - so
     * "already closed by CLS-2026/00002" named the reversal of 00001, and a
     * re-close was impossible.
     *
     * @return list<string>
     */
    public function yearEndCloseHolds(): array
    {
        $closer = app(YearEndClose::class);
        $machine = app(PeriodStateMachine::class);
        $violations = [];

        $year = \App\Tenant\Ledger\Models\FiscalYear::query()
            ->where('entity_id', $this->entityId)
            ->orderBy('starts_on')
            ->first();

        if ($year === null) {
            return ['the entity has no fiscal year, so there is nothing to close'];
        }

        /*
         | Revenue 120,000 and cost of sales 45,000: a result of 75,000.
         |
         | `5.1` and not `5.2`, because the dimension check above gives
         | `5.2` a required axis - and every check in this file shares one
         | transaction, so they share the database. Pointing both at the
         | same account made this one fail for want of a department, which
         | is the third time that lesson has come up and the reason each
         | check now owns its accounts.
         */
        $this->writeEntry([['4.1', '-120000.0000'], ['1.1.1.1', '120000.0000']]);
        $this->writeEntry([['5.2', '45000.0000'], ['1.1.1.1', '-45000.0000']]);

        /*
         | An open period blocks the close, and that is the criterion that
         | matters most.
         |
         | A year closed while December still takes postings means a
         | posting can land after the closing entry and never be carried
         | forward - the year's revenue understated, retained earnings short
         | by exactly that, and no total anywhere disagreeing with another.
         */
        try {
            $closer->close($this->entity(), $year);

            $violations[] = 'a year was closed while a period still accepted postings. A posting made after '.
                'the closing entry would never be carried forward, and no total anywhere would disagree '.
                'with another';
        } catch (YearEndCloseRefused) {
            // Correct.
        }

        foreach (
            \App\Tenant\Ledger\Models\Period::query()
                ->where('entity_id', $this->entityId)
                ->where('fiscal_year_id', $year->getKey())
                ->orderBy('ordinal')
                ->get() as $period
        ) {
            if ($period->state === PeriodState::Future) {
                $machine->transition($period, PeriodState::Open, 'opened so the year can be closed in order');
            }

            $machine->transition($period->refresh(), PeriodState::Closed, 'month-end figures signed off');
        }

        /*
         | The expectation is COMPUTED, not assumed.
         |
         | The first version asserted exactly 75,000 - the revenue and cost
         | of sales this check posts - and got 77,080, because every other
         | check in this file posts to revenue and expense accounts too and
         | they all share one transaction. The close zeroes the whole year,
         | so it carries all of it.
         |
         | Reading the net first is also a stronger assertion: it proves the
         | close carries the net of whatever is there, rather than a figure
         | somebody happened to know.
         */
        $expected = (string) BigDecimal::of((string) $this->connection->selectOne(<<<'SQL'
            SELECT coalesce(sum(l.functional_amount), 0) AS net
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN accounts a ON a.id = l.account_id
            WHERE l.entity_id = ?
              AND e.status IN ('posted', 'reversed')
              AND a.type IN ('revenue', 'expense')
        SQL, [$this->entityId])->net);

        $result = $closer->close($this->entity(), $year);

        if ($result['entry'] === null) {
            return ['the close posted no entry at all'];
        }

        $journalCode = (string) $this->connection->table('journals')
            ->where('id', $result['entry']->journal_id)
            ->value('code');

        if ($journalCode !== 'CLS') {
            $violations[] = sprintf(
                'the closing entry went into the %s journal rather than the reserved closing one. A close '.
                'that is an ordinary entry anywhere is a close somebody can type by hand',
                $journalCode,
            );
        }

        $retained = (string) $this->connection->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', 'retained_earnings')
            ->value('account_id');

        $carried = (string) $this->connection->table('journal_lines')
            ->where('journal_entry_id', $result['entry']->getKey())
            ->where('account_id', $retained)
            ->value('functional_amount');

        if (! BigDecimal::of($carried)->isEqualTo(BigDecimal::of($expected))) {
            $violations[] = sprintf(
                "the close carried %s to retained earnings rather than the year's net of %s. Retained ".
                "earnings takes the opposite of the revenue and expense accounts' own signed sum",
                $carried,
                $expected,
            );
        }

        // A second close refuses, and posts nothing.
        $before = $this->connection->table('journal_entries')
            ->where('entity_id', $this->entityId)
            ->where('source_type', 'year_end_close')
            ->count();

        try {
            $closer->close($this->entity(), $year);

            $violations[] = 'a year was closed twice, posting the same profit to retained earnings again';
        } catch (YearEndCloseRefused $e) {
            if (! str_contains($e->getMessage(), 'twice')) {
                $violations[] = 'the second close was refused without saying what would have gone wrong';
            }
        }

        $after = $this->connection->table('journal_entries')
            ->where('entity_id', $this->entityId)
            ->where('source_type', 'year_end_close')
            ->count();

        if ($after !== $before) {
            $violations[] = 'the refused second close still wrote an entry';
        }

        /*
         | And it is reversible - which is the whole reason it is an
         | ordinary entry rather than a flag.
         |
         | A close run against a year with a misposted accrual has to come
         | back out, and reversing a journal entry is a mechanism that
         | already exists and is already audited.
         */
        $closer->reopen(
            $this->entity(),
            $year,
            'the December accrual was misposted and the year has to be re-closed after the correction',
        );

        $reclosed = $closer->close($this->entity(), $year);

        $recarried = (string) $this->connection->table('journal_lines')
            ->where('journal_entry_id', $reclosed['entry']?->getKey())
            ->where('account_id', $retained)
            ->value('functional_amount');

        if (! BigDecimal::of($recarried)->isEqualTo(BigDecimal::of($expected))) {
            $violations[] = sprintf(
                're-closing after a reversal carried %s rather than the same %s. Reading its own entries as '.
                'activity would double it; reading nothing would give zero - the close has to see past the '.
                'closing journal',
                $recarried,
                $expected,
            );
        }

        /*
         | Nothing else may post into the reserved journal.
         |
         | `reserved` was a comment until now: any rule could post into the
         | opening-balances journal, and an opening balance typed by hand is
         | indistinguishable from the real one.
         */
        $byHand = $this->refusal(function () use ($year): void {
            $this->connection->table('journal_entries')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'journal_id' => $this->connection->table('journals')
                    ->where('entity_id', $this->entityId)
                    ->where('code', 'CLS')
                    ->value('id'),
                'period_id' => $this->periodId,
                'number' => 'HAND-'.Str::random(6),
                'accounting_date' => (string) $year->ends_on,
                'entered_at' => now(),
                'status' => 'draft',
                'posting_rule' => 'check.by_hand',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        if ($byHand === null) {
            $violations[] = 'an arbitrary rule posted into the reserved closing journal. A closing entry '.
                'typed by hand is indistinguishable from the real one, and is found a year later by whoever '.
                "compares this year's opening to last year's closing";
        }

        /*
         | Leave a period open for the checks that follow.
         |
         | This check closes every period of the year in order to close the
         | year - and the next check posts. Without this it was refused
         | silently, and the next check reported "the read model did not
         | move" rather than "the posting was refused", which sent the
         | reading in entirely the wrong direction.
         */
        $machine->transition(
            $this->period(),
            PeriodState::Open,
            'reopened after the year-end checks; the checks that follow need a period that accepts postings',
        );

        return $violations;
    }

    /**
     * PF-053: a read model is a cache, and a cache is a second source of truth.
     *
     * ## Three claims, each of which fails differently
     *
     * **The increments run in the database.** A read model maintained by one
     * write path drifts the first time somebody uses another - an import, a
     * correction script, a restore - and the drift is found by whoever
     * compares a report to the journal.
     *
     * **A draft has not happened.** Counting one overstates every figure
     * between entering a manual journal and posting it, which is exactly the
     * window somebody looks at a screen in.
     *
     * **Drift is reported, never repaired.** A silent repair hides the bug
     * that caused it, and the bug is what matters: a figure that was wrong
     * for a week was on a report for a week, and the repair erases the only
     * evidence of how long.
     *
     * @return list<string>
     */
    public function balancesFollowTheJournal(): array
    {
        $rebuilder = app(BalanceRebuilder::class);
        $violations = [];

        /*
         | Everything the other checks posted is already in the read model.
         |
         | So the baseline is "it agrees now", which is a stronger starting
         | point than an empty table - it means the increments have survived
         | every posting, reversal and refusal this file has performed.
         */
        $baseline = $rebuilder->check($this->entityId);

        if (! $baseline['clean']) {
            $violations[] = sprintf(
                'the read model already disagrees with the journal after the postings this file made: %s',
                json_encode(array_slice($baseline['findings'], 0, 3), JSON_THROW_ON_ERROR),
            );
        }

        /*
         | A line written WITHOUT the posting service.
         |
         | The import path. If the increments lived in the service this
         | would drift, and nothing would say so until a report disagreed
         | with the journal.
         */
        $entryId = (string) Str::ulid();
        $before = (string) ($this->connection->table('account_balances')
            ->where('entity_id', $this->entityId)
            ->where('account_id', $this->accounts['1.1.1.1'])
            ->whereNull('dimension_1')
            ->value('net') ?? '0');

        /*
         | The refusal is captured, not ignored.
         |
         | The first version threw the return value away, so when an earlier
         | check left every period closed this one reported "the read model
         | did not move" - true, and the cause was that nothing was written.
         | A check that cannot tell those apart sends the reader to the
         | wrong place.
         */
        $written = $this->writeEntry([['1.1.1.1', '42.0000'], ['4.1', '-42.0000']], entryId: $entryId);

        if ($written !== null) {
            return ['the check could not post a line to measure the read model with: '.$written];
        }

        $after = (string) ($this->connection->table('account_balances')
            ->where('entity_id', $this->entityId)
            ->where('account_id', $this->accounts['1.1.1.1'])
            ->whereNull('dimension_1')
            ->value('net') ?? '0');

        if (! BigDecimal::of($after)->isEqualTo(BigDecimal::of($before)->plus('42.0000'))) {
            $violations[] = sprintf(
                'a line written without the posting service did not move the read model (%s then %s). An '.
                'import, a correction script and a restore all write lines that way, and a cache only one '.
                'write path maintains is a cache that drifts',
                $before,
                $after,
            );
        }

        if (! $rebuilder->check($this->entityId)['clean']) {
            $violations[] = 'the read model disagrees with the journal after a direct line insert';
        }

        /*
         | Drift, introduced and then looked for.
         |
         | One hundredth, because that is the size that matters: it is too
         | small to notice on a screen and large enough to be a real
         | difference in a filed return.
         */
        $this->connection->table('account_balances')
            ->where('entity_id', $this->entityId)
            ->where('account_id', $this->accounts['1.1.1.1'])
            ->whereNull('dimension_1')
            ->update(['net' => BigDecimal::of($after)->plus('0.01')]);

        $drifted = $rebuilder->check($this->entityId);

        if ($drifted['clean']) {
            $violations[] = 'a balance one hundredth out of step with the journal was not detected. That is '.
                'the size that matters: too small to notice on a screen, large enough to be a real '.
                'difference in a filed return';
        }

        $wrong = array_values(array_filter(
            $drifted['findings'],
            static fn (array $finding): bool => $finding['kind'] === 'wrong',
        ));

        if ($wrong === []) {
            $violations[] = 'the drift was reported as something other than "wrong", so the three kinds - a '.
                'missing increment, a doubled one and an arithmetic error - cannot be told apart';
        }

        /*
         | And it must NOT have been repaired.
         |
         | Defaulted, because the row can be absent - which is what
         | happens when the maintenance trigger is disabled, and the first
         | version of this line then threw "the number must not be empty"
         | and took the whole suite down instead of reporting a finding. A
         | check that explodes when the thing it guards is removed proves
         | nothing about the guard.
         */
        $stillDrifted = (string) ($this->connection->table('account_balances')
            ->where('entity_id', $this->entityId)
            ->where('account_id', $this->accounts['1.1.1.1'])
            ->whereNull('dimension_1')
            ->value('net') ?? '0');

        if (BigDecimal::of($stillDrifted)->isEqualTo(BigDecimal::of($after))) {
            $violations[] = 'the check repaired the drift. A silent repair hides the bug that caused it, and '.
                'the bug is what matters - a figure that was wrong for a week was on a report for a week, '.
                'and the repair erases the only evidence of how long';
        }

        if (! $this->connection->table('balance_drift_findings')->where('entity_id', $this->entityId)->exists()) {
            $violations[] = 'the drift was not recorded, so "which account, in which period, by how much, '.
                'and since when" cannot be answered';
        }

        // The rebuild is the specification, and it clears an extra row too.
        $this->connection->table('account_balances')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'period_id' => $this->periodId,
            'account_id' => $this->accounts['4.1'],
            'dimension_key' => 'CHKGHOST|-|-|-|-|-|-|-',
            'net' => '5.0000',
            'debits' => '5.0000',
            'credits' => '0.0000',
            'line_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $withExtra = $rebuilder->check($this->entityId);

        $extras = array_filter(
            $withExtra['findings'],
            static fn (array $finding): bool => $finding['kind'] === 'extra',
        );

        if ($extras === []) {
            $violations[] = 'a balance row the journal does not justify was not reported as extra - which is '.
                'what a delta applied twice looks like';
        }

        $rebuilder->rebuild($this->entityId);

        if (! $rebuilder->check($this->entityId)['clean']) {
            $violations[] = 'a rebuild did not restore agreement with the journal, which is the one thing it '.
                'is for - and it is also the definition of what the increments should have produced';
        }

        if ($this->connection->table('account_balances')
            ->where('dimension_key', 'CHKGHOST|-|-|-|-|-|-|-')
            ->exists()) {
            $violations[] = 'the rebuild left behind a row the journal does not justify. An upsert cannot '.
                'clear one, which is why the rebuild deletes first';
        }

        return $violations;
    }

    /** The entity under check, as a model. */
    private function entity(): Entity
    {
        /** @var Entity $entity */
        $entity = Entity::query()->findOrFail($this->entityId);

        return $entity;
    }

    /**
     * A write expected to be refused, in its own savepoint.
     *
     * A refused statement aborts the whole transaction, not just itself.
     */
    private function refusal(callable $write): ?string
    {
        $savepoint = 'refuse_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            $write();
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200);
        }
    }

    /**
     * A write to `periods` expected to be refused, in its own savepoint.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function refusePeriodWrite(array $attributes, ?string $periodId = null): ?string
    {
        $savepoint = 'period_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            $this->connection->table('periods')
                ->where('id', $periodId ?? $this->periodId)
                ->update($attributes);

            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200);
        }
    }

    /**
     * Another period of the same entity, for the transitions that are
     * irreversible or that would stop the checks that follow from posting.
     */
    private function secondPeriod(string $state = 'open'): ?\App\Tenant\Ledger\Models\Period
    {
        /** @var ?\App\Tenant\Ledger\Models\Period $period */
        $period = \App\Tenant\Ledger\Models\Period::query()
            ->where('entity_id', $this->entityId)
            ->where('id', '<>', $this->periodId)
            ->where('state', $state)
            ->where('is_adjusting', false)
            ->first();

        return $period;
    }

    /** The check's period, freshly read - the machine takes the model. */
    private function period(): \App\Tenant\Ledger\Models\Period
    {
        /** @var \App\Tenant\Ledger\Models\Period $period */
        $period = \App\Tenant\Ledger\Models\Period::query()->findOrFail($this->periodId);

        return $period;
    }

    /** An axis for the checks, on a given column. */
    private function axis(int $number, string $key, ?string $entityId = null): string
    {
        $id = (string) Str::ulid();

        $this->connection->table('dimension_axes')->insert([
            'id' => $id,
            'entity_id' => $entityId ?? $this->entityId,
            'axis' => $number,
            'key' => $key,
            'name_ar' => $key,
            'name_en' => ucfirst(str_replace('_', ' ', $key)),
            'leaf_only' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function dimensionValue(string $axisId, string $code, ?string $entityId = null): string
    {
        $id = (string) Str::ulid();

        $this->connection->table('dimension_values')->insert([
            'id' => $id,
            'entity_id' => $entityId ?? $this->entityId,
            'dimension_axis_id' => $axisId,
            'code' => $code,
            'name_ar' => $code,
            'name_en' => $code,
            'path' => $code,
            'depth' => 0,
            'postable' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return list<string> */
    public function entriesMustBalance(): array
    {
        $violations = [];

        // Balanced: must survive the immediate check.
        $balanced = $this->writeEntry([['1.1.1.1', '1000.0000'], ['4.1', '-1000.0000']]);

        if ($balanced !== null) {
            $violations[] = 'a balanced entry was refused: '.$balanced;
        }

        // Unbalanced: must not.
        $unbalanced = $this->writeEntry([['1.1.1.1', '1000.0000'], ['4.1', '-999.0000']]);

        if ($unbalanced === null) {
            $violations[] = 'an entry whose lines sum to 1.0000 was ACCEPTED; the ledger does not balance';
        } elseif (! str_contains($unbalanced, 'does not balance')) {
            $violations[] = 'an unbalanced entry was refused for the wrong reason: '.$unbalanced;
        }

        /*
         | A multi-line entry is transiently unbalanced after every line but
         | the last. If this fails, the trigger has been changed to fire per
         | statement - which rejects every real posting while still passing a
         | test that writes a balanced entry in one go.
         */
        $multi = $this->writeEntry([
            ['1.1.1.1', '250.0000'], ['1.1.1.1', '250.0000'],
            ['1.1.1.1', '250.0000'], ['1.1.1.1', '250.0000'],
            ['4.1', '-1000.0000'],
        ]);

        if ($multi !== null) {
            $violations[] = 'a five-line balanced entry was refused, so the balance check is no longer '.
                'deferred to commit: '.$multi;
        }

        return $violations;
    }

    /** @return list<string> */
    public function accountsRefuseWhatTheyShould(): array
    {
        $violations = [];

        // '1' is the Assets header - a total, not a place to post.
        $header = $this->writeEntry([['1', '100.0000'], ['4.1', '-100.0000']]);

        if ($header === null || ! str_contains($header, 'is a header')) {
            $violations[] = 'a posting to a header account was accepted, so it is invisible in every '.
                'breakdown of the total it belongs to';
        }

        // '1.1.1.2' is the bank control account.
        $control = $this->writeEntry([['1.1.1.2', '100.0000'], ['4.1', '-100.0000']]);

        if ($control === null || ! str_contains($control, 'control account')) {
            $violations[] = 'a manual posting to a control account was accepted; once a control account and '.
                'its subledger disagree, neither can be shown to be the correct one';
        }

        // A registered posting rule may.
        $byRule = $this->writeEntry([['1.1.1.2', '100.0000'], ['4.1', '-100.0000']], rule: 'probe.rule');

        if ($byRule !== null) {
            $violations[] = 'a posting rule was refused a control account, which leaves nothing able to '.
                'post a receivable: '.$byRule;
        }

        return $violations;
    }

    /** @return list<string> */
    public function closedPeriodsRefuse(): array
    {
        $violations = [];

        /*
         | Through the state machine, and in the order the machine allows.
         |
         | `open -> soft_closed -> closed` rather than straight to each,
         | because the transitions are the states' own rules and a check
         | that sets a column directly is a check that stopped exercising
         | them - which is also what the recorded-transition guard caught
         | the first time this ran.
         */
        foreach ([PeriodState::SoftClosed, PeriodState::Closed] as $state) {
            app(PeriodStateMachine::class)->transition(
                $this->period(),
                $state,
                'closed for the ledger enforcement checks',
            );

            $refusal = $this->writeEntry([['1.1.1.1', '10.0000'], ['4.1', '-10.0000']]);

            if ($refusal === null || ! str_contains($refusal, 'does not accept this posting')) {
                $violations[] = sprintf('a %s period accepted an ordinary posting', $state->value);
            }
        }

        // Soft-closed still takes adjustments - that is what the state is for.
        app(PeriodStateMachine::class)->transition(
            $this->period(),
            PeriodState::Open,
            'reopened by the enforcement checks to exercise the soft-closed adjustment path',
        );

        app(PeriodStateMachine::class)->transition(
            $this->period(),
            PeriodState::SoftClosed,
            'soft closed for the adjustment check',
        );

        $adjusting = $this->writeEntry([['1.1.1.1', '10.0000'], ['4.1', '-10.0000']], rule: 'adjusting');

        if ($adjusting !== null) {
            $violations[] = 'a soft-closed period refused an adjusting posting, so the auditor\'s '.
                'adjustments have nowhere to land: '.$adjusting;
        }

        /*
         | Left open for the checks that follow.
         |
         | Through the machine, because every other way is refused - and
         | the recorded reason is what tells a reader of the transition
         | history why this period went closed and open three times in one
         | second, which is otherwise the shape of something alarming.
         */
        app(PeriodStateMachine::class)->transition(
            $this->period(),
            PeriodState::Open,
            'reopened by the enforcement checks; the checks that follow need a period that accepts postings',
        );

        return $violations;
    }

    /** @return list<string> */
    public function postedEntriesAreImmutable(): array
    {
        $violations = [];
        $entryId = (string) Str::ulid();

        $written = $this->writeEntry([['1.1.1.1', '50.0000'], ['4.1', '-50.0000']], entryId: $entryId);

        if ($written !== null) {
            return ['could not write a posted entry to test immutability: '.$written];
        }

        /*
         | Both tables, and the LINES are the half that was missing.
         |
         | `refuse_posted_mutation()` is attached to `journal_lines` as well
         | as to `journal_entries`, and this check only ever tried the
         | header - so the branch that looks the parent entry up by
         | `OLD.journal_entry_id` was never exercised. Editing one line of a
         | posted entry is also the more realistic tamper: it moves a figure
         | and leaves the entry's own row untouched.
         */
        $line = $this->connection->table('journal_lines')
            ->where('journal_entry_id', $entryId)
            ->first();

        if ($line === null) {
            return ['the posted entry has no lines, so the journal_lines half of the trigger cannot '.
                'be exercised and this check covers only the header'];
        }

        /*
         | WHICH layer answers is part of the assertion.
         |
         | There are two, and they are not interchangeable. DELETE is
         | revoked for the application role, so the privilege refuses before
         | any trigger runs and the message is `42501 permission denied` -
         | opaque, and the right trade: granting DELETE so the trigger could
         | produce a nicer sentence would swap a guarantee for a message.
         | UPDATE is permitted at the privilege level and refused by the
         | trigger, which is the layer that can explain itself.
         |
         | Asserting per operation is what makes this check able to fail in
         | the interesting direction. "Something threw" is satisfied by a
         | privilege that was revoked by accident, by a trigger raising the
         | wrong error, and by a typo in the SQL - all of which leave the row
         | safe and the guard untested.
         */
        foreach ([
            'UPDATE an entry' => ['trigger', fn () => $this->connection->statement(
                "UPDATE journal_entries SET narration = 'tampered' WHERE id = ?",
                [$entryId],
            )],
            'DELETE an entry' => ['privilege', fn () => $this->connection->statement(
                'DELETE FROM journal_entries WHERE id = ?',
                [$entryId],
            )],
            'UPDATE a line' => ['trigger', fn () => $this->connection->statement(
                'UPDATE journal_lines SET functional_amount = functional_amount + 1 '.
                'WHERE id = ? AND accounting_date = ?',
                [(string) $line->id, (string) $line->accounting_date],
            )],
            'DELETE a line' => ['privilege', fn () => $this->connection->statement(
                'DELETE FROM journal_lines WHERE id = ? AND accounting_date = ?',
                [(string) $line->id, (string) $line->accounting_date],
            )],
        ] as $operation => [$layer, $attempt]) {
            $savepoint = 'immutable_'.bin2hex(random_bytes(4));
            $this->connection->statement('SAVEPOINT '.$savepoint);

            try {
                $attempt();

                $violations[] = sprintf(
                    'a posted entry survived "%s" by raw SQL; a correction must be a reversal, or the '.
                    'trial balance stops reconciling to the statements printed from it',
                    $operation,
                );
            } catch (Throwable $e) {
                /*
                 | And the refusal has to SAY SO.
                 |
                 | This used to catch `Throwable` and discard it, with the
                 | comment "refused, by the trigger or by the privilege -
                 | both are right". Both are right about whether the row
                 | survived and neither is right about what the operator
                 | reads. A guard can refuse correctly and still fail at
                 | the only moment it is seen: an error about a missing
                 | column sends whoever hit it to look for a bug in the
                 | schema, and the thing they actually did - tried to edit
                 | a posted entry - is nowhere in the sentence.
                 |
                 | It is also how a trigger with a genuine fault hides.
                 | `OLD.status` evaluated on a `journal_lines` row would
                 | raise 42703, the row would still not change, and a check
                 | that asks only "did something throw" would stay green.
                 */
                $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';

                /*
                 | No early `continue` from here.
                 |
                 | The savepoint is rolled back at the END of this loop body,
                 | and a `continue` skips it - which leaves the transaction
                 | aborted and every later check in this suite failing with
                 | "current transaction is aborted". Written that way first,
                 | and the suite reported 0 passed and 1 failed, which is at
                 | least an unmissable way to find out.
                 */
                if ($layer === 'privilege' && ! str_contains($message, 'permission denied')) {
                    $violations[] = sprintf(
                        '"%s" should be refused by the revoked privilege, before any trigger runs, and '.
                        'was refused by something else: "%s". If the privilege has been granted back, '.
                        'the only thing standing between a posted entry and a DELETE is a trigger - '.
                        'and a trigger can be disabled by whoever owns the table.',
                        $operation,
                        Str::limit($message, 180),
                    );
                } elseif ($layer === 'trigger'
                    && (! str_contains($message, 'posted') || ! str_contains($message, 'reversal'))) {
                    $violations[] = sprintf(
                        '"%s" was refused, correctly, by a message that does not say the entry is '.
                        'posted or that the correction is a reversal: "%s". The row is safe and the '.
                        'person who hit this is now reading about something else - which is how a '.
                        'trigger with a real fault hides, because the row not changing looks the same '.
                        'either way.',
                        $operation,
                        Str::limit($message, 180),
                    );
                }
            }

            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }

        return $violations;
    }

    /** @return list<string> */
    public function chartIsUsable(): array
    {
        $missing = app(ChartTemplate::class)->missingRequiredRoles($this->entityId);

        if ($missing === []) {
            return [];
        }

        return [sprintf(
            'a freshly seeded entity is missing %d required control role(s): %s. It cannot post at all, and '.
            'the first attempt fails with a message about a role rather than about the chart',
            count($missing),
            implode(', ', array_map(static fn ($role): string => $role->value, $missing)),
        )];
    }

    /**
     * The planner prunes partitions and reaches for the indexes - PF-243.
     *
     * ## Why `EXPLAIN` rather than a timing
     *
     * On a fixture, everything is fast. `journal_lines` here holds a few
     * hundred rows across 43 monthly partitions, so a sequential scan of one
     * of them costs nothing and a timing assertion would pass against a
     * query that reads the whole table on a real tenant.
     *
     * What `EXPLAIN` answers is structural and the same at any size: **which
     * relations the plan touches**. A date-bounded query over a
     * RANGE-partitioned table either prunes to the months it asked for or it
     * does not, and that answer does not depend on how many rows are in
     * them.
     *
     * ## The positive control is the whole check
     *
     * Asserting "the bounded query touches one partition" alone passes
     * against a table that is not partitioned at all, against a fixture with
     * one partition, and against a planner that pruned for some other
     * reason. So the unbounded form of the same query is planned too, and it
     * has to touch MANY - which is what makes the one a consequence of the
     * bound rather than of the data.
     *
     * ## Indexes are asserted with `enable_seqscan` off
     *
     * A planner on a small table correctly prefers a sequential scan, so
     * "the plan uses an index" is false here for the right reason. Turning
     * the preference off asks the question that actually matters - **is
     * there an index it COULD use** - because `enable_seqscan = off` is a
     * discouragement and not a prohibition: with no usable index the plan
     * stays sequential and the assertion fails.
     *
     * Inside a transaction that is rolled back, so the setting does not
     * escape into the checks that follow.
     *
     * @return list<string>
     */
    public function theCriticalQueriesPruneAndIndex(): array
    {
        $connection = DB::connection('tenant');

        $partitions = (int) $connection->selectOne(
            'SELECT count(*) AS c FROM pg_inherits i JOIN pg_class p ON p.oid = i.inhparent WHERE p.relname = ?',
            ['journal_lines'],
        )->c;

        if ($partitions < 3) {
            return [sprintf(
                'journal_lines has %d partition(s), so pruning cannot be demonstrated. The table is '.
                'RANGE partitioned on accounting_date and this check is about the planner using '.
                'that - with one partition there is nothing to prune to',
                $partitions,
            )];
        }

        $entityId = (string) $connection->table('entities')->orderBy('code')->value('id');
        $problems = [];

        $bounded = $this->partitionsIn($connection->select(
            'EXPLAIN SELECT sum(l.functional_amount) FROM journal_lines l '.
            'WHERE l.entity_id = ? AND l.accounting_date BETWEEN ? AND ?',
            [$entityId, '2026-05-01', '2026-05-31'],
        ));

        $unbounded = $this->partitionsIn($connection->select(
            'EXPLAIN SELECT sum(l.functional_amount) FROM journal_lines l WHERE l.entity_id = ?',
            [$entityId],
        ));

        if ($unbounded <= $bounded) {
            $problems[] = sprintf(
                'a query with no date bound plans %d partition(s) and a bounded one plans %d, so the '.
                'bound is changing nothing. Either the table is no longer partitioned on '.
                'accounting_date or the planner cannot use it - and on a tenant with years of '.
                'history that is every month read to answer about one',
                $unbounded,
                $bounded,
            );
        }

        if ($bounded > 2) {
            $problems[] = sprintf(
                'a query bounded to one month plans %d partitions out of %d. A month spans at most '.
                'two when the boundary falls inside one; more than that is the bound not reaching '.
                'the partition key',
                $bounded,
                $partitions,
            );
        }

        /*
         | And an index exists for each shape the product actually runs.
         |
         | The names are the ones the schema declares, so a dropped or
         | renamed index fails here rather than at the first slow month-end.
         */
        $expected = [
            'journal_lines' => ['journal_lines_trial_balance', 'journal_lines_account_detail'],
            'invoices' => ['invoices_entity_id_status_index', 'invoices_entity_id_party_id_index'],
            'bills' => ['bills_entity_id_status_index'],
        ];

        foreach ($expected as $table => $indexes) {
            $present = array_map(
                static fn (object $row): string => (string) $row->indexname,
                $connection->select('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]),
            );

            foreach ($indexes as $index) {
                if (! \in_array($index, $present, true)) {
                    $problems[] = sprintf(
                        'the index "%s" is gone from %s. It is what the %s reads through, and without '.
                        'it the query still answers - at a cost nothing in this suite would notice '.
                        'and every month-end would',
                        $index,
                        $table,
                        $table === 'journal_lines' ? 'trial balance and the GL detail' : 'register',
                    );
                }
            }
        }

        /*
         | The planner can actually reach one.
         |
         | Rolled back, because `enable_seqscan` is a session setting and a
         | check that left it off would make every later plan in this suite
         | a different plan.
         */
        $connection->beginTransaction();

        try {
            $connection->statement('SET LOCAL enable_seqscan = off');

            $plan = implode(' ', array_map(
                static fn (object $row): string => (string) current((array) $row),
                $connection->select(
                    'EXPLAIN SELECT l.account_id, sum(l.functional_amount) FROM journal_lines l '.
                    'WHERE l.entity_id = ? AND l.accounting_date BETWEEN ? AND ? GROUP BY l.account_id',
                    [$entityId, '2026-05-01', '2026-05-31'],
                ),
            ));

            if (! str_contains($plan, 'Index Scan') && ! str_contains($plan, 'Index Only Scan') && ! str_contains($plan, 'Bitmap')) {
                $problems[] = sprintf(
                    'with sequential scans discouraged, the trial-balance shape still plans without '.
                    'an index: %s. enable_seqscan = off is a discouragement and not a prohibition, '.
                    'so a plan that stays sequential under it is a plan with no index available',
                    Str::limit(preg_replace('/\s+/', ' ', $plan) ?? '', 160),
                );
            }
        } finally {
            $connection->rollBack();
        }

        return $problems;
    }

    /**
     * How many `journal_lines` partitions a plan names.
     *
     * @param  list<object>  $plan
     */
    private function partitionsIn(array $plan): int
    {
        $text = implode("\n", array_map(
            static fn (object $row): string => (string) current((array) $row),
            $plan,
        ));

        preg_match_all('/journal_lines_\d{4}_\d{2}/', $text, $matches);

        return \count(array_unique($matches[0]));
    }


    /**
     * Every trigger guarding the ledger is actually ENABLED.
     *
     * `ALTER TABLE ... DISABLE TRIGGER` is persistent DDL. A migration, a
     * data fix, or a test that disables a trigger and is then interrupted
     * leaves the table unguarded - and every check below passes, because
     * nothing refuses anything any more.
     *
     * That is not hypothetical. An earlier version of the damage-injection
     * check disabled these triggers to produce an unbalanced entry; the run
     * was killed between disabling and re-enabling, and the next run reported
     * that unbalanced entries, header-account postings and manual control
     * -account postings were all ACCEPTED. The ledger had no guards at all
     * and the suite would have gone on saying so in whichever direction was
     * asked.
     *
     * So this runs first, and the current damage check never touches a
     * trigger.
     *
     * @return list<string>
     */
    public function guardsAreEnabled(): array
    {
        $expected = [
            'journal_lines' => [
                'journal_lines_entry_balances',
                'journal_lines_follow_entry',
                'journal_lines_account_is_postable',
                'journal_lines_posted_immutable',
            ],
            'journal_entries' => [
                'journal_entries_period_accepts',
                'journal_entries_posted_immutable',
            ],
            'periods' => ['periods_locked_is_terminal'],
        ];

        $rows = $this->connection->select(<<<'SQL'
            SELECT c.relname AS table_name, t.tgname AS trigger_name, t.tgenabled::text AS enabled
            FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND NOT t.tgisinternal
              AND c.relname IN ('journal_lines', 'journal_entries', 'periods')
        SQL);

        $state = [];

        foreach ($rows as $row) {
            $state[$row->table_name][$row->trigger_name] = (string) $row->enabled;
        }

        $violations = [];

        foreach ($expected as $table => $triggers) {
            foreach ($triggers as $trigger) {
                $enabled = $state[$table][$trigger] ?? null;

                if ($enabled === null) {
                    $violations[] = sprintf('%s.%s does not exist, so nothing enforces what it guarded', $table, $trigger);

                    continue;
                }

                // 'O' is origin-enabled, which is what a working trigger is.
                // 'D' is disabled; 'R' and 'A' are replica-only states that
                // also mean it does not fire for ordinary writes.
                if ($enabled !== 'O') {
                    $violations[] = sprintf(
                        '%s.%s is in state "%s" rather than enabled - the ledger is accepting writes it should '.
                        'refuse, and every other check here would pass while it does',
                        $table,
                        $trigger,
                        $enabled,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * A rule whose lines do not sum to zero fails with ITS OWN name - PF-047.
     *
     * The database catches it at commit, truly and uselessly: the message
     * says the entry does not balance, not which of forty rules produced it.
     * The poster checks first so a developer is told where to look.
     *
     * @return list<string>
     */
    public function badRulesFailByName(): array
    {
        $registry = app(PostingRuleRegistry::class);

        $broken = new class implements PostingRule
        {
            public function key(): string
            {
                return 'enforcement.probe.unbalanced';
            }

            public function describe(): RuleDescription
            {
                return RuleDescription::of($this->key(), 'فحص', 'Probe', []);
            }

            public function lines(array $event, PostingContext $context): array
            {
                // One side only. Balances to 100, which is the bug.
                return [PostingLine::toAccount((string) $event['account'], $context->money('100.0000'))];
            }
        };

        $registry->register($broken);

        $entity = Entity::query()->whereKey($this->entityId)->firstOrFail();
        $violations = [];

        try {
            app(Poster::class)->post(
                $entity,
                $broken->key(),
                ['account' => $this->accounts['1.1.1.1']],
                Carbon::parse($this->postingDate),
            );

            $violations[] = 'a rule returning one unbalanced line was accepted';
        } catch (EntryDoesNotBalance $e) {
            if (! str_contains($e->getMessage(), $broken->key())) {
                $violations[] = 'an unbalanced rule was refused without naming the rule, so the failure says '.
                    'which constraint fired rather than which rule is wrong';
            }
        } catch (Throwable $e) {
            $violations[] = 'an unbalanced rule failed with '.class_basename($e).
                ' rather than a refusal naming it: '.Str::limit($e->getMessage(), 120);
        }

        // And it wrote nothing.
        $written = $this->connection->table('journal_entries')
            ->where('posting_rule', $broken->key())
            ->count();

        if ($written > 0) {
            $violations[] = 'a refused rule still wrote an entry, so the check happens after the write';
        }

        return $violations;
    }

    /**
     * `ledger:verify` must find an entry that someone unbalanced - PF-054.
     *
     * ## The damage is made through the ordinary connection, on purpose
     *
     * The first version injected it as the MIGRATOR with the triggers
     * disabled, which is the shape of the real cause - a restore or a
     * backfill. It also hung: disabling a trigger needs ACCESS EXCLUSIVE on
     * the table, and the enclosing transaction already holds locks on it, so
     * the two waited on each other until the run was killed.
     *
     * The deferred balance trigger gives a simpler route. It fires at COMMIT,
     * and this transaction never commits - so an extra line inserted here
     * leaves the entry genuinely unbalanced, in a transaction that will be
     * rolled back, with no privileged connection and no lock to contend for.
     *
     * A verifier that has never found anything is a verifier nobody has
     * tested, and the first time that matters is during an audit.
     *
     * @return list<string>
     */
    public function verifyCatchesDamage(): array
    {
        $verifier = app(LedgerVerifier::class);

        if ($verifier->verify($this->entityId)->isNotEmpty()) {
            return ['the probe ledger is already damaged before anything was injected'];
        }

        $entryId = (string) Str::ulid();

        if ($this->writeEntry([['1.1.1.1', '20.0000'], ['4.1', '-20.0000']], entryId: $entryId) !== null) {
            return ['could not write a healthy entry to damage'];
        }

        $violations = [];
        $savepoint = 'damage_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            // One extra line. The entry now sums to 3.0000, and the deferred
            // trigger will not notice until a commit that never comes.
            $this->connection->table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'journal_entry_id' => $entryId,
                'accounting_date' => $this->postingDate,
                'period_id' => $this->periodId,
                'account_id' => $this->accounts['4.1'],
                'amount' => '3.0000',
                'currency' => 'SAR',
                'functional_amount' => '3.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $found = $verifier->verify($this->entityId)
                ->contains(static fn ($finding): bool => $finding->kind === 'unbalanced entry');

            if (! $found) {
                $violations[] = 'an entry left out by 3.0000 was not reported, so the global proof proves '.
                    'nothing about the invariant the whole phase exists to guarantee';
            }
        } catch (Throwable $e) {
            $violations[] = 'could not inject damage to test the verifier: '.Str::limit($e->getMessage(), 140);
        } finally {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
        }

        // And the finding clears once the damage is gone.
        if ($violations === [] && $verifier->verify($this->entityId)->isNotEmpty()) {
            $violations[] = 'the verifier still reports damage after it was rolled back, so it is reading '.
                'something other than the current state';
        }

        return $violations;
    }

    /**
     * A reversed entry's lines still count - PF-056, PF-058.
     *
     * `reversed` records that a REVERSING ENTRY EXISTS, not that the original
     * lines were withdrawn. The posting happened and is part of the history;
     * the reversal is a separate entry whose own lines cancel it.
     *
     * A report filtering on `status = 'posted'` therefore drops the original
     * and keeps the reversal, and every reversed transaction appears as its
     * own negative. A 1,234.56 receipt reversed in the next period showed a
     * cash balance of MINUS 1,234.56 - a sign error on a figure somebody
     * would have acted on, produced by a filter that reads as obviously
     * correct.
     *
     * @return list<string>
     */
    public function reversalsNetOut(): array
    {
        $entity = Entity::query()->whereKey($this->entityId)->firstOrFail();
        $poster = app(Poster::class);
        $reverser = app(Reverser::class);
        $trialBalance = app(TrialBalance::class);

        $date = Carbon::parse($this->postingDate);
        $cashCode = '1.1.1.1';

        $before = $this->balanceOf($trialBalance, $entity, $cashCode, $date);

        /*
         | A probe rule of its own, not the opening-balance rule.
         |
         | This check used to borrow `ledger.opening_balance` because it
         | produces a one-sided set and lets the rule supply the balancing
         | plug - convenient, and wrong once PF-038 bounded that rule to the
         | moment before anything else is posted. The check broke, correctly,
         | and borrowing a reserved rule to exercise reversals was always
         | reaching past what the test needed.
         */
        $registry = app(PostingRuleRegistry::class);

        $registry->register(new class implements PostingRule
        {
            public function key(): string
            {
                return 'probe.reversal_pair';
            }

            public function describe(): RuleDescription
            {
                return RuleDescription::of(
                    $this->key(),
                    'زوج للعكس',
                    'A balanced pair, to be reversed',
                    [['Dr', 'the named account', 'the amount'], ['Cr', 'retained earnings', 'the amount']],
                );
            }

            public function lines(array $event, PostingContext $context): array
            {
                $amount = $context->money((string) $event['amount']);

                return [
                    PostingLine::toAccount((string) $event['debit'], $amount),
                    PostingLine::toRole(ControlRole::RetainedEarnings, $amount->negated()),
                ];
            }
        });

        $entry = $poster->post(
            $entity,
            'probe.reversal_pair',
            ['debit' => $this->accounts[$cashCode], 'amount' => '1234.5600'],
            $date,
            journalCode: 'GEN',
        );

        $afterPosting = $this->balanceOf($trialBalance, $entity, $cashCode, $date);

        $violations = [];

        if (! $afterPosting->minus($before)->isEqualTo(BigDecimal::of('1234.5600'))) {
            $violations[] = sprintf(
                'posting 1234.5600 moved the trial balance by %s',
                (string) $afterPosting->minus($before),
            );
        }

        $reverser->reverse($entry, $date, 'checking that a reversal nets out');

        $afterReversal = $this->balanceOf($trialBalance, $entity, $cashCode, $date);

        if (! $afterReversal->isEqualTo($before)) {
            $violations[] = sprintf(
                'after reversing, the balance is %s instead of returning to %s - a report that counts the '.
                'reversal without counting what it reverses shows every reversed transaction as its own '.
                'negative',
                (string) $afterReversal,
                (string) $before,
            );
        }

        return $violations;
    }

    private function balanceOf(TrialBalance $trialBalance, Entity $entity, string $code, Carbon $date): BigDecimal
    {
        $row = $trialBalance
            ->for($this->entityId, (string) $entity->functional_currency, $date)['rows']
            ->firstWhere('code', $code);

        return BigDecimal::of($row['balance'] ?? '0');
    }

    /**
     * Write an entry and return the refusal, or null if it was accepted.
     *
     * `SET CONSTRAINTS ALL IMMEDIATE` forces the deferred balance trigger to
     * check here rather than at a commit that will never happen. Each attempt
     * runs in its own savepoint, because a refusal aborts the transaction.
     *
     * @param  list<array{0: string, 1: string}>  $lines  [account code, signed amount]
     */
    /**
     * @param  array<string, string|null>  $dimensions  applied to the FIRST line only
     */
    /**
     * The fingerprint is taken, and a divergence from it is found - PF-054.
     *
     * ## Why this cannot be tested with a real tamper
     *
     * A real tamper means changing a posted line, and that takes disabling a
     * trigger - which takes a privilege this connection does not have, and
     * should not. The tamper WAS performed by hand against a real database
     * while this was built: a posted entry's two lines were changed from
     * 12,345.67 to 99,999.00 with every trigger off, the entry still balanced
     * to the halala, the trial balance still tied, and `ledger:verify` named
     * the entry.
     *
     * What this check covers is the half that can silently rot: the
     * DETECTION. The stored hash is replaced with a wrong one inside this
     * transaction - which the write-once guard permits, because a transaction
     * may settle its own fingerprint - and the proof has to notice.
     *
     * A detector that has never fired is a detector nobody has tested, and
     * the first time that matters is during an audit.
     *
     * @return list<string>
     */
    public function fingerprintsAreChecked(): array
    {
        $violations = [];
        $verifier = app(LedgerVerifier::class);
        $entryId = (string) Str::ulid();

        if ($this->writeEntry([['1.1.1.1', '880.0000'], ['4.1', '-880.0000']], entryId: $entryId) !== null) {
            return ['could not write a healthy entry to fingerprint'];
        }

        $stored = $this->connection->table('journal_entry_fingerprints')
            ->where('journal_entry_id', $entryId)
            ->first();

        if ($stored === null) {
            return ['a posted entry got no fingerprint, so nothing can detect a change to it later'];
        }

        $recomputed = (string) $this->connection
            ->selectOne('SELECT peakflow_entry_fingerprint(?) AS h', [$entryId])->h;

        if ($recomputed !== (string) $stored->content_hash) {
            $violations[] = 'the fingerprint does not match a recomputation of the entry that was just '.
                'written, so every entry would read as tampered and the check would be worthless';
        }

        if ((int) $stored->line_count !== 2) {
            $violations[] = sprintf(
                'the fingerprint covers %d line(s) of 2, so a line added later would not change it',
                (int) $stored->line_count,
            );
        }

        /*
         | Now break the stored hash and see whether the proof notices.
         |
         | Permitted here because the row was written in this transaction;
         | after a commit the guard refuses it, which is the point of the
         | guard and is asserted separately below.
         */
        $this->connection->table('journal_entry_fingerprints')
            ->where('journal_entry_id', $entryId)
            ->update(['content_hash' => str_repeat('a', 64)]);

        $found = $verifier->verify($this->entityId)
            ->contains(fn (LedgerFinding $finding): bool => $finding->kind === 'entry_substance_changed');

        if (! $found) {
            $violations[] = 'the global proof did not report an entry whose fingerprint no longer matches '.
                'its contents. That is the only check that can see a figure changed by something which '.
                'bypassed the immutability trigger - a disabled trigger, a restored dump, a direct '.
                'session - and a tamper that balances is the only kind worth doing';
        }

        // Put it back, so the suite's own closing proof is not reporting this.
        $this->connection->table('journal_entry_fingerprints')
            ->where('journal_entry_id', $entryId)
            ->update(['content_hash' => $recomputed]);

        if ($verifier->verify($this->entityId)
            ->contains(fn (LedgerFinding $finding): bool => $finding->kind === 'entry_substance_changed')) {
            $violations[] = 'the proof still reports a divergence after the fingerprint was restored, so '.
                'it is reading something other than the stored hash';
        }

        return $violations;
    }

    /**
     * The global proof reports a broken audit chain - PF-054, PF-035.
     *
     * ## Why this is asserted through a stub
     *
     * The audit log is append-only with UPDATE and DELETE revoked from the
     * application role, so this connection cannot break the chain - which is
     * the correct arrangement and leaves no way to produce the condition.
     *
     * What can rot is the WIRING: the chain is reached through a `Support`
     * contract because the ledger may not depend on `Identity`, and a binding
     * is exactly the kind of thing that gets removed during a refactor with
     * nothing to notice. Deleting the line from `verify()` left every check
     * green until this one existed.
     *
     * So the contract is replaced with one that reports a break, and the
     * proof has to surface it. The real chain walker has its own checks
     * against the real log.
     *
     * @return list<string>
     */
    public function theProofIncludesTheChain(): array
    {
        $real = app(AuditChain::class);

        app()->instance(AuditChain::class, new class implements AuditChain
        {
            public function verdict(): array
            {
                return [
                    'intact' => false,
                    'checked' => 41,
                    'detail' => 'record 42 does not follow record 41 (stubbed by the check suite)',
                ];
            }
        });

        try {
            $findings = (new LedgerVerifier(app(AuditChain::class)))->verify($this->entityId);
        } finally {
            app()->instance(AuditChain::class, $real);
        }

        if (! $findings->contains(fn (LedgerFinding $finding): bool => $finding->kind === 'audit_chain_broken')) {
            return ['the global proof does not report a broken audit chain. The fingerprint says an '.
                'entry\'s substance is unchanged; the chain says the record of who did what has not been '.
                'edited. Without the chain, whoever changed a figure could delete the entry showing they '.
                'did'];
        }

        return [];
    }

    /**
     * The one override on the control-account rule, and its window - PF-038.
     *
     * ## What is being claimed
     *
     * A control account may be reached only by a registered posting rule.
     * Opening balances are the exception because no document produces them,
     * and an exception with no bound is not an exception - the rule could be
     * used in June to adjust a receivable, and the entry would be
     * indistinguishable from the real opening balance.
     *
     * ## On a FRESH entity, which the first version of this did not do
     *
     * The first version ran every assertion against the suite's own entity,
     * which has a dozen entries by the time this check runs - so its window
     * was already shut and the database trigger refused everything. Every
     * assertion passed, and removing the permission check from the service
     * changed nothing: the refusals were all coming from the trigger, for a
     * reason none of them was testing.
     *
     * So the permission, the reason and the figures are asserted while the
     * window is OPEN, on an entity of this check's own. The window is then
     * closed by posting something else, and the closure is asserted twice -
     * through the service, which explains it, and through the Poster, which
     * is what a backfill script would reach for.
     *
     * @return list<string>
     */
    public function openingBalancesAreBounded(): array
    {
        $violations = [];
        $openings = app(OpeningBalances::class);

        $entity = $this->freshEntity('OPB');
        $entityId = (string) $entity->getKey();
        $date = Carbon::parse($this->postingDate);

        $accounts = Account::query()
            ->where('entity_id', $entityId)
            ->whereIn('code', ['1.1.1.1', '1.1.2.1', '2.1.1'])
            ->pluck('id', 'code');

        $figures = ['1.1.1.1' => '50000.0000', '1.1.2.1' => '120000.0000', '2.1.1' => '-80000.0000'];
        $reason = 'from the signed closing trial balance of the previous system, dated 31 December';

        $before = auth()->guard('web')->user();

        try {
            if ($openings->window($entityId)['open'] !== true) {
                return ['a brand-new entity reports its opening-balance window already closed, so every '.
                    'assertion below would pass for the wrong reason'];
            }

            /*
             | A clerk may post a journal and may not open the books.
             |
             | Which is the whole reason the permission is separate: posting a
             | journal is an everyday act, and naming a control account by
             | hand is the act this module exists to prevent.
             */
            auth()->guard('web')->setUser($this->userWithRole('ar_clerk', $entityId));

            if ($this->refusal(fn () => $openings->post($entity, $date, $figures, $reason)) === null) {
                $violations[] = 'a receivables clerk entered opening balances into an empty ledger. The '.
                    'permission is separate from journal.post because posting a journal is an everyday '.
                    'act and naming a control account by hand is not';
            }

            auth()->guard('web')->setUser($this->userWithRole('controller', $entityId));

            $thin = $this->refusal(fn () => $openings->post($entity, $date, $figures, 'setup'));

            if ($thin === null || ! str_contains($thin, 'at least twenty characters')) {
                $violations[] = '"setup" was accepted as the reason, or refused for some other reason. It '.
                    'is read during the first audit of these books, when the only artefact is what '.
                    'somebody typed';
            }

            /*
             | The MESSAGE is asserted, not merely the refusal.
             |
             | A heading is refused by a database trigger too, so "it was
             | refused" passes with the service's own check deleted - and
             | what the person setting up a new set of books then sees is a
             | constraint violation naming a trigger. Asserting the
             | explanation is what makes the service's contribution
             | load-bearing.
             */
            $heading = $this->refusal(fn () => $openings->post($entity, $date, ['1.1' => '10.0000'], $reason));

            if ($heading === null) {
                $violations[] = 'an opening balance was accepted on a group heading, which is a balance no '.
                    'child explains and every drill-down loses';
            } elseif (! str_contains($heading, 'group heading')) {
                $violations[] = sprintf(
                    'a group heading was refused by something other than the opening-balance service, so '.
                    'the message does not say what is wrong: %s',
                    Str::limit($heading, 110),
                );
            }

            if ($this->refusal(fn () => $openings->post($entity, $date, ['9.9.9' => '10.0000'], $reason)) === null) {
                $violations[] = 'an opening balance was accepted for an account code that does not exist';
            }

            // And properly, which also closes nothing: opening balances do
            // not close their own window.
            $entry = $openings->post($entity, $date, $figures, $reason);

            if ($openings->window($entityId)['open'] !== true) {
                $violations[] = 'posting opening balances closed the window, so a set entered in two '.
                    'passes - which is how forty accounts get typed - could not be completed';
            }

            /*
             | The plug, asserted.
             |
             | 50,000 + 120,000 - 80,000 is a net debit of 90,000, so the
             | entry needs a 90,000 credit to retained earnings. A plug in
             | the wrong direction balances just as well and puts the
             | difference on the wrong side of equity.
             */
            $plug = $this->connection->table('journal_lines as l')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('l.journal_entry_id', $entry->getKey())
                ->where('a.code', '3.2')
                ->value('l.functional_amount');

            if ($plug === null || ! BigDecimal::of((string) $plug)->isEqualTo(BigDecimal::of('-90000.0000'))) {
                $violations[] = sprintf(
                    'the retained-earnings plug came out as %s where -90000.0000 belongs. A plug in the '.
                    'wrong direction balances just as well and puts the difference on the wrong side of '.
                    'equity',
                    $plug === null ? '(absent)' : (string) $plug,
                );
            }

            // Now something else, which does close it.
            app(Poster::class)->post(
                entity: $entity,
                ruleKey: 'probe.reversal_pair',
                event: ['debit' => (string) $accounts['1.1.1.1'], 'amount' => '100.0000'],
                accountingDate: $date,
                journalCode: 'GEN',
            );

            $window = $openings->window($entityId);

            if ($window['open'] !== false) {
                $violations[] = 'the window is still open after an ordinary entry was posted, so nothing '.
                    'is closing it';
            }

            $closed = $this->refusal(fn () => $openings->post($entity, $date, $figures, $reason));

            if ($closed === null) {
                $violations[] = 'opening balances were accepted into a ledger that already has postings in '.
                    'it. One typed six months into the year is indistinguishable from the real one, and is '.
                    'found a year later by whoever compares this opening to last year\'s closing';
            } elseif (! str_contains($closed, 'window is open only before')) {
                /*
                 | Again the message. The trigger refuses this as well, so
                 | deleting the service's window check leaves the refusal
                 | intact and the explanation gone - and the explanation is
                 | the half that tells somebody to use a reversal instead.
                 */
                $violations[] = sprintf(
                    'the closed window was reported by something other than the opening-balance service, '.
                    'so nothing tells the person what to do instead: %s',
                    Str::limit($closed, 110),
                );
            }

            /*
             | And the same thing with the service bypassed entirely.
             |
             | Through the Poster, which is the next thing down and the one a
             | script would reach for.
             */
            $direct = $this->refusal(fn () => app(Poster::class)->post(
                entity: $entity,
                ruleKey: 'ledger.opening_balance',
                event: ['balances' => [['account_id' => (string) $accounts['1.1.1.1'], 'amount' => '1.0000']]],
                accountingDate: $date,
                journalCode: 'OPN',
            ));

            if ($direct === null) {
                $violations[] = 'the opening-balance rule posted straight through the Poster into a ledger '.
                    'that already has entries. The service explains the refusal; the trigger is what a '.
                    'backfill script cannot talk its way past, and it let this through';
            }
        } finally {
            /*
             | `forgetUser()` when there was nobody, not `setUser(null)`.
             |
             | The guard's signature does not accept null, and the suite runs
             | with no authenticated user - so restoring "whoever was there"
             | has to handle the case where that was nobody.
             */
            $before === null
                ? auth()->guard('web')->forgetUser()
                : auth()->guard('web')->setUser($before);

            // Back to the suite's own entity, or every check after this one
            // reads an empty ledger.
            app(EntityContext::class)->use($this->entityId);
        }

        return $violations;
    }

    /**
     * Period 13, and the declaration a statement carries - PF-051.
     *
     * ## Period 13 was a row nothing could reach
     *
     * It carries no calendar days: it starts and ends on the fiscal year's
     * last day, sharing them with December, so `periodFor()` skips it on
     * purpose - otherwise every December posting would land in it. Which left
     * no way to post into it at all, and the only way to record an auditor's
     * adjustment was to re-date it into December: the exact thing period 13
     * exists to prevent.
     *
     * Found by measuring. A probe posted three adjustments "into the
     * adjusting period", read the trial balance both ways, and got the same
     * total twice - because all three had gone into December.
     *
     * ## The entry keeps the year-end date
     *
     * That is the whole reason the period shares its days. The adjustment is
     * dated 31 December, because it belongs to that year's figures, and the
     * PERIOD is what distinguishes "what the business recorded in December"
     * from "what the audit changed afterwards".
     *
     * ## And a statement says which it included
     *
     * In words, not as a boolean in a payload a screen may ignore, a PDF may
     * not print and an export may drop. Asserted here in both languages,
     * including the case where the choice made no difference - "excluding
     * adjusting entries" on a range that holds none reads as a warning about
     * nothing.
     *
     * @return list<string>
     */
    public function adjustmentsAreDeclared(): array
    {
        $violations = [];
        $entity = $this->freshEntity('ADJ');
        $entityId = (string) $entity->getKey();
        $adjusting = app(AdjustingEntries::class);
        $trialBalance = app(TrialBalance::class);

        $yearEnd = Carbon::parse($this->postingDate)->endOfYear()->startOfDay();

        $accounts = Account::query()
            ->where('entity_id', $entityId)
            ->whereIn('code', ['1.1.1.1', '4.1'])
            ->pluck('id', 'code');

        $december = app(FiscalCalendarGenerator::class)->periodFor($entityId, $yearEnd);

        if ($december === null) {
            return ['the fresh entity has no period covering its own year end'];
        }

        if ($december->state !== PeriodState::Open) {
            app(PeriodStateMachine::class)->transition(
                $december,
                PeriodState::Open,
                'opened December so the adjusting-period check has something to compare against',
            );
        }

        app(Poster::class)->post(
            entity: $entity,
            ruleKey: 'probe.reversal_pair',
            event: ['debit' => (string) $accounts['1.1.1.1'], 'amount' => '1000.0000'],
            accountingDate: $yearEnd,
            journalCode: 'GEN',
        );

        /*
         | The declaration BEFORE any adjustment exists.
         |
         | Both ways round, because the figures are the same and the sentence
         | has to say so rather than warning about an exclusion that excluded
         | nothing.
         */
        foreach ([true, false] as $include) {
            $basis = $trialBalance->for($entityId, 'SAR', $yearEnd, includeAdjusting: $include)['basis'];

            if ($basis['choice_mattered'] !== false) {
                $violations[] = 'a statement on a year with no adjustments claims the choice mattered';
            }

            foreach (['declaration_en' => 'No adjusting entries', 'declaration_ar' => 'لا توجد قيود تسوية'] as $key => $needle) {
                if (! str_contains((string) $basis[$key], $needle)) {
                    $violations[] = sprintf(
                        'the %s does not say that no adjusting entries fall in range: %s',
                        $key,
                        Str::limit((string) $basis[$key], 90),
                    );
                }
            }
        }

        $period = $adjusting->periodFor($entityId, $yearEnd);

        if ($period === null) {
            return [...$violations, 'the entity has no adjusting period, so period 13 is not being '.
                'generated and an auditor\'s adjustment has nowhere to go but December'];
        }

        $before = auth()->guard('web')->user();

        try {
            auth()->guard('web')->setUser($this->userWithRole('ar_clerk', $entityId));

            $reason = 'from the external auditor schedule B-1, accrued income understated at the year end';

            if ($this->refusal(fn () => $adjusting->post(
                $entity,
                'probe.reversal_pair',
                ['debit' => (string) $accounts['1.1.1.1'], 'amount' => '250.0000'],
                $yearEnd,
                $reason,
            )) === null) {
                $violations[] = 'a receivables clerk posted an adjusting entry. It is a figure entered '.
                    'after a year has been reported, which is why it is not covered by the everyday '.
                    'posting permission';
            }

            auth()->guard('web')->setUser($this->userWithRole('controller', $entityId));

            $thin = $this->refusal(fn () => $adjusting->post(
                $entity,
                'probe.reversal_pair',
                ['debit' => (string) $accounts['1.1.1.1'], 'amount' => '250.0000'],
                $yearEnd,
                'audit',
            ));

            if ($thin === null || ! str_contains($thin, 'at least twenty characters')) {
                $violations[] = '"audit" was accepted as the reason for an adjusting entry, or refused '.
                    'for some other reason. The disclosure under IAS 10 is written from it';
            }

            // A future adjusting period is refused, which is why it is opened
            // deliberately - the same rule as every other period.
            if ($period->state !== PeriodState::Open) {
                $future = $this->refusal(fn () => $adjusting->post(
                    $entity,
                    'probe.reversal_pair',
                    ['debit' => (string) $accounts['1.1.1.1'], 'amount' => '250.0000'],
                    $yearEnd,
                    $reason,
                ));

                if ($future === null) {
                    $violations[] = 'an adjustment was posted into an adjusting period that nobody had '.
                        'opened, so it could land in a year nobody has started closing';
                }

                app(PeriodStateMachine::class)->transition(
                    $period,
                    PeriodState::Open,
                    'opened the adjusting period for the audit adjustments of this year',
                );
            }

            foreach (['250.0000', '125.0000', '75.0000'] as $index => $amount) {
                $adjusting->post(
                    $entity,
                    'probe.reversal_pair',
                    ['debit' => (string) $accounts['1.1.1.1'], 'amount' => $amount],
                    $yearEnd,
                    sprintf('from the external auditor schedule B-%d, accrued income understated', $index + 1),
                );
            }
        } finally {
            $before === null
                ? auth()->guard('web')->forgetUser()
                : auth()->guard('web')->setUser($before);
        }

        $summary = $adjusting->summaryFor($entityId, $yearEnd);

        if ($summary['entries'] !== 3) {
            $violations[] = sprintf(
                '%d adjusting entr%s landed in the adjusting period where 3 belong - they went into '.
                'December instead, which is what period 13 exists to prevent',
                $summary['entries'],
                $summary['entries'] === 1 ? 'y' : 'ies',
            );
        }

        /*
         | And the two statements have to DISAGREE.
         |
         | This is the assertion that caught the real bug: with the
         | adjustments in December, including and excluding them gave the
         | same total, the flag was still reported faithfully, and nothing
         | else in the system noticed.
         */
        $including = $trialBalance->for($entityId, 'SAR', $yearEnd, includeAdjusting: true);
        $excluding = $trialBalance->for($entityId, 'SAR', $yearEnd, includeAdjusting: false);

        $difference = BigDecimal::of($including['total_debit'])->minus($excluding['total_debit']);

        if (! $difference->isEqualTo(BigDecimal::of('450.0000'))) {
            $violations[] = sprintf(
                'including the adjustments changed the total debit by %s where 450.0000 belongs. If it is '.
                'zero, the adjustments are in December and the two statements are the same page with two '.
                'different footings',
                (string) $difference,
            );
        }

        foreach ([
            [$including, 'including', 'بتضمين'],
            [$excluding, 'EXCLUDING', 'باستثناء'],
        ] as [$statement, $english, $arabic]) {
            $basis = $statement['basis'];

            if ($basis['choice_mattered'] !== true || $basis['adjusting_entries'] !== 3) {
                $violations[] = sprintf(
                    'the %s statement reports %d adjusting entries and mattered=%s',
                    $english,
                    $basis['adjusting_entries'],
                    var_export($basis['choice_mattered'], true),
                );
            }

            if (! str_contains((string) $basis['declaration_en'], $english)) {
                $violations[] = sprintf(
                    'the English declaration does not say "%s": %s',
                    $english,
                    Str::limit((string) $basis['declaration_en'], 90),
                );
            }

            if (! str_contains((string) $basis['declaration_ar'], $arabic)) {
                $violations[] = sprintf(
                    'the Arabic declaration does not say "%s": %s',
                    $arabic,
                    Str::limit((string) $basis['declaration_ar'], 90),
                );
            }

            /*
             | The Arabic counted noun, which is not decoration.
             |
             | Arabic counts 3 to 10 as a broken plural - "3 قيود" - and 11
             | upwards as a singular. "3 قيد" is wrong and this is a sentence
             | an auditor reads.
             */
            if (! str_contains((string) $basis['declaration_ar'], '3 قيود تسوية')) {
                $violations[] = sprintf(
                    'the Arabic declaration does not count three entries correctly: %s',
                    Str::limit((string) $basis['declaration_ar'], 90),
                );
            }
        }

        app(EntityContext::class)->use($this->entityId);

        return $violations;
    }

    /**
     * The read model and the journal must produce the same statement - PF-058.
     *
     * ## Why there are two paths at all
     *
     * At ten million journal lines, aggregating the journal for a full-year
     * trial balance took **7.5 seconds** - measured by `bin/bench
     * trialbalance`, against a two-second budget. `account_balances` holds
     * one row per entity, period, account and dimension set, so the same
     * statement reads 4,800 rows instead of ten million.
     *
     * ## Which makes this the check that matters
     *
     * A cache with no independent recomputation is a second source of truth,
     * and the first time it disagrees with the journal nobody can say which
     * is right. `BalanceRebuilder` proves the read model against the journal
     * row by row; this proves that the two READ PATHS answer identically -
     * which is a different claim, because the paths bound differently. One
     * filters by date, the other by period, and the day they diverge is the
     * day two reports of the same figure disagree.
     *
     * That divergence was found by reasoning rather than by a failure: an
     * adjusting entry dated mid-year would be included by the date bound and
     * excluded by the period bound. `AdjustingEntries` now refuses any date
     * but the year-end, which is asserted below.
     *
     * @return list<string>
     */
    public function bothSidesAgree(): array
    {
        $violations = [];
        $entity = $this->freshEntity('BTH');
        $entityId = (string) $entity->getKey();
        $trialBalance = app(TrialBalance::class);

        $date = Carbon::parse($this->postingDate);
        $monthStart = $date->copy()->startOfMonth();
        $monthEnd = $date->copy()->endOfMonth();

        $accounts = Account::query()
            ->where('entity_id', $entityId)
            ->whereIn('code', ['1.1.1.1', '5.2', '5.8'])
            ->pluck('id', 'code');

        foreach (['1234.5600', '77.0000', '9999.9900'] as $index => $amount) {
            app(Poster::class)->post(
                entity: $entity,
                ruleKey: 'probe.reversal_pair',
                event: [
                    'debit' => (string) $accounts[['1.1.1.1', '5.2', '5.8'][$index]],
                    'amount' => $amount,
                ],
                accountingDate: $date,
                journalCode: 'GEN',
            );
        }

        /*
         | A period-aligned range takes the read model; a mid-month one
         | cannot and takes the journal. Both are asserted, because "the fast
         | path is used" and "the slow path still works" are two claims and
         | losing either is a regression.
         */
        $aligned = $trialBalance->for($entityId, 'SAR', $monthEnd, $monthStart);
        $midMonth = $trialBalance->for($entityId, 'SAR', $date, $monthStart);

        if (($aligned['basis']['source'] ?? '') !== 'read_model') {
            $violations[] = sprintf(
                'a period-aligned range read from %s rather than the balance model, so the statement is '.
                'aggregating the journal - 7.5 seconds at ten million lines',
                var_export($aligned['basis']['source'] ?? null, true),
            );
        }

        if (($midMonth['basis']['source'] ?? '') !== 'journal') {
            $violations[] = sprintf(
                'a range ending mid-month read from %s. The balance model\'s grain is the period, so '.
                'there is no row to read for half a month - apportioning one would be a figure nothing in '.
                'the ledger agrees with',
                var_export($midMonth['basis']['source'] ?? null, true),
            );
        }

        /*
         | And the figures, row by row.
         |
         | Totals alone would pass with two accounts transposed, which is
         | precisely the shape of a grouping bug.
         */
        $fromJournal = $trialBalance->for($entityId, 'SAR', $monthEnd, $monthStart, fromJournal: true);

        if (($fromJournal['basis']['source'] ?? '') !== 'journal') {
            $violations[] = 'fromJournal did not force the journal path, so the verification compares the '.
                'read model against itself';
        }

        $cached = $aligned['rows']->keyBy('code');
        $journal = $fromJournal['rows']->keyBy('code');

        if ($cached->count() !== $journal->count() || $cached->count() === 0) {
            $violations[] = sprintf(
                'the balance model returned %d rows and the journal %d',
                $cached->count(),
                $journal->count(),
            );
        }

        foreach ($journal as $code => $row) {
            $other = $cached->get($code);

            if ($other === null) {
                $violations[] = sprintf('account %s is in the journal statement and not in the cached one', $code);

                continue;
            }

            foreach (['debit', 'credit', 'balance'] as $figure) {
                if (! BigDecimal::of((string) $row[$figure])->isEqualTo(BigDecimal::of((string) $other[$figure]))) {
                    $violations[] = sprintf(
                        'account %s: the journal says %s %s and the balance model says %s. A cache that '.
                        'disagrees with the journal is a second source of truth, and nobody can say which '.
                        'is right',
                        $code,
                        $figure,
                        (string) $row[$figure],
                        (string) $other[$figure],
                    );
                }
            }
        }

        foreach (['total_debit', 'total_credit', 'total_net_debit', 'total_net_credit'] as $total) {
            if (! BigDecimal::of((string) $aligned[$total])->isEqualTo(BigDecimal::of((string) $fromJournal[$total]))) {
                $violations[] = sprintf(
                    '%s is %s from the balance model and %s from the journal',
                    $total,
                    (string) $aligned[$total],
                    (string) $fromJournal[$total],
                );
            }
        }

        /*
         | The date rule on an adjustment, which is what keeps the two bounds
         | from diverging.
         */
        $adjustingPeriod = app(AdjustingEntries::class)->periodFor($entityId, $date);

        if ($adjustingPeriod !== null) {
            if ($adjustingPeriod->state !== PeriodState::Open) {
                app(PeriodStateMachine::class)->transition(
                    $adjustingPeriod,
                    PeriodState::Open,
                    'opened the adjusting period to assert the year-end date rule',
                );
            }

            $before = auth()->guard('web')->user();

            try {
                auth()->guard('web')->setUser($this->userWithRole('controller', $entityId));

                $midYear = $this->refusal(fn () => app(AdjustingEntries::class)->post(
                    $entity,
                    'probe.reversal_pair',
                    ['debit' => (string) $accounts['5.2'], 'amount' => '10.0000'],
                    $date,
                    'from the external auditor schedule C-1, dated mid-year on purpose',
                ));

                if ($midYear === null || ! str_contains($midYear, 'last day of the year it adjusts')) {
                    $violations[] = 'an adjusting entry was accepted with a mid-year date. Its period ends '.
                        'in December, so the date bound and the period bound would include it in '.
                        'different statements - two reports of the same figure disagreeing';
                }
            } finally {
                $before === null
                    ? auth()->guard('web')->forgetUser()
                    : auth()->guard('web')->setUser($before);
            }
        }

        app(EntityContext::class)->use($this->entityId);

        return $violations;
    }

    /**
     * Reopening a closed period needs a fresh factor - PF-032.
     *
     * ## Why this is asserted in the service and not on a route
     *
     * The middleware exists and is proven, and it protects the one route
     * there is. The same method is reached from a console command, a queued
     * job and a future API endpoint - each of which is a route that does not
     * exist yet, and a middleware protects only the one that does.
     *
     * So `PeriodStateMachine::transition()` asserts it on the write path,
     * which is the only place that cannot be reached around.
     *
     * ## Closing does not need it; reopening does
     *
     * Closing a period stops postings, which is the safe direction.
     * Reopening lets somebody change a figure that has been reported, and a
     * stolen session passes every check that looks at the session. It fails
     * this one.
     *
     * ## And the assertion is scoped to the period
     *
     * An assertion made to reopen March must not authorise reopening
     * December in the same five minutes. That is the difference between
     * re-proving a factor and holding a five-minute licence to reopen
     * anything - and it is the half of step-up that gets dropped, because an
     * unscoped assertion makes every test pass.
     *
     * @return list<string>
     */
    public function reopeningNeedsAFreshFactor(): array
    {
        $violations = [];
        $entity = $this->freshEntity('STP');
        $entityId = (string) $entity->getKey();
        $machine = app(PeriodStateMachine::class);

        $date = Carbon::parse($this->postingDate);

        $march = app(FiscalCalendarGenerator::class)->periodFor($entityId, $date);
        $december = app(FiscalCalendarGenerator::class)
            ->periodFor($entityId, $date->copy()->endOfYear());

        if ($march === null || $december === null || $march->getKey() === $december->getKey()) {
            return ['the fresh entity does not have two distinct periods to compare'];
        }

        $before = auth()->guard('web')->user();

        try {
            $user = $this->userWithRole('controller', $entityId);
            auth()->guard('web')->setUser($user);

            foreach ([$march, $december] as $period) {
                if ($period->state !== PeriodState::Open) {
                    $machine->transition($period, PeriodState::Open, 'opened for the step-up check');
                }

                $machine->transition(
                    $period->refresh(),
                    PeriodState::Closed,
                    'closed for the step-up check, which needs a closed period to reopen',
                );
            }

            /*
             | Closing worked without a factor, which is the point.
             |
             | If closing needed step-up too, the two lines above would have
             | thrown and this check would be asserting nothing - so the
             | successful close IS the first assertion.
             */
            if ($march->refresh()->state !== PeriodState::Closed) {
                $violations[] = 'closing a period required a step-up assertion. Closing stops postings, '.
                    'which is the safe direction, and demanding a factor for it trains people to produce '.
                    'one on request';
            }

            $refusal = $this->refusal(fn () => $machine->transition(
                $march->refresh(),
                PeriodState::Open,
                'reopening to test that a stolen session is not enough for this',
            ));

            if ($refusal === null) {
                $violations[] = 'a closed period was reopened with no fresh factor. A stolen session '.
                    'passes every check that looks at the session, and reopening lets somebody change a '.
                    'figure that has been reported';
            } elseif (! str_contains(mb_strtolower($refusal), 'step')
                && ! str_contains(mb_strtolower($refusal), 'factor')
                && ! str_contains(mb_strtolower($refusal), 'تحقّق')) {
                $violations[] = sprintf(
                    'reopening was refused for some other reason, so nothing here is testing step-up: %s',
                    Str::limit($refusal, 120),
                );
            }

            /*
             | Now with an assertion for MARCH, and December must still be
             | refused.
             |
             | This is the half that gets dropped. An unscoped assertion
             | makes the reopen-March case pass and leaves a five-minute
             | licence to reopen anything, which no test would notice.
             */
            $guard = app(RequiresStepUp::class);

            /*
             | A real TOTP enrolment and a real code.
             |
             | Not a back door into the guard. `recordAssertion()` verifies
             | the code against an enrolled authenticator, and a method that
             | skipped that would be a method somebody could call in
             | production - so the check enrols a factor and computes the
             | code the way an authenticator app would, from the secret and
             | the clock.
             |
             | Which also means this check exercises the TOTP path, so a
             | step-up that accepted any code would fail here rather than
             | somewhere nobody is looking.
             */
            $enrolment = app(MfaService::class)->beginTotpEnrolment($user, 'Check authenticator');
            $secret = (string) $enrolment['secret'];

            /*
             | One code per time step, because a used code is refused.
             |
             | TOTP replay protection is doing its job: confirming the
             | enrolment consumes the code for the current step, and asking
             | for the same code again - which `Totp::at()` returns for 30
             | seconds - gets it refused. Measured while writing this: the
             | enrolment confirmed, `hasConfirmedFactor` was true, and a
             | fresh `verifyCode()` with the same code came back false.
             |
             | So the codes are taken from explicit, consecutive steps rather
             | than from "now" twice. The drift window accepts one step
             | either side, so step N+1 is still valid when the assertion is
             | recorded.
             */
            $step = Totp::stepAt(time());

            app(MfaService::class)->confirmTotpEnrolment(
                $enrolment['authenticator'],
                Totp::at($secret, $step * 30),
            );

            $assertion = app(StepUpGuard::class)->recordAssertion(
                $user->refresh(),
                'period.reopen',
                (string) $march->getKey(),
                Totp::at($secret, ($step + 1) * 30),
            );

            if ($assertion === null) {
                $violations[] = 'a valid code from the next time step was not accepted, so nothing below '.
                    'is testing a satisfied assertion';
            }

            /*
             | The guard reads the user from the auth guard, and that
             | instance is STALE.
             |
             | It was set before the enrolment, so its `authenticators`
             | relation is the empty one it was loaded with - and
             | `hasConfirmedFactor()` on it is false however many factors the
             | database now holds. Which produced "set up a second factor" on
             | a user who had just enrolled one.
             |
             | (That message read "before you 2026-03" until PF-086 - the
             | label is a noun and the template expected a verb phrase. It now
             | reads "before continuing with 2026-03".)
             */
            auth()->guard('web')->setUser($user->refresh());

            /*
             | And the session has to say a factor was proved at login.
             |
             | `period.reopen` demands BOTH: a confirmed factor on the
             | account and a factor asserted in this session. The login flow
             | sets `factor_asserted_at`; a check suite has no HTTP session,
             | so it says so explicitly rather than the guard quietly
             | treating "no session" as "satisfied".
             |
             | Which is the right default for the guard and is why this line
             | is needed: a console command has no second factor and must not
             | be refused, so `sessionAssertedAFactor()` returning false
             | makes `isSatisfied` false - exactly as it should for a request
             | that never proved one.
             */
            session(['factor_asserted_at' => now()->toIso8601String()]);

            if (! $guard->isSatisfied('period.reopen', (string) $march->getKey())) {
                $violations[] = 'an assertion recorded for March does not satisfy reopening March, so '.
                    'the subject is not being matched at all';
            }

            if ($guard->isSatisfied('period.reopen', (string) $december->getKey())) {
                $violations[] = 'an assertion recorded for March also authorises reopening December. '.
                    'That is a five-minute licence to reopen anything rather than a factor re-proved for '.
                    'one act';
            }

            $reopened = $this->refusal(fn () => $machine->transition(
                $march->refresh(),
                PeriodState::Open,
                'reopening March with a factor re-proved for March',
            ));

            if ($reopened !== null) {
                $violations[] = sprintf(
                    'reopening March was refused even with an assertion recorded for it: %s',
                    Str::limit($reopened, 140),
                );
            }

            $decemberRefusal = $this->refusal(fn () => $machine->transition(
                $december->refresh(),
                PeriodState::Open,
                'reopening December on an assertion that was made for March',
            ));

            if ($decemberRefusal === null) {
                $violations[] = 'December was reopened on an assertion made for March';
            }
        } finally {
            $before === null
                ? auth()->guard('web')->forgetUser()
                : auth()->guard('web')->setUser($before);

            app(EntityContext::class)->use($this->entityId);
        }

        return $violations;
    }

    /**
     * An entity of this check's own, with its chart and one open period.
     *
     * Needed because several claims are only testable while a ledger is
     * empty, and the suite's entity stops being empty on its first check.
     */
    private function freshEntity(string $prefix): Entity
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => $prefix.random_int(100, 999),
            'legal_name' => $prefix.' ledger check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $context->use($entity);

        $period = app(FiscalCalendarGenerator::class)
            ->periodFor((string) $entity->getKey(), Carbon::parse($this->postingDate));

        if ($period !== null && $period->state !== PeriodState::Open) {
            app(PeriodStateMachine::class)->transition(
                $period,
                PeriodState::Open,
                'opened by the ledger check suite for an empty-ledger assertion',
            );
        }

        return $entity;
    }

    /**
     * A user holding one seeded role in this entity.
     *
     * Created per call rather than shared, because a check that needs two
     * different roles needs two different people - and reusing one would make
     * the permission assertions pass for the wrong reason.
     */
    private function userWithRole(string $role, string $entityId): User
    {
        $user = User::create([
            'name' => 'Check '.$role,
            'email' => $role.random_int(100000, 999999).'@check.test',
            'password' => 'x-not-a-real-password',
            'active' => true,
        ]);

        UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $entityId,
            'role_id' => Role::query()->where('key', $role)->firstOrFail()->getKey(),
        ]);

        return $user;
    }

    private function writeEntry(
        array $lines,
        ?string $rule = null,
        ?string $entryId = null,
        array $dimensions = [],
    ): ?string {
        $entryId ??= (string) Str::ulid();
        $savepoint = 'entry_'.bin2hex(random_bytes(4));

        $this->connection->statement('SAVEPOINT '.$savepoint);

        try {
            $this->connection->statement(
                "SELECT set_config('peakflow.posting_rule', ?, true)",
                [$rule ?? ''],
            );

            $this->connection->table('journal_entries')->insert([
                'id' => $entryId,
                'entity_id' => $this->entityId,
                'journal_id' => $this->journalId,
                'period_id' => $this->periodId,
                'number' => 'CHK-'.Str::ulid(),
                'accounting_date' => $this->postingDate,
                'status' => 'posted',
                'posting_rule' => $rule,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             | Dimensions on the FIRST line only.
             |
             | Which is the realistic shape - the expense line carries the
             | cost centre and the cash line does not - and it also means a
             | required-dimension check is testing the account that requires
             | one rather than both accounts at once.
             |
             | Inserted one row at a time, so the two lines need not carry
             | identical keys. A multi-row insert would demand that, and
             | giving one line a dimension and the other none produced
             | "VALUES lists must all be the same length" - a message that
             | names the symptom and only appears once a dimension is set.
             */
            foreach ($lines as $index => [$code, $amount]) {
                $this->connection->table('journal_lines')->insert(array_merge([
                    'id' => (string) Str::ulid(),
                    'entity_id' => $this->entityId,
                    'journal_entry_id' => $entryId,
                    'accounting_date' => $this->postingDate,
                    'period_id' => $this->periodId,
                    'account_id' => $this->accounts[$code],
                    'amount' => $amount,
                    'currency' => 'SAR',
                    'functional_amount' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $index === 0 ? $dimensions : []));
            }

            // The whole point: make the deferred trigger fire now.
            $this->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->connection->statement('SET CONSTRAINTS ALL DEFERRED');

            $this->connection->statement('RELEASE SAVEPOINT '.$savepoint);

            return null;
        } catch (Throwable $e) {
            $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);

            return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 240);
        }
    }

    private function seed(): void
    {
        $context = app(EntityContext::class);
        $context->forget();

        $entity = Entity::create([
            'code' => 'CHK'.random_int(100, 999),
            'legal_name' => 'Ledger check',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $this->entityId = (string) $entity->getKey();
        $context->use($entity);

        /*
         | Nothing is seeded here on purpose.
         |
         | Creating an entity now opens its ledger: the EntityCreated listener
         | applies the chart template and generates this year and the next.
         | Seeding again would be idempotent for the chart and would collide
         | with the exclusion constraint for the calendar - which is what this
         | check did until the listener landed, and the collision is the
         | constraint working.
         |
         | So the check uses what a real entity actually has, which is also
         | what it should be testing.
         */
        $postingDate = Carbon::now()->startOfYear()->addMonths(2)->addDays(9);

        $period = app(FiscalCalendarGenerator::class)
            ->periodFor($this->entityId, $postingDate);

        if ($period === null) {
            throw new \RuntimeException(
                'A newly created entity has no period covering '.$postingDate->toDateString().
                ', so the EntityCreated listener did not open its calendar.'
            );
        }

        /*
         | Through the state machine, not an update.
         |
         | A period's state cannot move without a recorded transition - a
         | database trigger refuses it - because "who reopened this period
         | and why" is the first question in any review of a restated
         | figure. This check used a bare update and was caught by the
         | guard, which is the guard working.
         */
        app(PeriodStateMachine::class)->transition(
            $period,
            PeriodState::Open,
            'opened for the ledger enforcement checks',
        );

        $this->periodId = (string) $period->getKey();
        $this->postingDate = $postingDate->toDateString();

        $this->journalId = (string) $this->connection->table('journals')
            ->where('entity_id', $this->entityId)
            ->where('code', 'GEN')
            ->value('id');

        foreach (['1', '1.1.1.1', '1.1.1.2', '4.1', '5.1', '5.2'] as $code) {
            $this->accounts[$code] = (string) $this->connection->table('accounts')
                ->where('entity_id', $this->entityId)
                ->where('code', $code)
                ->value('id');
        }
    }

    private function tenant(): ?Tenant
    {
        try {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->first();
        } catch (Throwable) {
            return null;
        }
    }
}
