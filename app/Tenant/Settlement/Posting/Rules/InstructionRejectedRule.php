<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * The bank refused one instruction - PF-178.
 *
 * ## Dr/Cr
 *
 *   Dr  payments in transit
 *   Cr  accounts payable, against the party
 *
 * ## The payable comes back, because it is owed again
 *
 * The exact reverse of the release, and that is the point: the supplier
 * was never paid, so they are still a creditor and the payable has to be
 * selectable into another run. A product that marked the instruction
 * failed without returning the payable leaves a supplier who is owed
 * money the system no longer thinks is outstanding - and the next person
 * to find out is the supplier.
 *
 * ## Why not simply reverse the release entry
 *
 * Because a batch is usually only partly refused. Reversing the release
 * would unwind the instructions the bank accepted as well, and they are
 * going to clear. So the rejection posts per instruction, for the
 * instruction's own amount, and the accepted ones are left alone.
 */
final class InstructionRejectedRule implements PostingRule
{
    public const KEY = 'settlement.instruction_rejected';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'رفض تعليمة دفع',
            'An instruction the bank refused',
            [
                ['Dr', ControlRole::PaymentsInTransit, 'closing what will not now be sent'],
                ['Cr', ControlRole::AccountsPayable, 'the payable, against the party, owed again because they were never paid'],
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
        $narration = (string) ($event['narration'] ?? 'Rejected by the bank');

        return [
            PostingLine::toRole(
                ControlRole::PaymentsInTransit,
                $amount,
                narration: $narration,
            ),
            PostingLine::toRole(
                ControlRole::AccountsPayable,
                $amount->negated(),
                partyId: isset($event['party_id']) ? (string) $event['party_id'] : null,
                narration: $narration,
            ),
        ];
    }
}
