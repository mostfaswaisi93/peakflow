<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Support\Money\Money;
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
    ): array {
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
}
