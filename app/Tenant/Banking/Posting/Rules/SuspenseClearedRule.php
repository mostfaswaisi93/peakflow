<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Suspense, reclassified to where the money belongs - PF-163.
 *
 * ## Dr/Cr
 *
 *   Dr  suspense, cancelling what was parked
 *   Cr  the account it turned out to belong to
 *
 * and the other way round for an item parked on the other side.
 *
 * ## A reclassification, never an edit
 *
 * The parked entry stays exactly as it was posted. That is the whole design
 * of this rule and it costs something: two entries instead of one, and a
 * ledger where the same money appears three times. What it buys is that the
 * original posting - its date, its narration, the fact that on the 14th
 * nobody knew what this was - survives discovering the answer.
 *
 * Editing the original would be tidier and would destroy the one thing
 * suspense exists to record. An auditor asking "how long was this
 * unexplained" has a question with no answer in a ledger that rewrites its
 * own history, and "how long was it unexplained" is the question suspense is
 * reported by.
 *
 * ## The amount is the parked amount, not a new one
 *
 * Clearing part of a parked item is clearing part of it, and the rest stays
 * parked and keeps its original age. Passing a different figure would leave
 * a remainder in suspense that no longer corresponds to anything that was
 * ever parked, which is how a suspense balance stops being a list of items
 * and becomes a number nobody can take apart.
 */
final class SuspenseClearedRule implements PostingRule
{
    public const KEY = 'banking.suspense_cleared';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'تصفية الحساب المعلّق بإعادة تصنيف',
            'Suspense cleared by reclassification',
            [
                ['Dr', ControlRole::Suspense, 'cancelling what was parked'],
                ['Cr', 'the account it belongs to', 'the same amount'],
            ],
        );
    }

    /**
     * @param  array{account_id: string, amount: string, party_id?: string|null, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        /*
         | `amount` is the bank movement as it was parked.
         |
         | Suspense took the opposite sign when it was parked, so cancelling
         | it means posting the movement's own sign to suspense now - and
         | the destination account takes the opposite, which is where the
         | money was going all along.
         */
        $amount = $context->money((string) $event['amount']);

        $narration = (string) ($event['narration'] ?? 'Suspense cleared');

        return [
            PostingLine::toRole(
                ControlRole::Suspense,
                $amount,
                narration: $narration,
            ),
            PostingLine::toAccount(
                accountId: (string) $event['account_id'],
                amount: $amount->negated(),
                partyId: isset($event['party_id']) ? (string) $event['party_id'] : null,
                narration: $narration,
            ),
        ];
    }
}
