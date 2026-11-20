<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Catalog\Models\PaymentTerm;
use App\Tenant\Catalog\Models\PaymentTermInstalment;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Sales\Enums\DocumentType;
use App\Tenant\Ledger\Services\LedgerVerifier;
use App\Tenant\Ledger\Services\TrialBalance;
use App\Tenant\Reporting\Drill\Figure;
use App\Tenant\Reporting\Overview\Ageing;
use App\Tenant\Reporting\Services\AgingReport;
use App\Tenant\Reporting\Services\DrillDown;
use App\Tenant\Reporting\Services\GeneralLedgerDetail;
use App\Tenant\Reporting\Services\JournalRegister;
use App\Tenant\Reporting\Services\StatementOfCashFlows;
use App\Tenant\Settlement\Enums\PaymentDirection;
use App\Tenant\Settlement\Enums\PaymentMethod;
use App\Tenant\Settlement\Services\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The general ledger, the drill-down and the ageing - PF-188 to PF-190.
 *
 * ## What a check here is for
 *
 * **A figure that cannot be explained.** PF-189's promise is structural: if
 * a number cannot be explained, it is a bug. The failure is never an error
 * - it is a drill-down that returns plausible lines adding up to something
 * else, which teaches people the report is approximate. So the checks pass
 * the printed figure in and assert the lines come to it, and the one that
 * matters most walks a cash-flow line down to an invoice line.
 *
 * **A report that does not say what it is.** A register filtered to a cost
 * centre, an ageing on the document-date basis, buckets dividing at 14
 * rather than 30: each produces figures that are correct and unusable,
 * because two people reading them are answering different questions. The
 * checks assert the parameters are on the output AND that the filter was
 * actually applied - a stated filter that does nothing is worse than an
 * unstated one.
 *
 * **An instalment aged from the last of its due dates.** The error PF-190
 * names, and the one every ageing report in the wild makes: a document on
 * 30/60/90 terms aged from the ninetieth day reports two months of overdue
 * money as current. The fixture issues one and asserts it lands in three
 * buckets, because a fixture with simple terms cannot tell the two
 * implementations apart.
 *
 * **A permission that is checked on the screen and not in the service.**
 * Hidden on a page is not hidden: the figure is still in the JSON the page
 * fetched. So the levels are asked for as a user who may not see them, and
 * what comes back has to say that something is there and is withheld -
 * never a blank, which reads as nothing being there at all.
 */
final class RegisterRules
{
    /*
     | Aliased, because this suite's own `refreshFixtures()` has to call
     | the bank side's: a trait's definition shadows the one it imports and
     | there is no `parent::` for a trait, so without the alias the selling
     | and banking fixtures would stop being re-read after a savepoint
     | rollback and every check after the first would assert against a
     | stale period.
     */
    use Concerns\OpensBankAccounts {
        refreshFixtures as refreshTheBankSide;
    }
    use Concerns\ProvesTheLedger;

    /** Holds report.view, and neither audit.view nor payroll.view. */
    private ?User $accountant = null;

    /** Holds all three, so the same level can be asked both ways. */
    private ?User $controller = null;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['registers and drill-down (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();

            /*
             | Somebody is signed in before anything posts.
             |
             | `Poster` records `created_by_user_id` and
             | `posted_by_user_id` from the session, so a fixture that
             | posted anonymously would leave both null - and PF-189's
             | third level would be checked against entries nobody made.
             | The checks on who may SEE those names would pass on a
             | drill-down that had nothing to show.
             */
            $this->accountant = $this->aUser('accountant', 'Register Probe');
            $this->controller = $this->aUser('controller', 'Register Controller');
            $this->signInAs($this->accountant);

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- PF-188, the general ledger ------------------------
                'opening, movement and closing are one arithmetic' => fn (): array => $this->theColumnsAgree(),
                'and the opening balance is the trial balance\'s own figure' => fn (): array => $this->theOpeningIsTheLedgers(),
                'the running balance moves within an account and never across two' => fn (): array => $this->theRunningBalanceIsPerAccount(),
                'the rows come back in the order the report claims' => fn (): array => $this->theOrderIsFixed(),
                'a filter is stated on the output and applied to the rows' => fn (): array => $this->filtersAreStatedAndApplied(),
                'a filter naming something that does not exist is refused' => fn (): array => $this->unknownFiltersAreRefused(),
                'the same question digests the same, and a filter changes it' => fn (): array => $this->theDigestIsTheQuestion(),
                'every column the report declares exists on every row' => fn (): array => $this->theColumnsAreReal(),

                // ---- PF-188, the register ------------------------------
                'the register lists every entry in the period, drafts included' => fn (): array => $this->theRegisterIsComplete(),
                'and totals the posted lines only' => fn (): array => $this->theRegisterTotalsThePostedOnly(),

                // ---- PF-189, the drill-down ----------------------------
                'a figure drills to lines that come to it' => fn (): array => $this->theLinesComeToTheFigure(),
                'a movement is explained by its period\'s lines, not by a balance\'s' => fn (): array => $this->aMovementIsBoundedBelow(),
                'and says so when they do not' => fn (): array => $this->aFigureThatDoesNotAddUp(),
                'a presentation sign is not reported as a difference' => fn (): array => $this->aSignIsNotADifference(),
                'a cash-flow line drills to the invoice line behind it' => fn (): array => $this->fromCashFlowToAnInvoiceLine(),
                'the drill-down and the statement agree on which accounts a line covers' => fn (): array => $this->theAccountSetAgrees(),
                'who entered and posted it is withheld, not blanked' => fn (): array => $this->thePeopleLevelIsGated(),
                'and named for somebody who holds audit.view' => fn (): array => $this->thePeopleLevelOpens(),
                'a drill-down with nobody signed in is refused' => fn (): array => $this->noViewerIsNoAccess(),
                'an entry from another entity is refused, identically' => fn (): array => $this->aForeignEntryIsRefused(),
                'every document type the map claims to open can be opened' => fn (): array => $this->theDocumentMapMatchesTheSchema(),
                'a salary document demands its own permission, which report.view is not' => fn (): array => $this->aPayrollDocumentDemandsPayrollView(),

                // ---- PF-190, the ageing -------------------------------
                'the basis and the buckets are stated on both sides' => fn (): array => $this->theAgeingStatesItself(),
                'an instalment document is split by each due date' => fn (): array => $this->instalmentsSplitAcrossBuckets(),
                'unapplied cash is a negative bucket, never netted away' => fn (): array => $this->unappliedCashIsNegative(),
                'the ageing reconciles to the control account and says so' => fn (): array => $this->theAgeingReconciles(),
                'and shouts when it does not' => fn (): array => $this->theAgeingShoutsWhenItDoesNot(),
                'DSO states its formula and refuses an empty window' => fn (): array => $this->dsoStatesItsFormula(),
                'boundaries that make an impossible bucket are refused' => fn (): array => $this->badBoundariesAreRefused(),
                'the boundaries reach the payables side' => fn (): array => $this->boundariesReachPayables(),
                'the default buckets are the ones the Overview tile uses' => fn (): array => $this->theDefaultsMatchTheTile(),
                'a register exports through the one export path, parameters first' => fn (): array => $this->theRegisterExports(),
                'and a closed period\'s file does not move when a later one does' => fn (): array => $this->theFileIsByteStable(),
                'receivables and payables are compared to their control accounts' => fn (): array => $this->theSubledgersTie(),
                'a credit note against an invoice is subtracted once, not twice' => fn (): array => $this->aCreditIsSubtractedOnce(),
                'a partial invoice is money owed everywhere that asks' => fn (): array => $this->aPartialInvoiceIsOwed(),
            ]));
        } catch (Throwable $e) {
            return ['the registers could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 400),
            ]];
        } finally {
            Auth::guard('web')->forgetUser();
            $this->connection->rollBack();
        }
    }

    // -----------------------------------------------------------------
    //  PF-188 - the general ledger detail
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function theColumnsAgree(): array
    {
        /*
         | One invoice BEFORE the window and one inside it.
         |
         | With an opening of zero the movement equals the closing
         | balance, so a report that printed the closing figure in the
         | movement column would pass. Two dates are the only way to tell
         | them apart.
         */
        $this->issue();
        $this->onDate('2026-05-20', fn () => $this->issue());

        $report = $this->gl($this->receivableCode(), '2026-05-19', '2026-05-31');
        $block = $report['account'];

        $opening = Money::of((string) $block['opening'], 'SAR');
        $movement = Money::of((string) $block['movement'], 'SAR');
        $closing = Money::of((string) $block['closing'], 'SAR');
        $problems = [];

        if ($opening->isZero()) {
            $problems[] = 'the fixture left an opening balance of zero, so the movement and the closing '.
                'balance are the same number and this check cannot tell them apart';
        }

        if (! $opening->plus($movement)->equals($closing)) {
            $problems[] = sprintf(
                'opening %s plus movement %s is not the closing balance %s. The three columns have to '.
                'be one arithmetic, or two of them disagree and nobody can say which is the error.',
                (string) $opening->amount,
                (string) $movement->amount,
                (string) $closing->amount,
            );
        }

        $rows = $block['rows'];
        $last = $rows === [] ? null : $rows[\count($rows) - 1];

        if ($last === null) {
            $problems[] = 'an account with two postings in the window produced no rows at all';
        } elseif (! Money::of((string) $last['running_balance'], 'SAR')->equals($closing)) {
            $problems[] = sprintf(
                'the last running balance is %s and the block closes at %s. The running column IS the '.
                'closing balance; two figures mean the column is being computed twice.',
                (string) $last['running_balance'],
                (string) $closing->amount,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function theOpeningIsTheLedgers(): array
    {
        $this->issue();
        $this->onDate('2026-05-20', fn () => $this->issue());

        $report = $this->gl($this->receivableCode(), '2026-05-19', '2026-05-31');
        $block = $report['account'];

        /*
         | Against the TRIAL BALANCE, not against the report's own rows.
         |
         | The opening balance is computed here from the lines before the
         | window, which is the only form that can obey a filter. That
         | makes it a second read of the same ledger, and two reads
         | agreeing is the only thing that makes either worth printing -
         | so the unfiltered case is asserted against the one definition
         | of a balance.
         */
        $problems = [];

        foreach ([
            ['2026-05-18', (string) $block['opening'], 'opening'],
            ['2026-05-31', (string) $block['closing'], 'closing'],
        ] as [$date, $reported, $column]) {
            $fromLedger = $this->trialBalanceFigure($this->receivableCode(), $date);

            if (! Money::of($reported, 'SAR')->equals($fromLedger)) {
                $problems[] = sprintf(
                    'the %s column says %s and the trial balance as at %s says %s. One of the two is '.
                    'not the account\'s balance, and both would be reconciled against.',
                    $column,
                    $reported,
                    $date,
                    (string) $fromLedger->amount,
                );
            }
        }

        if ((string) $block['opening_basis'] === '') {
            $problems[] = 'the block does not say what its opening balance is. Under a filter it is the '.
                'filtered population\'s balance and not the account\'s, and a reader who takes the '.
                'first for the second finds a difference that is not an error';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theRunningBalanceIsPerAccount(): array
    {
        $this->issue();

        $report = $this->activity('2026-05-01', '2026-05-31');
        $blocks = $report['accounts'];

        if (\count($blocks) < 2) {
            return ['an issued invoice touched fewer than two accounts, so a running balance that '.
                'carried across accounts would be invisible to this check'];
        }

        $problems = [];

        foreach ($blocks as $block) {
            $rows = $block['rows'];

            if ($rows === []) {
                continue;
            }

            $expected = Money::of((string) $block['opening'], 'SAR')
                ->plus(Money::of((string) $rows[0]['debit'], 'SAR'))
                ->minus(Money::of((string) $rows[0]['credit'], 'SAR'));

            if (! Money::of((string) $rows[0]['running_balance'], 'SAR')->equals($expected)) {
                $problems[] = sprintf(
                    'account %s opens at %s and its first running balance is %s rather than %s, so the '.
                    'column carried a total in from the account above it - which reads as a balance and '.
                    'is the sum of two unrelated accounts.',
                    (string) $block['code'],
                    (string) $block['opening'],
                    (string) $rows[0]['running_balance'],
                    (string) $expected->amount,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theOrderIsFixed(): array
    {
        $this->issue();
        $this->onDate('2026-05-20', fn () => $this->issue());

        $rows = $this->gl($this->receivableCode(), '2026-05-01', '2026-05-31')['account']['rows'];

        if (\count($rows) < 2) {
            return ['fewer than two rows, so no order could be wrong'];
        }

        $problems = [];
        $previous = null;

        foreach ($rows as $row) {
            $key = [(string) $row['date'], (string) $row['journal'], (string) $row['entry_number'], (string) $row['line_id']];

            if ($previous !== null && $key < $previous) {
                $problems[] = sprintf(
                    'row %s/%s on %s comes after %s/%s on %s. The order is date, journal, entry, line - '.
                    'and the running balance column is only reproducible while it holds.',
                    (string) $row['journal'],
                    (string) $row['entry_number'],
                    (string) $row['date'],
                    $previous[1],
                    $previous[2],
                    $previous[0],
                );
            }

            $previous = $key;
        }

        $again = $this->gl($this->receivableCode(), '2026-05-01', '2026-05-31')['account']['rows'];

        if (json_encode($again) !== json_encode($rows)) {
            $problems[] = 'two runs of the same register returned different rows. The figures may be '.
                'right either way and the report is not reproducible, which is what PF-188 asks of it';
        }

        return $problems;
    }

    /** @return list<string> */
    private function filtersAreStatedAndApplied(): array
    {
        /*
         | A customer invoice AND a posted supplier bill.
         |
         | The filter has to have something to exclude. With only the
         | invoice in the window every line on it carries the customer, so
         | filtering to that customer returned all three and the check
         | passed on a filter that did nothing - which it then proved, by
         | reporting three of three.
         */
        $this->issue();
        $this->postABill();

        $unfiltered = $this->activity('2026-05-01', '2026-05-31');
        $filtered = $this->activity('2026-05-01', '2026-05-31', partyId: (string) $this->party?->getKey());

        $problems = [];
        $stated = $filtered['parameters']['filters'];

        if (! isset($stated['party'])) {
            $problems[] = 'a register filtered to a party does not say so on its output. Two people '.
                'reading the same page are then answering different questions and neither can tell';
        } elseif ((string) $stated['party'] !== 'Acme Industrial LLC') {
            $problems[] = sprintf(
                'the filter block names "%s" and the filter was the customer Acme Industrial LLC. A '.
                'report stating a filter it did not apply is worse than one stating none.',
                (string) $stated['party'],
            );
        }

        /*
         | And the rows actually narrowed.
         |
         | A stated filter that is not applied is the failure this pair
         | exists for: the page says "filtered to Acme" above a list of
         | everybody, and it is believed precisely because it says so.
         */
        if ($filtered['lines'] >= $unfiltered['lines']) {
            $problems[] = sprintf(
                'the party filter left %d lines of %d, so it was stated and not applied',
                (int) $filtered['lines'],
                (int) $unfiltered['lines'],
            );
        }

        if ($filtered['lines'] === 0) {
            $problems[] = 'the party filter removed every line, so this check would pass on a register '.
                'that always returns nothing';
        }

        foreach ($filtered['accounts'] as $block) {
            if (! str_contains((string) $block['opening_basis'], 'not the account\'s')) {
                $problems[] = sprintf(
                    'account %s was filtered and its opening basis reads "%s". Under a filter the '.
                    'opening is the filtered population\'s balance, and a reader who reconciles it '.
                    'against the trial balance finds a difference that is not an error.',
                    (string) $block['code'],
                    (string) $block['opening_basis'],
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function unknownFiltersAreRefused(): array
    {
        $problems = [];

        $attempts = [
            'an account code' => fn (): array => $this->gl('NOPE-9999', '2026-05-01', '2026-05-31'),
            'a party id' => fn (): array => $this->activity(
                '2026-05-01',
                '2026-05-31',
                partyId: str_repeat('9', 26),
            ),
            'a journal code' => fn (): array => $this->activity(
                '2026-05-01',
                '2026-05-31',
                journalCode: 'NOSUCH',
            ),
            'a dimension value' => fn (): array => $this->activity(
                '2026-05-01',
                '2026-05-31',
                dimensions: ['cost_centre' => 'NOSUCH'],
            ),
        ];

        foreach ($attempts as $what => $attempt) {
            $refusal = $this->refusal($attempt);

            if ($refusal === null) {
                $problems[] = sprintf(
                    '%s that does not exist was accepted. The register comes back empty, and an empty '.
                    'register is read as "nothing happened here" - which is the answer somebody acts '.
                    'on.',
                    ucfirst($what),
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theDigestIsTheQuestion(): array
    {
        $this->issue();

        $first = $this->activity('2026-05-01', '2026-05-31')['parameters']['digest'];
        $second = $this->activity('2026-05-01', '2026-05-31')['parameters']['digest'];
        $filtered = $this->activity(
            '2026-05-01',
            '2026-05-31',
            partyId: (string) $this->party?->getKey(),
        )['parameters']['digest'];

        $problems = [];

        if ($first !== $second) {
            $problems[] = 'the same question digested differently twice, so nothing can ever be '.
                'compared against it - which is the one guarantee PF-198 asks for, and it is needed '.
                'exactly where the run time differs';
        }

        /*
         | And the clock is excluded BY CONSTRUCTION, not by being fast.
         |
         | Two runs a millisecond apart share a timestamp to the second,
         | so comparing two calls proves nothing about the clock: the bite
         | sweep put `run_at` into the digest and this check passed. The
         | two parameter sets below differ only in the run time, which is
         | the case the guarantee is for - a closed period's report run
         | again next month.
         */
        $monday = \App\Tenant\Reporting\Registers\ReportParameters::for(
            report: 'general_ledger_detail',
            entityId: $this->entityId,
            from: Carbon::parse('2026-05-01'),
            until: Carbon::parse('2026-05-31'),
            currency: 'SAR',
            runAt: Carbon::parse('2026-06-01 09:00:00'),
        );

        $tuesday = \App\Tenant\Reporting\Registers\ReportParameters::for(
            report: 'general_ledger_detail',
            entityId: $this->entityId,
            from: Carbon::parse('2026-05-01'),
            until: Carbon::parse('2026-05-31'),
            currency: 'SAR',
            runAt: Carbon::parse('2026-07-14 17:42:11'),
        );

        if ($monday->digest() !== $tuesday->digest()) {
            $problems[] = 'the same question asked six weeks apart digests differently, so a report of '.
                'a closed period can never be compared with itself - which is the only case the '.
                'guarantee was written for';
        }

        if ($monday->toArray()['run_at'] === $tuesday->toArray()['run_at']) {
            $problems[] = 'the run time is not on the output, so "when was this taken" - the first '.
                'question asked of a printout found in a file - has no answer';
        }

        if ($first === $filtered) {
            $problems[] = 'a register filtered to one customer digests the same as the whole entity\'s. '.
                'A filter changes every figure on the output, so the two are different questions and a '.
                'digest that calls them one is worse than no digest';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theColumnsAreReal(): array
    {
        $this->issue();

        $report = $this->gl($this->receivableCode(), '2026-05-01', '2026-05-31');
        $problems = [];
        $rows = $report['account']['rows'];

        if ($rows === []) {
            return ['no rows, so the declared columns prove nothing'];
        }

        foreach ($report['columns'] as $column) {
            foreach ($rows as $row) {
                if (! array_key_exists((string) $column['key'], $row)) {
                    $problems[] = sprintf(
                        'the report declares a "%s" column and no row carries one, so an export built '.
                        'from the column list writes a header over an empty column',
                        (string) $column['key'],
                    );

                    break;
                }
            }

            if ((string) $column['label_ar'] === '' || (string) $column['label_en'] === '') {
                $problems[] = sprintf('the "%s" column has no label in one of the two languages', (string) $column['key']);
            }
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  PF-188 - the journal register
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function theRegisterIsComplete(): array
    {
        $this->issue();

        /*
         | A draft entry in the window, because completeness is the claim.
         |
         | The first version entered a bill instead, which posts nothing
         | at all - so the period held no draft, and the bite that filtered
         | drafts out of the register changed nothing and this check passed.
         | A draft sitting in a period about to close is exactly what an
         | auditor is looking for.
         */
        $this->aDraftJournal();

        $register = $this->register('2026-05-01', '2026-05-31');

        $inLedger = (int) $this->connection->table('journal_entries')
            ->where('entity_id', $this->entityId)
            ->whereBetween('accounting_date', ['2026-05-01', '2026-05-31'])
            ->count();

        $problems = [];

        if (\count($register['entries']) !== $inLedger) {
            $problems[] = sprintf(
                'the register lists %d entries and the period holds %d. An entry missing from the '.
                'register is one an auditor can never select, and no total finds it.',
                \count($register['entries']),
                $inLedger,
            );
        }

        $byJournal = 0;

        foreach ($register['by_journal'] as $journal) {
            $byJournal += (int) $journal['entries'];
        }

        if ($byJournal !== \count($register['entries'])) {
            $problems[] = sprintf(
                'the per-journal summary accounts for %d of %d entries, so a sample drawn from the '.
                'summary is not a sample of the period',
                $byJournal,
                \count($register['entries']),
            );
        }

        foreach ($register['entries'] as $entry) {
            if ((string) $entry['entry_id'] === '' || (string) $entry['number'] === '') {
                $problems[] = 'an entry is listed with no id or no number, so it cannot be opened from '.
                    'the register it was sampled from';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theRegisterTotalsThePostedOnly(): array
    {
        $this->issue();
        $this->aDraftJournal();

        $register = $this->register('2026-05-01', '2026-05-31');

        /*
         | Against a direct read of the ledger, not against the register's
         | own rows.
         |
         | The register sums what it listed; this sums what is there. Two
         | independent reads are the only way to notice a register that is
         | consistent with itself and short of a line.
         */
        $posted = $this->connection->selectOne(<<<'SQL'
            SELECT coalesce(sum(l.functional_amount) FILTER (WHERE l.functional_amount > 0), 0)::text AS debits
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE l.entity_id = cast(? AS char(26))
              AND e.status IN ('posted', 'reversed')
              AND l.accounting_date BETWEEN cast(? AS date) AND cast(? AS date)
        SQL, [$this->entityId, '2026-05-01', '2026-05-31']);

        $problems = [];
        $expected = Money::of((string) $posted->debits, 'SAR');

        if (! Money::of((string) $register['total_debits'], 'SAR')->equals($expected)) {
            $problems[] = sprintf(
                'the register totals %s of debits and the period\'s posted lines come to %s. A register '.
                'that counts a draft does not tie to the trial balance, which is the first thing it is '.
                'reconciled against.',
                (string) $register['total_debits'],
                (string) $expected->amount,
            );
        }

        if ((int) $register['draft_entries'] < 1) {
            $problems[] = 'the fixture left no draft in the period, so a register that silently counted '.
                'drafts would pass this check';
        }

        $draftIsListed = false;

        foreach ($register['entries'] as $entry) {
            if ((string) $entry['status'] === 'draft') {
                $draftIsListed = true;

                if ((bool) $entry['counted'] !== false) {
                    $problems[] = 'a draft entry is marked as counted in the totals';
                }
            }
        }

        if (! $draftIsListed) {
            $problems[] = 'a draft entry in the period is not on the register at all. A draft sitting in '.
                'a period about to be closed is exactly what somebody needs to see, and hiding it is '.
                'how a month gets closed over an unposted document';
        }

        if (! str_contains((string) $register['declaration']['en'], 'draft')) {
            $problems[] = 'the register carries a draft and its declaration does not mention one, so '.
                'the reader has no way to know why its totals differ from its rows';
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  PF-189 - the drill-down
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function theLinesComeToTheFigure(): array
    {
        /*
         | Two invoices, so the figure has more than one line behind it.
         |
         | With one line, a drill-down that totalled only the first page
         | of its own lines would still add up - and that is a real bug
         | shape, not an invented one: the page is paginated and the total
         | is taken from the rows in hand.
         */
        $this->issue();
        $this->onDate('2026-05-20', fn () => $this->issue());

        $balance = $this->trialBalanceFigure($this->receivableCode(), '2026-05-31');

        $explained = app(DrillDown::class)->explain(
            Figure::asAt(
                report: 'trial_balance',
                kind: 'account',
                value: $this->receivableCode(),
                entityId: $this->entityId,
                currency: 'SAR',
                until: Carbon::parse('2026-05-31'),
            ),
            $this->accountant,
            (string) $balance->amount,
        );

        $problems = [];

        if ($explained['lines'] === []) {
            return ['an account carrying a balance drilled into no lines at all, which is the one '.
                'conclusion PF-189 exists to make impossible'];
        }

        if (($explained['reconciliation']['agrees'] ?? false) !== true) {
            $problems[] = sprintf(
                'the lines behind the figure come to %s and the figure is %s. A drill-down that does '.
                'not add up to its own total teaches people the report is approximate.',
                (string) $explained['total'],
                (string) $balance->amount,
            );
        }

        if ((string) $explained['declaration']['severity'] !== 'ok') {
            $problems[] = 'the lines add up and the drill-down does not say so: '
                .(string) $explained['declaration']['en'];
        }

        return $problems;
    }

    /** @return list<string> */
    private function aMovementIsBoundedBelow(): array
    {
        /*
         | One posting before the window and one inside it.
         |
         | A movement figure explained with a balance's lines is the
         | commonest drill-down defect there is, and it is invisible when
         | every posting happens to fall inside the window - which the bite
         | sweep proved by dropping the lower bound and changing nothing.
         | Two dates are the only way to tell the two apart.
         */
        $this->issue();
        $this->onDate('2026-05-20', fn () => $this->issue());

        $movement = $this->trialBalanceFigure($this->receivableCode(), '2026-05-31')
            ->minus($this->trialBalanceFigure($this->receivableCode(), '2026-05-19'));

        $explained = app(DrillDown::class)->explain(
            Figure::forPeriod(
                report: 'trial_balance',
                kind: 'account',
                value: $this->receivableCode(),
                entityId: $this->entityId,
                currency: 'SAR',
                from: Carbon::parse('2026-05-20'),
                until: Carbon::parse('2026-05-31'),
            ),
            $this->accountant,
            (string) $movement->amount,
        );

        $problems = [];

        if (\count($explained['lines']) !== 1) {
            $problems[] = sprintf(
                'a window holding one of the account\'s two postings drilled into %d lines. A movement '.
                'explained by every line since inception is a figure whose explanation is bigger than '.
                'it, and the reader concludes the report is wrong.',
                \count($explained['lines']),
            );
        }

        if (($explained['reconciliation']['agrees'] ?? false) !== true) {
            $problems[] = sprintf(
                'the movement is %s and the lines in the window come to %s',
                (string) $movement->amount,
                (string) $explained['total'],
            );
        }

        if (($explained['figure']['is_balance'] ?? true) !== false) {
            $problems[] = 'a figure with both bounds is reported as a balance, so the reader cannot '.
                'tell which question the lines below answer';
        }

        return $problems;
    }

    /** @return list<string> */
    private function aFigureThatDoesNotAddUp(): array
    {
        $this->issue();

        /*
         | A balance drilled with a figure that is not it.
         |
         | The usual real cause is a balance explained with a period's
         | bounds - the lines then come to the movement, which is smaller
         | and looks like an explanation. Here the wrong figure is handed
         | in directly, because what is under test is whether the
         | drill-down NOTICES.
         */
        $explained = app(DrillDown::class)->explain(
            Figure::asAt(
                report: 'trial_balance',
                kind: 'account',
                value: $this->receivableCode(),
                entityId: $this->entityId,
                currency: 'SAR',
                until: Carbon::parse('2026-05-31'),
            ),
            $this->accountant,
            '999.0000',
        );

        $problems = [];

        if (($explained['reconciliation']['agrees'] ?? true) !== false) {
            $problems[] = 'a figure the lines do not come to was reported as agreeing, so the '.
                'reconciliation cannot fail and proves nothing';
        }

        if ((string) $explained['declaration']['severity'] !== 'broken') {
            $problems[] = sprintf(
                'the lines do not come to the figure and the declaration says "%s". A figure that '.
                'cannot be explained is a bug, so the drill-down has to say so where it is read.',
                (string) $explained['declaration']['severity'],
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function aSignIsNotADifference(): array
    {
        $this->issue();

        $balance = $this->trialBalanceFigure($this->revenueCode(), '2026-05-31');

        if ($balance->isZero()) {
            return ['the fixture posted no revenue, so there is no credit balance to present'];
        }

        /*
         | Revenue is a credit, stored negative, and presented positive on
         | a statement of profit or loss. Handing in the presented figure
         | must not be reported as a difference, or every liability and
         | every revenue line on every statement would cry wolf - and the
         | day there was a real difference, nobody would be reading.
         */
        $explained = app(DrillDown::class)->explain(
            Figure::asAt(
                report: 'profit_or_loss',
                kind: 'account',
                value: $this->revenueCode(),
                entityId: $this->entityId,
                currency: 'SAR',
                until: Carbon::parse('2026-05-31'),
            ),
            $this->accountant,
            (string) $balance->negated()->amount,
        );

        $problems = [];

        if (($explained['reconciliation']['agrees'] ?? false) !== true) {
            $problems[] = 'the same magnitude with the statement\'s presentation sign was reported as a '.
                'difference, which would cry wolf on every credit balance in the product';
        }

        if (! str_contains((string) ($explained['reconciliation']['why'] ?? ''), 'presentation')) {
            $problems[] = 'the sign difference was accepted and not explained, so the reader cannot '.
                'tell it from an agreement that happened to be exact';
        }

        return $problems;
    }

    /** @return list<string> */
    private function fromCashFlowToAnInvoiceLine(): array
    {
        $invoice = $this->issue();

        $statement = app(StatementOfCashFlows::class)->forPeriod(
            $this->entityId,
            'SAR',
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31'),
        );

        $line = null;

        foreach ($statement['indirect']['sections'] as $section) {
            foreach ($section['lines'] as $candidate) {
                if ((string) $candidate['line'] === 'trade_and_other_receivables') {
                    $line = $candidate;
                }
            }
        }

        if ($line === null) {
            return ['the cash flow statement has no receivables line to drill into, so the fixture '.
                'cannot exercise the path PF-189 names'];
        }

        $drill = app(DrillDown::class);

        $figure = Figure::forPeriod(
            report: 'statement_of_cash_flows',
            kind: 'statement_line',
            value: 'trade_and_other_receivables',
            entityId: $this->entityId,
            currency: 'SAR',
            from: Carbon::parse('2026-05-01'),
            until: Carbon::parse('2026-05-31'),
            labelEn: 'Trade and other receivables',
        );

        $explained = $drill->explain($figure, $this->controller);
        $problems = [];
        $hop = null;

        foreach ($explained['lines'] as $candidate) {
            if ((string) ($candidate['source']['type'] ?? '') === 'invoice') {
                $hop = $candidate;
            }
        }

        if ($hop === null) {
            return ['the cash-flow line drilled to journal lines and none of them names an invoice, so '.
                'the path stops one level short of the document'];
        }

        $document = $drill->document($figure, (string) $hop['entry_id'], $this->controller);

        if (($document['document']['id'] ?? null) !== (string) $invoice->getKey()) {
            $problems[] = sprintf(
                'the drill-down reached document %s and the invoice posted was %s',
                (string) ($document['document']['id'] ?? '(none)'),
                (string) $invoice->getKey(),
            );
        }

        if ((string) ($document['document']['number'] ?? '') !== (string) $invoice->number) {
            $problems[] = 'the document level does not carry the number the document is known by, so '.
                'the reader cannot tell which invoice they have reached';
        }

        /*
         | And on to the LINE, which is where the criterion ends.
         |
         | A document level that stopped at the header would leave the
         | reader with "it came from INV-1001" - true, and not an
         | explanation of a figure that is the sum of particular lines.
         */
        $lines = $document['lines'] ?? [];

        if ($lines === []) {
            $problems[] = 'the document level reached an invoice and lists none of its lines. The '.
                'figure is the sum of particular lines, and "it came from this invoice" is not an '.
                'explanation of which';
        } else {
            $net = Money::zero('SAR');

            foreach ($lines as $documentLine) {
                $net = $net->plus(Money::of((string) $documentLine['net_amount'], 'SAR'));
            }

            if (! $net->equals($invoice->net())) {
                $problems[] = sprintf(
                    'the invoice lines reached come to %s and the invoice nets %s, so the drill-down '.
                    'has reached some of the document rather than the document',
                    (string) $net->amount,
                    (string) $invoice->net()->amount,
                );
            }
        }

        $levels = array_map(static fn (array $crumb): string => (string) $crumb['level'], $document['breadcrumb']);

        if (! \in_array('figure', $levels, true) || ! \in_array('source_document', $levels, true)) {
            $problems[] = 'the document level has no breadcrumb back to the figure, so a keyboard user '.
                'who drilled in has nowhere to return to';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theAccountSetAgrees(): array
    {
        $this->issue();

        $drill = app(DrillDown::class);

        $codes = $drill->accountsBehind(Figure::asAt(
            report: 'statement_of_financial_position',
            kind: 'statement_line',
            value: 'trade_and_other_receivables',
            entityId: $this->entityId,
            currency: 'SAR',
            until: Carbon::parse('2026-05-31'),
        ));

        $fromTheChart = $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('statement_line', 'trade_and_other_receivables')
            ->orderBy('code')
            ->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        $problems = [];

        if ($codes !== $fromTheChart) {
            $problems[] = sprintf(
                'the drill-down resolves the receivables line to %s and the chart classifies %s under '.
                'it. The statement groups by the chart, so a drill-down resolving a different set '.
                'explains a different number.',
                implode(', ', $codes) === '' ? '(none)' : implode(', ', $codes),
                implode(', ', $fromTheChart) === '' ? '(none)' : implode(', ', $fromTheChart),
            );
        }

        if ($codes === []) {
            $problems[] = 'no account is classified under the receivables line, so this check compares '.
                'two empty lists';
        }

        return $problems;
    }

    /** @return list<string> */
    private function thePeopleLevelIsGated(): array
    {
        $this->issue();

        $figure = $this->receivableFigure();
        $drill = app(DrillDown::class);
        $explained = $drill->explain($figure, $this->accountant);

        if ($explained['lines'] === []) {
            return ['no lines to drill into'];
        }

        $entryId = (string) $explained['lines'][0]['entry_id'];
        $people = $drill->people($figure, $entryId, $this->accountant);
        $problems = [];

        if (($people['access']['permitted'] ?? true) !== false) {
            $problems[] = 'an accountant, who does not hold audit.view, was shown who posted an entry. '.
                'Who did what is the audit trail and is gated as one, or a permission check on the '.
                'audit screen is a formality anybody can walk round through a report';
        }

        if (($people['entered_by'] ?? null) !== null || ($people['posted_by'] ?? null) !== null) {
            $problems[] = 'the names came back anyway, so the gate decides what is rendered and not '.
                'what is returned - and the figure is still in the JSON the page fetched';
        }

        /*
         | And it is not a blank.
         |
         | "Somebody entered this and somebody posted it, and you may not
         | see who" is an answer. A blank reads as an entry nobody is
         | accountable for, which is the opposite of what the ledger
         | knows.
         */
        if (($people['recorded']['posted'] ?? false) !== true) {
            $problems[] = 'the withheld level does not say that a person IS recorded, so it reads as an '.
                'entry nobody posted rather than as one whose poster is withheld';
        }

        if (! str_contains((string) ($people['why'] ?? ''), 'audit.view')) {
            $problems[] = 'the refusal does not name the permission that is missing, so the reader '.
                'cannot tell whether to ask for access or to report a bug';
        }

        return $problems;
    }

    /** @return list<string> */
    private function thePeopleLevelOpens(): array
    {
        $this->issue();

        $figure = $this->receivableFigure();
        $drill = app(DrillDown::class);
        $explained = $drill->explain($figure, $this->controller);

        if ($explained['lines'] === []) {
            return ['no lines to drill into'];
        }

        $people = $drill->people($figure, (string) $explained['lines'][0]['entry_id'], $this->controller);
        $problems = [];

        if (($people['access']['permitted'] ?? false) !== true) {
            $problems[] = 'a controller holding audit.view was refused the people level, so the gate '.
                'refuses everybody and the check above proves nothing';
        }

        if ((string) ($people['posted_by']['name'] ?? '') !== 'Register Probe') {
            $problems[] = sprintf(
                'the entry was posted while the accountant was signed in and the level names "%s". The '.
                'ledger records who posted; a drill-down that cannot say is not an explanation.',
                (string) ($people['posted_by']['name'] ?? '(nobody)'),
            );
        }

        if (! array_key_exists('same_person', $people)) {
            $problems[] = 'the level does not say whether the person who drafted the entry also posted '.
                'it, which is the whole of maker-checker and the one thing this level is read for';
        }

        return $problems;
    }

    /** @return list<string> */
    private function noViewerIsNoAccess(): array
    {
        $this->issue();

        $explained = app(DrillDown::class)->explain($this->receivableFigure(), null);
        $problems = [];

        if (($explained['access']['permitted'] ?? true) !== false) {
            $problems[] = 'a drill-down with nobody signed in was permitted. A permission question with '.
                'no subject has one honest answer, and the alternative is a console command or a queued '.
                'job seeing everything';
        }

        if ($explained['lines'] !== []) {
            $problems[] = 'the lines came back with nobody signed in, so the access decision is '.
                'decoration';
        }

        if ((string) $explained['declaration']['severity'] !== 'withheld') {
            $problems[] = 'the drill-down returned nothing and did not say it was withheld, so an empty '.
                'result reads as a figure with nothing behind it';
        }

        return $problems;
    }

    /** @return list<string> */
    private function aForeignEntryIsRefused(): array
    {
        $refusal = $this->refusal(fn (): array => app(DrillDown::class)->document(
            $this->receivableFigure(),
            (string) Str::ulid(),
            $this->controller,
        ));

        if ($refusal === null) {
            return ['an entry id that does not exist in this entity was accepted, so a report screen '.
                'can be used to learn which entry ids exist in another company'];
        }

        /*
         | And refused on purpose, rather than crashing on a null.
         |
         | The bite sweep found this: with the guard removed the drill-down
         | dereferenced a missing entry, the error was caught, and a check
         | that only asked "did something throw" reported the refusal as
         | working. A caller catching ReportRefused would have been hit by
         | a TypeError instead, and the page would be a 500 rather than a
         | sentence.
         */
        if (! str_contains($refusal, 'ReportRefused')) {
            return [sprintf(
                'a foreign entry id threw, and not the product\'s refusal: %s. A caller catching '.
                'ReportRefused is hit by something else, so the page is a 500 where a sentence '.
                'belongs.',
                $refusal,
            )];
        }

        if (! str_contains($refusal, 'No entry with that id')) {
            return ['the refusal does not say what was not found, so a foreign id and a mistyped one '.
                'are the same answer to whoever is reading it'];
        }

        return [];
    }

    /** @return list<string> */
    private function theDocumentMapMatchesTheSchema(): array
    {
        $problems = [];

        foreach (DrillDown::documentTypes() as $type => $map) {
            $columns = $this->connection->select(
                'SELECT column_name FROM information_schema.columns WHERE table_name = ?',
                [$map['table']],
            );

            $names = array_map(static fn (object $row): string => (string) $row->column_name, $columns);

            if ($names === []) {
                $problems[] = sprintf(
                    'the map opens a %s from the table "%s", which does not exist. It would throw the '.
                    'first time anybody drilled into one - which is to say, in front of an auditor.',
                    $type,
                    $map['table'],
                );

                continue;
            }

            foreach (array_filter([$map['number'], $map['date'], 'entity_id', 'id']) as $column) {
                if (! \in_array((string) $column, $names, true)) {
                    $problems[] = sprintf(
                        'the map reads "%s" off %s and the column does not exist',
                        (string) $column,
                        $map['table'],
                    );
                }
            }

            if ($map['screen'] !== null && ! \Illuminate\Support\Facades\Route::has((string) $map['screen'])) {
                $problems[] = sprintf(
                    'the map sends a %s to the route "%s", which is not registered - so the link a '.
                    'reader follows out of the drill-down is dead',
                    $type,
                    (string) $map['screen'],
                );
            }

            if ((string) $map['label_ar'] === '' || (string) $map['label_en'] === '') {
                $problems[] = sprintf('the %s has no label in one of the two languages', $type);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function aPayrollDocumentDemandsPayrollView(): array
    {
        /*
         | ## What this proves, and what it does not
         |
         | A salary is the one figure in a business that everybody wants
         | and almost nobody should have, and `report.view` - which every
         | shipped role holds - must not be enough to reach one. So the
         | map has to demand `payroll.view`, and the two probe users have
         | to differ on it: that is what makes the gate meaningful rather
         | than a permission nobody is ever without.
         |
         | It does NOT walk a posted payroll run end to end. Doing that
         | needs an employee, an approved schedule, a prepared and
         | approved run and a visibility claim, and the claim is a WRITE -
         | which a report may not make. The level of the drill-down that
         | the claim guards is asserted end to end for `audit.view`
         | instead, through the same `maySee()` call, and the gap is
         | recorded here rather than left to be assumed.
         */
        $map = DrillDown::documentTypes();
        $problems = [];

        if (! isset($map['payroll_run'])) {
            return ['the drill-down does not know what a payroll run is, so an entry from one would be '.
                'reported as an unopenable type rather than as a document behind a permission'];
        }

        if ((string) ($map['payroll_run']['permission'] ?? '') !== 'payroll.view') {
            $problems[] = sprintf(
                'a payroll run is gated on "%s". `report.view` is held by every shipped role including '.
                'the auditor, so gating a salary on it is gating it on nothing.',
                (string) ($map['payroll_run']['permission'] ?? 'nothing'),
            );
        }

        if (($map['payroll_run']['claim'] ?? null) === null) {
            $problems[] = 'the payroll entry does not say that its rows sit behind a claim. A report '.
                'may not claim payroll visibility - claiming records who looked and why, which is a '.
                'write - so a drill-down that tried would either fail or write from a report';
        }

        if (($map['payroll_run']['lines'] ?? null) !== null) {
            $problems[] = 'the drill-down would list a payroll run\'s own lines, which are salaries per '.
                'employee. The figure on the report is the total; itemising it is a different question '.
                'and a far more sensitive one';
        }

        if ($this->accountant?->holds('payroll.view', $this->entityId) !== false) {
            $problems[] = 'the probe accountant holds payroll.view, so this check and the gate it '.
                'describes would pass for a user who can see everything';
        }

        if ($this->accountant?->holds('report.view', $this->entityId) !== true) {
            $problems[] = 'the probe accountant does not hold report.view, so the levels above are '.
                'being refused for the wrong reason';
        }

        if ($this->controller?->holds('payroll.view', $this->entityId) !== true) {
            $problems[] = 'nobody in the fixture holds payroll.view, so a gate that refused everybody '.
                'would look correct';
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  PF-190 - the ageing
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function theAgeingStatesItself(): array
    {
        $this->issue();
        $this->postABill();

        $problems = [];

        foreach ([
            'receivable' => $this->receivablesAgeing(),
            'payable' => $this->payablesAgeing(),
        ] as $side => $report) {
            if ((string) $report['basis'] === '') {
                $problems[] = $side.' ageing does not state its basis. The due date and the document '.
                    'date give different answers, and a report that does not say which is read two '.
                    'ways and believed both times';
            }

            if (($report['boundaries'] ?? []) !== [30, 60, 90]) {
                $problems[] = sprintf(
                    '%s ageing was asked for 30/60/90 and states %s. "1-30" means nothing without the '.
                    'boundaries printed beside it.',
                    $side,
                    implode('/', array_map(static fn (mixed $b): string => (string) $b, $report['boundaries'] ?? [])),
                );
            }

            if (! str_contains((string) $report['declaration']['en'], 'basis')) {
                $problems[] = $side.' ageing does not mention the basis in its declaration, which is '.
                    'the one part of the page a reader cannot miss';
            }

            if (! str_contains((string) ($report['parameters']['filters']['basis'] ?? ''), (string) $report['basis'])) {
                $problems[] = $side.' ageing\'s parameter block does not carry the basis, so an export '.
                    'of it loses the one thing that makes the figures interpretable';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function instalmentsSplitAcrossBuckets(): array
    {
        /*
         | 30/60/90 terms, aged from a date 75 days later.
         |
         | The three instalments then fall 75, 45 and 15 days past their
         | own due dates - three different buckets. A fixture on simple
         | terms cannot tell the two implementations apart: aged from the
         | last due date the whole balance is current, and that is the
         | error PF-190 names.
         */
        $this->issueOnInstalments();

        $report = $this->receivablesAgeing('2026-08-01');
        $rows = array_values(array_filter(
            $report['rows'],
            static fn (array $row): bool => (int) $row['instalments'] > 1,
        ));

        if ($rows === []) {
            return ['the instalment invoice produced no instalment rows, so the fixture did not '.
                'exercise the split at all'];
        }

        $problems = [];
        $buckets = array_unique(array_map(static fn (array $row): string => (string) $row['bucket'], $rows));

        if (\count($buckets) < 3) {
            $problems[] = sprintf(
                'a document on three instalment dates landed in %d bucket%s (%s). Aged from the last '.
                'due date the whole balance reads as current, which reports two months of overdue '.
                'money as money that is not due.',
                \count($buckets),
                \count($buckets) === 1 ? '' : 's',
                implode(', ', $buckets),
            );
        }

        $sum = Money::zero('SAR');

        foreach ($rows as $row) {
            $sum = $sum->plus(Money::of((string) $row['outstanding'], 'SAR'));
        }

        $open = Money::of((string) $this->connection->selectOne(
            "SELECT peakflow_document_open_balance('invoice', cast(? AS char(26)))::text AS open",
            [(string) $rows[0]['document_id']],
        )->open, 'SAR');

        if (! $sum->equals($open)) {
            $problems[] = sprintf(
                'the instalments come to %s and the document is open for %s. A split that does not add '.
                'up to the balance is a report whose total is not what anybody is owed.',
                (string) $sum->amount,
                (string) $open->amount,
            );
        }

        foreach ($rows as $row) {
            if ((string) $row['aged_from'] === '') {
                $problems[] = 'an instalment row does not say which date it was aged from, so a reader '.
                    'disputing a bucket has nothing to check';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function unappliedCashIsNegative(): array
    {
        $this->issue();
        $this->recordAReceipt('750.0000');

        $report = $this->receivablesAgeing();
        $unapplied = $report['unapplied_cash'];
        $problems = [];

        if ($unapplied['items'] === []) {
            return ['a receipt was recorded against nothing and the ageing shows no unapplied cash, so '.
                'the money was netted into what the customer owes and the old invoice disappeared from '.
                'the one report that would have made anybody ask'];
        }

        if (! Money::of((string) $unapplied['total'], 'SAR')->isNegative()) {
            $problems[] = sprintf(
                'unapplied cash is reported as %s. It is a credit on the customer\'s account, so it is '.
                'negative - shown positive it reads as more money owed.',
                (string) $unapplied['total'],
            );
        }

        $documents = Money::of((string) ($report['document_totals']['SAR'] ?? '0'), 'SAR');
        $totals = Money::of((string) ($report['totals']['SAR'] ?? '0'), 'SAR');

        if ($documents->equals($totals)) {
            $problems[] = 'the document total and the report total are the same figure, so the '.
                'unapplied receipt is either missing from the buckets or was netted into the documents';
        }

        if (! $totals->equals($documents->plus(Money::of((string) $unapplied['total'], 'SAR')))) {
            $problems[] = sprintf(
                'the buckets come to %s and the documents plus the credits come to %s, so the negative '.
                'buckets are not what the total is made of',
                (string) $totals->amount,
                (string) $documents->plus(Money::of((string) $unapplied['total'], 'SAR'))->amount,
            );
        }

        $negative = false;

        foreach ($report['buckets']['SAR'] ?? [] as $amount) {
            if (Money::of((string) $amount, 'SAR')->isNegative()) {
                $negative = true;
            }
        }

        if (! $negative && $documents->isZero()) {
            $problems[] = 'no bucket came back negative, so the credit was netted somewhere';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theAgeingReconciles(): array
    {
        $this->issue();

        $report = $this->receivablesAgeing();
        $reconciliation = $report['reconciliation'];
        $problems = [];

        if (($reconciliation['checked'] ?? false) !== true) {
            return ['the receivables ageing did not attempt the reconciliation: '
                .(string) ($reconciliation['why'] ?? '')];
        }

        if (($reconciliation['agrees'] ?? false) !== true) {
            $problems[] = sprintf(
                'one issued invoice and the ageing is out against the receivables control account by '.
                '%s. The two are the same number taken two ways.',
                (string) $reconciliation['difference'],
            );
        }

        if (Money::of((string) $reconciliation['control_account'], 'SAR')->isZero()) {
            $problems[] = 'the control account reads zero with an invoice issued, so this check '.
                'compares two zeros and would pass on an ageing that reported nothing';
        }

        if (! str_contains((string) $report['declaration']['en'], 'reconcile')) {
            $problems[] = 'the reconciliation is in a nested key and not in the declaration. PF-190 '.
                'asks the report to declare it rather than leave it to be discovered, and a reader '.
                'finds a nested key only when they already suspect something';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theAgeingShoutsWhenItDoesNot(): array
    {
        /*
         | A receipt banked against the ledger and not against a document.
         |
         | Which is the third cause the report's own sentence names, and
         | the honest way to produce the difference: the withholding
         | receipt rule credits the receivables control account for a
         | customer, and nothing allocates it to an invoice. The ledger
         | then says less is owed and the open documents still say the
         | whole invoice is - and both are used to chase money.
         |
         | Not a row written around the posting path, which is what a
         | fixture reaching for a journal to a control account would be,
         | and which the product refuses.
         */
        $this->issue();
        $this->bankAReceipt();

        $report = $this->receivablesAgeing();
        $reconciliation = $report['reconciliation'];
        $problems = [];

        if (($reconciliation['checked'] ?? false) !== true) {
            return ['the ageing did not attempt the reconciliation, so it cannot report a difference: '
                .(string) ($reconciliation['why'] ?? '')];
        }

        if (($reconciliation['agrees'] ?? false) !== false) {
            $problems[] = 'a receipt credited the receivables control account and settled no document, '.
                'so the ledger and the open documents disagree by exactly that receipt - and the report '.
                'says the two agree';
        }

        if ((string) $report['declaration']['severity'] !== 'broken') {
            $problems[] = sprintf(
                'the ageing disagrees with the control account and its declaration reads "%s". A '.
                'difference between the two is the one thing on this report that means somebody must '.
                'stop.',
                (string) $report['declaration']['severity'],
            );
        }

        if (! str_contains((string) $report['declaration']['en'], (string) $reconciliation['difference'])) {
            $problems[] = 'the declaration announces a difference without naming it, so the reader '.
                'cannot tell a rounding difference from a missing bill';
        }

        return $problems;
    }

    /** @return list<string> */
    private function dsoStatesItsFormula(): array
    {
        $this->issue();

        $report = $this->receivablesAgeing();
        $dso = $report['dso'];
        $problems = [];

        if (! str_contains((string) $dso['formula'], 'receivables')) {
            $problems[] = 'DSO is reported without its formula, so two people comparing their figures '.
                'cannot tell whether they computed the same ratio';
        }

        if ((int) $dso['days'] !== 90 || (string) $dso['from'] === '') {
            $problems[] = 'DSO does not state its window. DSO over a quarter and over a year are '.
                'different numbers, and a ratio quoted without its window cannot be reproduced';
        }

        if ($dso['dso'] === null) {
            $problems[] = 'an invoice was issued inside the window and DSO came back null, so the '.
                'sales denominator did not see it';
        }

        /*
         | And an empty window is null rather than zero.
         |
         | A DSO of zero reads as "our customers pay immediately", which
         | is the opposite of what a window with no sales means - and it
         | is the figure somebody would put in a board pack.
         */
        $empty = app(AgingReport::class)->daysSalesOutstanding(
            $this->entityId,
            'SAR',
            Carbon::parse('2025-01-01'),
            Carbon::parse('2025-03-31'),
        );

        if ($empty['dso'] !== null) {
            $problems[] = sprintf(
                'a window with no sales produced a DSO of %s. Zero reads as "our customers pay '.
                'immediately", which is the opposite of what an empty window means.',
                (string) $empty['dso'],
            );
        }

        if (! str_contains((string) $empty['why'], 'immediately')) {
            $problems[] = 'the empty window returns null without saying why, so the page shows a blank '.
                'where a sentence belongs';
        }

        return $problems;
    }

    /** @return list<string> */
    private function badBoundariesAreRefused(): array
    {
        $problems = [];

        $attempts = [
            'a repeated boundary' => [30, 30, 60],
            'a decreasing boundary' => [60, 30],
            'no boundaries at all' => [],
            'more buckets than anybody reads' => [5, 10, 15, 20, 25, 30, 35],
        ];

        foreach ($attempts as $what => $boundaries) {
            $refusal = $this->refusal(fn (): array => app(AgingReport::class)->receivables(
                $this->entityId,
                'SAR',
                Carbon::parse('2026-05-31'),
                boundaries: $boundaries,
            ));

            if ($refusal === null) {
                $problems[] = sprintf(
                    '%s was accepted. A bucket nothing can fall into prints a column of zeros, which '.
                    'reads as "nothing is that old".',
                    ucfirst($what),
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function boundariesReachPayables(): array
    {
        $this->postABill();

        $report = app(AgingReport::class)->payables(
            $this->entityId,
            'SAR',
            Carbon::parse('2026-05-31'),
            boundaries: [10, 20],
        );

        $problems = [];
        $expected = ['current', '1-10', '11-20', '20+'];

        if (($report['bucket_names'] ?? []) !== $expected) {
            $problems[] = sprintf(
                'the payables side was asked for buckets at 10/20 and names %s. A boundary the AP side '.
                'ignores makes one report with two grids, and the bucket names do not say so.',
                implode(', ', $report['bucket_names'] ?? []),
            );
        }

        foreach ($report['buckets'] as $currency => $set) {
            if (array_keys($set) !== $expected) {
                $problems[] = sprintf(
                    'the %s buckets are %s rather than %s, so the figures were bucketed on the default '.
                    'boundaries and labelled with the asked-for ones',
                    (string) $currency,
                    implode(', ', array_keys($set)),
                    implode(', ', $expected),
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theDefaultsMatchTheTile(): array
    {
        $this->issue();

        $report = $this->receivablesAgeing();

        if (($report['bucket_names'] ?? []) !== Ageing::BUCKETS) {
            return [sprintf(
                'the ageing report\'s default buckets are %s and the Overview tile\'s are %s. The tile, '.
                'the provision matrix and this report are read side by side, and two of them meaning '.
                'different things by "61-90" is a difference nobody would think to check.',
                implode(', ', $report['bucket_names'] ?? []),
                implode(', ', Ageing::BUCKETS),
            )];
        }

        return [];
    }

    // -----------------------------------------------------------------
    //  The fixture
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function gl(string $code, string $from, string $until): array
    {
        return app(GeneralLedgerDetail::class)->forAccount(
            $this->entityId,
            'SAR',
            $code,
            Carbon::parse($from),
            Carbon::parse($until),
        );
    }

    /**
     * @param  array<string, string>|null  $dimensions
     * @return array<string, mixed>
     */
    private function activity(
        string $from,
        string $until,
        ?string $partyId = null,
        ?string $journalCode = null,
        ?array $dimensions = null,
    ): array {
        return app(GeneralLedgerDetail::class)->activity(
            $this->entityId,
            'SAR',
            Carbon::parse($from),
            Carbon::parse($until),
            partyId: $partyId,
            journalCode: $journalCode,
            dimensions: $dimensions,
        );
    }

    /** @return array<string, mixed> */
    /**
     * The export is reached from production, and carries its provenance.
     *
     * ## Why this check matters more than it looks
     *
     * `CsvExport` and `ReportBasis` were both built for PF-197 and PF-198,
     * both correct, both with a suite of their own - and neither had a
     * production caller anywhere. The same shape as the three worst defects
     * found in this repository on 2026-11-18: `TaxPoint::derive()`,
     * `EvidenceGate` and `factor_asserted_at` were each built, ticked and
     * never reached, and each stayed invisible for exactly this reason -
     * the checks called them directly, so the checks passed.
     *
     * So this one does not construct the exporter. It asks the REGISTER for
     * a file, which is what a page would do, and then reads the bytes.
     *
     * @return list<string>
     */
    private function theRegisterExports(): array
    {
        $this->issue();

        $csv = app(JournalRegister::class)->csvForPeriod(
            $this->entityId,
            'SAR',
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31'),
        );

        $problems = [];

        if (! str_starts_with($csv, "\xEF\xBB\xBF")) {
            $problems[] = 'the register\'s file has no byte-order mark, so Excel on Windows reads every '.
                'Arabic name in the system code page and the person who exported it reports that the '.
                'Arabic is broken in this product';
        }

        $rows = explode("\r\n", ltrim($csv, "\xEF\xBB\xBF"));

        /*
         | The parameters are ABOVE the figures, and the check reads the
         | order rather than merely their presence.
         |
         | A printed export is detached from the page that made it within
         | about a day. In a footer the block is still in the bytes and is
         | below the 4,000th row, which is the same as not being there.
         */
        $digest = (string) ($this->register('2026-05-01', '2026-05-31')['parameters']['digest'] ?? '');

        $blockEnds = array_search('', $rows, true);

        if ($blockEnds === false || $blockEnds === 0) {
            $problems[] = 'the file has no parameter block separated from its header row. Without the '.
                'blank row a spreadsheet reads the first parameter as the header and every column is '.
                'named "Report".';

            return $problems;
        }

        $block = implode("\n", \array_slice($rows, 0, $blockEnds));

        if (! str_contains($block, substr($digest, 0, 16))) {
            $problems[] = 'the file does not carry the question\'s digest above its figures, so two '.
                'printouts on a desk cannot be shown to answer the same question';
        }

        if (! str_contains($block, '2026-05-01') || ! str_contains($block, '2026-05-31')) {
            $problems[] = 'the file does not state its own window, which is the first thing asked of a '.
                'sheet somebody found in a file six months later';
        }

        $header = $rows[$blockEnds + 1] ?? '';

        foreach (['date', 'journal', 'number', 'status'] as $column) {
            if (! str_contains($header, $column) && ! str_contains($header, 'التاريخ')) {
                $problems[] = sprintf(
                    'the header row is %s, which does not come from the register\'s declared columns - '.
                    'so the file and the screen have two column lists and the first reorder splits them',
                    $header,
                );

                break;
            }
        }

        return $problems;
    }

    /**
     * A closed period's file is the same file, whatever happens later.
     *
     * ## The guarantee, and the one part of it that cannot hold
     *
     * PF-198 asks for byte-stability proved by running a report twice
     * across a data change in a LATER period. Everything in the artefact
     * can honour that except the run clock, which is printed in the
     * parameter block and is by definition different on the second run.
     *
     * The answer is not to drop the clock - "when was this taken" is the
     * first question asked of a printout. It is to make the clock a
     * PARAMETER, so a caller who wants a reproducible artefact names the
     * instant. This check does both halves: with the instant named the two
     * files are byte-identical, and with it left out the ONLY line that
     * differs is the run-clock line. The second half is what proves the
     * first is a real guarantee rather than two identical calls.
     *
     * @return list<string>
     */
    private function theFileIsByteStable(): array
    {
        $this->issue();

        $named = Carbon::parse('2026-06-01T08:00:00+00:00');

        $before = app(GeneralLedgerDetail::class)->csvActivity(
            entityId: $this->entityId,
            functionalCurrency: 'SAR',
            from: Carbon::parse('2026-05-01'),
            until: Carbon::parse('2026-05-31'),
            ranAt: $named,
        );

        /*
         | A change in a LATER period, posted through the real service.
         |
         | A row written straight onto the table would not exercise the
         | thing under test: the question is whether the report's window
         | bounds its population, and a posting the ledger accepted is the
         | only kind that could leak into it.
         |
         | June has to be OPENED first. The fixture's calendar opens only
         | the month it works in, and the rest start `future` - which is
         | correct and is itself the refusal that caught this check's first
         | draft: a document cannot be issued into a future period, so the
         | "later change" silently became no change at all.
         */
        $june = app(\App\Tenant\Ledger\Services\FiscalCalendarGenerator::class)
            ->periodFor($this->entityId, Carbon::parse('2026-06-15'));

        app(\App\Tenant\Ledger\Services\PeriodStateMachine::class)->transition(
            $june,
            \App\Tenant\Ledger\Enums\PeriodState::Open,
            'opened so a later period can change while an earlier one is read',
        );

        $this->onDate('2026-06-15', fn (): object => $this->issue());

        $after = app(GeneralLedgerDetail::class)->csvActivity(
            entityId: $this->entityId,
            functionalCurrency: 'SAR',
            from: Carbon::parse('2026-05-01'),
            until: Carbon::parse('2026-05-31'),
            ranAt: $named,
        );

        $problems = [];

        if ($before !== $after) {
            $problems[] = sprintf(
                'a May register changed after a June posting: %d bytes became %d. Either the window '.
                'does not bound the population, or something in the file reads the clock - and a '.
                'report that cannot reproduce itself cannot be used as evidence of anything.',
                \strlen($before),
                \strlen($after),
            );
        }

        $unnamed = app(GeneralLedgerDetail::class)->csvActivity(
            entityId: $this->entityId,
            functionalCurrency: 'SAR',
            from: Carbon::parse('2026-05-01'),
            until: Carbon::parse('2026-05-31'),
        );

        $differing = array_values(array_diff(
            explode("\r\n", $unnamed),
            explode("\r\n", $after),
        ));

        if (\count($differing) !== 1) {
            $problems[] = sprintf(
                'leaving the run instant out changed %d lines rather than the one that prints it: %s. '.
                'The byte-stability guarantee is then about a caller passing the same arguments twice '.
                'and not about the report.',
                \count($differing),
                implode(' | ', \array_slice($differing, 0, 3)),
            );
        }

        return $problems;
    }

    /**
     * `ledger:verify` compares the subledgers to their control accounts.
     *
     * ## The gap, and why nothing noticed it
     *
     * The verifier composed ten checks and registered three more - suspense,
     * in-transit money, inventory. **Receivables and payables were compared
     * by nothing**, which is the first reconciliation an auditor performs.
     *
     * Three things made that read as covered. `LedgerVerifier` carried a
     * docblock claiming *"Each subledger control account equals the sum of
     * its own lines"*, which described a method two hundred lines below that
     * `verify()` does not call. `controlAccountsPostedManually()` reports a
     * manual posting whose stated consequence is *"The subledger and the
     * control account now disagree"* - and fires only when
     * `posting_rule IS NULL`. And `SuspenseIsTraceable`, arguing its own
     * finding is critical, borrows for authority *"the same class of fault
     * as a receivables control that does not agree with the customer
     * ledger"* - the very comparison nothing made.
     *
     * ## The injection, and why it is this one
     *
     * A balanced pair of lines on a posted entry: one on the receivables
     * control account, one on an expense account, with the entry's
     * `posting_rule` left intact.
     *
     * Every existing check survives it. The entry balances, so
     * `unbalancedEntries()` is silent. A rule is recorded, so
     * `controlAccountsPostedManually()` is silent. The lines belong to an
     * entry in an open period at the entry's own date, so the period and
     * orphan checks are silent. The trial balance ties. Only the control
     * account has moved away from the customer ledger.
     *
     * ## It stands in for the real mechanism, and is not it
     *
     * Worth being exact about, because the difference matters to whoever
     * reads this next. The condition under test - the control account no
     * longer equal to the customer ledger - is real and this produces it.
     * The *route* is not: writing lines onto a posted entry is a tamper,
     * and the ledger has three checks aimed at tampers.
     *
     * The way this arises in production is a posting rule that moves a
     * control account by the wrong figure. The sales foreign-currency
     * defect fixed on 2026-11-18 did exactly that: a 1,000 EUR invoice
     * credited 1,000 of the entity's own currency to receivables, so the
     * control account disagreed with the customer ledger by the whole
     * translation. That version is *more* invisible than this one, not
     * less - the entry is correctly fingerprinted over its own wrong
     * figures, so every tamper check is satisfied as well.
     *
     * It is not injectable from here, because producing it means breaking
     * a posting rule, and a rule broken inside a check is a bite rather
     * than a fixture. The sweep for `fix(sales)` is where that was proved;
     * this is the check that would have caught it.
     *
     * A subledger tie is the one check that is not internal: it asks
     * whether the ledger agrees with the documents, which is the only
     * question a balanced wrong answer cannot survive.
     *
     * @return list<string>
     */
    private function theSubledgersTie(): array
    {
        $this->issue();

        $problems = [];

        $before = app(LedgerVerifier::class)->verify($this->entityId);

        if ($this->findingsAbout($before, 'receivables') !== []) {
            return ['the fixture already does not tie, so this check cannot tell its own injection '.
                'from the state it started in: '.implode(' | ', $this->findingsAbout($before, 'receivables'))];
        }

        $this->moveTheControlAccount($this->codeForRole(ControlRole::AccountsReceivable), '2500.0000');

        $after = app(LedgerVerifier::class)->verify($this->entityId);
        $named = $this->findingsAbout($after, 'receivables');

        if ($named === []) {
            /*
             | The list of what DID fire is in the message on purpose.
             |
             | "Nothing was reported" sends the next reader to look for a
             | check that does not exist. Naming the ten that ran and stayed
             | silent says which question was never asked.
             */
            $problems[] = sprintf(
                'the receivables control account was moved 2,500 away from the customer ledger and '.
                '`ledger:verify` reported nothing about receivables. The entry balances, a posting '.
                'rule is recorded and the trial balance ties, so every check that asks whether the '.
                'books are internally CONSISTENT is satisfied - and no check asks whether the ledger '.
                'agrees with the documents, which is the only question a balanced wrong answer '.
                'cannot survive. What did fire: %s',
                $after->isEmpty() ? '(nothing at all)' : $after->pluck('kind')->implode(', '),
            );

            return $problems;
        }

        $detail = implode(' ', $named);

        /*
         | The figures have to be in the sentence.
         |
         | A critical finding that says "receivables does not tie" at 03:00
         | is a finding somebody has to go and reproduce before they can act.
         | One that says which two figures and by how much is one they can
         | start from.
         */
        foreach (['2500', 'control account'] as $expected) {
            if (! str_contains($detail, $expected)) {
                $problems[] = sprintf(
                    'the finding does not mention %s: "%s". The difference and both sides of it are '.
                    'what makes this actionable rather than a prompt to go and look.',
                    $expected,
                    $detail,
                );
            }
        }

        return $problems;
    }

    /**
     * The findings whose kind names a side, as sentences.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Tenant\Ledger\LedgerFinding>  $findings
     * @return list<string>
     */
    private function findingsAbout(\Illuminate\Support\Collection $findings, string $side): array
    {
        return $findings
            ->filter(static fn (object $f): bool => str_contains((string) $f->kind, $side))
            ->map(static fn (object $f): string => (string) $f->detail)
            ->values()
            ->all();
    }

    /**
     * A balanced pair of lines that moves one control account and nothing else.
     *
     * Written onto an existing POSTED entry rather than through a service,
     * because no service will do this - which is the point. The state exists
     * in the wild: a posting rule that credits a control account with the
     * wrong figure produces it, and that is a defect this repository has
     * actually had.
     *
     * `accounting_date` and `period_id` are supplied although a trigger
     * overwrites them: `journal_lines` is RANGE-partitioned on the date and
     * PostgreSQL routes the row before the trigger runs, so a row left to
     * the trigger lands in the default partition and cannot then be moved.
     */
    private function moveTheControlAccount(string $controlCode, string $amount): void
    {
        $entry = $this->connection->table('journal_entries')
            ->where('entity_id', $this->entityId)
            ->where('status', 'posted')
            ->whereNotNull('posting_rule')
            ->orderByDesc('accounting_date')
            ->first();

        if ($entry === null) {
            return;
        }

        $accountId = fn (string $code): string => (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('code', $code)
            ->value('id');

        $pairs = [
            [$accountId($controlCode), $amount],
            [$accountId($this->revenueCode()), '-'.$amount],
        ];

        foreach ($pairs as [$id, $signed]) {
            $this->connection->table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'journal_entry_id' => (string) $entry->id,
                'entity_id' => $this->entityId,
                'accounting_date' => (string) $entry->accounting_date,
                'period_id' => (string) $entry->period_id,
                'account_id' => $id,
                'amount' => $signed,
                'currency' => 'SAR',
                'functional_amount' => $signed,
                'narration' => 'A movement no document accounts for',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * A partial invoice is a receivable in all three places that ask - PF-090.
     *
     * ## Three copies of one stale list
     *
     * `OverviewQuery::receivables()`, `ExpectedCreditLoss::openReceivables()`
     * and `Banking\Matching\OpenDocuments` each carried
     * `IN ('380', '383', '386')`. All three were written before the partial
     * invoice and the corrected invoice existed, and none of them learned
     * about either. Both are money a customer owes.
     *
     * The consequences differ, and the third is the worst:
     *
     * The Overview tile understated what was owed - on the first screen
     * anybody opens, by exactly the partial invoices outstanding.
     *
     * The IFRS 9 loss allowance carried **none** against them, because they
     * were not in the provision base. A receivable with no expected credit
     * loss is not a conservative estimate, it is an omitted one.
     *
     * And a partial invoice was not a matching CANDIDATE, so a customer's
     * payment against one had nothing to match and landed in the
     * unexplained column of a bank reconciliation - where somebody then
     * hunted for a document the engine had declined to offer them. That is
     * a functional bug rather than a reporting one.
     *
     * ## Why this check reads all three rather than the enum
     *
     * Asserting `DocumentType::receivableCodes()` contains `326` would pass
     * while all three queries still carried their literals. The question is
     * whether the three consumers see the document, so the check asks each
     * of them - and `OpenDocuments` is asked through the container, because
     * `Banking` reaches the answer through a `Support` contract it does not
     * implement.
     *
     * @return list<string>
     */
    private function aPartialInvoiceIsOwed(): array
    {
        $partial = $this->issue(DocumentType::PartialInvoice);
        $id = (string) $partial->getKey();
        $problems = [];

        /*
         | The bank matching candidates. `Banking` may not depend on `Sales`,
         | so the list arrives through `NamesReceivableDocuments` - and this
         | resolves the service the way production does rather than
         | constructing it, so a binding that was never registered fails
         | here instead of in a reconciliation.
         */
        $candidates = app(\App\Tenant\Banking\Matching\OpenDocuments::class)->receivables(
            $this->entityId,
            'SAR',
        );

        $offered = false;

        foreach ($candidates as $candidate) {
            $candidateId = \is_array($candidate)
                ? (string) ($candidate['id'] ?? '')
                : (string) ($candidate->id ?? '');

            if ($candidateId === $id) {
                $offered = true;
            }
        }

        if (! $offered) {
            $problems[] = sprintf(
                'a partial invoice (%s) is not a bank-matching candidate, so a customer paying it has '.
                'nothing to match and the receipt lands in the unexplained column of a '.
                'reconciliation - where somebody hunts for a document the engine declined to offer',
                (string) $partial->number,
            );
        }

        /*
         | The Overview tile.
         */
        $overview = app(\App\Tenant\Reporting\Overview\OverviewQuery::class)->for(
            $this->entity(),
            \Carbon\CarbonImmutable::parse($this->date->toDateString()),
        );

        if ($overview->receivables->documents < 1) {
            $problems[] = sprintf(
                'the Overview receivables tile counts %d documents with a partial invoice '.
                'outstanding. It is the first figure anybody sees, and it understates what is owed '.
                'by exactly the partial invoices.',
                $overview->receivables->documents,
            );
        }

        /*
         | And the provision base.
         */
        /*
         | And the provision base.
         |
         | Read through the private query by reflection rather than by
         | running `recompute()`, which needs a provision matrix, a period
         | label and an actor, and POSTS an entry. What is under test is
         | which documents the base contains, and the posting is PF-172's
         | subject with its own checks - driving it here would couple this
         | check to a matrix fixture for no gain.
         */
        $ecl = app(\App\Tenant\Settlement\Services\ExpectedCreditLoss::class);
        $query = new \ReflectionMethod($ecl, 'openReceivables');
        $query->setAccessible(true);

        /** @var list<object> $exposed */
        $exposed = $query->invoke($ecl, $this->entityId, 'SAR', $this->date->copy());

        $provisioned = false;

        foreach ($exposed as $row) {
            if ((string) ($row->id ?? '') === $id) {
                $provisioned = true;
            }
        }

        if (! $provisioned) {
            $problems[] = sprintf(
                'a partial invoice (%s) is not in the IFRS 9 exposure, so it carries no loss '.
                'allowance at all. A receivable with no expected credit loss is not a conservative '.
                'estimate, it is an omitted one.',
                (string) $partial->number,
            );
        }

        return $problems;
    }

    private function register(string $from, string $until): array
    {
        return app(JournalRegister::class)->forPeriod(
            $this->entityId,
            'SAR',
            Carbon::parse($from),
            Carbon::parse($until),
        );
    }

    /** @return array<string, mixed> */
    private function receivablesAgeing(string $asOf = '2026-05-31'): array
    {
        return app(AgingReport::class)->receivables(
            $this->entityId,
            'SAR',
            Carbon::parse($asOf),
        );
    }

    /** @return array<string, mixed> */
    private function payablesAgeing(string $asOf = '2026-05-31'): array
    {
        return app(AgingReport::class)->payables(
            $this->entityId,
            'SAR',
            Carbon::parse($asOf),
        );
    }

    private function receivableFigure(): Figure
    {
        return Figure::asAt(
            report: 'trial_balance',
            kind: 'account',
            value: $this->receivableCode(),
            entityId: $this->entityId,
            currency: 'SAR',
            until: Carbon::parse('2026-05-31'),
        );
    }

    private function trialBalanceFigure(string $code, string $asAt): Money
    {
        $balances = app(TrialBalance::class)->for(
            $this->entityId,
            'SAR',
            Carbon::parse($asAt),
        );

        foreach ($balances['rows'] as $row) {
            if ((string) $row['code'] === $code) {
                return Money::of((string) $row['balance'], 'SAR');
            }
        }

        return Money::zero('SAR');
    }

    private function receivableCode(): string
    {
        return $this->codeForRole(ControlRole::AccountsReceivable);
    }

    private function revenueCode(): string
    {
        return (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('id', $this->revenueAccountId)
            ->value('code');
    }

    private function codeForRole(ControlRole $role): string
    {
        return (string) $this->connection->table('accounts as a')
            ->join('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('a.entity_id', $this->entityId)
            ->where('r.role', $role->value)
            ->orderBy('a.code')
            ->value('a.code');
    }

    /** Run something as though the fixture date were another day. */
    private function onDate(string $date, callable $what): void
    {
        $was = $this->date;
        $this->date = Carbon::parse($date);

        try {
            $what();
        } finally {
            $this->date = $was;
        }
    }

    /**
     * An invoice on 30/60/90 terms, so its three due dates can be aged apart.
     */
    private function issueOnInstalments(): void
    {
        $term = PaymentTerm::create([
            'code' => 'THIRDS-'.Str::upper(Str::random(5)),
            'name_ar' => 'أثلاث',
            'name_en' => 'Thirds, 30/60/90',
            'kind' => 'instalments',
        ]);

        foreach ([
            [1, '0.333333333333', 'net', 0],
            [2, '0.333333333333', 'net', 30],
            [3, '0.333333333334', 'net', 60],
        ] as [$sequence, $proportion, $kind, $days]) {
            PaymentTermInstalment::create([
                'payment_term_id' => $term->getKey(),
                'sequence' => $sequence,
                'proportion' => $proportion,
                'kind' => $kind,
                'days' => $days,
            ]);
        }

        // The instalment rows are checked by a deferred constraint, which
        // would otherwise fire at commit - long after the issue below has
        // read the term and frozen a schedule from it.
        $this->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');

        $was = $this->terms;
        $this->terms = $term->fresh();

        try {
            $this->issue();
        } finally {
            $this->terms = $was;
        }
    }

    /** A bill entered and posted, so the payables side has figures. */
    private function postABill(): void
    {
        $bill = $this->enterBill();

        app(\App\Tenant\Purchasing\Services\Bills::class)
            ->post($bill->refresh(), $this->entity(), $this->secondActorId());
    }

    /** A receipt against nothing, which is ordinary and is unapplied cash. */
    private function recordAReceipt(string $amount): void
    {
        app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of($amount, 'SAR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $this->bank()->getKey(),
            partyId: (string) $this->party?->getKey(),
        );
    }

    /**
     * A draft journal entry in the period.
     *
     * Written through the entry model rather than the import path, which
     * posts: what is needed is an entry that has NOT happened, and the
     * register's promise is that it is listed and not totalled.
     */
    private function aDraftJournal(): void
    {
        $journalId = (string) $this->connection->table('journals')
            ->where('entity_id', $this->entityId)
            ->where('code', 'GEN')
            ->value('id');

        $this->connection->table('journal_entries')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'journal_id' => $journalId,
            'period_id' => (string) $this->period?->getKey(),
            'number' => 'DRAFT-'.Str::upper(Str::random(5)),
            'accounting_date' => $this->date->toDateString(),
            'narration' => 'A draft an auditor needs to see before the period closes',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function aUser(string $roleKey, string $name): User
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();

        $user = User::query()->create([
            'name' => $name,
            'email' => Str::lower($roleKey).'-'.bin2hex(random_bytes(5)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        UserEntityRole::query()->create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => $role->getKey(),
        ]);

        return $user;
    }

    private function signInAs(User $user): void
    {
        Auth::guard('web')->setUser($user);
    }

    /**
     * A savepoint rolls back the database and not PHP.
     *
     * The two probe users are created before the loop and survive it, so
     * only their resolved permissions need clearing - a cache that
     * outlived a rollback would answer for roles the database no longer
     * holds.
     */
    protected function refreshFixtures(): void
    {
        $this->refreshTheBankSide();

        $this->accountant?->forgetResolvedPermissions();
        $this->controller?->forgetResolvedPermissions();
    }
    /**
     * A referencing credit note is subtracted once - PF-190, PF-054.
     *
     * ## What was wrong
     *
     * `AgingReport::instalmentsOf()` said, in its own comment, that a credit
     * note naming an invoice "reaches this method only when it stands alone
     * - which is a credit balance on the account". Nothing made that true.
     * `receivableTypes()` returns every non-purchase-side document type,
     * credit notes among them, and no filter asked whether one referenced
     * anything.
     *
     * So the credit was counted twice: once by
     * `peakflow_document_open_balance`, which subtracts it from the invoice
     * it credits, and again as a negative bucket of its own.
     *
     * ## Why it hid, and where it surfaced
     *
     * The ageing is also what `SubledgersTieToTheirControls` compares with
     * the receivables control account - deliberately, so that there is one
     * answer to "does receivables tie" rather than two. With the ageing
     * understated, the check that exists to prove the subledger ties was the
     * thing that did not tie, and it reported the control account as wrong.
     *
     * It took the concurrency suite to surface it, because that is the only
     * place that issues a referencing credit note and then runs the ledger
     * proof over the same entity: 209 invoices and two credit notes of 115,
     * ledger 23,575 against a control of 23,805.
     *
     * ## What is asserted
     *
     * The ageing total falls by the credit exactly once, and the credit note
     * does not appear as a row of its own. Both halves, because the fix is a
     * filter and a filter that excluded too much would make a STANDALONE
     * credit vanish - which is the balance PF-190 exists to show.
     *
     * @return list<string>
     */
    private function aCreditIsSubtractedOnce(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $gross = Money::of((string) $invoice->gross_amount, 'SAR');

        $before = app(AgingReport::class)->receivables($this->entityId, 'SAR', $this->date->copy());
        $totalBefore = $this->ageingTotal($before);

        /*
         | A second person issues the credit note - PF-033.
         |
         | Whoever issued an invoice may not credit it, and the pair cannot
         | be overridden: one person creating a debt and then wiping it out
         | is the control's whole subject. The suite signs in as the
         | accountant for everything else, so the controller signs this one
         | and the session is handed back afterwards - a check that left
         | somebody else signed in would make the NEXT check's refusals
         | land on the wrong person.
         */
        $this->signInAs($this->controller ?? $this->accountant);

        $credit = app(\App\Tenant\Sales\Services\Issuer::class)->issue(
            app(\App\Tenant\Sales\Services\Crediting::class)->full($invoice, $this->date->copy()),
            $this->entity(),
            /*
             | The SECOND actor, because whoever issued may not credit.
             |
             | PF-033's pair, and it cannot be overridden: issuing an
             | invoice and then wiping it out is one person creating and
             | destroying a debt.
             */
            (string) ($this->controller?->getKey() ?? $this->secondActorId()),
            $this->decision,
        );

        $this->signInAs($this->accountant);

        $after = app(AgingReport::class)->receivables($this->entityId, 'SAR', $this->date->copy());
        $totalAfter = $this->ageingTotal($after);
        $moved = $totalBefore->minus($totalAfter);

        if (! $moved->equals($gross)) {
            $violations[] = sprintf(
                'crediting %s moved the ageing by %s. Twice the document means the credit is '.
                'subtracted both by the open-balance definition and again as a bucket of its own, '.
                'and the ageing is what the subledger tie compares with the control account',
                (string) $gross->toPresentation(),
                (string) $moved->toPresentation(),
            );
        }

        $rows = array_values(array_filter(
            (array) ($after['rows'] ?? []),
            static fn (array $row): bool => (string) ($row['document_id'] ?? '') === (string) $credit->getKey(),
        ));

        if ($rows !== []) {
            $violations[] = 'the credit note has a row of its own in the ageing as well as being '.
                'netted off the invoice it names, which is the double count';
        }

        /*
         | And the invoice it credits is gone from the grid, at zero.
         |
         | Asserted so the check cannot pass by the credit note being
         | filtered out AND the invoice staying at its full value - which
         | would move the total by nothing and look like a different bug.
         */
        $stillThere = array_values(array_filter(
            (array) ($after['rows'] ?? []),
            static fn (array $row): bool => (string) ($row['document_id'] ?? '') === (string) $invoice->getKey(),
        ));

        if ($stillThere !== []) {
            $violations[] = sprintf(
                'the fully credited invoice is still in the ageing at %s',
                (string) ($stillThere[0]['outstanding'] ?? '?'),
            );
        }

        return $violations;
    }

    /** The ageing's own total for the functional currency. */
    private function ageingTotal(array $report): Money
    {
        $total = Money::zero('SAR');

        foreach ((array) ($report['rows'] ?? []) as $row) {
            if ((string) ($row['currency'] ?? '') !== 'SAR') {
                continue;
            }

            $total = $total->plus(Money::of((string) $row['outstanding'], 'SAR'));
        }

        return $total;
    }

}
