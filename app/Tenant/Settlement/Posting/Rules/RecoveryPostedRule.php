<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Money that arrived after it had been written off - PF-172.
 *
 * ## Dr/Cr
 *
 *   Dr  unapplied cash, releasing what the receipt put there
 *   Cr  bad debt recovered
 *
 * ## A recovery is not an un-write-off
 *
 * This is the whole reason the rule exists. Reversing the write-off would
 * say the conclusion had been wrong, and it was not: on the day it was
 * made, with what was known then, it was right. A customer who pays
 * eighteen months after liquidation proceedings closed has not made the
 * earlier decision a mistake.
 *
 * It also destroys the only question that improves those decisions. "We
 * wrote off 400,000 and recovered 30,000" is a sentence about how good
 * the write-offs were; netted into one account it is a sentence about
 * nothing, and nobody can tell a business that writes off too eagerly
 * from one that writes off too late.
 *
 * ## Which is why the receivable is not touched either
 *
 * The document was closed by the write-off. Reopening it to settle it
 * again would make the same invoice appear twice in every report that
 * counts what was collected, and the customer would have two balances
 * where they have none.
 */
final class RecoveryPostedRule implements PostingRule
{
    public const KEY = 'settlement.recovery_posted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'تحصيل دين معدوم',
            'A written-off debt recovered',
            [
                ['Dr', ControlRole::UnappliedCash, 'releasing what the receipt put there'],
                ['Cr', ControlRole::BadDebtRecovery, 'income of its own, never a credit back to bad debt'],
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
        $narration = (string) ($event['narration'] ?? 'Bad debt recovered');
        $partyId = isset($event['party_id']) ? (string) $event['party_id'] : null;

        return [
            PostingLine::toRole(
                ControlRole::UnappliedCash,
                $amount,
                partyId: $partyId,
                narration: $narration,
            ),
            PostingLine::toRole(
                ControlRole::BadDebtRecovery,
                $amount->negated(),
                narration: $narration,
            ),
        ];
    }
}
