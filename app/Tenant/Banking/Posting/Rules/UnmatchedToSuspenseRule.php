<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * An unexplained bank line, parked - PF-163.
 *
 * ## Dr/Cr
 *
 *   Dr  the bank account's GL account, for money in
 *   Cr  suspense, for the same
 *
 * and the other way round for money out.
 *
 * ## Why an unexplained item is posted at all
 *
 * The instinct is to leave it until somebody works out what it is. That is
 * the wrong instinct, and the reason is the bank reconciliation: a line the
 * bank has and the ledger does not is an **unrecorded bank item**, and while
 * it sits there the bank account does not reconcile. Every statement after
 * it carries the same unexplained difference, and the person reconciling
 * next month has to re-derive the same conclusion about the same item.
 *
 * Posting it makes the bank account agree with the bank, which is a fact,
 * and moves the uncertainty to a control account named for exactly that -
 * which is what suspense is for. The item is not less unexplained; it is
 * unexplained **in one place**, where it can be aged and reported and where
 * a period close can refuse to pass over it.
 *
 * ## What this rule is not
 *
 * It is not a write-off and must never be used as one. The money is still
 * somebody's: clearing it is a reclassification to wherever it belongs, and
 * a difference somebody has decided to absorb is PF-164's rule with PF-164's
 * permission. The distinction matters because the two look identical on a
 * bank reconciliation and are opposite in accounting terms - one is "we do
 * not know yet" and the other is "we have decided".
 */
final class UnmatchedToSuspenseRule implements PostingRule
{
    public const KEY = 'banking.unmatched_to_suspense';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'حركة بنكية غير مفسّرة إلى الحساب المعلّق',
            'An unexplained bank line, parked in suspense',
            [
                ['Dr', 'the bank account\'s GL account', 'the amount received'],
                ['Cr', ControlRole::Suspense, 'the same, until somebody says what it is'],
            ],
        );
    }

    /**
     * @param  array{gl_account_id: string, amount: string, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $amount = $context->money((string) $event['amount']);

        $narration = (string) ($event['narration'] ?? 'Unexplained bank line');

        return [
            PostingLine::toAccount(
                accountId: (string) $event['gl_account_id'],
                amount: $amount,
                narration: $narration,
            ),
            /*
             | The opposite sign, so the entry balances by construction.
             |
             | Not by a subtraction somewhere that could be wrong: there
             | are two lines and the second is the negation of the first,
             | which is the only form of this entry that cannot be out.
             */
            PostingLine::toRole(
                ControlRole::Suspense,
                $amount->negated(),
                narration: $narration,
            ),
        ];
    }
}
