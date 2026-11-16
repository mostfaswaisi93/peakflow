<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Ledger\Services\Reverser;
use App\Tenant\Ledger\Models\JournalEntry;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Purchasing\Exceptions\OrderRefused;
use App\Tenant\Purchasing\Models\GoodsReceipt;
use App\Tenant\Purchasing\Models\GoodsReceiptLine;
use App\Tenant\Purchasing\Models\PurchaseOrder;
use App\Tenant\Purchasing\Models\PurchaseOrderLine;
use App\Tenant\Purchasing\Posting\Rules\GoodsReceivedRule;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recording what arrived, and the accrual behind it - PF-132.
 *
 * ## The accrual is the point
 *
 * Goods arrive on the 29th and the invoice arrives on the 4th. Without an
 * accrual the expense lands in the wrong month and the balance sheet omits a
 * liability that exists.
 *
 * It is posted here and reversed by {@see ThreeWayMatch} when the bill
 * arrives, which is what makes {@see agedAccruals()} meaningful: a GRNI
 * balance that has not cleared in ninety days is a receipt nobody was ever
 * billed for. A monthly accrual journal reversed in full on the 1st cannot
 * tell you that, because it forgets.
 *
 * ## Over-delivery is checked against the supplier's tolerance
 *
 * A delivery of 102 against an order of 100 is routine in bulk goods and is
 * theft in serial-numbered equipment, which is why the tolerance is per
 * supplier and per line type rather than a constant. Beyond it the receipt
 * is refused, because accepting a quantity nobody agreed to buy commits the
 * business to paying for it: the supplier will invoice what they delivered.
 *
 * ## A rejection is recorded, not subtracted
 *
 * Ten arrived and two were damaged: the receipt says ten with two rejected,
 * and the order line moves by eight. Both figures are kept, because "we
 * received eight" loses the fact that the supplier shipped ten and owes a
 * replacement - which is the conversation the warehouse needs to have.
 */
final class GoodsReceipts
{
    public function __construct(
        private readonly Poster $poster,
        private readonly Reverser $reverser,
        private readonly NumberAllocator $numbers,
        private readonly MatchTolerances $tolerances,
        private readonly Settings $settings,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Record a receipt against a confirmed order.
     *
     * @param  list<array<string, mixed>>  $lines  each with `purchase_order_line_id`,
     *                                             `quantity`, and optionally
     *                                             `quantity_rejected`, `rejection_reason`,
     *                                             `confirmed_by`, `confirmation`
     *
     * @throws OrderRefused
     */
    public function receive(
        Entity $entity,
        PurchaseOrder $order,
        Carbon $receivedOn,
        array $lines,
        ?string $receivedBy = null,
        ?string $deliveryNote = null,
        ?bool $accrue = null,
        ?string $note = null,
    ): GoodsReceipt {
        $order = $order->refresh()->load('lines');

        if (! $order->isOpenForMatching()) {
            throw OrderRefused::theOrderIsNotConfirmed($order->label(), (string) $order->status);
        }

        if ($lines === []) {
            throw OrderRefused::aReceiptOfNothing();
        }

        $entityId = (string) $entity->getKey();
        $prepared = $this->prepare($order, $lines);

        /*
         | The accrual is the entity's decision, overridable per receipt.
         |
         | Per receipt because adopting the accrual halfway through a period
         | means accruing the receipts that are still open and leaving the
         | ones already billed alone, and a setting read at the moment of
         | posting cannot express "this one, not that one".
         */
        $accrue ??= $this->settings->bool('purchasing.accrue_receipts', $entityId);

        return DB::connection('tenant')->transaction(function () use (
            $entity, $entityId, $order, $receivedOn, $prepared, $receivedBy,
            $deliveryNote, $accrue, $note,
        ): GoodsReceipt {
            $receipt = GoodsReceipt::create([
                'entity_id' => $entityId,
                'number' => $this->numbers->next($entityId, 'goods_receipt', 'GRN', $receivedOn),
                'purchase_order_id' => (string) $order->getKey(),
                'received_on' => $receivedOn->toDateString(),
                'received_by' => $receivedBy,
                'delivery_note' => $deliveryNote,
                'note' => $note,
            ]);

            foreach ($prepared as $row) {
                GoodsReceiptLine::create([
                    'entity_id' => $entityId,
                    'goods_receipt_id' => (string) $receipt->getKey(),
                    'purchase_order_line_id' => (string) $row['line']->getKey(),
                    'quantity' => (string) $row['quantity'],
                    'quantity_rejected' => (string) $row['rejected'],
                    'rejection_reason' => $row['rejection_reason'],
                    'confirmed_by' => $row['confirmed_by'],
                    'confirmation' => $row['confirmation'],
                ]);

                /*
                 | The order line moves by what was ACCEPTED.
                 |
                 | A rejected unit did not arrive as far as the business is
                 | concerned: it is going back. Counting it would make the
                 | three-way match pass a bill for goods nobody kept.
                 */
                $row['line']->update([
                    'quantity_received' => (string) $row['line']->received()->plus($row['accepted']),
                ]);
            }

            if ($accrue) {
                $this->accrue($entity, $receipt->refresh(), $receivedOn);
            }

            $this->audit->record(
                category: 'purchasing',
                action: 'goods_receipt.recorded',
                subjectType: 'goods_receipt',
                subjectId: (string) $receipt->getKey(),
                subjectLabel: $receipt->label(),
                after: [
                    'order' => $order->label(),
                    'received_on' => $receivedOn->toDateString(),
                    'lines' => \count($prepared),
                    'accrued' => $accrue,
                    'delivery_note' => $deliveryNote,
                ],
                entityId: $entityId,
                partyId: (string) $order->party_id,
            );

            return $receipt->refresh();
        });
    }

    /**
     * Post the accrual for a receipt - `Dr expense / Cr GRNI`.
     *
     * Separated from `receive()` so a tenant that turns the accrual on
     * halfway through a period can accrue the receipts that are still open,
     * which is the usual way this feature is adopted.
     */
    public function accrue(Entity $entity, GoodsReceipt $receipt, ?Carbon $on = null): GoodsReceipt
    {
        $receipt = $receipt->refresh();

        if ($receipt->isAccrued()) {
            return $receipt;
        }

        $event = $this->accrualEvent($receipt);

        if ($event['accrual'] === []) {
            /*
             | Nothing to accrue.
             |
             | Every line was rejected, or the order's prices are zero. A
             | poster call would be refused for an entry of nothing, and
             | rightly - but that is not an error here, it is a delivery
             | that turned out to be worth nothing.
             */
            return $receipt;
        }

        $entry = $this->poster->post(
            entity: $entity,
            ruleKey: GoodsReceivedRule::KEY,
            event: $event,
            accountingDate: $on ?? Carbon::parse($receipt->received_on->toDateString()),
            journalCode: 'PUR',
            sourceType: 'goods_receipt',
            sourceId: (string) $receipt->getKey(),
            narration: sprintf(
                'Received on %s against order %s',
                $receipt->received_on->toDateString(),
                $receipt->purchaseOrder->label(),
            ),
        );

        $receipt->update(['journal_entry_id' => (string) $entry->getKey()]);

        $this->audit->record(
            category: 'purchasing',
            action: 'goods_receipt.accrued',
            subjectType: 'goods_receipt',
            subjectId: (string) $receipt->getKey(),
            subjectLabel: $receipt->label(),
            after: ['journal_entry_id' => (string) $entry->getKey()],
            entityId: (string) $receipt->entity_id,
        );

        return $receipt->refresh();
    }

    /**
     * Reverse the accrual, which is what the matching bill does.
     *
     * On the bill's own posting date rather than the receipt's, because the
     * accrual belongs to the month it was raised in and its release belongs
     * to the month the invoice arrived. Reversing it in the original month
     * would reopen a closed period, and the ledger refuses that - correctly.
     */
    public function releaseAccrual(GoodsReceipt $receipt, Carbon $on, string $reason): GoodsReceipt
    {
        $receipt = $receipt->refresh();

        if (! $receipt->isAccrued() || $receipt->isReversed()) {
            return $receipt;
        }

        $entry = JournalEntry::query()->find((string) $receipt->journal_entry_id);

        if ($entry === null) {
            return $receipt;
        }

        $reversal = $this->reverser->reverse($entry, $on, $reason);

        $receipt->update(['reversal_entry_id' => (string) $reversal->getKey()]);

        $this->audit->record(
            category: 'purchasing',
            action: 'goods_receipt.accrual_released',
            subjectType: 'goods_receipt',
            subjectId: (string) $receipt->getKey(),
            subjectLabel: $receipt->label(),
            after: ['reversal_entry_id' => (string) $reversal->getKey(), 'on' => $on->toDateString()],
            reason: $reason,
            entityId: (string) $receipt->entity_id,
        );

        return $receipt->refresh();
    }

    /**
     * Receipts never billed, by how long they have been waiting - PF-132.
     *
     * The report the GRNI control account exists for. A balance that has not
     * cleared in ninety days is one of three things: an invoice that was
     * never sent, a receipt recorded for a delivery that never came, or a
     * bill entered without matching it to the order. All three are worth
     * finding, and none of them is visible in a month-end accrual that
     * reverses itself.
     *
     * @return array<string, mixed>
     */
    public function agedAccruals(string $entityId, ?Carbon $asOf = null): array
    {
        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();

        $receipts = GoodsReceipt::query()
            ->where('entity_id', $entityId)
            ->whereNotNull('journal_entry_id')
            ->whereNull('reversal_entry_id')
            ->with(['lines.orderLine', 'purchaseOrder'])
            ->get();

        $buckets = ['0-30' => [], '31-60' => [], '61-90' => [], '90+' => []];
        $rows = [];

        foreach ($receipts as $receipt) {
            $order = $receipt->purchaseOrder;

            if ($order === null) {
                continue;
            }

            $currency = (string) $order->currency;
            $value = Money::zero($currency);

            foreach ($receipt->lines as $line) {
                $orderLine = $line->orderLine;

                if ($orderLine === null) {
                    continue;
                }

                $value = $value->plus(Money::ofRounded(
                    (string) $line->accepted()->multipliedBy($orderLine->price()),
                    $currency,
                    RoundingPolicy::halfUp(Scale::Amount),
                ));
            }

            $age = (int) $receipt->received_on->diffInDays($day, absolute: true);
            $bucket = match (true) {
                $age <= 30 => '0-30',
                $age <= 60 => '31-60',
                $age <= 90 => '61-90',
                default => '90+',
            };

            $row = [
                'receipt' => $receipt->label(),
                'receipt_id' => (string) $receipt->getKey(),
                'order' => $order->label(),
                'supplier' => (string) ($order->supplierName() ?? $order->party_id),
                'received_on' => $receipt->received_on->toDateString(),
                'age_days' => $age,
                'bucket' => $bucket,
                'currency' => $currency,
                'value' => (string) $value->amount,
            ];

            $rows[] = $row;
            $buckets[$bucket][] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => $b['age_days'] <=> $a['age_days']);

        return [
            'as_of' => $day->toDateString(),
            'rows' => $rows,
            'totals' => array_map(
                static fn (array $bucket): int => \count($bucket),
                $buckets,
            ),
        ];
    }

    /**
     * The accrual event: each accepted quantity at the order's price.
     *
     * @return array<string, mixed>
     */
    public function accrualEvent(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing(['lines.orderLine', 'purchaseOrder']);
        $order = $receipt->purchaseOrder;
        $currency = (string) $order->currency;
        $accrual = [];

        foreach ($receipt->lines as $line) {
            $orderLine = $line->orderLine;

            if ($orderLine === null || ! $line->accepted()->isPositive()) {
                continue;
            }

            $amount = Money::ofRounded(
                (string) $line->accepted()->multipliedBy($orderLine->price()),
                $currency,
                RoundingPolicy::halfUp(Scale::Amount),
            );

            if ($amount->isZero()) {
                continue;
            }

            $accrual[] = [
                'account_id' => (string) $orderLine->expense_account_id,
                'amount' => (string) $amount->amount,
                'dimensions' => [
                    $orderLine->dimension_1 === null ? null : (string) $orderLine->dimension_1,
                    $orderLine->dimension_2 === null ? null : (string) $orderLine->dimension_2,
                    $orderLine->dimension_3 === null ? null : (string) $orderLine->dimension_3,
                    $orderLine->dimension_4 === null ? null : (string) $orderLine->dimension_4,
                ],
            ];
        }

        return [
            'goods_receipt_id' => (string) $receipt->getKey(),
            'party_id' => (string) $order->party_id,
            'narration' => null,
            'accrual' => $accrual,
        ];
    }

    /**
     * Check each line before anything is written.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     *
     * @throws OrderRefused
     */
    private function prepare(PurchaseOrder $order, array $lines): array
    {
        $prepared = [];
        $seen = [];

        foreach ($lines as $input) {
            $lineId = (string) ($input['purchase_order_line_id'] ?? '');
            $orderLine = $order->lines->first(
                static fn (PurchaseOrderLine $l): bool => (string) $l->getKey() === $lineId,
            );

            if ($orderLine === null) {
                throw OrderRefused::theLineBelongsToAnotherOrder();
            }

            if (isset($seen[$lineId])) {
                /*
                 | One row per order line per receipt.
                 |
                 | The unique index says the same thing. Two rows for one
                 | line in one receipt are two answers to "how many arrived",
                 | and the code reading them would take whichever came back
                 | first.
                 */
                throw OrderRefused::because(sprintf(
                    'Line %d appears twice in this receipt. Two rows for one line are two answers to '.
                    'how many arrived; add the quantities together instead.',
                    (int) $orderLine->line_no,
                ));
            }

            $seen[$lineId] = true;

            $quantity = BigDecimal::of((string) ($input['quantity'] ?? '0'));
            $rejected = BigDecimal::of((string) ($input['quantity_rejected'] ?? '0'));

            if (! $quantity->isPositive()) {
                throw OrderRefused::because(sprintf(
                    'Line %d has no quantity. A receipt of zero records nothing; leave the line out.',
                    (int) $orderLine->line_no,
                ));
            }

            if ($rejected->isGreaterThan($quantity)) {
                throw OrderRefused::because(sprintf(
                    'Line %d rejects %s of %s delivered. More rejected than arrived is a counting error, '.
                    'and recording it would move the order line backwards.',
                    (int) $orderLine->line_no,
                    (string) $rejected,
                    (string) $quantity,
                ));
            }

            $accepted = $quantity->minus($rejected);

            /*
             | A service line is confirmed, not counted.
             |
             | There is nothing on a loading bay to count, so the receipt's
             | evidence is a person's confirmation that the work was done.
             | Without it the three-way match has nothing to compare a
             | service bill against, which is why it is required here rather
             | than left to the match to complain about later.
             */
            if ($orderLine->isService()) {
                $confirmation = trim((string) ($input['confirmation'] ?? ''));

                if (($input['confirmed_by'] ?? null) === null || \strlen($confirmation) <= 5) {
                    throw OrderRefused::because(sprintf(
                        'Line %d is a service and needs a confirmation of performance: who accepted the '.
                        'work and what they accepted. There is nothing on a loading bay to count, so '.
                        'that confirmation is the only evidence a three-way match will have.',
                        (int) $orderLine->line_no,
                    ));
                }
            }

            $this->assertWithinTolerance($order, $orderLine, $accepted);

            $prepared[] = [
                'line' => $orderLine,
                'quantity' => (string) $quantity,
                'rejected' => (string) $rejected,
                'accepted' => $accepted,
                'rejection_reason' => $rejected->isPositive()
                    ? (string) ($input['rejection_reason'] ?? '')
                    : null,
                'confirmed_by' => $input['confirmed_by'] ?? null,
                'confirmation' => isset($input['confirmation']) ? trim((string) $input['confirmation']) : null,
            ];
        }

        return $prepared;
    }

    /**
     * Over-delivery beyond the supplier's tolerance is refused.
     *
     * Because accepting a quantity nobody agreed to buy commits the business
     * to paying for it: the supplier will invoice what they delivered, and
     * by the time the bill arrives the goods are in the warehouse and the
     * argument is lost.
     *
     * @throws OrderRefused
     */
    private function assertWithinTolerance(PurchaseOrder $order, PurchaseOrderLine $line, BigDecimal $accepted): void
    {
        $alreadyReceived = $line->received();
        $total = $alreadyReceived->plus($accepted);
        $ordered = $line->ordered();

        if ($total->isLessThanOrEqualTo($ordered)) {
            return;
        }

        $tolerance = $this->tolerances->for(
            (string) $order->entity_id,
            (string) $order->party_id,
            (string) $line->line_type,
        );

        $allowance = $tolerance->quantityAllowance($ordered);
        $over = $total->minus($ordered);

        if ($over->isLessThanOrEqualTo($allowance)) {
            return;
        }

        throw OrderRefused::because(sprintf(
            'Line %d would be over-received: %s against %s ordered, which is %s over a tolerance of %s. '.
            'Accepting a quantity nobody agreed to buy commits the business to paying for it - the '.
            'supplier will invoice what they delivered. Either raise the order, or return the excess.',
            (int) $line->line_no,
            (string) $total->strippedOfTrailingZeros(),
            (string) $ordered->strippedOfTrailingZeros(),
            (string) $over->strippedOfTrailingZeros(),
            (string) $allowance->strippedOfTrailingZeros(),
        ));
    }
}
