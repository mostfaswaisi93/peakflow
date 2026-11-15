<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Posting\Rules;

use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Zeroing the year's revenue and expenses - PF-052, IAS 1.
 *
 * ## Dr/Cr
 *
 *   Dr  every revenue account, by its credit balance
 *   Cr  every expense account, by its debit balance
 *   Dr/Cr  retained earnings, by the net
 *
 * ## An ordinary journal entry, in a reserved journal
 *
 * Not a flag on a row, and not a computed view. Three reasons, in order of
 * how often they matter:
 *
 * **It is drillable.** "Why is retained earnings 4.2 million" is answered by
 * opening the entry and reading the lines, the same way as any other figure.
 * A close implemented as a flag has no lines to read.
 *
 * **It is reversible.** A close run against a year with a misposted accrual
 * has to come back out, and reversing a journal entry is a mechanism that
 * already exists and is already audited. Un-setting a flag is not.
 *
 * **It is visible.** It appears in the journal, in the audit log and in the
 * trial balance's own movements, so nobody has to know that closing is a
 * special case in order to see that it happened.
 *
 * ## The reserved journal matters
 *
 * `CLS` accepts postings only from this rule. An opening balance typed by
 * hand six months into the year is indistinguishable from the real one, and
 * is found a year later; the same is true of a closing entry.
 *
 * ## Why the lines come in the event rather than being read here
 *
 * The rule computes nothing it could get wrong about which accounts exist.
 * `YearEndClose` reads the balances - one query, as at the year end,
 * excluding the close's own entries - and hands them over. The rule turns
 * them into lines and the poster checks they balance, which is the same
 * division as every other rule in the system.
 */
final class YearEndCloseRule implements PostingRule
{
    public const KEY = 'ledger.year_end_close';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'إقفال السنة المالية',
            'Year-end close',
            [
                ['Dr', 'every revenue account', 'its credit balance, bringing it to zero'],
                ['Cr', 'every expense account', 'its debit balance, bringing it to zero'],
                ['Dr/Cr', ControlRole::RetainedEarnings, "the net - the year's profit or loss"],
            ],
        );
    }

    /**
     * @param  array{balances: list<array{account_id: string, balance: string, dimensions?: array<string, string|null>}>}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $lines = [];
        $net = $context->zero();

        foreach ($event['balances'] as $balance) {
            $amount = Money::of($balance['balance'], $context->functionalCurrency);

            /*
             | An account with no balance produces no line.
             |
             | Not a line of zero: `journal_lines` refuses one, and a chart
             | with two hundred accounts of which forty moved would
             | otherwise produce an entry nobody reads.
             */
            if ($amount->isZero()) {
                continue;
            }

            /*
             | NEGATED, and this is the whole arithmetic.
             |
             | A revenue account carries a credit balance, which is negative
             | in the signed convention. Zeroing it means posting the
             | opposite, so the line is the negation of the balance - and
             | the sum of all of them is the year's result, which goes to
             | retained earnings with the opposite sign again.
             */
            /*
             | The dimensions come through from the balance.
             |
             | An account that requires a cost centre refuses a line without
             | one, including this one - and that is the right answer rather
             | than an obstacle: an expense account closed without its cost
             | centre leaves that cost centre's profit never zeroed, so the
             | next year's dimensional P&L opens with last year's figures
             | still in it.
             */
            $dimensions = [];

            foreach ($balance['dimensions'] ?? [] as $column => $value) {
                if ($value !== null) {
                    $dimensions[(int) str_replace('dimension_', '', $column)] = $value;
                }
            }

            $lines[] = PostingLine::toAccount(
                accountId: $balance['account_id'],
                amount: $amount->negated(),
                narration: 'Year-end close (IAS 1)',
                dimensions: $dimensions,
            );

            $net = $net->plus($amount->negated());
        }

        if ($lines === []) {
            // A year with no revenue and no expenses. The caller records a
            // close with no entry rather than posting an empty one, which
            // the poster refuses and should.
            return [];
        }

        $lines[] = PostingLine::toRole(
            ControlRole::RetainedEarnings,
            $net->negated(),
            narration: "The year's profit or loss, carried to retained earnings",
        );

        return $lines;
    }
}
