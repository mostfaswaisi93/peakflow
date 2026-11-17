<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Stock that is not what the ledger said - PF-248, PF-253.
 *
 * ## Dr/Cr
 *
 *   Dr  cost of sales   (a shortfall: stock that is gone)
 *   Cr  inventory
 *
 * and the other way for a surplus.
 *
 * ## Why the loss goes to cost of sales and not to its own account
 *
 * It is a cost of trading the goods, and IAS 2 treats a write-down of
 * inventory as an expense of the period. A separate "stock loss" account
 * is tempting and is how the figure gets managed: a line nobody includes
 * in gross margin, growing quietly, while the margin looks correct.
 *
 * Shrinkage belongs in the cost of what was sold, because it is part of
 * what it costs this business to sell things.
 *
 * ## The reason is on the movement, not here
 *
 * Which is deliberate: the posting is the same whatever the cause, and
 * the cause - a count, a breakage, a theft, a correction - is what
 * somebody reads on the movement. Splitting the posting by reason would
 * put the analysis in the chart of accounts, where changing it means
 * changing the chart.
 */
final class StockAdjustedRule implements PostingRule
{
    public const KEY = 'catalog.stock_adjusted';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'تسوية مخزون',
            'Stock adjusted',
            [
                ['Dr', ControlRole::CostOfSales, 'a shortfall, as part of what it costs to sell things'],
                ['Cr', ControlRole::Inventory, 'the stock that is not there - or the reverse for a surplus'],
            ],
        );
    }

    /**
     * @param  array{amount: string, narration?: string}  $event
     * @return list<PostingLine>
     */
    public function lines(array $event, PostingContext $context): array
    {
        /*
         | Signed by the caller.
         |
         | A positive amount is a shortfall - stock that is gone, so a
         | cost. A negative one is a surplus. One rule rather than two,
         | because a count produces either and a reader of the ledger
         | needs them in the same place to see that they net out.
         */
        $amount = $context->money((string) $event['amount']);
        $narration = (string) ($event['narration'] ?? 'Stock adjusted');

        return [
            PostingLine::toRole(ControlRole::CostOfSales, $amount, narration: $narration),
            PostingLine::toRole(ControlRole::Inventory, $amount->negated(), narration: $narration),
        ];
    }
}
