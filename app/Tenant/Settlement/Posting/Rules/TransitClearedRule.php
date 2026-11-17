<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * The bank says the money left - PF-179.
 *
 * ## Dr/Cr
 *
 *   Dr  payments in transit
 *   Cr  bank
 *
 * ## This is the posting the statement line authorises
 *
 * Not the release. The release said what we intended; this says what the
 * bank did, and the evidence for it is a line on a statement the bank
 * produced. Posting cash on anything weaker makes the ledger's bank
 * balance a record of our intentions.
 *
 * Which is also why it closes the in-transit position rather than
 * reducing AP: AP was closed on release, and reducing it twice would
 * leave a creditor in credit for an amount nobody can trace.
 */
final class TransitClearedRule implements PostingRule
{
    public const KEY = 'settlement.transit_cleared';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'تسوية مبالغ في الطريق',
            'An in-transit payment cleared the bank',
            [
                ['Dr', ControlRole::PaymentsInTransit, 'closing what the bank had not yet sent'],
                ['Cr', ControlRole::Bank, 'the cash that actually left, on the statement line that says so'],
            ],
        );
    }

    /**
     * @param  array{amount: string, bank_account_id?: string|null, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $amount = $context->money((string) $event['amount']);
        $narration = (string) ($event['narration'] ?? 'Cleared the bank');

        return [
            PostingLine::toRole(
                ControlRole::PaymentsInTransit,
                $amount,
                narration: $narration,
            ),
            PostingLine::toRole(
                ControlRole::Bank,
                $amount->negated(),
                narration: $narration,
            ),
        ];
    }
}
