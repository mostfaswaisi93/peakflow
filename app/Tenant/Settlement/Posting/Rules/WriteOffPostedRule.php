<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Concluding that a debt will not be collected - PF-172.
 *
 * ## Dr/Cr
 *
 *   Dr  bad debt
 *   Cr  accounts receivable, against the party
 *
 * ## It is a credit loss, not an adjustment to revenue
 *
 * The sale happened at the invoiced figure and it is still that. What
 * changed is that the money is not coming, and that is a cost of having
 * extended credit - IFRS 9's view and the useful one. Restating revenue
 * instead would make the margin on that sale look correct while the cash
 * never arrived, and it would spread the pattern of which customers do
 * not pay across the revenue accounts where nobody can see it.
 *
 * ## The party is on the receivable line, always
 *
 * Without it the control account moves and the customer's own balance
 * does not, so the subledger and the control stop agreeing - and the
 * first symptom is a statement chasing money somebody already decided to
 * lose.
 */
final class WriteOffPostedRule implements PostingRule
{
    public const KEY = 'settlement.write_off_posted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'إعدام دين',
            'A debt written off',
            [
                ['Dr', ControlRole::BadDebt, 'the amount concluded to be uncollectable'],
                ['Cr', ControlRole::AccountsReceivable, 'the same, against the party, closing what they owed'],
            ],
        );
    }

    /**
     * @param  array{amount: string, party_id?: string|null, payable?: bool, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $amount = $context->money((string) $event['amount']);
        $narration = (string) ($event['narration'] ?? 'Written off');
        $partyId = isset($event['party_id']) ? (string) $event['party_id'] : null;

        /*
         | A bill written off relieves the payable side.
         |
         | Rarer and real: a supplier who will never invoice for a
         | goods receipt, a payable nobody can trace to a creditor. The
         | credit goes to the same bad-debt account, because the figure
         | somebody wants is "what did we conclude was not going to
         | settle" rather than which direction it faced.
         */
        $control = ($event['payable'] ?? false) === true
            ? ControlRole::AccountsPayable
            : ControlRole::AccountsReceivable;

        return [
            PostingLine::toRole(
                ControlRole::BadDebt,
                $amount,
                narration: $narration,
            ),
            PostingLine::toRole(
                $control,
                $amount->negated(),
                partyId: $partyId,
                narration: $narration,
            ),
        ];
    }
}
