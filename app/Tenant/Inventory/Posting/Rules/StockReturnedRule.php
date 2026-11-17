<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Goods a customer sent back - PF-252.
 *
 * ## Dr/Cr
 *
 *   Dr  inventory      (the goods are ours again, at what the sale took out)
 *   Cr  cost of sales  (the same, because the sale's cost was overstated)
 *
 * ## Why this is not {@see StockReceivedRule}
 *
 * The first draft of PF-252 restored the stock with a receipt, which
 * credits goods-received-not-invoiced. That account holds one thing: the
 * gap between goods arriving from a supplier and the supplier's bill
 * arriving. A sales return has no supplier behind it and no bill coming,
 * so the credit would sit in a liability that nobody can clear - the
 * three-way match has nothing to match it against - while cost of sales
 * stayed overstated by the same figure.
 *
 * Both halves of that are wrong and neither shows up in a stock report:
 * the quantity and the inventory value are correct either way, which is
 * what the inventory reconciliation checks. It was found by reading the
 * rule the service was calling.
 *
 * ## Why this is not {@see StockAdjustedRule} either
 *
 * The two post the same shape, which is exactly why they are separate. An
 * adjustment is stock that is not what the ledger said, with a reason
 * somebody typed. A return is a document with a customer, an invoice and
 * a credit note behind it. Collapsing them would make "how much came
 * back last month" unanswerable without reading reasons, and would put
 * returns into the shrinkage figure - where a rising returns rate would
 * look like theft.
 */
final class StockReturnedRule implements PostingRule
{
    public const KEY = 'inventory.stock_returned';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'مرتجع مبيعات إلى المخزون',
            'Goods returned to stock',
            [
                ['Dr', ControlRole::Inventory, 'the goods are ours again, at what the sale took out'],
                ['Cr', ControlRole::CostOfSales, 'the sale\'s cost, reversed by what came back'],
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
        $narration = (string) ($event['narration'] ?? 'Goods returned to stock');

        return [
            PostingLine::toRole(ControlRole::Inventory, $amount, narration: $narration),
            PostingLine::toRole(ControlRole::CostOfSales, $amount->negated(), narration: $narration),
        ];
    }
}
