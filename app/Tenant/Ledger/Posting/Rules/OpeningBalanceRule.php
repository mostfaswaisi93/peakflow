<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Opening balances - owned by `Ledger`, because nothing else owns them.
 *
 * ## Dr/Cr
 *
 *   Dr  each account with a debit opening balance
 *   Cr  each account with a credit opening balance
 *   Dr/Cr  retained earnings, for whatever the two sides do not cover
 *
 * ## The plug goes to retained earnings, and that is not a shortcut
 *
 * An opening balance set that does not balance means the figures came from a
 * trial balance that did not balance - which happens, usually because the
 * previous system was not double-entry or because someone transcribed a
 * subtotal. The difference has to land somewhere, and retained earnings is
 * where a prior-period difference belongs under IAS 8.
 *
 * Putting it in suspense instead would be tidier and worse: suspense is for
 * items awaiting identification, and this one is not awaiting anything. It is
 * a known difference in a known place, and naming it that way is what lets
 * someone decide whether to investigate it.
 *
 * The amount of the plug is returned so the caller can show it, because a
 * plug nobody saw is a figure nobody questioned.
 */
final class OpeningBalanceRule implements PostingRule
{
    public const KEY = 'ledger.opening_balance';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'الأرصدة الافتتاحية',
            'Opening balances',
            [
                ['Dr', 'each account with a debit balance', 'the opening figure as given'],
                ['Cr', 'each account with a credit balance', 'the opening figure as given'],
                ['Dr/Cr', ControlRole::RetainedEarnings, 'the difference, if the given figures do not balance'],
            ],
        );
    }

    /**
     * @param  array{balances: list<array{account_id: string, amount: string}>}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $lines = [];
        $total = $context->zero();

        foreach ($event['balances'] ?? [] as $balance) {
            $amount = $context->money((string) $balance['amount']);

            if ($amount->isZero()) {
                // A zero opening balance is not a balance. Posting it would
                // violate the non-zero line constraint and tells nobody
                // anything.
                continue;
            }

            $lines[] = PostingLine::toAccount(
                accountId: (string) $balance['account_id'],
                amount: $amount,
                narration: 'Opening balance',
            );

            $total = $total->plus($amount);
        }

        if (! $total->isZero()) {
            // The plug. Signed so that it cancels the difference: a net debit
            // of 100 needs a credit of 100.
            $lines[] = PostingLine::toRole(
                ControlRole::RetainedEarnings,
                $total->negated(),
                narration: 'Opening balance difference carried to retained earnings',
            );
        }

        return $lines;
    }
}
