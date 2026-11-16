<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Posting\Rules;

use App\Tenant\Banking\Enums\DifferenceType;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * The shape every difference posting shares - PF-164.
 *
 * ## Why there are six rules and not one with a parameter
 *
 * PF-164 asks for a posting rule per type, and the reason is not symmetry.
 * A posting rule is the thing a person reads to answer "what does a short
 * payment do to my accounts", and that question has six different answers.
 * One rule with a lookup table inside it answers none of them: the registry
 * would list a single entry called "difference", and the Dr/Cr somebody
 * needed would be a branch in a method.
 *
 * What they do share is the mechanics - two lines, the receivable relieved
 * and the difference taken to its own account - so that lives here and each
 * rule says which account and why.
 *
 * ## Dr/Cr, in general
 *
 *   Dr  the difference account (a cost, or a tax credit, or the customer)
 *   Cr  whatever the difference came out of
 *
 * and the reverse where the difference is in our favour.
 *
 * ## The counter account is given, not assumed
 *
 * A difference arises in two places and they are not the same entry.
 *
 * A **bank charge on the statement** is the whole transaction: the bank
 * took 25 and there is no document. The counter account is the bank's own
 * GL account, and the entry is complete on its own.
 *
 * A **short payment against an invoice** arises when a receipt is allocated,
 * and the counter account is accounts receivable with the party on the line.
 * Hard-coding that here would make the first case post a receivable
 * movement for a fee the bank charged us, which relieves a customer balance
 * that nothing was ever owed against.
 *
 * The party travels with the line whenever there is one. A difference
 * posted against receivables without it moves the control account and
 * leaves the customer's own balance at the full invoice, so the subledger
 * and the control stop agreeing - and the first symptom is a statement
 * chasing money somebody already settled.
 */
abstract class DifferenceRule implements PostingRule
{
    abstract public function type(): DifferenceType;

    /** What this difference means, for the rule registry. */
    abstract protected function because(): string;

    public function key(): string
    {
        return $this->type()->postingRule();
    }

    public function describe(): RuleDescription
    {
        $type = $this->type();

        return RuleDescription::of(
            $this->key(),
            sprintf('فرق تسوية: %s', $type->labelAr()),
            sprintf('Reconciliation difference: %s', $type->labelEn()),
            [
                ['Dr', $type->account(), $this->because()],
                ['Cr', 'whatever the difference came out of', 'the bank account for a charge on the statement, or receivables for a payment that fell short'],
            ],
        );
    }

    /**
     * @param  array{amount: string, counter_account_id: string, party_id?: string|null, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $type = $this->type();

        /*
         | `amount` is the difference, signed from our side.
         |
         | Positive where it costs us - a fee, a shortfall - and negative
         | where it is in our favour. One convention for all six, so a
         | rule cannot be right about the figure and wrong about the
         | direction.
         */
        $amount = $context->money((string) $event['amount']);
        $narration = (string) ($event['narration'] ?? sprintf('Reconciliation difference: %s', $type->labelEn()));
        $partyId = isset($event['party_id']) ? (string) $event['party_id'] : null;

        return [
            PostingLine::toRole(
                $type->account(),
                $amount,
                partyId: $this->carriesAParty($type) ? $partyId : null,
                narration: $narration,
            ),
            PostingLine::toAccount(
                accountId: (string) $event['counter_account_id'],
                amount: $amount->negated(),
                partyId: $partyId,
                narration: $narration,
            ),
        ];
    }

    /**
     * Whether the difference account itself is a party account.
     *
     * Only receivables is. Putting a party on a bank-charges line would
     * attribute a cost of banking to a customer, and the expense report by
     * customer that somebody eventually builds would be wrong in a way that
     * reads as insight.
     */
    private function carriesAParty(DifferenceType $type): bool
    {
        return $type->account() === \App\Tenant\Ledger\Enums\ControlRole::AccountsReceivable;
    }
}
