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
 * The borrowing side of an inter-entity transaction - PF-057.
 *
 * ## Dr/Cr
 *
 *   Dr  whatever this entity received - an expense, an asset
 *   Cr  inter-entity (due to the other entity)
 *
 * The mirror of {@see InterEntityDueFromRule}, and deliberately a separate
 * rule rather than the same one with a sign flipped: the two sides are posted
 * in different entities, at different rates, against different charts, and a
 * single rule parameterised by direction would have to reason about which
 * entity it is in. A reserved journal names ONE rule, too, so two sides that
 * share a rule key could not be told apart by the journal they landed in.
 */
final class InterEntityDueToRule implements PostingRule
{
    public const KEY = 'ledger.inter_entity_due_to';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'الجانب المدين في معاملة بين كيانين - ما علينا للكيان الآخر',
            'The borrowing side of an inter-entity transaction',
            [
                ['Dr', 'the named account', 'what this entity received'],
                ['Cr', 'inter_entity', 'what is now owed to the other entity, in this entity\'s currency'],
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

        if ($settlement->currency === $context->functionalCurrency) {
            $payable = PostingLine::toRoleFor(
                ControlRole::InterEntity,
                $settlement->negated(),
                $transactionId,
                $narration,
            );

            $functional = $settlement;
        } else {
            if (! $rate instanceof Rate) {
                throw InterEntityRuleNeedsARate::for($settlement->currency, $context->functionalCurrency);
            }

            $payable = PostingLine::translatedToRole(
                role: ControlRole::InterEntity,
                original: $settlement->negated(),
                rate: $rate,
                policy: RoundingPolicy::halfUp(Scale::Amount),
                narration: $narration,
                interEntityTransactionId: $transactionId,
            );

            $functional = $payable->amount->negated();
        }

        return [
            PostingLine::toAccount(
                accountId: (string) $event['counter_account_id'],
                amount: $functional,
                narration: $narration,
            ),
            $payable,
        ];
    }
}
