<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Models\GoodsReceipt;
use App\Tenant\Purchasing\Models\PurchaseOrder;
use App\Tenant\Purchasing\Models\PurchaseOrderLine;
use App\Tenant\Purchasing\Services\GoodsReceipts;
use App\Tenant\Purchasing\Services\PurchaseOrders;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The fixture an order, receipt and match check needs - PF-131, PF-132,
 * PF-135.
 *
 * Builds on {@see EntersBills} rather than repeating it: the entity, the
 * supplier and the expense account are the same ones a bill check uses, and
 * a second seeder would drift and then the two suites would be checking
 * different entities.
 *
 * ## The quantities are deliberately awkward
 *
 * A hundred ordered, a hundred received and a hundred billed makes every
 * off-by-one error invisible. The defaults here order a hundred so that a
 * partial receipt of sixty and a partial bill of forty are both expressible
 * without a fractional unit, and the price is ten so that a price difference
 * of one is exactly ten per cent - which is the platform ceiling, and
 * therefore the edge the tolerance checks need.
 */
trait RaisesOrders
{
    use EntersBills;

    /** Raise and confirm an order, which is the state a receipt needs. */
    protected function confirmedOrder(?array $lines = null, ?string $partyId = null): PurchaseOrder
    {
        $order = app(PurchaseOrders::class)->raise(
            entity: $this->entity(),
            partyId: $partyId ?? (string) $this->supplier?->getKey(),
            orderedOn: $this->date->copy(),
            currency: 'SAR',
            lines: $lines ?? $this->defaultOrderLines(),
            expectedOn: $this->date->copy()->addDays(14),
            createdBy: $this->actorId(),
            paymentTermId: $this->terms === null ? null : (string) $this->terms->getKey(),
        );

        return app(PurchaseOrders::class)->confirm($order, $this->actorId());
    }

    /** @return list<array<string, mixed>> */
    protected function defaultOrderLines(): array
    {
        return [[
            'description' => 'Machined brackets, batch 41',
            'line_type' => 'goods',
            'quantity_ordered' => '100',
            'unit_price' => '10',
            'expense_account_id' => $this->expenseAccountId,
            'tax_code_id' => $this->taxCodeId,
            'tax_category' => 'S',
            'tax_rate' => '15.000000',
        ]];
    }

    /** @return list<array<string, mixed>> */
    protected function serviceOrderLines(): array
    {
        return [[
            'description' => 'Site supervision, twelve days',
            'line_type' => 'service',
            'quantity_ordered' => '12',
            'unit_price' => '500',
            'expense_account_id' => $this->expenseAccountId,
            'tax_code_id' => $this->taxCodeId,
            'tax_category' => 'S',
            'tax_rate' => '15.000000',
        ]];
    }

    /**
     * Record a receipt against an order.
     *
     * @param  list<array<string, mixed>>|null  $lines  defaults to the whole
     *                                                  of every line
     */
    protected function receive(
        PurchaseOrder $order,
        ?array $lines = null,
        bool $accrue = false,
        ?Carbon $on = null,
    ): GoodsReceipt {
        $lines ??= $order->lines->map(static fn (PurchaseOrderLine $line): array => [
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => (string) $line->quantity_ordered,
        ])->all();

        return app(GoodsReceipts::class)->receive(
            entity: $this->entity(),
            order: $order,
            receivedOn: $on ?? $this->date->copy(),
            lines: $lines,
            receivedBy: $this->actorId(),
            deliveryNote: 'DN-'.Str::upper(Str::random(5)),
            accrue: $accrue,
        );
    }

    /** A receipt line that confirms a service was performed. */
    protected function confirmedServiceLine(PurchaseOrderLine $line, string $quantity): array
    {
        return [
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => $quantity,
            'confirmed_by' => $this->actorId(),
            'confirmation' => 'Supervision delivered and signed off on site by the project engineer.',
        ];
    }

    /**
     * Enter a bill whose lines point at an order's.
     *
     * @param  list<array{0: PurchaseOrderLine, 1: string, 2?: string}>  $against
     *                                                                   each
     *                                                                   order
     *                                                                   line,
     *                                                                   the
     *                                                                   quantity
     *                                                                   billed
     *                                                                   and
     *                                                                   optionally
     *                                                                   a price
     */
    protected function billAgainst(array $against, ?Carbon $postingDate = null, ?string $partyId = null): Bill
    {
        $lines = [];

        foreach ($against as $index => [$orderLine, $quantity]) {
            $price = $against[$index][2] ?? (string) $orderLine->unit_price;
            $net = (string) \Brick\Math\BigDecimal::of($quantity)
                ->multipliedBy($price)
                ->toScale(4, \Brick\Math\RoundingMode::HalfUp);

            $lines[] = [
                'description' => (string) $orderLine->description,
                'quantity' => $quantity,
                'unit_price' => $price,
                'net_amount' => $net,
                'expense_account_id' => (string) $orderLine->expense_account_id,
                'tax_code_id' => $this->taxCodeId,
                'tax_category' => 'S',
                'tax_rate' => '15.000000',
                'tax_amount' => (string) \Brick\Math\BigDecimal::of($net)
                    ->multipliedBy('0.15')
                    ->toScale(4, \Brick\Math\RoundingMode::HalfUp),
                'purchase_order_line_id' => (string) $orderLine->getKey(),
            ];
        }

        return $this->enterBill(lines: $lines, postingDate: $postingDate, partyId: $partyId);
    }
}
