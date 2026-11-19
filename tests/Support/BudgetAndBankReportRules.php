<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Banking\Models\BankTransaction;
use App\Tenant\Banking\Services\BankBalances;
use App\Tenant\Banking\Services\Matches;
use App\Tenant\Banking\Services\Reconciliations;
use App\Tenant\Banking\Services\Suspense;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;
use App\Tenant\Ledger\Exceptions\BudgetRefused;
use App\Tenant\Ledger\Manual\JournalImport;
use App\Tenant\Ledger\Services\Budgets;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Purchasing\Services\PurchaseOrders;
use App\Tenant\Reporting\Exceptions\ReportRefused;
use App\Tenant\Reporting\Services\BankReconciliationReport;
use App\Tenant\Reporting\Services\BudgetVariance;
use App\Tenant\Settlement\Posting\Rules\RunReleasedRule;
use App\Tenant\Settlement\Services\TransitClearing;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bank reconciliation reporting and budgets - PF-191, PF-193.
 *
 * ## What a check here is for
 *
 * **A report that recomputed what Banking already recorded.** PF-191 is a
 * report over a module with 275 checks of its own, and the way to get it
 * wrong is not to compute something incorrectly - it is to compute it at
 * all. A reconciliation re-derived from today's ledger restates itself
 * the moment a prior-period entry is posted; a line judged "matched" by
 * a second query disagrees with `Splits::remainder()` the first time
 * somebody applies half of one. So the checks here post INTO a
 * reconciled period and assert the document does not move, and apply
 * part of a line and assert the rest of it is still reported as
 * unexplained.
 *
 * **A total that added two currencies.** The cross-entity suspense table
 * is the one place in this report where two entities' figures land in one
 * column, and two entities need not keep their books in the same
 * currency. The check gives the tenant an entity in another currency and
 * asserts it is named and counted out rather than converted at today's
 * rate - which would be a figure nobody could reproduce.
 *
 * **An aging whose newest items are missing.** PF-179's in-transit
 * tolerance is five days, which is the right default for "what should
 * somebody chase" and the wrong floor for an aging table: its first
 * bucket is "current". The check releases one instruction inside the
 * tolerance and one outside it and asserts both are on the report, and
 * that the five-day call still returns only the one.
 */
final class BudgetAndBankReportRules
{
    /*
     | Aliased, because this suite's own `refreshFixtures()` has to call
     | the bank side's: a trait's definition shadows the one it imports
     | and there is no `parent::` for a trait, so without the alias the
     | banking fixtures would stop being re-read after a savepoint
     | rollback and every check after the first would assert against a
     | stale bank account.
     */
    use Concerns\OpensBankAccounts {
        refreshFixtures as refreshTheBankSide;
    }
    use Concerns\ProvesTheLedger;

    /** The person who authorises a reconciliation, so a name can be asserted. */
    private ?User $controller = null;

    /** Somebody else, for the pair that must not be one person. */
    private ?User $clerk = null;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['bank reconciliation reporting (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();

            /*
             | The opening entry, once, outside the savepoint loop.
             |
             | It is what makes the account reconcilable at all: without
             | it the ledger is short by 50,000 and every reconciliation
             | in here would be refused for a reason that has nothing to
             | do with what is being checked.
             */
            $this->postTheOpeningEntry();

            $this->controller = $this->aUser('controller', 'Reconciliation Controller');
            $this->clerk = $this->aUser('accountant', 'Reconciliation Clerk');
            $this->signInAs($this->controller);

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- PF-191, the reconciliation statement ---------------
                'a past reconciliation is the frozen document, not today\'s figures' => fn (): array => $this->theStatementIsFrozen(),
                'and it says which entity, account and statement it is for' => fn (): array => $this->theStatementNamesItsSubject(),
                'the items on it bridge the statement balance to the ledger balance' => fn (): array => $this->theBridgeAddsUp(),

                // ---- PF-191, suspense and in-transit aging --------------
                'suspense is aged by bucket and by entity' => fn (): array => $this->suspenseIsAgedByEntity(),
                'an entity in another currency is named and counted out, never converted' => fn (): array => $this->anotherCurrencyIsCountedOut(),
                'in-transit aging carries every open instruction, not only the aged ones' => fn (): array => $this->transitAgingHasACurrentBucket(),
                'in transit and suspense are never added together' => fn (): array => $this->transitIsNotAddedToSuspense(),
                'a report over no entity is refused, not returned as zeros' => fn (): array => $this->noEntityIsRefused(),

                // ---- PF-191, unreconciled-item aging --------------------
                'unreconciled items are aged per account from the bank\'s own date' => fn (): array => $this->unreconciledItemsAreAged(),
                'a part-applied line is part-unexplained' => fn (): array => $this->aPartAppliedLineIsPartUnexplained(),
                'a line whose only match was reversed is unexplained again' => fn (): array => $this->aReversedMatchExplainsNothing(),

                // ---- PF-191, the history --------------------------------
                'the history names who authorised each one, with the frozen balances' => fn (): array => $this->theHistoryNamesItsAuthor(),
                'and says when one person both matched and authorised' => fn (): array => $this->theHistorySeesSelfAuthorisation(),
                'the history of an account that does not exist is refused' => fn (): array => $this->anUnknownAccountIsRefused(),

                // ---- PF-191, the shape the export depends on ------------
                'every column each report declares exists on every row' => fn (): array => $this->theColumnsAreReal(),
                'the same question digests the same, and a changed filter changes it' => fn (): array => $this->theDigestIsTheQuestion(),
                'each report renders as a CSV with its parameters above the header' => fn (): array => $this->theReportsExport(),

                // ---- PF-193, the budget as a document ------------------
                'a budget version is drafted, approved by somebody else, then locked' => fn (): array => $this->aBudgetIsADocument(),
                'the database refuses an edit to a locked version, not only the service' => fn (): array => $this->theLockIsInTheDatabase(),
                'a budget may not be approved by the person who drafted it' => fn (): array => $this->selfApprovalIsRefused(),
                'approving supersedes the version that was the budget, and names its successor' => fn (): array => $this->approvingSupersedes(),
                'a dimension value this entity cannot use is refused, and says why' => fn (): array => $this->aForeignDimensionIsRefused(),

                // ---- PF-193, budget versus actual ---------------------
                'the variance is stated in money and in per cent' => fn (): array => $this->theVarianceIsStated(),
                'a variance below the threshold is not highlighted and one above it is' => fn (): array => $this->materialityHighlightsWhatMatters(),
                'spending against no budget line at all is reported and is always material' => fn (): array => $this->unbudgetedSpendingIsReported(),
                'an open purchase-order commitment reduces what is available' => fn (): array => $this->commitmentsReduceAvailable(),
                'measuring against a draft budget is refused unless the draft is named' => fn (): array => $this->aDraftBudgetIsRefused(),
                'a materiality threshold past the platform ceiling is refused' => fn (): array => $this->theCeilingHolds(),

                // ---- PF-193, P&L by dimension combination -------------
                'profit or loss by dimension combination adds to the statement' => fn (): array => $this->theDimensionPivotAddsUp(),
                'and two axes combine, which is what "any combination" means' => fn (): array => $this->twoAxesCombine(),
                'every column the budget reports declare exists on every row' => fn (): array => $this->theBudgetColumnsAreReal(),
            ]));
        } catch (Throwable $e) {
            return ['the bank reconciliation reports could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 500),
            ]];
        } finally {
            Auth::guard('web')->forgetUser();
            $this->connection->rollBack();
        }
    }

    // -----------------------------------------------------------------
    //  PF-191 - the reconciliation statement, reproducible as it stood
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function theStatementIsFrozen(): array
    {
        $id = $this->aReconciliation();
        $before = $this->report()->statement($id);

        /*
         | A posting AFTER the statement period, which is the case.
         |
         | A reconciliation is signed at a period end and the books carry
         | on. The figure that must not move is the one that was true on
         | the day it was signed - so the fixture moves the ledger and the
         | check asserts the document did not follow it, having first
         | asserted that the LIVE figure did. Without that second
         | assertion a report hard-coded to any constant would pass.
         */
        $this->releaseAPayment();

        $after = $this->report()->statement($id);
        $live = app(BankBalances::class)->all($this->bank(), $this->date->copy());

        $problems = [];

        if ((string) $before['ledger_balance'] !== (string) $after['ledger_balance']) {
            $problems[] = sprintf(
                'the reconciliation reported a ledger balance of %s and now reports %s. It is read '.
                'back from the stored figures or it is not evidence of anything: a document that '.
                'restates itself when somebody posts a prior-period entry cannot be re-performed.',
                (string) $before['ledger_balance'],
                (string) $after['ledger_balance'],
            );
        }

        if ((string) $live['ledger'] === (string) $after['ledger_balance']) {
            $problems[] = sprintf(
                'the fixture did not move the ledger: the live balance is %s and the frozen one is '.
                'the same, so this check would pass against a report that recomputed everything',
                (string) $live['ledger'],
            );
        }

        if ((string) $after['as_of'] === '') {
            $problems[] = 'the statement does not say as at when it was taken, so frozen figures '.
                'would be read as today\'s position';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theStatementNamesItsSubject(): array
    {
        $id = $this->aReconciliation();
        $report = $this->report()->statement($id);
        $problems = [];

        if ((string) $report['entity_id'] !== $this->entityId) {
            $problems[] = sprintf(
                'the statement names entity "%s" and the reconciliation belongs to "%s" - an auditor '.
                'holding this page cannot tell whose books it is about',
                (string) $report['entity_id'],
                $this->entityId,
            );
        }

        if ((string) $report['bank_account_id'] !== (string) $this->bank()->getKey()) {
            $problems[] = 'the statement does not name the bank account it reconciles, so the figures '.
                'can be re-performed and the thing they are about cannot be identified';
        }

        if ((string) $report['bank_account'] !== (string) $this->bank()->label) {
            $problems[] = 'the statement names no readable bank account label, only an id';
        }

        if ((string) $report['bank_statement_id'] === '') {
            $problems[] = 'the statement does not name the bank statement it was reconciled against, '.
                'which is the first thing an audit asks for';
        }

        $parameters = $report['parameters'];

        foreach (['report', 'entity_id', 'from', 'until', 'currency', 'digest'] as $key) {
            if (! isset($parameters[$key]) || (string) $parameters[$key] === '') {
                $problems[] = sprintf('the parameters block carries no %s', $key);
            }
        }

        foreach ($report['parameter_lines'] as $line) {
            if ((string) $line['label_ar'] === '' || (string) $line['label_en'] === '') {
                $problems[] = 'a parameter line is missing one of the two languages, so the block '.
                    'prints in English on an Arabic page';

                break;
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theBridgeAddsUp(): array
    {
        $id = $this->aReconciliation();
        $report = $this->report()->statement($id);
        $currency = (string) $report['currency'];

        $rows = [];

        foreach ($report['rows'] as $row) {
            $rows[(string) $row['key']] = (string) $row['value'];
        }

        $problems = [];

        if (! isset($rows['statement_balance'], $rows['ledger_balance'])) {
            return ['the statement does not print both balances, so there is no bridge to check'];
        }

        $bridge = Money::of($rows['statement_balance'], $currency);

        foreach ($report['rows'] as $row) {
            if (\in_array((string) $row['key'], ['statement_balance', 'ledger_balance'], true)) {
                continue;
            }

            $bridge = $bridge->plus(Money::of((string) $row['value'], $currency));
        }

        if (! $bridge->equals(Money::of($rows['ledger_balance'], $currency))) {
            $problems[] = sprintf(
                'the printed items bridge the statement balance to %s and the page prints a ledger '.
                'balance of %s. A reconciliation whose displayed lines do not add to the balance '.
                'beside them is the one error a reader cannot catch, because every figure looks '.
                'plausible.',
                (string) $bridge->amount,
                $rows['ledger_balance'],
            );
        }

        if (\count($report['rows']) !== 6) {
            $problems[] = sprintf(
                'the bridge has %d lines rather than the two balances and the four reconciling items, '.
                'so one of them is not being presented at all',
                \count($report['rows']),
            );
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  PF-191 - suspense and in-transit aging
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function suspenseIsAgedByEntity(): array
    {
        $this->importASpreadStatement();

        foreach (['SPREAD-EARLY', 'SPREAD-LATE'] as $reference) {
            app(Suspense::class)->park(
                $this->transactionBy($reference),
                (string) $this->controller?->getKey(),
                'nobody can say who sent this yet',
            );
        }

        $asOf = Carbon::parse('2026-08-05');
        $report = $this->report()->suspenseAging('SAR', [$this->entityId], $asOf);

        $problems = [];
        $suspense = $report['suspense'];
        $buckets = $suspense['buckets'];

        /*
         | Two items, deliberately in two DIFFERENT buckets.
         |
         | The statement's own lines are a day apart, so a fixture built
         | on it would put both items in one bucket and a bucket boundary
         | off by thirty days would change nothing. The spread statement
         | exists for that reason: 2 May and 25 May, read on 5 August, are
         | 95 and 72 days old.
         */
        if ((string) $buckets['90+'] !== '1000.0000') {
            $problems[] = sprintf(
                'the 95-day item reports %s in the 90+ bucket rather than 1,000, so the oldest '.
                'unexplained money is in the wrong column - which is the column somebody acts on',
                (string) $buckets['90+'],
            );
        }

        if ((string) $buckets['61-90'] !== '500.0000') {
            $problems[] = sprintf(
                'the 72-day item reports %s in the 61-90 bucket rather than 500',
                (string) $buckets['61-90'],
            );
        }

        if ((string) $suspense['total'] !== '1500.0000') {
            $problems[] = sprintf('the suspense total is %s rather than 1,500', (string) $suspense['total']);
        }

        if ((int) $suspense['oldest_days'] !== 95) {
            $problems[] = sprintf(
                'the oldest item reports %d days rather than 95, so the age is not being measured '.
                'from the bank\'s own booking date',
                (int) $suspense['oldest_days'],
            );
        }

        $entities = $suspense['by_entity'];

        if (\count($entities) !== 1) {
            $problems[] = sprintf('%d entity rows for one entity', \count($entities));

            return $problems;
        }

        if ((string) $entities[0]['total'] !== (string) $suspense['total']) {
            $problems[] = sprintf(
                'the entity row totals %s and the tenant total is %s - a cross-entity table whose '.
                'rows do not add to its total is two reports on one page',
                (string) $entities[0]['total'],
                (string) $suspense['total'],
            );
        }

        if ($entities[0]['control_account_agrees'] !== true) {
            $problems[] = 'the parked items do not account for the suspense control account on a '.
                'fixture that parked both of them through the real service: '.
                (string) $entities[0]['why'];
        }

        $rows = array_values(array_filter(
            $report['rows'],
            static fn (array $row): bool => (string) $row['position'] === 'suspense',
        ));

        if (\count($rows) !== 2) {
            $problems[] = sprintf('%d suspense rows rather than the two that were parked', \count($rows));
        }

        return $problems;
    }

    /** @return list<string> */
    private function anotherCurrencyIsCountedOut(): array
    {
        $other = Entity::create([
            'code' => 'USD'.Str::upper(Str::random(6)),
            'legal_name' => 'Peakflow Overseas Ltd.',
            'country' => 'US',
            'functional_currency' => 'USD',
            'active' => true,
        ]);

        $report = $this->report()->suspenseAging(
            'SAR',
            [$this->entityId, (string) $other->getKey()],
            Carbon::parse('2026-08-05'),
        );

        $problems = [];
        $excluded = $report['entities_excluded'];

        if (\count($excluded) !== 1) {
            $problems[] = sprintf(
                '%d entities counted out, and exactly one keeps its books in another currency. An '.
                'entity whose suspense is in USD added into a SAR total is a figure nobody can '.
                'reproduce from a rate and a date.',
                \count($excluded),
            );

            return $problems;
        }

        if ((string) $excluded[0]['entity'] !== (string) $other->code) {
            $problems[] = 'the excluded entity is not named, so a reader cannot go and look at it';
        }

        if ((string) $excluded[0]['currency'] !== 'USD') {
            $problems[] = 'the exclusion does not say which currency it was left out for';
        }

        if (! str_contains((string) $report['declaration']['en'], (string) $other->code)) {
            $problems[] = 'the declaration does not name the entity it left out, so the total above '.
                'it reads as covering the whole tenant';
        }

        if (\count($report['suspense']['by_entity']) !== 1) {
            $problems[] = 'the foreign-currency entity has a row in a table denominated in SAR';
        }

        return $problems;
    }

    /** @return list<string> */
    private function transitAgingHasACurrentBucket(): array
    {
        /*
         | One instruction inside PF-179's five-day tolerance and one
         | outside it, read on the 20th.
         |
         | 19 May is one day old and 4 May is sixteen. The service's own
         | default floor hides the first, which is right for "what should
         | somebody chase" and wrong for an aging table - so both numbers
         | are asserted and the floored call is asserted to still find
         | only one.
         */
        $this->aReleasedRun('2026-05-04', ['4500.0000']);
        $this->aReleasedRun('2026-05-19', ['9000.0000']);

        $asOf = Carbon::parse('2026-05-20');
        $report = $this->report()->suspenseAging('SAR', [$this->entityId], $asOf);
        $transit = $report['in_transit'];
        $problems = [];

        if ((string) $transit['total'] !== '13500.0000') {
            $problems[] = sprintf(
                'the in-transit position reports %s rather than 13,500: an aging table that cannot '.
                'see an instruction released yesterday shows a position whose newest items are '.
                'missing, and the figure is then quietly short',
                (string) $transit['total'],
            );
        }

        if ((string) $transit['buckets']['1-30'] !== '13500.0000') {
            $problems[] = sprintf(
                'the 1-30 bucket holds %s rather than both instructions',
                (string) $transit['buckets']['1-30'],
            );
        }

        if ((int) $transit['oldest_days'] !== 16) {
            $problems[] = sprintf(
                'the oldest in-transit item reports %d days rather than 16, so the age is not being '.
                'measured from the day the run was payable',
                (int) $transit['oldest_days'],
            );
        }

        if (\count($transit['items']) !== 2) {
            $problems[] = sprintf('%d in-transit items rather than two', \count($transit['items']));
        }

        /*
         | And the service's own tolerance still means what it meant.
         |
         | The widening added a parameter; it must not have changed what
         | PF-179's callers get. If this stops returning exactly the one
         | aged instruction, the default moved and the close's "aged
         | in-transit items" blocker moved with it.
         */
        $aged = app(TransitClearing::class)->aged($this->entityId, 5, $asOf);

        if (\count($aged) !== 1) {
            $problems[] = sprintf(
                'the five-day call returns %d instructions rather than the one that is older than '.
                'five days, so widening the method changed what every existing caller sees',
                \count($aged),
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function transitIsNotAddedToSuspense(): array
    {
        $this->importASpreadStatement();

        app(Suspense::class)->park(
            $this->transactionBy('SPREAD-EARLY'),
            (string) $this->controller?->getKey(),
            'nobody can say who sent this yet',
        );

        $this->aReleasedRun('2026-05-04', ['9000.0000']);

        $report = $this->report()->suspenseAging('SAR', [$this->entityId], Carbon::parse('2026-05-20'));
        $problems = [];

        if ((string) $report['suspense']['total'] !== '1000.0000') {
            $problems[] = sprintf(
                'the suspense total is %s and only 1,000 was parked. In transit is money we have told '.
                'the bank to send and suspense is money the bank has that nobody can explain: they '.
                'sit in different control accounts, and one figure covering both belongs to neither.',
                (string) $report['suspense']['total'],
            );
        }

        if ((string) $report['in_transit']['total'] !== '9000.0000') {
            $problems[] = sprintf(
                'the in-transit total is %s rather than 9,000',
                (string) $report['in_transit']['total'],
            );
        }

        foreach ($report['suspense']['buckets'] as $bucket => $amount) {
            if ((string) $amount === '10000.0000') {
                $problems[] = sprintf(
                    'the %s suspense bucket holds 10,000, which is the two positions summed',
                    (string) $bucket,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function noEntityIsRefused(): array
    {
        $refusal = $this->refusal(fn () => $this->report()->suspenseAging('SAR', []));

        if ($refusal === null) {
            return ['a suspense report naming no entity came back as a page of zeros, which reads as '.
                '"nothing is unexplained" - the answer somebody acts on'];
        }

        $problems = [];

        if (! str_contains($refusal, ReportRefused::class)) {
            $problems[] = 'the refusal is a '.$refusal.' rather than a ReportRefused, so a caller '.
                'cannot tell a refused report from a broken one';
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  PF-191 - unreconciled-item aging
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function unreconciledItemsAreAged(): array
    {
        $this->importASpreadStatement();

        $asOf = Carbon::parse('2026-08-05');
        $report = $this->report()->unreconciledAging($this->entityId, null, $asOf);
        $problems = [];

        $mine = array_values(array_filter(
            $report['accounts'],
            fn (array $account): bool => (string) $account['bank_account_id'] === (string) $this->bank()->getKey(),
        ));

        if ($mine === []) {
            return ['the account with two unexplained lines on it is not in the report at all'];
        }

        $account = $mine[0];

        if ((int) $account['lines'] !== 2) {
            $problems[] = sprintf(
                '%d unexplained lines rather than the two the statement carries',
                (int) $account['lines'],
            );
        }

        if ((string) $account['total'] !== '1500.0000') {
            $problems[] = sprintf(
                'the unexplained total is %s rather than 1,500',
                (string) $account['total'],
            );
        }

        if ((string) $account['buckets']['90+'] !== '1000.0000'
            || (string) $account['buckets']['61-90'] !== '500.0000') {
            $problems[] = sprintf(
                'the two lines bucket as 90+=%s and 61-90=%s rather than 1,000 and 500: aged from '.
                'anything but the bank\'s own booking date, an item gets younger every time somebody '.
                'touches the record',
                (string) $account['buckets']['90+'],
                (string) $account['buckets']['61-90'],
            );
        }

        if ((int) $account['oldest_days'] !== 95) {
            $problems[] = sprintf(
                'the oldest line reports %d days rather than 95 - the age of the oldest is the '.
                'leading indicator this report exists for',
                (int) $account['oldest_days'],
            );
        }

        if ($account['statement_period_to'] === null) {
            $problems[] = 'the account does not print the statement the bank last closed, so a reader '.
                'cannot tell an ordinary timing item from a difference that will be in every '.
                'reconciliation from now on';
        }

        $rows = array_values(array_filter(
            $report['rows'],
            fn (array $row): bool => (string) $row['bank_account_id'] === (string) $this->bank()->getKey(),
        ));

        foreach ($rows as $row) {
            $expected = (int) Carbon::parse((string) $row['dated'])->diffInDays($asOf, absolute: false);

            if ((int) $row['age_days'] !== $expected) {
                $problems[] = sprintf(
                    'a line dated %s reports an age of %d days and the as-of date is %s, which is %d',
                    (string) $row['dated'],
                    (int) $row['age_days'],
                    $asOf->toDateString(),
                    $expected,
                );

                break;
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function aPartAppliedLineIsPartUnexplained(): array
    {
        $invoice = $this->issue();

        $this->importLines([$this->line('1000.0000', ['bank_reference' => 'PART-ONE'])]);
        $transaction = $this->transactionBy('PART-ONE');

        $this->aMatch($transaction, '400.0000', 'invoice', (string) $invoice->getKey());

        $report = $this->report()->unreconciledAging(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-08-05'),
        );

        $row = null;

        foreach ($report['rows'] as $candidate) {
            if ((string) $candidate['reference'] === 'PART-ONE') {
                $row = $candidate;
            }
        }

        if ($row === null) {
            return ['a line of 1,000 with 400 applied to an invoice is not on the report at all. 600 '.
                'of it has no document behind it, and a report that asks whether a line is matched '.
                'rather than how much of it is cannot see that - which is the whole reason '.
                '`Splits::remainder()` exists.'];
        }

        $problems = [];

        if ((string) $row['unexplained'] !== '600.0000') {
            $problems[] = sprintf(
                'the line reports %s unexplained rather than 600 - the part of it nobody has applied '.
                'to anything',
                (string) $row['unexplained'],
            );
        }

        if ((string) $row['line_amount'] !== '1000.0000') {
            $problems[] = sprintf(
                'the line reports an amount of %s rather than the 1,000 the bank moved, so the reader '.
                'cannot see how much of it was explained',
                (string) $row['line_amount'],
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function aReversedMatchExplainsNothing(): array
    {
        $invoice = $this->issue();

        $this->importLines([$this->line('1000.0000', ['bank_reference' => 'REVERSED-ONE'])]);
        $transaction = $this->transactionBy('REVERSED-ONE');

        $matchId = $this->aMatch($transaction, '1000.0000', 'invoice', (string) $invoice->getKey());

        $before = $this->unexplainedFor('REVERSED-ONE');

        app(Matches::class)->reverse(
            $matchId,
            (string) $this->controller?->getKey(),
            'applied to the wrong invoice by somebody reading the remittance',
        );

        $after = $this->unexplainedFor('REVERSED-ONE');
        $problems = [];

        if ($before !== null) {
            $problems[] = sprintf(
                'a fully applied line is reported as %s unexplained, so this check cannot tell a '.
                'reversal from the state before it',
                $before,
            );
        }

        if ($after !== '1000.0000') {
            $problems[] = sprintf(
                'after the only match on it was reversed the line reports %s unexplained rather than '.
                'the whole 1,000. A reversed match explains nothing: the money is back to being a '.
                'movement the bank asserts and nothing here accounts for.',
                $after ?? 'nothing at all',
            );
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  PF-191 - the history
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function theHistoryNamesItsAuthor(): array
    {
        $id = $this->aReconciliation();
        $frozen = $this->report()->statement($id);

        // The ledger moves after the signature, so a recomputed history
        // would differ from the document it claims to list.
        $this->releaseAPayment();

        $history = $this->report()->history($this->entityId, (string) $this->bank()->getKey());
        $problems = [];

        if (\count($history['rows']) !== 1) {
            $problems[] = sprintf('%d rows for one reconciliation', \count($history['rows']));

            return $problems;
        }

        $row = $history['rows'][0];

        if ((string) $row['authorised_by'] !== (string) $this->controller?->name) {
            $problems[] = sprintf(
                'the history names "%s" as the authoriser rather than %s - "who signed this" is the '.
                'question the criterion asks, and a user id is not an answer anybody can use',
                (string) $row['authorised_by'],
                (string) $this->controller?->name,
            );
        }

        if ((string) $row['authorised_at'] === '') {
            $problems[] = 'the history does not say when each one was authorised';
        }

        if ((string) $row['ledger_balance'] !== (string) $frozen['ledger_balance']) {
            $problems[] = sprintf(
                'the history reports a ledger balance of %s and the document says %s. The history '.
                'lists documents, so its figures are the frozen ones - a row recomputed from '.
                'today\'s ledger would disagree with the statement it links to.',
                (string) $row['ledger_balance'],
                (string) $frozen['ledger_balance'],
            );
        }

        if ($row['standing'] !== true || (int) $history['standing'] !== 1) {
            $problems[] = 'the one standing reconciliation is not reported as standing';
        }

        if ($history['last_reconciled_to'] === null) {
            $problems[] = 'the history does not say what the account is reconciled to, which is the '.
                'figure that says whether anybody is keeping up';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theHistorySeesSelfAuthorisation(): array
    {
        $this->importLines([$this->line('1000.0000', ['bank_reference' => 'SELF-ONE'])]);

        /*
         | Parked by the controller, and then authorised by the controller.
         |
         | Parking is a real match applied by a real person - it writes the
         | `matches` row and posts the movement - so the ledger and the
         | statement agree and the reconciliation can be authorised at all.
         | A fixture that wrote a match row by hand would leave the account
         | out of balance by the line, and `reconcile()` would refuse it:
         | the check would then be proving the refusal rather than the
         | control.
         */
        app(Suspense::class)->park(
            $this->transactionBy('SELF-ONE'),
            (string) $this->controller?->getKey(),
            'nobody can say who sent this yet',
        );

        $statement = $this->bank()->lastStatement();
        \assert($statement !== null);

        app(Reconciliations::class)->reconcile(
            $statement,
            (string) $this->controller?->getKey(),
        );

        $history = $this->report()->history($this->entityId, (string) $this->bank()->getKey());
        $problems = [];

        if ($history['self_authorised'] === []) {
            $problems[] = 'one person applied every match in the period and authorised the '.
                'reconciliation over them, and the history does not say so. Each document looks '.
                'perfectly ordinary on its own, so this page is the only place the control not '.
                'having operated is visible.';
        }

        if (! str_contains((string) $history['declaration']['en'], 'APPLIED THE MATCHES')) {
            $problems[] = 'the declaration does not name it, so the fact sits in a nested key nobody '.
                'goes looking in';
        }

        return $problems;
    }

    /** @return list<string> */
    private function anUnknownAccountIsRefused(): array
    {
        $refusal = $this->refusal(fn () => $this->report()->history(
            $this->entityId,
            (string) Str::ulid(),
        ));

        if ($refusal === null) {
            return ['the history of an account that does not exist came back empty, which reads as an '.
                'account nobody has ever reconciled - and that is a finding, not a typo'];
        }

        return str_contains($refusal, ReportRefused::class)
            ? []
            : ['the refusal is a '.$refusal.' rather than a ReportRefused'];
    }

    // -----------------------------------------------------------------
    //  PF-191 - the shape the one export path depends on
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function theColumnsAreReal(): array
    {
        /*
         | One statement, reconciled, with one of its lines then parked.
         |
         | Not two statements: the second import's opening balance has to
         | continue the first's closing balance and its period has to
         | follow it, so a fixture that imported both would be refused -
         | and the four reports would then be checked against no rows at
         | all, which is the state every column assertion passes in.
         */
        $id = $this->aReconciliation();

        app(Suspense::class)->park(
            $this->transactionBy('BR-ALPHA-001'),
            (string) $this->controller?->getKey(),
            'nobody can say who sent this yet',
        );

        $asOf = Carbon::parse('2026-08-05');

        $reports = [
            'the reconciliation statement' => $this->report()->statement($id),
            'suspense and in-transit aging' => $this->report()->suspenseAging('SAR', [$this->entityId], $asOf),
            'unreconciled-item aging' => $this->report()->unreconciledAging($this->entityId, null, $asOf),
            /*
             | The history takes no as-of, deliberately.
             |
             | `authorised_at` is a wall-clock timestamp and the fixture's
             | books are in May 2026, so a history asked as at August would
             | correctly exclude a document signed today - and the check
             | would be asserting over an empty list.
             */
            'the reconciliation history' => $this->report()->history(
                $this->entityId,
                (string) $this->bank()->getKey(),
            ),
        ];

        $problems = [];

        foreach ($reports as $name => $report) {
            if ($report['rows'] === []) {
                $problems[] = sprintf('%s produced no rows, so its declared columns prove nothing', $name);

                continue;
            }

            foreach ($report['columns'] as $column) {
                if ((string) $column['label_ar'] === '' || (string) $column['label_en'] === '') {
                    $problems[] = sprintf(
                        '%s declares a "%s" column with no label in one of the two languages',
                        $name,
                        (string) $column['key'],
                    );
                }

                foreach ($report['rows'] as $row) {
                    if (! \array_key_exists((string) $column['key'], $row)) {
                        $problems[] = sprintf(
                            '%s declares a "%s" column and a row carries none, so an export built from '.
                            'the column list writes a header over an empty column',
                            $name,
                            (string) $column['key'],
                        );

                        break;
                    }
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theDigestIsTheQuestion(): array
    {
        $this->importASpreadStatement();

        $asOf = Carbon::parse('2026-08-05');

        /*
         | Two runs six weeks apart, asking the same question.
         |
         | The run clock is printed and is not in the fingerprint, so two
         | runs of one question over a closed period carry one digest and
         | can be compared. Two calls a millisecond apart would share a
         | timestamp to the second and prove nothing, which is how this
         | check was hollow elsewhere in this repository.
         */
        $first = $this->report()->unreconciledAging(
            $this->entityId,
            null,
            $asOf,
            Carbon::parse('2026-08-06 09:00:00'),
        );

        $second = $this->report()->unreconciledAging(
            $this->entityId,
            null,
            $asOf,
            Carbon::parse('2026-09-17 16:31:00'),
        );

        $filtered = $this->report()->unreconciledAging(
            $this->entityId,
            (string) $this->bank()->getKey(),
            $asOf,
            Carbon::parse('2026-08-06 09:00:00'),
        );

        $problems = [];

        if ((string) $first['parameters']['digest'] !== (string) $second['parameters']['digest']) {
            $problems[] = 'the same question asked six weeks apart digests differently, so no two '.
                'printed reports can ever be compared - which is the one guarantee the digest exists '.
                'for';
        }

        if ((string) $first['parameters']['run_at'] === (string) $second['parameters']['run_at']) {
            $problems[] = 'the two runs printed the same run time, so this check cannot tell a digest '.
                'that ignores the clock from one that happens to agree';
        }

        if ((string) $filtered['parameters']['digest'] === (string) $first['parameters']['digest']) {
            $problems[] = 'a report filtered to one bank account carries the same digest as the whole '.
                'entity. The two are different questions and every figure on them differs, so a '.
                'digest that calls them the same is worse than none - somebody would rely on it.';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theReportsExport(): array
    {
        $this->importASpreadStatement();

        $asOf = Carbon::parse('2026-08-05');
        $report = $this->report()->unreconciledAging($this->entityId, null, $asOf);
        $csv = $this->report()->csvUnreconciledAging($this->entityId, null, $asOf, 'en');

        $problems = [];

        if (! str_contains($csv, 'Question digest')) {
            $problems[] = 'the CSV does not carry the parameter block, so a printed export cannot be '.
                'answered six months later - which is the whole of PF-198';
        }

        foreach ($report['columns'] as $column) {
            if (! str_contains($csv, (string) $column['label_en'])) {
                $problems[] = sprintf(
                    'the CSV has no "%s" header, so the export and the page do not have the same '.
                    'columns',
                    (string) $column['label_en'],
                );
            }
        }

        foreach ($report['rows'] as $row) {
            if (! str_contains($csv, (string) $row['unexplained'])) {
                $problems[] = sprintf(
                    'the CSV does not carry the unexplained figure %s that the page shows',
                    (string) $row['unexplained'],
                );

                break;
            }
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  PF-193 - the budget as a document
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function aBudgetIsADocument(): array
    {
        $versionId = $this->aBudget([[$this->expenseCode(), '1000.0000']]);
        $version = app(Budgets::class)->version($versionId);
        $problems = [];

        if ((string) $version->status !== 'approved') {
            $problems[] = sprintf('the approved version reports status "%s"', (string) $version->status);
        }

        if ($version->locked_at === null) {
            $problems[] = 'an approved budget version carries no locked_at, so nothing says its '.
                'lines are fixed - and a variance report against it is a report against whatever '.
                'the budget was most recently made to say';
        }

        if ((string) $version->approved_by !== (string) $this->controller?->getKey()) {
            $problems[] = 'the version does not record who approved it';
        }

        if ((int) $version->version_no !== 1) {
            $problems[] = sprintf('the first version is numbered %d', (int) $version->version_no);
        }

        /*
         | And the service refuses the edit, with its own exception.
         |
         | The class matters: with this guard removed the database still
         | refuses, so a check that only asked whether something threw
         | would pass while every caller catching BudgetRefused was
         | getting a QueryException instead.
         */
        $refusal = $this->refusal(fn () => app(Budgets::class)->set(
            $versionId,
            (string) $this->period?->getKey(),
            $this->expenseCode(),
            '9999.0000',
        ));

        if ($refusal === null) {
            $problems[] = 'a line was added to an approved budget version, so the approved figures '.
                'can still be edited and no variance report against them means anything';
        } elseif (! str_contains($refusal, BudgetRefused::class)) {
            $problems[] = 'the refusal is a '.$refusal.' rather than a BudgetRefused';
        } elseif (! str_contains($refusal, 'approved')) {
            $problems[] = 'the refusal does not say that the version is approved, which is the one '.
                'thing the caller needs to know to do something about it';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theLockIsInTheDatabase(): array
    {
        $versionId = $this->aBudget([[$this->expenseCode(), '1000.0000']]);

        $line = $this->connection->table('budget_lines')
            ->where('budget_version_id', $versionId)
            ->first();

        if ($line === null) {
            return ['the fixture produced no budget line, so the lock cannot be tested'];
        }

        $problems = [];

        /*
         | Straight at the table, which is where an import goes.
         |
         | The service's refusal is not the control: a console session, a
         | repair script and a spreadsheet import all reach the table
         | directly, and the edit they would make leaves no trace at all
         | because the only evidence would have been the figure it
         | replaced.
         */
        $update = $this->refusal(fn () => $this->connection->table('budget_lines')
            ->where('id', (string) $line->id)
            ->update(['amount' => '7777.0000']));

        if ($update === null) {
            $problems[] = 'a direct UPDATE changed a line of an approved budget version. The service '.
                'refusing is not enough: an import does not go through the service';
        } elseif (! str_contains($update, 'locked')) {
            $problems[] = 'the database refused the update without saying the version is locked: '.$update;
        }

        $delete = $this->refusal(fn () => $this->connection->table('budget_lines')
            ->where('id', (string) $line->id)
            ->delete());

        if ($delete === null) {
            $problems[] = 'a direct DELETE removed a line of an approved budget version, which is an '.
                'edit that leaves no trace whatsoever';
        }

        $insert = $this->refusal(fn () => $this->connection->table('budget_lines')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'budget_version_id' => $versionId,
            'period_id' => (string) $this->period?->getKey(),
            'account_id' => $this->expenseAccountId,
            'amount' => '1.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($insert === null) {
            $problems[] = 'a direct INSERT added a line to an approved budget version, so the '.
                'approved total can be grown after the fact';
        }

        /*
         | The positive control, and this check is worthless without it.
         |
         | Three refusals in a row prove nothing on their own: a revoked
         | privilege or a row policy that refused EVERY write to this table
         | would satisfy all three, and the lock could then be removed
         | with nothing failing. That is the shape of the privilege check
         | that silently skipped the table it was meant to prove. So the
         | same direct UPDATE is made against a DRAFT version's line and
         | has to succeed - which is the only thing that says the refusals
         | above came from the lock.
         */
        $draft = app(Budgets::class)->draft(
            $this->entityId,
            $this->fiscalYearId(),
            'Still being worked on',
            (string) $this->clerk?->getKey(),
        );

        $draftLine = app(Budgets::class)->set(
            (string) $draft->id,
            (string) $this->period?->getKey(),
            $this->secondExpenseCode(),
            '400.0000',
        );

        $allowed = $this->refusal(fn () => $this->connection->table('budget_lines')
            ->where('id', (string) $draftLine->id)
            ->update(['amount' => '450.0000']));

        if ($allowed !== null) {
            $problems[] = 'a line of a DRAFT version could not be updated either, so the three '.
                'refusals above are not the lock - they are a privilege or a row policy refusing '.
                'every write to this table, and removing the lock would break nothing: '.$allowed;
        }

        return $problems;
    }

    /** @return list<string> */
    private function selfApprovalIsRefused(): array
    {
        $version = app(Budgets::class)->draft(
            $this->entityId,
            $this->fiscalYearId(),
            'Self approved',
            (string) $this->clerk?->getKey(),
        );

        app(Budgets::class)->set(
            (string) $version->id,
            (string) $this->period?->getKey(),
            $this->expenseCode(),
            '1000.0000',
        );

        $refusal = $this->refusal(fn () => app(Budgets::class)->approve(
            (string) $version->id,
            (string) $this->clerk?->getKey(),
        ));

        if ($refusal === null) {
            return ['the person who drafted the budget approved it themselves. What is being approved '.
                'is what a department may spend, and a budget somebody set for themselves and signed '.
                'for themselves is a limit nobody imposed.'];
        }

        return str_contains($refusal, BudgetRefused::class)
            ? []
            : ['the refusal is a '.$refusal.' rather than a BudgetRefused'];
    }

    /** @return list<string> */
    private function approvingSupersedes(): array
    {
        $first = $this->aBudget([[$this->expenseCode(), '1000.0000']]);
        $second = $this->aBudget([[$this->expenseCode(), '1200.0000']]);

        $old = app(Budgets::class)->version($first);
        $new = app(Budgets::class)->version($second);
        $problems = [];

        if ((string) $old->status !== 'superseded') {
            $problems[] = sprintf(
                'the version that was the budget reports status "%s" after another was approved, so '.
                'two approved budgets exist for one year and nothing says which the report reads',
                (string) $old->status,
            );
        }

        if ((string) $old->superseded_by_id !== $second) {
            $problems[] = 'the superseded version does not name the one that replaced it, so "what '.
                'was the budget before this" has no answer - a chain column nothing sets reads as an '.
                'answer and returns null for every row';
        }

        if ((string) $new->status !== 'approved') {
            $problems[] = 'the newly approved version is not the approved one';
        }

        $standing = app(Budgets::class)->approved($this->entityId, $this->fiscalYearId());

        if ($standing === null || (string) $standing->id !== $second) {
            $problems[] = 'the approved version is not the one approved most recently';
        }

        return $problems;
    }

    /** @return list<string> */
    private function aForeignDimensionIsRefused(): array
    {
        $other = Entity::create([
            'code' => 'OTH'.Str::upper(Str::random(6)),
            'legal_name' => 'Peakflow Elsewhere Ltd.',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        /*
         | Created INSIDE the other entity's scope, because row-level
         | security refuses it from here - correctly.
         |
         | Which is the point worth recording: the first draft wrote the
         | rows under the fixture entity's context and RLS refused them,
         | so the check could not be built at all. Using a random ULID
         | instead would have been the hollow version of this check - it
         | would exercise the "no such value" guard and leave the
         | cross-entity one untested, free to be removed with nothing
         | failing.
         */
        [$axisId, $foreign] = app(\App\Tenant\Tenancy\Services\EntityContext::class)->during(
            $other,
            function () use ($other): array {
                $axis = $this->aDimensionAxis((string) $other->getKey(), 'cost_centre', 3);

                return [$axis, $this->aDimensionValue($axis, (string) $other->getKey(), 'THEIRS')];
            },
        );

        unset($axisId);

        $version = app(Budgets::class)->draft(
            $this->entityId,
            $this->fiscalYearId(),
            'With a foreign cost centre',
            (string) $this->clerk?->getKey(),
        );

        $refusal = $this->refusal(fn () => app(Budgets::class)->set(
            (string) $version->id,
            (string) $this->period?->getKey(),
            $this->expenseCode(),
            '500.0000',
            [3 => $foreign],
        ));

        if ($refusal === null) {
            return ['a budget line was keyed to another entity\'s cost centre. It would put a '.
                'department in a company it is not part of, and because both ids are valid ULIDs '.
                'nothing else would notice.'];
        }

        $problems = [];

        if (! str_contains($refusal, BudgetRefused::class)) {
            $problems[] = 'the refusal is a '.$refusal.' rather than a BudgetRefused, so a caller '.
                'catching it is hit by something else and the screen is a 500 where a sentence belongs';
        }

        /*
         | The SENTENCE, not only the class - and this check was hollow
         | until it asserted one.
         |
         | The bite sweep found it: with the cross-entity guard removed the
         | call was still refused, because row-level security hides another
         | entity's dimension value from this session entirely - so the
         | "no value answers to that id" guard fired instead, threw the
         | same class, and the check reported a guard that was not running
         | as working.
         |
         | Both sentences are correct refusals of the same attempt, so
         | either satisfies this, and what is asserted is that the reader
         | is told WHICH - "a cost centre that does not exist" and "a
         | department in a company it is not part of" send somebody to
         | different places. With the existence guard removed the code
         | dereferences a null instead, and the class assertion above is
         | what then fails.
         */
        if (! str_contains($refusal, 'does not exist') && ! str_contains($refusal, 'another entity')) {
            $problems[] = 'the refusal does not say what was wrong with the value: "'.$refusal.'"';
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  PF-193 - budget versus actual
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function theVarianceIsStated(): array
    {
        $versionId = $this->aBudget([[$this->expenseCode(), '1000.0000']]);
        $this->postToExpense('600.0000');

        /*
         | 300 posted in the period BEFORE the window, and the window
         | opens after it.
         |
         | This check was hollow without it. With every posting inside the
         | window the account's opening balance is zero, so its movement
         | and its closing balance are the same number - and a report
         | reading the closing balance as the actual passed every
         | assertion here. The bite sweep found exactly that. April's
         | period is opened for the posting and the window starts on the
         | first of May, which is the only arrangement that tells a
         | movement and a balance apart.
         */
        $this->postToAPriorPeriod('300.0000');

        $report = $this->variance($versionId, from: Carbon::parse('2026-05-01'));
        $row = $this->rowFor($report, $this->expenseCode());

        if ($row === null) {
            return ['the budgeted account is not on the report at all'];
        }

        $problems = [];

        if ((string) $row['budget'] !== '1000.0000') {
            $problems[] = sprintf('the budget reads %s rather than 1,000', (string) $row['budget']);
        }

        if ((string) $row['actual'] !== '600.0000') {
            $problems[] = sprintf(
                'the actual reads %s rather than the 600 that was posted - the actual is the '.
                'movement over the window and nothing else',
                (string) $row['actual'],
            );
        }

        if ((string) $row['variance'] !== '-400.0000') {
            $problems[] = sprintf('the variance reads %s rather than -400', (string) $row['variance']);
        }

        if ((string) $row['variance_percent'] !== '-40.00') {
            $problems[] = sprintf(
                'the variance percentage reads %s rather than -40.00. A percentage of money is '.
                'still arithmetic on money: through a float it prints digits nobody can defend.',
                (string) $row['variance_percent'],
            );
        }

        if ((string) $row['direction'] !== 'under_budget') {
            $problems[] = sprintf('an expense account that spent less reads "%s"', (string) $row['direction']);
        }

        if ($row['favourable'] !== true) {
            $problems[] = 'an expense account under its budget is not reported as favourable, and '.
                'the sign alone cannot say: the same sign on revenue is the opposite judgement';
        }

        return $problems;
    }

    /** @return list<string> */
    private function materialityHighlightsWhatMatters(): array
    {
        /*
         | One account 40% away from its budget and one 2% away.
         |
         | With one row the threshold cannot be observed to do anything:
         | a report that highlighted everything and a report that
         | highlighted the right thing look identical.
         */
        $versionId = $this->aBudget([
            [$this->expenseCode(), '1000.0000'],
            [$this->secondExpenseCode(), '1000.0000'],
        ]);

        $this->postToExpense('600.0000');
        $this->postToExpense('980.0000', $this->secondExpenseCode());

        $report = $this->variance($versionId);
        $big = $this->rowFor($report, $this->expenseCode());
        $small = $this->rowFor($report, $this->secondExpenseCode());
        $problems = [];

        if ($big === null || $small === null) {
            return ['the two budgeted accounts are not both on the report'];
        }

        if ($big['material'] !== true) {
            $problems[] = sprintf(
                'a %s%% variance is not highlighted at a %s%% threshold, so the report shows nothing '.
                'of what matters',
                (string) $big['variance_percent'],
                (string) $report['materiality']['percent'],
            );
        }

        if ($small['material'] !== false) {
            $problems[] = sprintf(
                'a %s%% variance is highlighted at a %s%% threshold. A report with every line '.
                'highlighted is a report nobody reads, which is the same as none being highlighted.',
                (string) $small['variance_percent'],
                (string) $report['materiality']['percent'],
            );
        }

        if ((string) $report['materiality']['percent'] !== '5.00') {
            $problems[] = sprintf(
                'the threshold on the output is %s and the entity setting is 5.00 - two reports with '.
                'the same highlighted rows and different thresholds are two different questions, and '.
                'the highlighting does not say so',
                (string) $report['materiality']['percent'],
            );
        }

        if ((int) $report['materiality']['material_rows'] < 1) {
            $problems[] = 'the report counts no material rows while one is 40% away from its budget';
        }

        /*
         | And a threshold passed in moves the highlighting.
         |
         | A threshold read from the setting and then ignored would pass
         | every assertion above, because the default and the fixture
         | happen to agree.
         */
        $lenient = $this->variance($versionId, materiality: '60.00');
        $wasBig = $this->rowFor($lenient, $this->expenseCode());

        if ($wasBig !== null && $wasBig['material'] !== false) {
            $problems[] = 'at a 60% threshold a 40% variance is still highlighted, so the threshold '.
                'is stated and not applied';
        }

        return $problems;
    }

    /** @return list<string> */
    private function unbudgetedSpendingIsReported(): array
    {
        $versionId = $this->aBudget([[$this->expenseCode(), '1000.0000']]);

        // Spent on an account this version budgeted nothing for.
        $this->postToExpense('750.0000', $this->secondExpenseCode());

        $report = $this->variance($versionId);
        $row = $this->rowFor($report, $this->secondExpenseCode());

        if ($row === null) {
            return ['750 was spent on an account with no budget line and the report does not list '.
                'it. A variance report showing only budgeted accounts hides every riyal spent '.
                'outside the budget, which is the spending nobody authorised.'];
        }

        $problems = [];

        if ($row['variance_percent'] !== null) {
            $problems[] = sprintf(
                'an account with no budget reports a variance of %s%%, so a division by zero became '.
                'a percentage - and 0%% reads as a line exactly on budget',
                (string) $row['variance_percent'],
            );
        }

        if ($row['material'] !== true || $row['unbudgeted'] !== true) {
            $problems[] = 'unbudgeted spending is not reported as material, so it can fall below a '.
                'threshold that has no percentage to compare against';
        }

        if ((int) $report['unbudgeted_accounts'] < 1) {
            $problems[] = 'the report does not count the unbudgeted accounts';
        }

        if (! str_contains((string) $report['declaration']['en'], 'no budget line')) {
            $problems[] = 'the declaration does not mention the spending against no budget line';
        }

        return $problems;
    }

    /** @return list<string> */
    private function commitmentsReduceAvailable(): array
    {
        $versionId = $this->aBudget([[$this->expenseCode(), '10000.0000']]);
        $this->postToExpense('1000.0000');

        /*
         | A confirmed order for 100 at 10, which commits 1,000 net.
         |
         | Confirmed and not billed: the commitment is money not yet
         | spent, and a line that counted the receipt would release the
         | commitment before the obligation existed.
         */
        $this->aConfirmedOrder();

        $report = $this->variance($versionId);
        $row = $this->rowFor($report, $this->expenseCode());

        if ($row === null) {
            return ['the budgeted account is not on the report'];
        }

        $problems = [];

        if ((string) $row['committed'] !== '1000.0000') {
            $problems[] = sprintf(
                'the commitment column reads %s rather than the 1,000 still open on the order. A '.
                'committed budget is not an available one, and a report showing only the actual '.
                'says the department has more left than it has.',
                (string) $row['committed'],
            );
        }

        if ((string) $row['available'] !== '8000.0000') {
            $problems[] = sprintf(
                'available reads %s rather than 10,000 less 1,000 spent less 1,000 committed',
                (string) $row['available'],
            );
        }

        if ((string) $report['commitments']['total'] !== '1000.0000') {
            $problems[] = sprintf(
                'the commitment total reads %s rather than 1,000',
                (string) $report['commitments']['total'],
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function aDraftBudgetIsRefused(): array
    {
        $version = app(Budgets::class)->draft(
            $this->entityId,
            $this->fiscalYearId(),
            'Unfinished work',
            (string) $this->clerk?->getKey(),
        );

        app(Budgets::class)->set(
            (string) $version->id,
            (string) $this->period?->getKey(),
            $this->expenseCode(),
            '1000.0000',
        );

        $refusal = $this->refusal(fn () => $this->variance(null));
        $problems = [];

        if ($refusal === null) {
            $problems[] = 'with no approved version the report fell back to a draft. A draft is '.
                'somebody\'s unfinished work, and a variance against it changes without anything '.
                'being posted.';
        } elseif (! str_contains($refusal, ReportRefused::class)) {
            $problems[] = 'the refusal is a '.$refusal.' rather than a ReportRefused';
        }

        // And naming the draft explicitly is allowed, with the status said.
        $named = $this->variance((string) $version->id);

        if ((string) $named['version']['status'] !== 'draft') {
            $problems[] = 'a report run against a named draft does not say the version is a draft, '.
                'so a reader cannot tell that its figures may still move';
        }

        if (! str_contains((string) $named['declaration']['en'], 'DRAFT VERSION')) {
            $problems[] = 'the declaration does not say the budget is a draft, which is the one '.
                'thing that makes the comparison unreproducible';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theCeilingHolds(): array
    {
        $refusal = $this->refusal(fn () => app(Settings::class)->set(
            'budget.materiality_percent',
            '50.00',
            $this->entityId,
        ));

        if ($refusal === null) {
            return ['a materiality threshold of 50% was accepted. A department spending half again '.
                'as much as it was allowed does not appear on a report set to highlight at 50%, and '.
                'the page looks clean - which is the same shape as a generous write-off tolerance.'];
        }

        $problems = [];

        // And the ceiling does not forbid an ordinary value.
        $ok = $this->refusal(fn () => app(Settings::class)->set(
            'budget.materiality_percent',
            '2.50',
            $this->entityId,
        ));

        if ($ok !== null) {
            $problems[] = 'a threshold of 2.5% was refused as well, so the ceiling is refusing '.
                'everything rather than refusing what it is for: '.$ok;
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  PF-193 - profit or loss by dimension combination
    // -----------------------------------------------------------------

    /** @return list<string> */
    private function theDimensionPivotAddsUp(): array
    {
        $axisId = $this->aDimensionAxis($this->entityId, 'cost_centre', 3);
        $riyadh = $this->aDimensionValue($axisId, $this->entityId, 'RUH');
        $jeddah = $this->aDimensionValue($axisId, $this->entityId, 'JED');

        $this->postToExpenseWithDimension('600.0000', 3, $riyadh);
        $this->postToExpenseWithDimension('400.0000', 3, $jeddah);

        /*
         | And one posting with no cost centre at all.
         |
         | The usual cause of a pivot that is plausible and short. It has
         | to appear as "unclassified" rather than be dropped, or the
         | shortfall IS the spending nobody classified.
         */
        $this->postToExpense('100.0000');

        $report = app(BudgetVariance::class)->byDimension(
            $this->entityId,
            'SAR',
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31'),
            ['cost_centre'],
        );

        $problems = [];
        $combinations = [];

        foreach ($report['rows'] as $row) {
            $combinations[(string) $row['combination']] = (string) $row['result'];
        }

        foreach (['cost_centre: RUH', 'cost_centre: JED', 'unclassified'] as $expected) {
            if (! \array_key_exists($expected, $combinations)) {
                $problems[] = sprintf(
                    'the pivot has no "%s" row, and it has %s',
                    $expected,
                    implode(', ', array_keys($combinations)) ?: 'no rows at all',
                );
            }
        }

        if (($combinations['cost_centre: RUH'] ?? null) !== '-600.0000') {
            $problems[] = sprintf(
                'the Riyadh combination reports a result of %s rather than -600, so the filter is '.
                'not reaching the lines',
                (string) ($combinations['cost_centre: RUH'] ?? 'nothing'),
            );
        }

        if (($combinations['cost_centre: JED'] ?? null) !== '-400.0000') {
            $problems[] = sprintf(
                'the Jeddah combination reports %s rather than -400',
                (string) ($combinations['cost_centre: JED'] ?? 'nothing'),
            );
        }

        if ($report['reconciliation']['agrees'] !== true) {
            $problems[] = 'the combinations do not add to the statement of profit or loss for the '.
                'same window: '.(string) $report['reconciliation']['why'];
        }

        $refusal = $this->refusal(fn () => app(BudgetVariance::class)->byDimension(
            $this->entityId,
            'SAR',
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31'),
            ['no_such_axis'],
        ));

        if ($refusal === null) {
            $problems[] = 'a pivot on an axis that does not exist came back with one unclassified '.
                'column, which reads as a business that classifies nothing';
        }

        return $problems;
    }

    /** @return list<string> */
    private function twoAxesCombine(): array
    {
        /*
         | Two axes, because "any dimension combination" is a plural.
         |
         | A pivot proved on one axis has not been proved to combine
         | anything: the whole reason dimensions are columns is that a
         | cost centre and a project can be read together, and a method
         | that silently used only the first axis would pass every
         | assertion in the one-axis check.
         */
        $centres = $this->aDimensionAxis($this->entityId, 'cost_centre', 3);
        $projects = $this->aDimensionAxis($this->entityId, 'project', 4);

        $riyadh = $this->aDimensionValue($centres, $this->entityId, 'RUH');
        $jeddah = $this->aDimensionValue($centres, $this->entityId, 'JED');
        $atlas = $this->aDimensionValue($projects, $this->entityId, 'ATLAS');

        $this->postToExpenseWithDimensions('600.0000', [3 => $riyadh, 4 => $atlas]);
        $this->postToExpenseWithDimensions('400.0000', [3 => $riyadh]);
        $this->postToExpenseWithDimensions('250.0000', [3 => $jeddah, 4 => $atlas]);

        $report = app(BudgetVariance::class)->byDimension(
            $this->entityId,
            'SAR',
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31'),
            ['cost_centre', 'project'],
        );

        $combinations = [];

        foreach ($report['rows'] as $row) {
            $combinations[(string) $row['combination']] = (string) $row['result'];
        }

        $problems = [];

        $expected = [
            'cost_centre: RUH / project: ATLAS' => '-600.0000',
            'cost_centre: RUH' => '-400.0000',
            'cost_centre: JED / project: ATLAS' => '-250.0000',
        ];

        foreach ($expected as $label => $result) {
            if (! \array_key_exists($label, $combinations)) {
                $problems[] = sprintf(
                    'the pivot has no "%s" row; it has %s',
                    $label,
                    implode(' | ', array_keys($combinations)) ?: 'no rows at all',
                );

                continue;
            }

            if ($combinations[$label] !== $result) {
                $problems[] = sprintf(
                    '"%s" reports %s rather than %s, so the two axes are not both reaching the lines',
                    $label,
                    $combinations[$label],
                    $result,
                );
            }
        }

        /*
         | Riyadh appears twice, with and without a project.
         |
         | Which is the case a pivot on one axis cannot produce and the
         | one that proves the combination is the key: 1,000 was spent in
         | Riyadh and only 600 of it is on Atlas.
         */
        if (\array_key_exists('cost_centre: RUH', $combinations)
            && \array_key_exists('cost_centre: RUH / project: ATLAS', $combinations)
            && $combinations['cost_centre: RUH'] === $combinations['cost_centre: RUH / project: ATLAS']) {
            $problems[] = 'the two Riyadh rows carry the same figure, so the project axis is being '.
                'ignored and the pivot is one axis wearing two column headings';
        }

        if ($report['reconciliation']['agrees'] !== true) {
            $problems[] = 'the two-axis combinations do not add to the statement for the same '.
                'window: '.(string) $report['reconciliation']['why'];
        }

        if ($report['axes'] !== ['cost_centre', 'project']) {
            $problems[] = 'the output does not state both axes it was run on';
        }

        return $problems;
    }

    /** @return list<string> */
    private function theBudgetColumnsAreReal(): array
    {
        $versionId = $this->aBudget([[$this->expenseCode(), '1000.0000']]);
        $this->postToExpense('600.0000');

        $axisId = $this->aDimensionAxis($this->entityId, 'cost_centre', 3);
        $riyadh = $this->aDimensionValue($axisId, $this->entityId, 'RUH');
        $this->postToExpenseWithDimension('300.0000', 3, $riyadh);

        $reports = [
            'budget versus actual' => $this->variance($versionId),
            'profit or loss by dimension' => app(BudgetVariance::class)->byDimension(
                $this->entityId,
                'SAR',
                Carbon::parse('2026-05-01'),
                Carbon::parse('2026-05-31'),
                ['cost_centre'],
            ),
        ];

        $problems = [];

        foreach ($reports as $name => $report) {
            if ($report['rows'] === []) {
                $problems[] = sprintf('%s produced no rows, so its declared columns prove nothing', $name);

                continue;
            }

            foreach ($report['columns'] as $column) {
                if ((string) $column['label_ar'] === '' || (string) $column['label_en'] === '') {
                    $problems[] = sprintf(
                        '%s declares a "%s" column with no label in one of the two languages',
                        $name,
                        (string) $column['key'],
                    );
                }

                foreach ($report['rows'] as $row) {
                    if (! \array_key_exists((string) $column['key'], $row)) {
                        $problems[] = sprintf(
                            '%s declares a "%s" column and a row carries none, so an export built '.
                            'from the column list writes a header over an empty column',
                            $name,
                            (string) $column['key'],
                        );

                        break;
                    }
                }
            }
        }

        return $problems;
    }

    // -----------------------------------------------------------------
    //  Fixtures
    // -----------------------------------------------------------------

    private function report(): BankReconciliationReport
    {
        return app(BankReconciliationReport::class);
    }

    /**
     * A reconciled statement, authorised by the controller.
     *
     * Through the real service, which refuses an account that does not
     * reconcile - so a fixture that produced a document at all has
     * produced one whose identity holds.
     */
    private function aReconciliation(): string
    {
        $this->importFirstStatement();

        $statement = $this->bank()->lastStatement();
        \assert($statement !== null);

        $result = app(Reconciliations::class)->reconcile(
            $statement,
            (string) $this->controller?->getKey(),
        );

        return (string) $result['id'];
    }

    /**
     * A statement whose two lines are three weeks apart.
     *
     * The default fixture statement's lines are a day apart, so every
     * item on it falls in one bucket whatever the boundaries are - and a
     * bucket boundary moved by thirty days would change nothing. These
     * two, read in August, are 95 and 72 days old: two buckets, and the
     * boundary between them is load-bearing.
     */
    private function importASpreadStatement(): void
    {
        $from = $this->openingDate();

        $this->importFirstStatement(
            lines: [
                [
                    'booking_date' => $from->copy()->addDay()->toDateString(),
                    'value_date' => $from->copy()->addDay()->toDateString(),
                    'amount' => '1000.0000',
                    'bank_reference' => 'SPREAD-EARLY',
                    'counterparty_name' => 'Gulf Retail LLC',
                    'remittance_unstructured' => 'Nothing anybody can read',
                ],
                [
                    'booking_date' => $from->copy()->addDays(24)->toDateString(),
                    'value_date' => $from->copy()->addDays(24)->toDateString(),
                    'amount' => '500.0000',
                    'bank_reference' => 'SPREAD-LATE',
                    'counterparty_name' => 'Gulf Retail LLC',
                    'remittance_unstructured' => 'Nothing anybody can read either',
                ],
            ],
            openingBalance: '50000.0000',
            closingBalance: '51500.0000',
            periodFrom: $from->copy(),
            periodTo: $from->copy()->addDays(28),
            contentHash: hash('sha256', 'spread-'.Str::random(8)),
        );
    }

    /**
     * A match row, with who applied it.
     *
     * The shared fixture's `applyAMatchDirectly()` imports its own line
     * for the exact amount being matched and records no actor, which is
     * right for the checks it was written for and wrong for both of the
     * things being checked here: a PART-applied line, and who applied it.
     */
    private function aMatch(
        BankTransaction $transaction,
        string $amount,
        string $type,
        string $documentId,
        ?string $appliedBy = null,
    ): string {
        $id = (string) Str::ulid();

        $this->connection->table('matches')->insert([
            'id' => $id,
            'entity_id' => $this->entityId,
            'bank_transaction_id' => (string) $transaction->getKey(),
            'booking_date' => $transaction->booking_date->toDateString(),
            'matched_type' => $type,
            'matched_id' => $documentId,
            'amount' => $amount,
            'currency' => (string) $transaction->currency,
            'pass' => 'exact_reference',
            'confidence' => '0.9000',
            'reason' => 'the remittance named the document',
            'applied_automatically' => false,
            'applied_at' => now(),
            'applied_by' => $appliedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * A released run with its in-transit posting, written at the table.
     *
     * Preparing and releasing a run through the services needs three
     * distinct people and a step-up at the act - which is PF-174 and
     * PF-175's subject and is checked there. What an in-transit AGING
     * depends on is narrower: released instructions nothing has cleared.
     * So the fixture writes that state, satisfies every constraint the
     * tables carry, and posts the release through the real rule - because
     * `ledger:verify` asserts the in-transit control account equals the
     * derivation, and a fixture that wrote the rows and skipped the
     * posting would fail the global proof rather than the check.
     *
     * One `pay_on` per run, because that is what the table holds and what
     * the age is measured from: two ages need two runs, and a helper that
     * pretended otherwise would quietly put both instructions in one
     * bucket and the aging check would prove nothing.
     *
     * @param  list<string>  $amounts
     */
    private function aReleasedRun(string $payOn, array $amounts): string
    {
        $runId = (string) Str::ulid();
        $total = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        /*
         | Prepared first, then released - because the database says so.
         |
         | A released run's lines are frozen by a trigger, so a fixture
         | that inserted the run as released could not then give it any
         | instructions at all. Preparing it, writing the lines and moving
         | it to released is the one order the tables allow, and it is the
         | order the real service goes in: the freeze is the control
         | working, not an obstacle.
         */
        $this->connection->table('payment_runs')->insert([
            'id' => $runId,
            'entity_id' => $this->entityId,
            'number' => 'RUN-'.Str::upper(Str::random(8)),
            'state' => 'prepared',
            'bank_account_id' => (string) $this->bank()->getKey(),
            'currency' => 'SAR',
            'pay_on' => $payOn,
            'criteria' => json_encode(['horizon_days' => 30], JSON_THROW_ON_ERROR),
            'gross_total' => (string) $total->toScale(4),
            'discount_total' => '0.0000',
            'net_total' => (string) $total->toScale(4),
            'item_count' => \count($amounts),
            'instruction_count' => \count($amounts),
            'prepared_by' => (string) $this->clerk?->getKey(),
            'prepared_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($amounts as $amount) {
            $this->connection->table('payment_run_instructions')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'run_id' => $runId,
                'party_id' => (string) $this->supplier?->getKey(),
                'amount' => $amount,
                'currency' => 'SAR',
                'sequence' => 1,
                'of' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             | Posted on the run's own date, through the real rule.
             |
             | Dr accounts payable, Cr payments in transit - which is what
             | makes the control account equal the derivation, and what
             | `ledger:verify` asserts at the end of this suite.
             */
            app(Poster::class)->post(
                entity: $this->entity(),
                ruleKey: RunReleasedRule::KEY,
                event: [
                    'amount' => $amount,
                    'party_id' => (string) $this->supplier?->getKey(),
                    'narration' => 'Released for payment',
                ],
                accountingDate: Carbon::parse($payOn),
                journalCode: 'BNK',
                sourceType: 'payment_run',
                sourceId: $runId,
            );
        }

        $this->connection->table('payment_runs')->where('id', $runId)->update([
            'state' => 'released',
            'released_by' => (string) $this->controller?->getKey(),
            'released_at' => now(),
            'updated_at' => now(),
        ]);

        return $runId;
    }

    /** What the report says is unexplained on one line, if anything. */
    private function unexplainedFor(string $reference): ?string
    {
        $report = $this->report()->unreconciledAging(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-08-05'),
        );

        foreach ($report['rows'] as $row) {
            if ((string) $row['reference'] === $reference) {
                return (string) $row['unexplained'];
            }
        }

        return null;
    }

    /**
     * An approved budget: drafted by the clerk, approved by the controller.
     *
     * Two people because the table requires two - a budget somebody set
     * for themselves and approved for themselves is the control not
     * having operated, and the column pair refuses it.
     *
     * @param  list<array{0: string, 1: string}>  $lines  account code, amount
     */
    private function aBudget(array $lines, bool $approve = true): string
    {
        $version = app(Budgets::class)->draft(
            $this->entityId,
            $this->fiscalYearId(),
            'Budget '.Str::upper(Str::random(5)),
            (string) $this->clerk?->getKey(),
        );

        foreach ($lines as [$code, $amount]) {
            app(Budgets::class)->set(
                (string) $version->id,
                (string) $this->period?->getKey(),
                $code,
                $amount,
            );
        }

        if ($approve) {
            app(Budgets::class)->approve((string) $version->id, (string) $this->controller?->getKey());
        }

        return (string) $version->id;
    }

    /**
     * The variance report over the fixture's own period.
     *
     * @return array<string, mixed>
     */
    private function variance(
        ?string $versionId,
        ?string $materiality = null,
        ?Carbon $from = null,
        ?Carbon $until = null,
    ): array {
        return app(BudgetVariance::class)->versus(
            entityId: $this->entityId,
            functionalCurrency: 'SAR',
            fiscalYearId: $this->fiscalYearId(),
            versionId: $versionId,
            from: $from,
            until: $until,
            materialityPercent: $materiality,
        );
    }

    /**
     * Post to the same account in the period before the fixture's own.
     *
     * The period has to be opened first: a generated calendar's periods
     * start `future` and the ledger refuses a posting into one, which is
     * correct and is why this is two steps rather than one.
     */
    private function postToAPriorPeriod(string $amount): void
    {
        $on = $this->date->copy()->subMonth()->startOfMonth()->addDays(14);

        $period = app(\App\Tenant\Ledger\Services\FiscalCalendarGenerator::class)
            ->periodFor($this->entityId, $on);

        if ($period->state !== \App\Tenant\Ledger\Enums\PeriodState::Open) {
            app(\App\Tenant\Ledger\Services\PeriodStateMachine::class)->transition(
                $period,
                \App\Tenant\Ledger\Enums\PeriodState::Open,
                'opened so a budget check can post before its window',
            );
        }

        $this->importAnEntry($this->expenseCode(), $amount, [], $on);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>|null
     */
    private function rowFor(array $report, string $code): ?array
    {
        foreach ($report['rows'] as $row) {
            if ((string) $row['account'] === $code) {
                return $row;
            }
        }

        return null;
    }

    private function fiscalYearId(): string
    {
        return (string) $this->period?->fiscal_year_id;
    }

    /**
     * A postable expense account with NO control role.
     *
     * The purchasing fixture's own expense account is `cost_of_sales`,
     * which is a control account - and a manual journal to a control
     * account is refused, because its balance is the total of a subledger
     * and a manual entry makes the two disagree. The first draft of this
     * suite posted there and the ledger refused every actual, which is
     * the guard working rather than the fixture being unlucky.
     *
     * @return list<string>
     */
    private function freeExpenseCodes(): array
    {
        return array_map(
            static fn (mixed $code): string => (string) $code,
            $this->connection->table('accounts as a')
                ->leftJoin('account_roles as r', 'r.account_id', '=', 'a.id')
                ->where('a.entity_id', $this->entityId)
                ->where('a.type', 'expense')
                ->where('a.postable', true)
                ->whereNull('r.role')
                ->orderBy('a.code')
                ->pluck('a.code')
                ->all(),
        );
    }

    private function expenseCode(): string
    {
        return $this->freeExpenseCodes()[0] ?? '';
    }

    private function freeExpenseAccountId(): string
    {
        return (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('code', $this->expenseCode())
            ->value('id');
    }

    /**
     * A second postable expense account, so two variances can differ.
     *
     * One account cannot tell a threshold that filters from one that
     * highlights everything, and it cannot tell budgeted spending from
     * unbudgeted either.
     */
    private function secondExpenseCode(): string
    {
        return $this->freeExpenseCodes()[1] ?? '';
    }

    /** A postable account that is neither revenue nor expense, with no role. */
    private function balancingCode(): string
    {
        return (string) $this->connection->table('accounts as a')
            ->leftJoin('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('a.entity_id', $this->entityId)
            ->where('a.postable', true)
            ->whereIn('a.type', ['asset', 'liability'])
            ->whereNull('r.role')
            ->orderBy('a.code')
            ->value('a.code');
    }

    /**
     * Post an expense through the real import path.
     *
     * `JournalImport` rather than a hand-written row: a journal line
     * written at the table would skip the dimension validity rules, the
     * balanced-entry constraint and the period check, and the actuals on
     * a budget report are exactly the figures those rules protect.
     *
     * It needs `journal.post` through the Gate, so the accountant signs
     * in for the call and the controller is put back afterwards.
     */
    private function postToExpense(string $amount, ?string $code = null): void
    {
        $this->importAnEntry($code ?? $this->expenseCode(), $amount, []);
    }

    /** The same, with a dimension value on the expense line. */
    private function postToExpenseWithDimension(string $amount, int $axis, string $valueId): void
    {
        $this->importAnEntry($this->expenseCode(), $amount, ['dimension_'.$axis => $valueId]);
    }

    /**
     * The same, with several axes at once.
     *
     * @param  array<int, string>  $values  axis number => dimension value id
     */
    private function postToExpenseWithDimensions(string $amount, array $values): void
    {
        $extra = [];

        foreach ($values as $axis => $valueId) {
            $extra['dimension_'.$axis] = $valueId;
        }

        $this->importAnEntry($this->expenseCode(), $amount, $extra);
    }

    /** @param array<string, string> $extra */
    private function importAnEntry(string $code, string $amount, array $extra, ?Carbon $on = null): void
    {
        $was = Auth::guard('web')->user();
        $reference = 'BUD-'.Str::upper(Str::random(6));
        $date = ($on ?? $this->date)->toDateString();

        /*
         | Signed in as the accountant, who holds `journal.post`.
         |
         | The import asks the Gate, which is right: an import that posted
         | without the ability would be a second posting path round the
         | permission that governs the first.
         */
        \assert($this->clerk !== null);
        $this->signInAs($this->clerk);

        try {
            app(JournalImport::class)->import(
                entity: $this->entity(),
                /*
                 | ONE reference for both rows, because a reference is an
                 | ENTRY and not a row.
                 |
                 | The first draft gave each row its own, and the import
                 | refused both: two entries of one line each, neither of
                 | which balances. The refusal named exactly that, which
                 | is the import doing the job it exists for.
                 */
                rows: [
                    [
                        'reference' => $reference,
                        'date' => $date,
                        'account_code' => $code,
                        'amount' => $amount,
                    ] + $extra,
                    [
                        'reference' => $reference,
                        'date' => $date,
                        'account_code' => $this->balancingCode(),
                        'amount' => '-'.$amount,
                    ],
                ],
                reason: 'a budget check needs an actual to compare against',
                headerRows: 0,
            );
        } finally {
            $was instanceof User ? $this->signInAs($was) : Auth::guard('web')->forgetUser();
        }
    }

    /** A dimension axis for an entity, at a named position. */
    private function aDimensionAxis(string $entityId, string $key, int $axis): string
    {
        $existing = $this->connection->table('dimension_axes')
            ->where('entity_id', $entityId)
            ->where('key', $key)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::ulid();

        $this->connection->table('dimension_axes')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'axis' => $axis,
            'key' => $key,
            'name_ar' => 'مركز التكلفة',
            'name_en' => 'Cost centre',
            'leaf_only' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function aDimensionValue(string $axisId, string $entityId, string $code): string
    {
        $id = (string) Str::ulid();

        $this->connection->table('dimension_values')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'dimension_axis_id' => $axisId,
            'code' => $code,
            'name_ar' => 'فرع',
            'name_en' => 'Branch '.$code,
            'path' => mb_strtolower($code),
            'postable' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** A confirmed order committing 1,000 net on the expense account. */
    private function aConfirmedOrder(): void
    {
        $order = app(PurchaseOrders::class)->raise(
            entity: $this->entity(),
            partyId: (string) $this->supplier?->getKey(),
            orderedOn: $this->date->copy(),
            currency: 'SAR',
            lines: [[
                'description' => 'Machined brackets, batch 41',
                'line_type' => 'goods',
                'quantity_ordered' => '100',
                'unit_price' => '10',
                /*
                 | The account the BUDGET is on, not the fixture's own.
                 |
                 | A commitment is shown against a budget line, so the two
                 | have to be keyed to the same account - and the
                 | purchasing fixture's expense account is a control
                 | account nothing here may budget or post to.
                 */
                'expense_account_id' => $this->freeExpenseAccountId(),
                'tax_code_id' => $this->taxCodeId,
                'tax_category' => 'S',
                'tax_rate' => '15.000000',
            ]],
            expectedOn: $this->date->copy()->addDays(14),
            createdBy: $this->actorId(),
        );

        app(PurchaseOrders::class)->confirm($order, $this->secondActorId());
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

        $this->controller?->forgetResolvedPermissions();
        $this->clerk?->forgetResolvedPermissions();
    }
}
