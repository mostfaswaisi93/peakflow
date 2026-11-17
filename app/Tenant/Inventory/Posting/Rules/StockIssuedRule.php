<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Stock leaving, and what it cost - PF-248.
 *
 * ## Dr/Cr
 *
 *   Dr  cost of sales
 *   Cr  inventory
 *
 * ## At the cost the stock had, not the cost it has
 *
 * The figure comes from the movements before this one and is frozen on
 * the line. Recomputing it later would make the margin on a sale depend
 * on when somebody asked - and a reversal would reverse a different
 * number from the one it posted, which leaves the inventory account
 * holding the difference for ever.
 *
 * ## Why this is not a reduction of revenue
 *
 * The sale happened at the invoiced figure and it is still that. What it
 * cost to fulfil is a different fact, and netting the two reports a
 * smaller sale rather than a cost - which hides the pattern of which
 * products make money in the revenue accounts where nobody looks for it.
 */
final class StockIssuedRule implements PostingRule
{
    public const KEY = 'catalog.stock_issued';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'صرف مخزون',
            'Stock issued',
            [
                ['Dr', ControlRole::CostOfSales, 'what the stock cost when it left, from the movements before it'],
                ['Cr', ControlRole::Inventory, 'the same, reducing the asset'],
            ],
        );
    }

    /**
     * @param  array{amount: string, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        $amount = $context->money((string) $event['amount']);
        $narration = (string) ($event['narration'] ?? 'Stock issued');

        return [
            PostingLine::toRole(ControlRole::CostOfSales, $amount, narration: $narration),
            /*
             | No party on either line.
             |
             | Stock is not owed to or by anybody: it is a thing the
             | business holds. Attributing it to the customer who bought
             | it would put a balance on their account that nobody owes.
             */
            PostingLine::toRole(ControlRole::Inventory, $amount->negated(), narration: $narration),
        ];
    }
}
