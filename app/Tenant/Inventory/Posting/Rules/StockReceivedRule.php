<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Posting\Rules;

use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Posting\PostingContext;
use App\Tenant\Ledger\Posting\PostingLine;
use App\Tenant\Ledger\Posting\PostingRule;
use App\Tenant\Ledger\Posting\RuleDescription;

/**
 * Stock arriving - PF-248.
 *
 * ## Dr/Cr
 *
 *   Dr  inventory
 *   Cr  goods received not invoiced
 *
 * ## Why the credit is not accounts payable
 *
 * The goods are here and the invoice is not. Crediting AP would create a
 * payable nobody has sent us, so the creditors report would show a figure
 * no supplier agrees with - and when the bill arrives it would post the
 * payable a second time.
 *
 * `goods_received_not_invoiced` is the account that holds exactly that
 * gap, and Phase 07's three-way match is what closes it. The role already
 * existed for the purchasing side; this is the stock side of the same
 * event.
 *
 * ## An adjustment uses this rule too, with a negative amount
 *
 * A count that finds more than the ledger says is stock arriving from
 * nowhere, and the credit goes to cost of sales rather than to a
 * supplier - which is what {@see StockAdjustedRule} is for. The
 * distinction matters because one has a creditor behind it and the other
 * has a reason somebody typed.
 */
final class StockReceivedRule implements PostingRule
{
    public const KEY = 'catalog.stock_received';

    public function key(): string
    {
        return self::KEY;
    }

    public function describe(): RuleDescription
    {
        return RuleDescription::of(
            self::KEY,
            'استلام مخزون',
            'Stock received',
            [
                ['Dr', ControlRole::Inventory, 'what arrived, at what it cost'],
                ['Cr', ControlRole::GoodsReceivedNotInvoiced, 'the gap until the supplier bills for it'],
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
        $narration = (string) ($event['narration'] ?? 'Stock received');

        return [
            PostingLine::toRole(ControlRole::Inventory, $amount, narration: $narration),
            PostingLine::toRole(
                ControlRole::GoodsReceivedNotInvoiced,
                $amount->negated(),
                narration: $narration,
            ),
        ];
    }
}
