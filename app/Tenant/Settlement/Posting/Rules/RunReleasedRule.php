<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * A released payment run leaves accounts payable - PF-179.
 *
 * ## Dr/Cr
 *
 *   Dr  accounts payable, against the party
 *   Cr  payments in transit
 *
 * ## Why the money is not gone yet
 *
 * The instruction has been sent and the supplier has not been paid. Those
 * are different facts, and the gap between them is days: a file submitted
 * on Thursday clears on Monday, a cross-border transfer takes a week, and
 * a rejected instruction never clears at all.
 *
 * Crediting the bank account on release would report cash the business
 * still has as cash it has spent - so every balance sheet between the
 * release and the statement is wrong, and the bank reconciliation has a
 * difference nobody can name because the ledger already thinks the
 * payment happened.
 *
 * ## And why it leaves AP immediately
 *
 * The payable is no longer outstanding in any useful sense: nobody should
 * select it into another run, chase it, or include it in a creditors
 * report as something still to be decided. It has been decided. What is
 * unresolved is whether the bank executed it, which is a different
 * question and lives in a different account.
 *
 * That account is what makes the three-way tie possible. AP is what we
 * owe, the bank is what the bank says we have, and in transit is the
 * difference we can explain. Without it the difference is a figure
 * somebody reconstructs from release dates every month.
 */
final class RunReleasedRule implements PostingRule
{
    public const KEY = 'settlement.run_released';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'إطلاق دفعة سداد',
            'A payment run released',
            [
                ['Dr', ControlRole::AccountsPayable, 'what the beneficiary was owed, against the party, closing it'],
                ['Cr', ControlRole::PaymentsInTransit, 'the same, held until the bank says the money left'],
            ],
        );
    }

    /**
     * @param  array{amount: string, party_id?: string|null, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $amount = $context->money((string) $event['amount']);
        $narration = (string) ($event['narration'] ?? 'Released for payment');

        return [
            PostingLine::toRole(
                ControlRole::AccountsPayable,
                $amount,
                partyId: isset($event['party_id']) ? (string) $event['party_id'] : null,
                narration: $narration,
            ),
            /*
             | No party on the in-transit line.
             |
             | It is a position against the BANK, not against the
             | supplier: the question it answers is "what have we told the
             | bank to send that it has not sent", and attributing that to
             | a counterparty invites somebody to read it as still owing
             | them - which is the thing the debit above just settled.
             */
            PostingLine::toRole(
                ControlRole::PaymentsInTransit,
                $amount->negated(),
                narration: $narration,
            ),
        ];
    }
}
