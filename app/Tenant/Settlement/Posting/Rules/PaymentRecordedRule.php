<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Money arrived, or money left - PF-166.
 *
 * ## Dr/Cr
 *
 *   Dr  the bank or cash account, for a receipt
 *   Cr  unapplied cash, for the same
 *
 * and the reverse for a disbursement.
 *
 * ## Why neither line touches the party
 *
 * This is the decision the whole settlement module rests on. "They sent
 * 10,000" and "10,000 of their invoices are settled" are different facts,
 * and they become the same fact only when somebody says which invoices.
 *
 * A rule that credited receivables here would be guessing at that, and the
 * cost is not a wrong total - the total would be right. The cost is a
 * customer balance that cannot be taken apart: 4,000 outstanding that is
 * three invoices and a receipt, with nothing saying which invoice the
 * receipt was against. Every question about it afterwards is a
 * reconstruction, and the reconstruction is what people do in
 * spreadsheets.
 *
 * ## Unapplied cash is a liability, and that is not a holding pen
 *
 * It is what we owe the payer until a document claims it. Posting it
 * anywhere else - to revenue, to receivables - makes an unallocated
 * receipt look like performance or like a settlement, and both are
 * figures somebody eventually restates.
 */
final class PaymentRecordedRule implements PostingRule
{
    public const KEY = 'settlement.payment_recorded';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'تسجيل مقبوضات أو مدفوعات',
            'A receipt or a disbursement',
            [
                ['Dr', 'the bank or cash account named on the payment', 'the amount received, or the amount paid as a credit'],
                ['Cr', ControlRole::UnappliedCash, 'the same, held for the payer until a document claims it'],
            ],
        );
    }

    /**
     * @param  array{cash_account_id: string, amount: string, party_id?: string|null, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        /*
         | `amount` is signed as the cash moved.
         |
         | Positive into the account for a receipt, negative out of it for
         | a disbursement. The service applies the direction, so this rule
         | has one form and cannot be right about the figure and wrong
         | about the way it went.
         */
        $amount = $context->money((string) $event['amount']);
        $narration = (string) ($event['narration'] ?? 'Payment');
        $partyId = isset($event['party_id']) ? (string) $event['party_id'] : null;

        return [
            PostingLine::toAccount(
                accountId: (string) $event['cash_account_id'],
                amount: $amount,
                narration: $narration,
            ),
            /*
             | The party travels on the clearing line, not on the cash one.
             |
             | Which is what makes "whose unallocated money is this" a
             | query rather than an investigation - and old unallocated
             | cash is almost always a payer nobody identified, so the
             | party is the one column that makes the ageing report
             | useful.
             */
            PostingLine::toRole(
                ControlRole::UnappliedCash,
                $amount->negated(),
                partyId: $partyId,
                narration: $narration,
            ),
        ];
    }
}
