<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What moved each equity account, and what caused it - PF-187.
 *
 * ## Why the cause cannot be read off the account
 *
 * A credit to retained earnings is a profit if the year-end close made it
 * and a distribution if a dividend did. IAS 1.106 presents those under
 * different headings and they must never be added together, so the account
 * alone cannot answer the question - the ENTRY has to.
 *
 * `journal_entries.posting_rule` is what every posting carries, and the
 * year-end close is one rule. That is the fact this classification rests
 * on. Everything else that touches equity and is not an OCI component is a
 * transaction with owners, which is a definition this product can defend:
 * the alternative is a box on a dividend form that nobody ticks, and a
 * statement built on it would be wrong in a way only the person who
 * skipped the box could ever explain.
 *
 * ## One query, not one per component
 *
 * An entity with eight equity accounts and a statement that asked per
 * account would run eight queries that each scan the same period. The
 * grouping is free in SQL and invisible in PHP, and the difference shows
 * up at year end on the entity with the most history.
 */
final class EquityMovements
{
    /**
     * Movements in the period, grouped by kind and then by account code.
     *
     * @param  list<string>  $codes
     * @return array<string, array<string, string>>
     */
    public function byKind(
        string $entityId,
        Carbon $from,
        Carbon $until,
        array $codes,
        string $closeRule,
        bool $includeAdjusting = true,
    ): array {
        if ($codes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($codes), '?'));

        /*
         | `-sum(...)` because equity is stored as a credit.
         |
         | The statement presents a credit movement as a positive addition
         | to equity, exactly as the balance sheet presents the balance. A
         | statement that reported the ledger's sign would show a year's
         | profit as a reduction, and its closing column would not match
         | the balance sheet it has to reconcile to.
         */
        $rows = DB::connection('tenant')->select(
            <<<SQL
            SELECT a.code,
                   CASE
                       WHEN a.statement_line IN (
                           'foreign_operation_translation',
                           'cash_flow_hedge_reserve',
                           'revaluation_surplus',
                           'defined_benefit_remeasurement'
                       ) THEN 'other_comprehensive_income'
                       WHEN e.posting_rule = ? THEN 'result_for_the_period'
                       ELSE 'transactions_with_owners'
                   END AS kind,
                   (-sum(l.functional_amount))::text AS amount
              FROM journal_lines l
              JOIN journal_entries e ON e.id = l.journal_entry_id
              JOIN accounts a ON a.id = l.account_id
              JOIN periods p ON p.id = l.period_id
             WHERE l.entity_id = ?
               AND e.status = 'posted'
               AND l.accounting_date >= ?
               AND l.accounting_date <= ?
               AND a.code IN ({$placeholders})
               /*
                | Adjusting entries are in or out of BOTH sides, or neither.
                |
                | The opening and closing columns come from a trial balance
                | that honours this flag. A movement query that ignored it
                | would include period 13 while the balances excluded it, and
                | the statement would not reconcile by exactly the year-end
                | adjustments - on the one view an auditor asks for first.
                */
               AND (? OR NOT p.is_adjusting)
             GROUP BY a.code, kind
            SQL,
            [
                $closeRule,
                $entityId,
                $from->toDateString(),
                $until->toDateString(),
                ...$codes,
                $includeAdjusting,
            ],
        );

        $byKind = [];

        foreach ($rows as $row) {
            $byKind[(string) $row->kind][(string) $row->code] = (string) $row->amount;
        }

        /*
         | Every kind gets a full set of columns.
         |
         | A row whose amounts array is missing a component would render a
         | short row, and a reader scanning across would attribute the next
         | column's figure to the wrong account.
         */
        foreach ($byKind as $kind => $amounts) {
            foreach ($codes as $code) {
                $byKind[$kind][$code] ??= '0';
            }
        }

        return $byKind;
    }
}
