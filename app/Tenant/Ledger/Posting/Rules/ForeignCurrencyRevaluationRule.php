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
 * Restating monetary items at the closing rate - PF-070, IAS 21.
 *
 * ## Dr/Cr
 *
 *   Dr/Cr  each monetary account, by the difference between its restated
 *          functional value and the functional value already booked
 *   Dr/Cr  FX revaluation, for the total - the other side of every one
 *
 * A gain on a receivable debits the receivable and credits revaluation; a
 * loss does the reverse. Both directions arise in one run, because a tenant
 * holding both foreign receivables and foreign payables moves opposite ways
 * on the same rate change.
 *
 * ## The lines are in the FUNCTIONAL currency and carry no rate
 *
 * This is the part that looks wrong and is not.
 *
 * A revaluation line is not a transaction in euro. The euro exposure has not
 * changed - the customer still owes exactly EUR 4,000. What changed is what
 * those euro are worth in riyal, and that difference is a riyal amount with
 * no euro counterpart at all.
 *
 * Posting it as a EUR line with a rate would be saying a further EUR amount
 * arose, which would make the euro balance wrong while making the riyal
 * balance right. So the adjustment is functional-currency only, and the
 * database refuses a rate on it - see `assert_line_translation()`.
 *
 * It also means the account's foreign-currency balance and its functional
 * balance stop being related by any single rate, which is correct: the
 * account holds euro acquired at several rates and has been restated at a
 * later one.
 *
 * ## Why this rule does not look up a rate
 *
 * `Ledger` must not know what the catalog is. The rates arrive already
 * applied, in the event: whoever runs the revaluation chose the rate set and
 * the closing date, and the ledger's job is to post what it is given and
 * refuse what does not balance.
 *
 * That boundary is also what makes this rule testable without a rate feed.
 */
final class ForeignCurrencyRevaluationRule implements PostingRule
{
    public const KEY = 'ledger.fx_revaluation';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'إعادة تقييم العملات الأجنبية',
            'Foreign currency revaluation',
            [
                ['Dr/Cr', 'each monetary account held in a foreign currency', 'the unrealized difference at the closing rate'],
                ['Dr/Cr', ControlRole::FxRevaluation, 'the total, as the other side'],
            ],
        );
    }

    /**
     * @param  array{
     *     adjustments: list<array{account_id: string, difference: string}>,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $lines = [];
        $total = $context->zero();

        foreach ($event['adjustments'] as $adjustment) {
            $difference = Money::of($adjustment['difference'], $context->functionalCurrency);

            /*
             | A zero difference produces no line at all.
             |
             | Not a line of zero: `journal_lines` refuses one, and rightly -
             | an account whose value did not move has nothing to say, and a
             | zero line on fifty accounts turns a revaluation entry into
             | something nobody reads.
             */
            if ($difference->isZero()) {
                continue;
            }

            $lines[] = PostingLine::toAccount(
                accountId: $adjustment['account_id'],
                amount: $difference,
                narration: 'Unrealized exchange difference at the closing rate (IAS 21)',
            );

            $total = $total->plus($difference);
        }

        if ($lines === []) {
            // Nothing moved. The caller records a run with no entry rather
            // than posting an empty one - an entry with no lines is refused
            // by the poster, and should be.
            return [];
        }

        /*
         | The other side, as ONE line.
         |
         | A line per account would be tidier to read in isolation and worse
         | in practice: the revaluation account's own history becomes fifty
         | entries per close where one belongs, and the figure that goes to
         | profit or loss is the total.
         */
        $lines[] = PostingLine::toRole(
            ControlRole::FxRevaluation,
            $total->negated(),
            narration: 'Total unrealized exchange difference for the period',
        );

        return $lines;
    }
}
