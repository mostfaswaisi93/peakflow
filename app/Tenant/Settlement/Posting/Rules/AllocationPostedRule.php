<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Cash claimed by a document - PF-168.
 *
 * ## Dr/Cr
 *
 *   Dr  unapplied cash, releasing what was held for the payer
 *   Cr  accounts receivable, against the party
 *
 * and the reverse for a disbursement against a bill.
 *
 * ## This is where a party balance moves, and the only place
 *
 * The receipt put money in a clearing position; this is somebody saying
 * which document it was for. Those two acts are separate because they are
 * separate facts, and keeping them separate is what makes a customer
 * ledger something you can take apart: every movement on the balance
 * names the document it belongs to.
 *
 * ## The party is on both the clearing line and the receivable line
 *
 * On receivables because that is what makes the subledger agree with the
 * control account. On unapplied cash because "whose money is still
 * unallocated" is the question the ageing report answers - and an
 * allocation that released the clearing position without naming the party
 * would make that report drift from the first partial allocation onwards.
 */
final class AllocationPostedRule implements PostingRule
{
    public const KEY = 'settlement.allocation_posted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'تخصيص مقبوضات على مستند',
            'A payment allocated to a document',
            [
                ['Dr', ControlRole::UnappliedCash, 'releasing what was held for the payer'],
                ['Cr', ControlRole::AccountsReceivable, 'the part of the document this payment settles, against the party'],
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
        $narration = (string) ($event['narration'] ?? 'Allocation');
        $partyId = isset($event['party_id']) ? (string) $event['party_id'] : null;

        /*
         | A bill settles the payable side, not the receivable one.
         |
         | Same shape, other control account. Writing two rules for it
         | would duplicate the clearing line and the party handling, and
         | the pair would drift the first time one of them changed.
         */
        $control = ($event['payable'] ?? false) === true
            ? ControlRole::AccountsPayable
            : ControlRole::AccountsReceivable;

        return [
            PostingLine::toRole(
                ControlRole::UnappliedCash,
                $amount,
                partyId: $partyId,
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
