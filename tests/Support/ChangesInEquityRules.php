<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Reporting\Services\StatementOfChangesInEquity;
use App\Tenant\Reporting\Services\StatementOfFinancialPosition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The statement of changes in equity - PF-187.
 *
 * ## What a check here is for
 *
 * **Three statements that each look right and do not reconcile.** This is
 * the only one that has to agree with both of the others, and an entity
 * that published three different answers leaves the reader no way to tell
 * which one the ledger holds. So the closing column is checked against the
 * equity section of the statement of financial position, figure for
 * figure.
 *
 * **A movement that falls into no kind.** It is then on the balance sheet
 * and missing from this page, and the opening and closing columns do not
 * join up - which the statement has to say rather than print.
 *
 * **Two different causes added together.** A credit to retained earnings
 * is a profit if the close made it and a distribution if a dividend did.
 * IAS 1.106 presents those separately, so a check that only tested the
 * total would pass on a product that merged them.
 */
final class ChangesInEquityRules
{
    use Concerns\IssuesInvoices;
    use Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['changes in equity (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'one column per component of equity' => fn (): array => $this->aColumnPerComponent(),
                'opening plus the movements reaches closing' => fn (): array => $this->theArithmeticHolds(),
                'the adjusting bound is on both sides' => fn (): array => $this->theAdjustingBoundIsOnBothSides(),
                'the closing column is the balance sheet\'s equity' => fn (): array => $this->itReconcilesToTheBalanceSheet(),
                'an OCI movement is not a transaction with owners' => fn (): array => $this->ociIsItsOwnRow(),
                'a reversed period is refused' => fn (): array => $this->aReversedPeriodIsRefused(),
                'every row cites the paragraph that asks for it' => fn (): array => $this->rowsCiteTheStandard(),
            ]));
        } catch (Throwable $e) {
            return ['changes in equity could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return array<string, mixed> */
    private function statement(): array
    {
        return app(StatementOfChangesInEquity::class)->forPeriod(
            $this->entityId,
            'SAR',
            Carbon::parse($this->date->toDateString())->startOfMonth(),
            Carbon::parse($this->date->toDateString())->endOfMonth(),
        );
    }

    /** @return list<string> */
    private function aColumnPerComponent(): array
    {
        $this->creditTheTranslationReserve('300.0000');

        $components = $this->statement()['components'];

        if ($components === []) {
            return ['an entity with a movement on an equity account produced no components at all'];
        }

        $lines = array_column($components, 'line');

        if (! \in_array('foreign_operation_translation', $lines, true)) {
            return ['the translation reserve is not a component of equity on this statement, so its '.
                'movement is invisible - and IAS 1.106 asks for a reconciliation of EACH component'];
        }

        /*
         | Two reserves must not become one column.
         |
         | Grouping by statement line rather than by account would merge
         | reserves an entity deliberately keeps apart, and the reader
         | could not see the movement on either.
         */
        $codes = array_column($components, 'code');

        if (\count($codes) !== \count(array_unique($codes))) {
            return ['two components share an account code, so the columns are not one per account'];
        }

        return [];
    }

    /** @return list<string> */
    private function theArithmeticHolds(): array
    {
        $this->creditTheTranslationReserve('300.0000');

        $statement = $this->statement();
        $unexplained = Money::of((string) $statement['totals']['unexplained'], 'SAR');

        if (! $unexplained->isZero()) {
            return [sprintf(
                'opening plus the movements misses closing by %s, so a movement fell into none of the '.
                'kinds the statement presents.',
                (string) $unexplained->amount,
            )];
        }

        if ((string) $statement['declaration']['severity'] !== 'ok') {
            return ['the statement reconciles and does not say so: '
                .(string) $statement['declaration']['en']];
        }

        return [];
    }

    /**
     * The adjusting bound is on BOTH sides of the statement.
     *
     * ## What this found, and why the check is structural
     *
     * The opening and closing columns come from a trial balance that
     * honours `includeAdjusting`. The movement query did not: it summed
     * every posted line in the period, period 13 included. On the view that
     * EXCLUDES adjusting entries the two sides would disagree by exactly
     * the year-end adjustments, and the statement would declare itself
     * broken while the ledger was fine.
     *
     * ## Why it is not checked by posting one
     *
     * Reaching the adjusting period needs `AdjustingEntries::post()`, which
     * demands `period.post_adjusting` WITH step-up authentication and a
     * written reason of twenty characters - all correct, and all of it
     * machinery this suite does not have. A fixture posting to an ordinary
     * period cannot see the flag at all: the bite sweep proved that, by
     * removing the bound and changing nothing.
     *
     * So this reads the query. That is weaker than exercising it and the
     * weakness is the point of saying so here: it catches the bound being
     * deleted, and it would not catch the bound being wrong. The behavioural
     * check arrives with the fixture that can open period 13.
     *
     * @return list<string>
     */
    private function theAdjustingBoundIsOnBothSides(): array
    {
        $problems = [];

        $movements = (string) file_get_contents(
            base_path('app/Tenant/Reporting/Services/EquityMovements.php'),
        );

        if (! str_contains($movements, 'is_adjusting')) {
            $problems[] = 'the movement query does not mention is_adjusting, so it sums period 13 while '.
                'the balances beside it exclude it - and the statement disagrees with itself by exactly '.
                'the year-end adjustments on the view an auditor asks for first';
        }

        /*
         | And the flag actually reaches the query.
         |
         | A bound in the SQL that no caller can switch is a bound that is
         | always on, which is the same bug with an extra line of evidence
         | for it.
         */
        $statement = (string) file_get_contents(
            base_path('app/Tenant/Reporting/Services/StatementOfChangesInEquity.php'),
        );

        if (! str_contains($statement, '$includeAdjusting,')) {
            $problems[] = 'the statement does not pass includeAdjusting to the movement query, so the '.
                'bound is always on and the two sides can still disagree';
        }

        foreach ([true, false] as $includeAdjusting) {
            $this->creditTheTranslationReserve('300.0000');

            $result = app(StatementOfChangesInEquity::class)->forPeriod(
                $this->entityId,
                'SAR',
                Carbon::parse($this->date->toDateString())->startOfYear(),
                Carbon::parse($this->date->toDateString())->endOfYear(),
                includeAdjusting: $includeAdjusting,
            );

            if (! Money::of((string) $result['totals']['unexplained'], 'SAR')->isZero()) {
                $problems[] = sprintf(
                    'with adjusting entries %s the statement leaves %s unexplained',
                    $includeAdjusting ? 'included' : 'excluded',
                    (string) $result['totals']['unexplained'],
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function itReconcilesToTheBalanceSheet(): array
    {
        $this->issue();
        $this->creditTheTranslationReserve('300.0000');

        $equity = app(StatementOfChangesInEquity::class)->forPeriod(
            $this->entityId,
            'SAR',
            Carbon::parse($this->date->toDateString())->startOfMonth(),
            Carbon::parse($this->date->toDateString())->endOfMonth(),
        );

        $position = app(StatementOfFinancialPosition::class)->asAt(
            $this->entityId,
            'SAR',
            Carbon::parse($this->date->toDateString())->startOfMonth(),
            Carbon::parse($this->date->toDateString())->endOfMonth(),
        );

        $closing = Money::of((string) $equity['totals']['closing_balance'], 'SAR');

        /*
         | Against the balance sheet's equity BEFORE the result is carried.
         |
         | The position statement adds the unclosed result into its equity
         | total, because revenue and expense balances are real and are
         | nowhere else on it. This statement's closing column is the equity
         | ACCOUNTS, which is what the ledger holds - so the figure to
         | compare is the section total, not the one with the result in it.
         */
        $section = null;

        foreach ($position['sections'] as $candidate) {
            if ($candidate['section'] === 'equity') {
                $section = $candidate;
            }
        }

        if ($section === null) {
            return ['the statement of financial position has no equity section to reconcile against'];
        }

        $onTheBalanceSheet = Money::of((string) $section['total'], 'SAR');

        if (! $closing->equals($onTheBalanceSheet)) {
            return [sprintf(
                'this statement closes equity at %s and the balance sheet reports %s. Two statements '.
                'with two answers leaves the reader no way to tell which one the ledger holds.',
                (string) $closing->amount,
                (string) $onTheBalanceSheet->amount,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function ociIsItsOwnRow(): array
    {
        $this->creditTheTranslationReserve('300.0000');

        $statement = $this->statement();
        $rows = [];

        foreach ($statement['rows'] as $row) {
            $rows[(string) $row['kind']] = $row;
        }

        $oci = Money::zero('SAR');

        foreach ($rows['other_comprehensive_income']['amounts'] as $amount) {
            $oci = $oci->plus(Money::of((string) $amount, 'SAR'));
        }

        if ($oci->isZero()) {
            return ['a credit to the translation reserve was not reported as other comprehensive '.
                'income, so an OCI movement is being presented as something else'];
        }

        $owners = Money::zero('SAR');

        foreach ($rows['transactions_with_owners']['amounts'] as $amount) {
            $owners = $owners->plus(Money::of((string) $amount, 'SAR'));
        }

        if (! $owners->isZero()) {
            return [sprintf(
                'a translation difference appeared under transactions with owners (%s). IAS 1.106 '.
                'presents those separately, and merging them tells the reader the shareholders put '.
                'money in when nobody did.',
                (string) $owners->amount,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function aReversedPeriodIsRefused(): array
    {
        try {
            app(StatementOfChangesInEquity::class)->forPeriod(
                $this->entityId,
                'SAR',
                Carbon::parse($this->date->toDateString())->endOfMonth(),
                Carbon::parse($this->date->toDateString())->startOfMonth(),
            );
        } catch (Throwable $e) {
            return str_contains($e->getMessage(), 'ends before it starts')
                ? []
                : ['a reversed period was refused for the wrong reason: '.$e->getMessage()];
        }

        return ['a period ending before it starts was accepted; every movement in it would read as its '.
            'own negative'];
    }

    /** @return list<string> */
    private function rowsCiteTheStandard(): array
    {
        $problems = [];

        foreach ($this->statement()['rows'] as $row) {
            $reference = (string) $row['reference'];

            if (! str_starts_with($reference, 'IAS 1.') && ! str_starts_with($reference, 'IAS 8.')) {
                $problems[] = sprintf(
                    'the row "%s" cites "%s". A row on this statement is there because a paragraph asks '.
                    'for it, and one that cites nothing is a heading somebody chose.',
                    (string) $row['kind'],
                    $reference,
                );
            }
        }

        return $problems;
    }

    /**
     * A credit to the translation reserve, through the real posting path.
     *
     * The counter side is any ordinary asset account: the fixture is about
     * where the credit is PRESENTED, and a real cause is a foreign
     * operation, which arrives with consolidation.
     */
    private function creditTheTranslationReserve(string $amount): void
    {
        $reserve = (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('statement_line', 'foreign_operation_translation')
            ->value('code');

        $counter = (string) $this->connection->table('accounts')
            ->where('entity_id', $this->entityId)
            ->where('type', 'asset')
            ->where('postable', true)
            ->whereNotIn('id', function ($query): void {
                $query->select('account_id')
                    ->from('account_roles')
                    ->where('entity_id', $this->entityId);
            })
            ->orderBy('code')
            ->value('code');

        if ($reserve === '' || $counter === '') {
            return;
        }

        /*
         | One reference for both lines, and a new one per call.
         |
         | The reference is what groups rows into an entry. Generating it
         | per ROW gave each line its own entry, and the import refused two
         | one-sided entries - the import working, and the fixture asking
         | for something incoherent.
         */
        $reference = 'SOCIE-'.Str::random(4);
        $when = $this->date->toDateString();

        $was = \Illuminate\Support\Facades\Auth::guard('web')->user();
        \Illuminate\Support\Facades\Auth::guard('web')->setUser($this->anAccountant());

        try {
            app(\App\Tenant\Ledger\Manual\JournalImport::class)->import(
                $this->entity(),
                [
                    ['reference' => $reference, 'date' => $when,
                        'account_code' => $counter, 'amount' => $amount],
                    ['reference' => $reference, 'date' => $when,
                        'account_code' => $reserve, 'amount' => '-'.$amount],
                ],
                reason: 'a translation difference, for the statement of changes in equity',
                headerRows: 0,
            );
        } finally {
            \Illuminate\Support\Facades\Auth::guard('web')->forgetUser();

            if ($was !== null) {
                \Illuminate\Support\Facades\Auth::guard('web')->setUser($was);
            }
        }
    }

    private function anAccountant(): \App\Tenant\Identity\Models\User
    {
        $role = \App\Tenant\Identity\Models\Role::query()->where('key', 'accountant')->firstOrFail();

        $user = \App\Tenant\Identity\Models\User::query()->create([
            'name' => 'Equity Probe',
            'email' => 'equity-probe-'.bin2hex(random_bytes(6)).'@invalid.test',
            'password' => Str::password(32),
            'active' => true,
        ]);

        \App\Tenant\Identity\Models\UserEntityRole::query()->create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => $role->getKey(),
        ]);

        return $user;
    }
}
