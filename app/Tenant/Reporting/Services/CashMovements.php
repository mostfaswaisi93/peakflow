<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Services;

use App\Tenant\Ledger\Enums\CashFlowActivity;
use App\Tenant\Ledger\Enums\StatementLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the bank actually did, classified by what it was for - PF-186.
 *
 * ## The direct method, and why it is read this way
 *
 * IAS 7.18(a) presents major classes of gross receipts and payments. The
 * honest source for those is the cash accounts themselves: every line on
 * them is money that moved, and the question is only what it moved for.
 *
 * An entry's OTHER lines answer that. A receipt against a receivable is
 * operating; a payment against property is investing; a drawdown against a
 * loan is financing. So each cash line is classified by the activity of
 * the counterpart lines in its own entry, weighted by their share of the
 * entry - because one payment can settle a supplier invoice and buy a
 * machine in the same entry, and attributing all of it to whichever
 * counterpart happened to be first would report a machine nobody bought.
 *
 * ## Why this is independent of the indirect method
 *
 * It has to be, or the agreement between them proves nothing. The indirect
 * method reads balances from the trial balance; this reads journal lines
 * and never touches a balance. They share the ledger and no arithmetic.
 */
final class CashMovements
{
    /**
     * The movements of transactions that moved no cash at all - IAS 7.43.
     *
     * ## What counts, and why it is not "any entry without a cash line"
     *
     * An accrual - debit an expense, credit a payable - moves no cash and
     * MUST stay in the indirect method: it is precisely the difference
     * between a profit and the cash that profit produced. Excluding it
     * would break the statement rather than correct it.
     *
     * IAS 7.43 is about something narrower: an investing or financing
     * transaction settled without cash. Buying a machine on credit, a
     * revaluation, a translation difference, converting a loan to equity.
     * The shape they share is that both sides are BALANCE-SHEET accounts
     * and neither is cash - so profit is untouched and no money moved,
     * and the indirect method would otherwise report the movement as a
     * cash flow that never happened.
     *
     * ## It was found by the two methods disagreeing
     *
     * Which is the whole reason the product computes both. The first
     * version excluded only accounts whose own line is non-cash, so a
     * transaction's OTHER leg - an ordinary asset - was still counted as
     * investing, and the indirect method was short by exactly that leg.
     *
     * @return array<string, string> account code => movement, ledger sign
     */
    public function nonCashTransactions(
        string $entityId,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting = true,
    ): array {
        $adjusting = $includeAdjusting ? '' : ' AND NOT p.is_adjusting';

        $rows = DB::connection('tenant')->select(
            <<<SQL
            WITH lines AS (
                SELECT l.journal_entry_id, l.functional_amount, a.code, a.type, a.statement_line
                  FROM journal_lines l
                  JOIN journal_entries e ON e.id = l.journal_entry_id
                  JOIN periods p ON p.id = l.period_id
                  JOIN accounts a ON a.id = l.account_id
                 WHERE l.entity_id = :entity
                   AND e.status = 'posted'
                   AND l.accounting_date BETWEEN :from AND :until
                   {$adjusting}
            ),
            /*
             | An entry qualifies when NO line on it touched cash and no
             | line on it touched profit or loss. Both conditions, because
             | either one alone admits an ordinary accrual.
             */
            qualifying AS (
                SELECT journal_entry_id
                  FROM lines
                 GROUP BY journal_entry_id
                HAVING bool_and(statement_line IS DISTINCT FROM :cash_line)
                   AND bool_and(type NOT IN ('revenue', 'expense'))
            )
            SELECT l.code, sum(l.functional_amount)::text AS amount
              FROM lines l
              JOIN qualifying q ON q.journal_entry_id = l.journal_entry_id
             GROUP BY l.code
            SQL,
            [
                'entity' => $entityId,
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
                'cash_line' => 'cash_and_cash_equivalents',
            ],
        );

        $byCode = [];

        foreach ($rows as $row) {
            $byCode[(string) $row->code] = (string) $row->amount;
        }

        return $byCode;
    }

    /**
     * Cash movement in the period, totalled per counterpart activity.
     *
     * @return array<string, string>
     */
    public function byCounterpartActivity(
        string $entityId,
        Carbon $from,
        Carbon $until,
        bool $includeAdjusting = true,
    ): array {
        $cashLine = 'cash_and_cash_equivalents';

        /*
         | One statement, three parts, and the weighting in the middle.
         |
         | `cash` is every line on a cash account. `counterparts` is every
         | line in the same entries that is NOT on one, with the activity
         | its account's statement line implies. The join apportions each
         | cash line across its entry's counterparts in proportion to their
         | absolute amounts, so a mixed entry lands in two sections in the
         | ratio it actually split.
         |
         | `abs()` in the weight because a counterpart can be either sign
         | and a weight must not be: a negative weight would hand one
         | section a flow in the wrong direction and still total correctly.
         */
        $adjusting = $includeAdjusting ? '' : ' AND NOT p.is_adjusting';

        $rows = DB::connection('tenant')->select(
            <<<SQL
            WITH cash AS (
                SELECT l.journal_entry_id, sum(l.functional_amount) AS amount
                  FROM journal_lines l
                  JOIN journal_entries e ON e.id = l.journal_entry_id
                  JOIN periods p ON p.id = l.period_id
                  JOIN accounts a ON a.id = l.account_id
                 WHERE l.entity_id = :entity
                   AND e.status = 'posted'
                   AND l.accounting_date BETWEEN :from AND :until
                   AND a.statement_line = :cash_line
                   {$adjusting}
                 GROUP BY l.journal_entry_id
            ),
            counterparts AS (
                SELECT l.journal_entry_id,
                       a.statement_line,
                       sum(abs(l.functional_amount)) AS weight
                  FROM journal_lines l
                  JOIN journal_entries e ON e.id = l.journal_entry_id
                  JOIN periods p ON p.id = l.period_id
                  JOIN accounts a ON a.id = l.account_id
                 WHERE l.entity_id = :entity2
                   AND e.status = 'posted'
                   AND l.accounting_date BETWEEN :from2 AND :until2
                   AND (a.statement_line IS DISTINCT FROM :cash_line2)
                   {$adjusting}
                 GROUP BY l.journal_entry_id, a.statement_line
            ),
            totals AS (
                SELECT journal_entry_id, sum(weight) AS total
                  FROM counterparts
                 GROUP BY journal_entry_id
            )
            SELECT coalesce(c.statement_line, '') AS statement_line,
                   sum(cash.amount * c.weight / nullif(t.total, 0))::text AS amount
              FROM cash
              JOIN counterparts c ON c.journal_entry_id = cash.journal_entry_id
              JOIN totals t ON t.journal_entry_id = cash.journal_entry_id
             GROUP BY c.statement_line
            SQL,
            [
                'entity' => $entityId,
                'entity2' => $entityId,
                'from' => $from->toDateString(),
                'from2' => $from->toDateString(),
                'until' => $until->toDateString(),
                'until2' => $until->toDateString(),
                'cash_line' => $cashLine,
                'cash_line2' => $cashLine,
            ],
        );

        $byActivity = [];

        foreach ($rows as $row) {
            $line = (string) $row->statement_line;

            /*
             | An unclassified counterpart is reported, not absorbed.
             |
             | Folding it into operating would make the direct method agree
             | with the indirect one by putting the mystery somewhere
             | plausible, which is the failure that looks like success. It
             | gets its own bucket so the statement can say the figure is
             | short and by how much.
             */
            $activity = $line === ''
                ? 'unclassified'
                : (StatementLine::tryFrom($line)?->cashFlowActivity()
                    ?? CashFlowActivity::NonCash)->value;

            if ($line !== '' && StatementLine::tryFrom($line) === null) {
                $activity = 'unclassified';
            }

            /*
             | `BigDecimal`, not `bcadd`.
             |
             | The bcmath extension is a `suggest` and is not loaded in this
             | deployment, so a bcmath call is a fatal error on the one
             | page that needed it. An architecture rule says so, and this
             | is the second place it would have been found at runtime.
             */
            $byActivity[$activity] = (string) BigDecimal::of($byActivity[$activity] ?? '0')
                ->plus(BigDecimal::of((string) $row->amount))
                ->toScale(4, RoundingMode::HalfUp);
        }

        return $byActivity;
    }
}
