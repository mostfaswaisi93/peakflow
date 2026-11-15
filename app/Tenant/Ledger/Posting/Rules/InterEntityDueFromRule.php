<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Posting\Rules;

use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * The lending side of an inter-entity transaction - PF-057.
 *
 * ## Dr/Cr
 *
 *   Dr  inter-entity (due from the other entity)
 *   Cr  whatever this entity actually gave up - cash, an expense it paid
 *
 * ## It is one half of a pair and knows nothing about the other
 *
 * Which is the point. Each side balances in its OWN functional currency,
 * against its own chart, in its own period - so neither rule has to reason
 * about the other entity's books, and the two entries are two ordinary
 * entries that happen to share a transaction id.
 *
 * What makes them a pair is the row in `inter_entity_transactions`: one
 * settlement amount, in one currency, which both sides translate from. The
 * elimination in consolidation is then arithmetic on that single figure
 * rather than a reconciliation of two.
 */
final class InterEntityDueFromRule implements PostingRule
{
    public const KEY = 'ledger.inter_entity_due_from';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'الجانب الدائن في معاملة بين كيانين - ما لنا على الكيان الآخر',
            'The lending side of an inter-entity transaction',
            [
                ['Dr', 'inter_entity', 'what the other entity now owes, in this entity\'s currency'],
                ['Cr', 'the named account', 'what this entity gave up'],
            ],
        );
    }

    /**
     * @param  array{
     *     transaction_id: string,
     *     settlement_amount: string,
     *     settlement_currency: string,
     *     counter_account_id: string,
     *     rate?: Rate|null,
     *     narration?: string|null,
     * }  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $transactionId = (string) $event['transaction_id'];
        $settlement = Money::of((string) $event['settlement_amount'], (string) $event['settlement_currency']);
        $rate = $event['rate'] ?? null;
        $narration = $event['narration'] ?? null;

        /*
         | Translated, or not - and never translated at a rate of 1.
         |
         | When the settlement currency IS this entity's functional currency
         | there is no translation, and recording one at a rate of 1 would
         | assert that a translation happened and came out even. The database
         | refuses both halves of that mistake, and so does this.
         */
        if ($settlement->currency === $context->functionalCurrency) {
            $receivable = PostingLine::toRoleFor(
                ControlRole::InterEntity,
                $settlement,
                $transactionId,
                $narration,
            );

            $functional = $settlement;
        } else {
            if (! $rate instanceof Rate) {
                throw InterEntityRuleNeedsARate::for($settlement->currency, $context->functionalCurrency);
            }

            $receivable = PostingLine::translatedToRole(
                role: ControlRole::InterEntity,
                original: $settlement,
                rate: $rate,
                policy: RoundingPolicy::halfUp(Scale::Amount),
                narration: $narration,
                interEntityTransactionId: $transactionId,
            );

            $functional = $receivable->amount;
        }

        return [
            $receivable,
            /*
             | The counter line is in the FUNCTIONAL amount, not the
             | settlement one.
             |
             | Because the entry has to balance in this entity's functional
             | currency, and the receivable's functional amount is what the
             | translation produced. Using the settlement figure here would
             | leave the entry unbalanced by the whole translation.
             */
            PostingLine::toAccount(
                accountId: (string) $event['counter_account_id'],
                amount: $functional->negated(),
                narration: $narration,
            ),
        ];
    }
}
