<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Money\Money;
use App\Tenant\Ledger\Reporting\StatementBasis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The trial balance - PF-058.
 *
 * ## One query, whatever the number of accounts
 *
 * The totals are aggregated in the database and the accounts joined in the
 * same statement. An N+1 here is invisible to every functional test - the
 * numbers come out right, and the report takes four seconds per thousand
 * accounts - so the shape is fixed here and asserted by a check that counts
 * queries rather than by anyone remembering.
 *
 * ## Dates, not just periods
 *
 * A trial balance as at an arbitrary date is what an auditor asks for, and
 * bounding by period alone cannot answer it. Both are supported and both
 * prune partitions, because the bound is on `accounting_date` either way.
 *
 * ## Adjusting entries are included or excluded, and the report says which
 *
 * A statement that does not say whether it includes period 13 is a statement
 * that cannot be compared to another one. The flag is part of the result, not
 * just an argument.
 */
final class TrialBalance
{
    /**
     * @return array{
     *     entity_id: string,
     *     as_at: string,
     *     from: ?string,
     *     includes_adjusting: bool,
     *     basis: array{includes_adjusting: bool, adjusting_entries: int, choice_mattered: bool, as_at: string, from: ?string, currency: string, declaration_ar: string, declaration_en: string},
     *     currency: string,
     *     rows: Collection<int, array{code: string, name_ar: string, name_en: string, type: string, debit: string, credit: string, balance: string, net_debit: string, net_credit: string}>,
     *     total_debit: string,
     *     total_credit: string,
     *     total_net_debit: string,
     *     total_net_credit: string,
     *     balances: bool,
     * }
     */
    public function for(
        string $entityId,
        string $functionalCurrency,
        Carbon $asAt,
        ?Carbon $from = null,
        bool $includeAdjusting = true,
        bool $fromJournal = false,
    ): array {
        /*
         | The read model first, when the range allows it - PF-058.
         |
         | `account_balances` holds one row per entity, period, account and
         | dimension set, maintained by triggers as lines are posted and
         | proved against the journal by BalanceRebuilder. Reading it instead
         | of aggregating the journal is the difference between 7.5 seconds
         | and a few milliseconds at ten million lines - measured, not
         | supposed: `bin/bench trialbalance` reports both.
         |
         | It can only be used for a range that lines up with whole periods,
         | because the read model's grain IS the period. An arbitrary
         | mid-month date has no row to read, and inventing one by
         | apportioning would be a figure nothing in the ledger agrees with.
         |
         | `fromJournal` forces the slow path. The verification check uses it
         | to assert the two agree, which is the only thing that makes the
         | fast path trustworthy.
         */
        if (! $fromJournal) {
            $periods = $this->periodsCovering($entityId, $asAt, $from, $includeAdjusting);

            if ($periods !== null) {
                return $this->fromReadModel(
                    $entityId,
                    $functionalCurrency,
                    $asAt,
                    $from,
                    $includeAdjusting,
                    $periods,
                );
            }
        }

        $bindings = [$entityId, $asAt->toDateString()];
        $dateBound = 'l.accounting_date <= ?';

        if ($from !== null) {
            $dateBound = 'l.accounting_date BETWEEN ? AND ?';
            $bindings = [$entityId, $from->toDateString(), $asAt->toDateString()];
        }

        /*
         | The adjusting period shares its calendar days with the last month,
         | so it cannot be excluded by date - only by the period's own flag.
         | That is the whole reason `is_adjusting` exists as a column rather
         | than being inferred from the ordinal.
         */
        $adjustingBound = $includeAdjusting ? '' : ' AND p.is_adjusting = false';

        $rows = DB::connection('tenant')->select(<<<SQL
            SELECT
                a.code,
                a.name_ar,
                a.name_en,
                a.type,
                -- Split for presentation only. The storage is one signed
                -- column; this is where the sign becomes two columns, and
                -- nowhere else.
                coalesce(sum(l.functional_amount) FILTER (WHERE l.functional_amount > 0), 0) AS debit,
                coalesce(-sum(l.functional_amount) FILTER (WHERE l.functional_amount < 0), 0) AS credit,
                coalesce(sum(l.functional_amount), 0) AS balance
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN periods p ON p.id = l.period_id
            JOIN accounts a ON a.id = l.account_id
            WHERE l.entity_id = ?
              /*
               | 'posted' AND 'reversed', and this is not a widening.
               |
               | `reversed` records that a REVERSING ENTRY EXISTS, not that
               | these lines were withdrawn - the original posting happened
               | and is still part of the history. The reversal is a separate
               | entry whose own lines cancel it.
               |
               | Counting only 'posted' therefore drops the original while
               | keeping the reversal, so every reversed transaction appears
               | as its own negative. A 1,234.56 receipt reversed in the next
               | period showed a cash balance of -1,234.56.
               |
               | A draft is excluded because it has not happened.
               */
              AND e.status IN ('posted', 'reversed')
              AND {$dateBound}{$adjustingBound}
            GROUP BY a.code, a.name_ar, a.name_en, a.type
            HAVING sum(l.functional_amount) <> 0
            ORDER BY a.code
        SQL, $bindings);

        return $this->assemble(
            $rows,
            $entityId,
            $functionalCurrency,
            $asAt,
            $from,
            $includeAdjusting,
            'journal',
        );
    }

    /**
     * The lines behind one figure - PF-058's drill-down.
     *
     * Same bounds as the balance it explains, so the sum of what comes back
     * equals the figure that was clicked. A drill-down that does not add up
     * to its own total is worse than none: it teaches people the report is
     * approximate.
     *
     * @return Collection<int, object>
     */
    public function drillDown(
        string $entityId,
        string $accountCode,
        Carbon $asAt,
        ?Carbon $from = null,
        bool $includeAdjusting = true,
    ): Collection {
        $bindings = [$entityId, $accountCode, $asAt->toDateString()];
        $dateBound = 'l.accounting_date <= ?';

        if ($from !== null) {
            $dateBound = 'l.accounting_date BETWEEN ? AND ?';
            $bindings = [$entityId, $accountCode, $from->toDateString(), $asAt->toDateString()];
        }

        $adjustingBound = $includeAdjusting ? '' : ' AND p.is_adjusting = false';

        return collect(DB::connection('tenant')->select(<<<SQL
            SELECT
                e.number,
                e.accounting_date,
                e.narration AS entry_narration,
                e.posting_rule,
                e.source_type,
                e.source_id,
                l.functional_amount,
                l.currency,
                l.amount,
                l.narration AS line_narration
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            JOIN periods p ON p.id = l.period_id
            JOIN accounts a ON a.id = l.account_id
            WHERE l.entity_id = ?
              AND a.code = ?
              -- Same set as the balance this explains; a drill-down that
              -- does not add up to its own total teaches people the report
              -- is approximate.
              AND e.status IN ('posted', 'reversed')
              AND {$dateBound}{$adjustingBound}
            ORDER BY e.accounting_date, e.number
        SQL, $bindings));
    }

    /**
     * How many adjusting entries fall in the statement's range.
     *
     * Counted rather than assumed, because "excluding adjusting entries" on a
     * range that holds none is the same figure as including them - and saying
     * so on the page settles an argument before it starts.
     *
     * Entries, not lines: the sentence is about how many adjustments the
     * auditor asked for, and forty lines in one entry is one adjustment.
     */
    private function adjustingEntriesIn(string $entityId, Carbon $asAt, ?Carbon $from): int
    {
        $bindings = [$entityId, $asAt->toDateString()];
        $dateBound = 'e.accounting_date <= ?';

        if ($from !== null) {
            $dateBound = 'e.accounting_date BETWEEN ? AND ?';
            $bindings = [$entityId, $from->toDateString(), $asAt->toDateString()];
        }

        $row = DB::connection('tenant')->selectOne(<<<SQL
            SELECT count(*) AS n
            FROM journal_entries e
            JOIN periods p ON p.id = e.period_id
            WHERE e.entity_id = cast(? AS char(26))
              AND e.status IN ('posted', 'reversed')
              AND p.is_adjusting = true
              AND {$dateBound}
        SQL, $bindings);

        return (int) ($row->n ?? 0);
    }

    /**
     * The periods a range covers exactly, or null if it does not line up.
     *
     * ## Why "exactly" is the whole question
     *
     * The read model's grain is the period. A range from the first of January
     * to the fifteenth of June has no rows to read for half of June, and
     * apportioning that half from the period's total would produce a figure
     * nothing in the ledger agrees with - a trial balance that is close.
     *
     * So the range has to start on a period's first day and end on a
     * period's last day. An open-ended range (`from` is null) is "everything
     * up to this date", which lines up whenever the date is a period end.
     *
     * Returns null rather than throwing, because not lining up is ordinary -
     * somebody asking for a balance as at the fifteenth is not making a
     * mistake, and the journal path answers them correctly.
     *
     * @return list<string>|null
     */
    private function periodsCovering(
        string $entityId,
        Carbon $asAt,
        ?Carbon $from,
        bool $includeAdjusting,
    ): ?array {
        $adjustingBound = $includeAdjusting ? '' : ' AND p.is_adjusting = false';

        /*
         | The adjusting period is excluded from the ALIGNMENT test as well
         | as from the result.
         |
         | It starts and ends on the year's last day, so it is never the row
         | that establishes a boundary - December is. Including it here would
         | let a range ending on 31 December "align" to the adjusting period
         | and silently drop December.
         */
        $rows = DB::connection('tenant')->select(<<<SQL
            SELECT p.id, p.starts_on, p.ends_on, p.is_adjusting
            FROM periods p
            WHERE p.entity_id = cast(? AS char(26))
              AND p.ends_on <= cast(? AS date)
              AND (cast(? AS date) IS NULL OR p.starts_on >= cast(? AS date))
              {$adjustingBound}
            ORDER BY p.starts_on, p.is_adjusting
        SQL, [$entityId, $asAt->toDateString(), $from?->toDateString(), $from?->toDateString()]);

        if ($rows === []) {
            return null;
        }

        $ordinary = array_values(array_filter(
            $rows,
            static fn (object $row): bool => ! (bool) $row->is_adjusting,
        ));

        if ($ordinary === []) {
            return null;
        }

        $last = $ordinary[count($ordinary) - 1];

        if ((string) $last->ends_on !== $asAt->toDateString()) {
            return null;
        }

        if ($from !== null && (string) $ordinary[0]->starts_on !== $from->toDateString()) {
            return null;
        }

        /*
         | And with no `from`, every period from the beginning has to be
         | there - a gap would silently drop a month.
         |
         | The gap test is on the ORDINARY periods only, since the adjusting
         | one shares its days with December and would read as an overlap.
         */
        for ($index = 1; $index < count($ordinary); $index++) {
            $previousEnd = Carbon::parse((string) $ordinary[$index - 1]->ends_on);
            $thisStart = Carbon::parse((string) $ordinary[$index]->starts_on);

            if (! $previousEnd->copy()->addDay()->isSameDay($thisStart)) {
                return null;
            }
        }

        return array_map(static fn (object $row): string => (string) $row->id, $rows);
    }

    /**
     * The same statement, read from `account_balances`.
     *
     * One grouped read over a table whose grain is the period, so the row
     * count follows the chart rather than the journal: 400 accounts over 12
     * periods is 4,800 rows to aggregate instead of ten million.
     *
     * The figures are identical to the journal path's by construction - the
     * read model is maintained by triggers from the same lines - and that
     * identity is asserted rather than assumed, by a check that runs both
     * and compares.
     *
     * @param  list<string>  $periodIds
     * @return array<string, mixed>
     */
    private function fromReadModel(
        string $entityId,
        string $functionalCurrency,
        Carbon $asAt,
        ?Carbon $from,
        bool $includeAdjusting,
        array $periodIds,
    ): array {
        $placeholders = implode(',', array_fill(0, count($periodIds), '?'));

        $rows = DB::connection('tenant')->select(<<<SQL
            SELECT
                a.code,
                a.name_ar,
                a.name_en,
                a.type,
                coalesce(sum(b.debits), 0)  AS debit,
                coalesce(sum(b.credits), 0) AS credit,
                coalesce(sum(b.net), 0)     AS balance
            FROM account_balances b
            JOIN accounts a ON a.id = b.account_id
            WHERE b.entity_id = cast(? AS char(26))
              AND b.period_id IN ({$placeholders})
            GROUP BY a.code, a.name_ar, a.name_en, a.type
            HAVING sum(b.net) <> 0
            ORDER BY a.code
        SQL, array_merge([$entityId], $periodIds));

        return $this->assemble(
            $rows,
            $entityId,
            $functionalCurrency,
            $asAt,
            $from,
            $includeAdjusting,
            'read_model',
        );
    }

    /**
     * Turn grouped rows into a statement, whichever side they came from.
     *
     * Shared by the journal path and the read-model path so the two cannot
     * drift in presentation while agreeing on the figures - a difference in
     * how the sign becomes two columns would be a difference nobody would
     * think to check.
     *
     * @param  list<object>  $rows
     * @return array<string, mixed>
     */
    private function assemble(
        array $rows,
        string $entityId,
        string $functionalCurrency,
        Carbon $asAt,
        ?Carbon $from,
        bool $includeAdjusting,
        string $source,
    ): array {
        $totalDebit = Money::zero($functionalCurrency);
        $totalCredit = Money::zero($functionalCurrency);

        $mapped = collect($rows)->map(function (object $row) use (
            $functionalCurrency,
            &$totalDebit,
            &$totalCredit,
        ): array {
            // Money from the string PostgreSQL returned, never through a
            // float - a trial balance that is out by 1e-17 still prints as
            // balanced and reconciles to nothing.
            $debit = Money::of((string) $row->debit, $functionalCurrency);
            $credit = Money::of((string) $row->credit, $functionalCurrency);

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);

            /*
             | The NET columns, which are what a trial balance actually is.
             |
             | `debit` and `credit` above are gross MOVEMENTS: an account
             | with 42,000 of debits and 1,000 of credits shows both. That is
             | useful and it is not a trial balance - no accountant expects
             | one account to appear in both columns, and a statement built
             | from the gross figures prints inflated totals that still
             | balance, so the totals check cannot catch it.
             |
             | The case that made this concrete: a receivable revalued twice,
             | where the reversal and the fresh adjustment both land on the
             | account. Gross: 42,000 Dr and 1,000 Cr. Net, which is the
             | balance: 41,000 Dr.
             |
             | Both are returned, named for what they are, so a caller cannot
             | pick the wrong pair by accident.
             */
            $balance = Money::of((string) $row->balance, $functionalCurrency);

            return [
                'code' => (string) $row->code,
                'name_ar' => (string) $row->name_ar,
                'name_en' => (string) $row->name_en,
                'type' => (string) $row->type,
                'debit' => (string) $debit->amount,
                'credit' => (string) $credit->amount,
                'balance' => (string) $balance->amount,
                'net_debit' => (string) ($balance->isPositive() ? $balance->amount : Money::zero($functionalCurrency)->amount),
                'net_credit' => (string) ($balance->isNegative() ? $balance->negated()->amount : Money::zero($functionalCurrency)->amount),
            ];
        });

        return [
            'entity_id' => $entityId,
            'as_at' => $asAt->toDateString(),
            'from' => $from?->toDateString(),
            // Part of the RESULT, not just an argument: a statement that does
            // not say whether it includes period 13 cannot be compared to
            // another one.
            'includes_adjusting' => $includeAdjusting,
            /*
             | And in words, because the boolean is not a declaration - PF-051.
             |
             | It sits in an array a screen may ignore, a PDF may not print
             | and an export may drop, and the one place it matters is the
             | printed page in front of an auditor. The sentence also says
             | whether the choice made any DIFFERENCE, which it does not when
             | the range holds no adjusting entries at all.
             */
            'basis' => StatementBasis::of(
                includesAdjusting: $includeAdjusting,
                adjustingEntries: $this->adjustingEntriesIn($entityId, $asAt, $from),
                asAt: $asAt,
                from: $from,
                currency: $functionalCurrency,
                source: $source,
            )->toArray(),
            'currency' => $functionalCurrency,
            'rows' => $mapped,
            'total_debit' => (string) $totalDebit->amount,
            'total_credit' => (string) $totalCredit->amount,
            'total_net_debit' => (string) $mapped->reduce(
                static fn (Money $carry, array $row): Money => $carry->plus(
                    Money::of($row['net_debit'], $functionalCurrency),
                ),
                Money::zero($functionalCurrency),
            )->amount,
            'total_net_credit' => (string) $mapped->reduce(
                static fn (Money $carry, array $row): Money => $carry->plus(
                    Money::of($row['net_credit'], $functionalCurrency),
                ),
                Money::zero($functionalCurrency),
            )->amount,
            'balances' => $totalDebit->equals($totalCredit),
        ];
    }
}
