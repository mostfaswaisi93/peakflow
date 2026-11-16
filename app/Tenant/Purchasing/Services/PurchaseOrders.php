<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Purchasing\Exceptions\OrderRefused;
use App\Tenant\Purchasing\Models\PurchaseOrder;
use App\Tenant\Purchasing\Models\PurchaseOrderLine;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raising and tracking a commitment to buy - PF-131.
 *
 * ## An order posts nothing
 *
 * There is no journal entry anywhere in this class. An order is a promise,
 * not a transaction: nothing has been delivered, nobody is owed anything and
 * no expense has been incurred. The remaining commitment is a figure
 * {@see commitment()} computes from the lines.
 *
 * Systems that post an "encumbrance" to a ledger account end up with a
 * balance sheet carrying a liability nobody owes, which reconciles to
 * nothing and has to be explained to an auditor every year. Budget
 * visibility is a report; it is not an entry.
 *
 * ## Everything is tracked per line
 *
 * `quantity_received` and `quantity_billed` live on the line, maintained by
 * {@see GoodsReceipts} and {@see ThreeWayMatch} as documents arrive. An
 * order for ten of which six arrived and four were billed is the ordinary
 * case, and an order-level percentage cannot say which six - which is
 * exactly what the three-way match needs to know.
 *
 * ## A closed order says why, and an order with uninvoiced receipts is not
 * closed at all
 *
 * `close()` requires a reason, and the database agrees. "Closed" with
 * nineteen units never delivered and no explanation is what somebody finds
 * in a year while asking what happened to them, and by then the buyer has
 * left.
 *
 * And it is refused outright where something has arrived and has not been
 * billed. That quantity has a GRNI balance behind it - a liability for goods
 * the business is holding and has not paid for. Closing the order would
 * strand it: the balance would sit in the control account with no open
 * document pointing at it, and the only way to find out what it was would be
 * to read the ledger backwards. The refusal names the lines and quantities,
 * because "cannot close" without them sends somebody hunting.
 */
final class PurchaseOrders
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Raise an order.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws OrderRefused
     */
    public function raise(
        Entity $entity,
        string $partyId,
        Carbon $orderedOn,
        string $currency,
        array $lines,
        ?Carbon $expectedOn = null,
        ?string $createdBy = null,
        ?string $paymentTermId = null,
        ?string $note = null,
        string $seriesKey = 'PO',
    ): PurchaseOrder {
        if ($lines === []) {
            throw OrderRefused::because(
                'An order with no lines commits to nothing. An order raised to hold a number and filled '.
                'in later is a draft with no lines, which is a thing nobody can approve.',
            );
        }

        $entityId = (string) $entity->getKey();

        return DB::connection('tenant')->transaction(function () use (
            $entity, $entityId, $partyId, $orderedOn, $expectedOn, $currency, $lines,
            $createdBy, $paymentTermId, $note, $seriesKey,
        ): PurchaseOrder {
            $computed = $this->compute($lines, $currency);

            $order = PurchaseOrder::create([
                'entity_id' => $entityId,
                'number' => $this->numbers->next($entityId, 'purchase_order', $seriesKey, $orderedOn),
                'series_key' => $seriesKey,
                'status' => 'draft',
                'party_id' => $partyId,
                'ordered_on' => $orderedOn->toDateString(),
                'expected_on' => $expectedOn?->toDateString(),
                'currency' => $currency,
                'net_amount' => (string) $computed['net']->amount,
                'tax_amount' => (string) $computed['tax']->amount,
                'gross_amount' => (string) $computed['gross']->amount,
                'payment_term_id' => $paymentTermId,
                'created_by' => $createdBy,
                'note' => $note,
            ]);

            foreach ($lines as $index => $line) {
                $this->writeLine($order, $line, $index, $currency);
            }

            $this->audit->record(
                category: 'purchasing',
                action: 'purchase_order.raised',
                subjectType: 'purchase_order',
                subjectId: (string) $order->getKey(),
                subjectLabel: $order->label(),
                after: [
                    'supplier' => $partyId,
                    'gross' => (string) $computed['gross']->amount,
                    'currency' => $currency,
                    'lines' => \count($lines),
                ],
                entityId: $entityId,
                partyId: $partyId,
            );

            return $order->refresh();
        });
    }

    /**
     * Confirm an order - after which it may be received against.
     *
     * Separate from raising it, because the commitment begins when the
     * supplier is told and not when somebody typed it. An order sitting in
     * draft is a shopping list; a confirmed one is a figure the business has
     * to budget for.
     *
     * @throws OrderRefused
     */
    public function confirm(PurchaseOrder $order, string $userId): PurchaseOrder
    {
        $order = $order->refresh();

        if ($order->isConfirmed()) {
            return $order;
        }

        if ($order->isClosed()) {
            throw OrderRefused::because(sprintf(
                'Order %s is %s and cannot be confirmed. Re-opening a closed order would release a '.
                'commitment and then re-create it, which makes the commitment report disagree with '.
                'itself across a month end.',
                $order->label(),
                (string) $order->status,
            ));
        }

        $order->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by' => $userId,
        ]);

        $this->audit->record(
            category: 'purchasing',
            action: 'purchase_order.confirmed',
            subjectType: 'purchase_order',
            subjectId: (string) $order->getKey(),
            subjectLabel: $order->label(),
            after: ['confirmed_by' => $userId, 'gross' => (string) $order->gross_amount],
            entityId: (string) $order->entity_id,
            partyId: (string) $order->party_id,
        );

        return $order->refresh();
    }

    /**
     * Close an order, with a reason that is kept.
     *
     * @throws OrderRefused
     */
    public function close(PurchaseOrder $order, string $userId, string $reason, bool $cancelled = false): PurchaseOrder
    {
        $order = $order->refresh()->load('lines');

        if (\strlen(trim($reason)) <= 5) {
            throw OrderRefused::because(
                'Closing an order needs a reason. An order closed with nineteen units never delivered '.
                'and no explanation is what somebody finds in a year while asking what happened to '.
                'them, and by then the buyer has left.',
            );
        }

        /*
         | Uninvoiced receipts refuse the closure - PF-131.
         |
         | That quantity has a GRNI balance behind it: a liability for goods
         | the business is holding and has not paid for. Closing the order
         | would strand it in the control account with no open document
         | pointing at it, and the only way to find out what it was would be
         | to read the ledger backwards.
         */
        $uninvoiced = $this->uninvoicedReceipts($order);

        if ($uninvoiced !== []) {
            throw OrderRefused::because(sprintf(
                'Order %s cannot be closed: %s has arrived and has not been billed. That quantity has a '.
                'goods-received-not-invoiced balance behind it, and closing the order would leave the '.
                'balance in the control account with no open document pointing at it. Either enter the '.
                "supplier's bill, or reverse the receipt if it was recorded in error.",
                $order->label(),
                implode('; ', array_map(
                    static fn (array $row): string => sprintf(
                        'line %d (%s) %s of %s received, %s billed',
                        $row['line_no'],
                        $row['description'],
                        $row['received'],
                        $row['ordered'],
                        $row['billed'],
                    ),
                    $uninvoiced,
                )),
            ));
        }

        if ($cancelled && $order->lines->contains(static fn (PurchaseOrderLine $l): bool => $l->received()->isPositive())) {
            throw OrderRefused::because(sprintf(
                'Order %s has received lines and cannot be cancelled. Something arrived: the supplier '.
                'either keeps it or takes it back, and both are events that need recording. Close the '.
                'order with the reason instead.',
                $order->label(),
            ));
        }

        $order->update([
            'status' => $cancelled ? 'cancelled' : 'closed',
            'closed_at' => now(),
            'closed_by' => $userId,
            'closed_reason' => trim($reason),
        ]);

        $this->audit->record(
            category: 'purchasing',
            action: $cancelled ? 'purchase_order.cancelled' : 'purchase_order.closed',
            subjectType: 'purchase_order',
            subjectId: (string) $order->getKey(),
            subjectLabel: $order->label(),
            after: [
                'released_commitment' => (string) $order->remainingCommitment()->amount,
                'fully_received' => $order->isFullyReceived(),
                'fully_billed' => $order->isFullyBilled(),
            ],
            reason: trim($reason),
            entityId: (string) $order->entity_id,
            partyId: (string) $order->party_id,
        );

        return $order->refresh();
    }

    /**
     * The lines that have received more than they have been billed for.
     *
     * Public, because the form wants to show this before somebody presses
     * the button that would be refused. A refusal somebody could have been
     * warned about is a refusal that reads as a bug.
     *
     * @return list<array<string, mixed>>
     */
    public function uninvoicedReceipts(PurchaseOrder $order): array
    {
        $rows = [];

        foreach ($order->lines as $line) {
            if (! $line->receivedNotBilled()->isPositive()) {
                continue;
            }

            $rows[] = [
                'line_no' => (int) $line->line_no,
                'description' => (string) $line->description,
                'ordered' => (string) $line->ordered()->strippedOfTrailingZeros(),
                'received' => (string) $line->received()->strippedOfTrailingZeros(),
                'billed' => (string) $line->billed()->strippedOfTrailingZeros(),
                'uninvoiced' => (string) $line->receivedNotBilled()->strippedOfTrailingZeros(),
            ];
        }

        return $rows;
    }

    /**
     * The commitment report - PF-131.
     *
     * Per line, with its own remaining figure, because "what have we
     * committed to and not yet spent" is answered by a buyer looking at
     * which lines are still open and not by a single total.
     *
     * @return array<string, mixed>
     */
    public function commitment(string $entityId, ?string $partyId = null): array
    {
        $orders = PurchaseOrder::query()
            ->where('entity_id', $entityId)
            ->where('status', 'confirmed')
            ->when($partyId !== null, static fn ($q) => $q->where('party_id', $partyId))
            ->with('lines')
            ->get();

        $byCurrency = [];
        $rows = [];

        foreach ($orders as $order) {
            $currency = (string) $order->currency;
            $remaining = $order->remainingCommitment();

            if ($remaining->isZero()) {
                continue;
            }

            $byCurrency[$currency] ??= Money::zero($currency);
            $byCurrency[$currency] = $byCurrency[$currency]->plus($remaining);

            foreach ($order->lines as $line) {
                $lineRemaining = $line->remainingCommitment($currency);

                if ($lineRemaining->isZero()) {
                    continue;
                }

                $rows[] = [
                    'order' => $order->label(),
                    'order_id' => (string) $order->getKey(),
                    'line_no' => (int) $line->line_no,
                    'description' => (string) $line->description,
                    'currency' => $currency,
                    'ordered' => (string) $line->quantity_ordered,
                    'received' => (string) $line->quantity_received,
                    'billed' => (string) $line->quantity_billed,
                    'remaining' => (string) $lineRemaining->amount,
                    'expected_on' => $line->expected_on?->toDateString() ?? $order->expected_on?->toDateString(),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => BigDecimal::of($b['remaining'])
            ->compareTo(BigDecimal::of($a['remaining'])));

        return [
            'totals' => array_map(static fn (Money $m): string => (string) $m->amount, $byCurrency),
            'lines' => $rows,
        ];
    }

    /**
     * Total the lines. Tax is indicative on an order and the bill decides.
     *
     * A buyer wants to know roughly what the order will cost including tax,
     * so a figure is computed - but it is never posted and never claimed.
     * The authority's figure comes from the supplier's tax invoice, and an
     * order's estimate that found its way into a return would be a claim
     * supported by a document that does not exist.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, Money>
     */
    private function compute(array $lines, string $currency): array
    {
        $net = Money::zero($currency);
        $tax = Money::zero($currency);

        foreach ($lines as $line) {
            $lineNet = $this->lineNet($line, $currency);
            $rate = BigDecimal::of((string) ($line['tax_rate'] ?? '0'));

            $net = $net->plus($lineNet);

            if ($rate->isPositive()) {
                $tax = $tax->plus($lineNet->multipliedBy(
                    (string) $rate->dividedBy('100', 12, \Brick\Math\RoundingMode::HalfUp),
                    RoundingPolicy::halfUp(Scale::Amount),
                ));
            }
        }

        return ['net' => $net, 'tax' => $tax, 'gross' => $net->plus($tax)];
    }

    /** @param array<string, mixed> $line */
    private function lineNet(array $line, string $currency): Money
    {
        if (isset($line['net_amount'])) {
            return Money::of((string) $line['net_amount'], $currency);
        }

        $quantity = BigDecimal::of((string) ($line['quantity_ordered'] ?? '1'));
        $price = BigDecimal::of((string) ($line['unit_price'] ?? '0'));

        return Money::ofRounded(
            (string) $quantity->multipliedBy($price),
            $currency,
            RoundingPolicy::halfUp(Scale::Amount),
        );
    }

    /** @param array<string, mixed> $line */
    private function writeLine(PurchaseOrder $order, array $line, int $index, string $currency): PurchaseOrderLine
    {
        return PurchaseOrderLine::create([
            'entity_id' => (string) $order->entity_id,
            'purchase_order_id' => (string) $order->getKey(),
            'line_no' => (int) ($line['line_no'] ?? $index + 1),
            'item_id' => $line['item_id'] ?? null,
            'item_code' => $line['item_code'] ?? null,
            'description' => (string) $line['description'],
            'line_type' => (string) ($line['line_type'] ?? 'goods'),
            'unit_code' => (string) ($line['unit_code'] ?? 'C62'),
            'quantity_ordered' => (string) ($line['quantity_ordered'] ?? '1'),
            'unit_price' => (string) ($line['unit_price'] ?? '0'),
            'net_amount' => (string) $this->lineNet($line, $currency)->amount,
            'expense_account_id' => $line['expense_account_id'] ?? null,
            'tax_code_id' => $line['tax_code_id'] ?? null,
            'tax_category' => (string) ($line['tax_category'] ?? TaxCategory::Standard->value),
            'tax_rate' => (string) ($line['tax_rate'] ?? '0'),
            'expected_on' => isset($line['expected_on'])
                ? Carbon::parse((string) $line['expected_on'])->toDateString()
                : null,
            'dimension_1' => $line['dimension_1'] ?? null,
            'dimension_2' => $line['dimension_2'] ?? null,
            'dimension_3' => $line['dimension_3'] ?? null,
            'dimension_4' => $line['dimension_4'] ?? null,
        ]);
    }
}
