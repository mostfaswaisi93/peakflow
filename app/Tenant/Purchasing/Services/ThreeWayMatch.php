<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Purchasing\Exceptions\OrderRefused;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Models\BillLine;
use App\Tenant\Purchasing\Models\GoodsReceipt;
use App\Tenant\Purchasing\Models\PurchaseOrderLine;
use App\Tenant\Purchasing\Models\ThreeWayMatchException;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bill against order against receipt - PF-135.
 *
 * ## The three documents, and why all three
 *
 * The order says what was agreed. The receipt says what arrived. The bill
 * says what is being charged. Any two of them can agree while the third
 * disagrees, and each pairing hides a different error:
 *
 * - order against bill, with no receipt: pays for goods that never came
 * - receipt against bill, with no order: pays a price nobody negotiated
 * - order against receipt, with no bill: not an error, it is the accrual
 *
 * So the match compares quantity **and** price, per line. A bill for the
 * right quantity at the wrong price passes a quantity-only match, and that
 * is the common form of supplier overcharging: the units are right and the
 * rate crept up.
 *
 * ## The exception names the discrepancy
 *
 * "Billed 120 units, received 100" - with the figures in their own columns so
 * the queue can be sorted by how wrong each one is. A generic hold makes
 * somebody open three documents to find out what is wrong, and the person
 * who opens them is not the person who knows.
 *
 * ## Partial everything, and one bill across several orders
 *
 * Nothing here assumes a bill maps to one order. Each bill line carries its
 * own `purchase_order_line_id`, so a bill covering three orders matches each
 * line against its own, and the quantities it consumes are the ones already
 * billed on that line rather than on the bill. That is why
 * `quantity_billed` is on the order line: a bill-level counter could not
 * tell the second bill how much of the order the first one used.
 *
 * ## A matched bill records what it matched against
 *
 * `bill_lines.purchase_order_line_id` and `goods_receipt_line_id` are
 * written by the match and kept. Which means the question "was this bill
 * ever checked against anything" has an answer six months later, and the
 * answer is not "somebody says so".
 */
final class ThreeWayMatch
{
    public function __construct(
        private readonly MatchTolerances $tolerances,
        private readonly GoodsReceipts $receipts,
        private readonly Settings $settings,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Match a bill, raising an exception for each discrepancy found.
     *
     * Returns the exceptions raised. An empty list means the bill matched,
     * and the bill's `match_status` says so either way.
     *
     * ## `$consume` is what separates checking from committing
     *
     * The match runs twice: at entry, to tell the person typing what is
     * wrong while they still have the document in front of them, and again
     * at posting, which is the run that advances the order line's
     * `quantity_billed`.
     *
     * They are separated because a draft bill that advanced the counter
     * would consume the order line and then be abandoned, leaving a
     * commitment released against a bill that never existed and an order
     * nobody could receive against again. Posting is the point at which the
     * obligation is real, so posting is the point at which the order line
     * moves.
     *
     * @return list<ThreeWayMatchException>
     */
    public function match(Bill $bill, bool $consume = false): array
    {
        $bill = $bill->refresh()->load('lines');

        /*
         | Whether a bill with no order is an exception - a decision, not a
         | constant.
         |
         | A utility bill, a bank charge and a tax assessment do not go
         | through an order, and a business that required one for all of them
         | would spend its day raising orders after the fact. A business with
         | a procurement policy wants to know about the bill that bypassed
         | it, because that is the bill nobody negotiated a price for.
         */
        $requireOrder = $this->settings->bool('purchasing.require_order', (string) $bill->entity_id);

        return DB::connection('tenant')->transaction(function () use ($bill, $requireOrder, $consume): array {
            $exceptions = [];
            $matchedLines = 0;

            foreach ($bill->lines as $line) {
                $orderLineId = $line->purchase_order_line_id;

                if ($orderLineId === null) {
                    if ($requireOrder) {
                        $exceptions[] = $this->raiseWithoutAnOrder($bill, $line);
                    }

                    /*
                     | And never refused, whichever way the setting is set.
                     |
                     | Refusing would stop the electricity bill. The
                     | exception records that this purchase bypassed the
                     | procurement policy, and a person decides what to do
                     | about it.
                     */
                    continue;
                }

                $orderLine = PurchaseOrderLine::query()->with('purchaseOrder')->find((string) $orderLineId);

                if ($orderLine === null) {
                    continue;
                }

                $matchedLines++;

                foreach ($this->discrepanciesOn($bill, $line, $orderLine) as $discrepancy) {
                    $exceptions[] = $this->raise($bill, $line, $orderLine, $discrepancy);
                }
            }

            $status = match (true) {
                $exceptions !== [] => 'exception',
                $matchedLines > 0 => 'matched',
                default => 'not_applicable',
            };

            $bill->update([
                'match_status' => $status,
                'matched_at' => now(),
            ]);

            /*
             | The order lines move only on a clean match.
             |
             | Advancing `quantity_billed` for a line under exception would
             | let a second bill for the same goods look like a first: the
             | order would say the quantity was already billed, and the
             | duplicate would pass.
             */
            if ($exceptions === [] && $consume) {
                foreach ($bill->lines as $line) {
                    if ($line->purchase_order_line_id === null) {
                        continue;
                    }

                    $orderLine = PurchaseOrderLine::query()->find((string) $line->purchase_order_line_id);

                    $orderLine?->update([
                        'quantity_billed' => (string) $orderLine->billed()
                            ->plus(BigDecimal::of((string) $line->quantity)),
                    ]);
                }
            }

            $this->audit->record(
                category: 'purchasing',
                action: $exceptions === [] ? 'bill.matched' : 'bill.match_exception',
                subjectType: 'bill',
                subjectId: (string) $bill->getKey(),
                subjectLabel: (string) $bill->supplier_document_number,
                after: [
                    'match_status' => $status,
                    'lines_matched' => $matchedLines,
                    'consumed' => $consume,
                    'exceptions' => array_map(
                        static fn (ThreeWayMatchException $e): string => (string) $e->kind,
                        $exceptions,
                    ),
                ],
                entityId: (string) $bill->entity_id,
                partyId: (string) $bill->party_id,
            );

            return $exceptions;
        });
    }

    /**
     * Link a bill line to an order line, and to the receipt behind it.
     *
     * Done before matching, because the match needs to know what the line
     * claims to be against. Separated so an automated linker (by item code,
     * by order number on the document) and a person doing it by hand go
     * through the same path and leave the same record.
     *
     * @throws OrderRefused
     */
    public function link(BillLine $line, PurchaseOrderLine $orderLine, ?string $receiptLineId = null): BillLine
    {
        $bill = $line->bill;

        if ((string) $bill->party_id !== (string) $orderLine->purchaseOrder->party_id) {
            throw OrderRefused::because(sprintf(
                'Bill %s is from a different supplier than order %s. Matching across suppliers would '.
                "release one supplier's commitment against another's invoice, and the money would go to ".
                'whoever the bill names.',
                (string) $bill->supplier_document_number,
                $orderLine->purchaseOrder->label(),
            ));
        }

        $line->update([
            'purchase_order_line_id' => (string) $orderLine->getKey(),
            'goods_receipt_line_id' => $receiptLineId,
        ]);

        return $line->refresh();
    }

    /**
     * Release the accrual on every receipt a matched bill covers - PF-132.
     *
     * The bill is the event that turns "received and not invoiced" into a
     * payable, so the accrual it replaces is reversed on the bill's posting
     * date. Only for receipts now fully billed: a receipt of a hundred
     * against which sixty have been invoiced still has forty accrued, and
     * releasing the whole entry would understate the liability by forty
     * units of goods the business is holding.
     *
     * @return list<string> the receipts released
     */
    public function releaseAccruals(Bill $bill): array
    {
        $bill = $bill->refresh()->load('lines');
        $released = [];

        foreach ($this->receiptsBehind($bill) as $receipt) {
            if (! $receipt->isAccrued() || $receipt->isReversed()) {
                continue;
            }

            if (! $this->isFullyBilled($receipt)) {
                continue;
            }

            $this->receipts->releaseAccrual(
                $receipt,
                Carbon::parse($bill->posting_date->toDateString()),
                sprintf(
                    'Bill %s matched the receipt, so what was accrued is now a payable.',
                    (string) $bill->supplier_document_number,
                ),
            );

            $released[] = $receipt->label();
        }

        return $released;
    }

    /**
     * Resolve an exception, with an explanation that is kept.
     *
     * @throws OrderRefused
     */
    public function resolve(ThreeWayMatchException $exception, string $userId, string $resolution): ThreeWayMatchException
    {
        if (\strlen(trim($resolution)) <= 5) {
            throw OrderRefused::because(
                'Resolving a match exception needs an explanation. "Approved" with nothing behind it is '.
                'what somebody clicks to clear a queue, and whoever defends the payment later needs to '.
                'know what was checked.',
            );
        }

        $exception->update([
            'resolved_at' => now(),
            'resolved_by' => $userId,
            'resolution' => trim($resolution),
        ]);

        $this->audit->record(
            category: 'purchasing',
            action: 'match_exception.resolved',
            subjectType: 'bill',
            subjectId: (string) $exception->bill_id,
            subjectLabel: (string) $exception->kind,
            after: [
                'expected' => $exception->expected === null ? null : (string) $exception->expected,
                'actual' => $exception->actual === null ? null : (string) $exception->actual,
            ],
            reason: trim($resolution),
            entityId: (string) $exception->entity_id,
        );

        return $exception->refresh();
    }

    /**
     * The exception queue, worst first - PF-135.
     *
     * Ordered by how far out each one is rather than by date, because a
     * queue ordered by date puts a two-cent rounding difference above a
     * thousand-unit over-billing and whoever works it has to open every one
     * to find out which matters.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(string $entityId): array
    {
        $rows = DB::connection('tenant')->table('three_way_match_exceptions as e')
            ->join('bills as b', 'b.id', '=', 'e.bill_id')
            ->leftJoin('bill_lines as l', 'l.id', '=', 'e.bill_line_id')
            ->where('e.entity_id', $entityId)
            ->whereNull('e.resolved_at')
            /*
             | A deterministic base order, before the sort below.
             |
             | Without one, PostgreSQL returns the rows in whatever order
             | the plan produces, and it is free to change between runs on
             | the same data - which makes a paginated queue skip rows and
             | repeat others. Measured: a check that asserted the queue is
             | ordered worst first passed with the sort REMOVED, because the
             | unordered rows happened to come back in the right order.
             */
            ->orderBy('e.created_at')
            ->orderBy('e.id')
            ->select([
                'e.id', 'e.kind', 'e.detail', 'e.expected', 'e.actual', 'e.tolerance',
                'b.supplier_document_number as number', 'b.gross_amount', 'b.currency',
                'b.status', 'b.party_id',
                'l.line_no', 'l.description',
            ])
            ->get()
            ->map(static function (object $row): array {
                $out = (array) $row;

                $expected = $row->expected === null ? null : BigDecimal::of((string) $row->expected);
                $actual = $row->actual === null ? null : BigDecimal::of((string) $row->actual);

                $out['variance'] = $expected === null || $actual === null
                    ? null
                    : (string) $actual->minus($expected);

                $out['variance_percent'] = $expected === null || $actual === null || $expected->isZero()
                    ? null
                    : (string) $actual->minus($expected)->abs()
                        ->dividedBy($expected->abs(), 4, RoundingMode::HalfUp)
                        ->multipliedBy('100');

                return $out;
            })
            ->all();

        usort($rows, static function (array $a, array $b): int {
            $left = $a['variance_percent'] === null ? BigDecimal::of('-1') : BigDecimal::of($a['variance_percent']);
            $right = $b['variance_percent'] === null ? BigDecimal::of('-1') : BigDecimal::of($b['variance_percent']);

            return $right->compareTo($left);
        });

        return array_values($rows);
    }

    /**
     * Every discrepancy on one bill line.
     *
     * @return list<array<string, mixed>>
     */
    private function discrepanciesOn(Bill $bill, BillLine $line, PurchaseOrderLine $orderLine): array
    {
        $order = $orderLine->purchaseOrder;
        $found = [];

        if ($order === null) {
            return $found;
        }

        if ((string) $order->party_id !== (string) $bill->party_id) {
            $found[] = [
                'kind' => 'wrong_supplier',
                'detail' => sprintf(
                    'This bill is from %s and order %s is with %s. A bill matched across suppliers '.
                    "releases one supplier's commitment against another's invoice.",
                    (string) ($bill->supplierName() ?? $bill->party_id),
                    $order->label(),
                    (string) ($order->supplierName() ?? $order->party_id),
                ),
            ];

            return $found;
        }

        if ($order->isClosed()) {
            $found[] = [
                'kind' => 'order_closed',
                'detail' => sprintf(
                    'Order %s was closed on %s (%s), and this bill arrived afterwards. Either the order '.
                    'was closed too early or the bill is for something else.',
                    $order->label(),
                    $order->closed_at?->toDateString() ?? 'an unrecorded date',
                    (string) ($order->closed_reason ?? 'no reason recorded'),
                ),
            ];
        }

        $tolerance = $this->tolerances->for(
            (string) $bill->entity_id,
            (string) $bill->party_id,
            (string) $orderLine->line_type,
        );

        $billed = BigDecimal::of((string) $line->quantity);
        $alreadyBilled = $orderLine->billed();
        $received = $orderLine->received();
        $ordered = $orderLine->ordered();

        /*
         | A service line with no confirmation of performance.
         |
         | Its own kind rather than a quantity mismatch, because there is
         | nothing to count: the question is whether anybody agreed the work
         | was done, and "received 0" would read as a delivery that never
         | came.
         */
        if ($orderLine->isService() && $received->isZero()) {
            $found[] = [
                'kind' => 'service_not_confirmed',
                'expected' => (string) $billed,
                'actual' => '0',
                'detail' => sprintf(
                    'Billed %s of "%s", and nobody has confirmed the work was done. A service has '.
                    'nothing on a loading bay to count, so that confirmation is the only evidence '.
                    'there is.',
                    (string) $billed->strippedOfTrailingZeros(),
                    (string) $orderLine->description,
                ),
            ];

            return $found;
        }

        if ($received->isZero() && ! $orderLine->isService()) {
            $found[] = [
                'kind' => 'no_receipt',
                'expected' => (string) $billed,
                'actual' => '0',
                'detail' => sprintf(
                    'Billed %s of "%s" and nothing has been received against that line. Paying for goods '.
                    'that never arrived is the error all three documents exist to prevent.',
                    (string) $billed->strippedOfTrailingZeros(),
                    (string) $orderLine->description,
                ),
            ];

            return $found;
        }

        /*
         | Billed beyond received, net of what was billed before.
         |
         | Against the order line's running total rather than this bill's
         | quantity, so a second bill for the same goods is caught: the first
         | one consumed the receipt and the second has nothing left to match.
         */
        $allowance = $tolerance->quantityAllowance($ordered);
        $billedInTotal = $alreadyBilled->plus($billed);

        if ($billedInTotal->minus($received)->isGreaterThan($allowance)) {
            $found[] = [
                'kind' => 'quantity_over_received',
                'expected' => (string) $received,
                'actual' => (string) $billedInTotal,
                'tolerance' => (string) $allowance,
                'detail' => sprintf(
                    'Billed %s units, received %s%s. %s',
                    (string) $billedInTotal->strippedOfTrailingZeros(),
                    (string) $received->strippedOfTrailingZeros(),
                    $alreadyBilled->isPositive()
                        ? sprintf(' (%s of which was billed earlier)', (string) $alreadyBilled->strippedOfTrailingZeros())
                        : '',
                    $allowance->isZero()
                        ? 'No tolerance is set for this supplier, so any difference is a question.'
                        : sprintf('The tolerance is %s.', (string) $allowance->strippedOfTrailingZeros()),
                ),
            ];
        }

        /*
         | Billed beyond ORDERED, and only where the goods really arrived.
         |
         | Measured: without the `received > ordered` condition, billing 101
         | against an order of 100 that was fully received raised BOTH
         | kinds, with identical figures - expected 100, actual 101 - because
         | received and ordered were the same number. Two rows for one
         | problem doubles the queue's length and makes somebody answer the
         | same question twice, and it hid a defect: a check that compared
         | the first two rows of the queue found the pair in both positions
         | and passed with the sort removed.
         |
         | So this kind is for the case the receipt one cannot express: the
         | excess was accepted, and somebody has committed the business to
         | goods nobody ordered. Where it has not arrived,
         | `quantity_over_received` is the tighter and more actionable
         | answer - chasing the order is the wrong question about a unit
         | that was never delivered.
         */
        if ($received->isGreaterThan($ordered)
            && $billedInTotal->minus($ordered)->isGreaterThan($allowance)) {
            $found[] = [
                'kind' => 'quantity_over_ordered',
                'expected' => (string) $ordered,
                'actual' => (string) $billedInTotal,
                'tolerance' => (string) $allowance,
                'detail' => sprintf(
                    'Billed %s units against %s ordered. Something arrived and was accepted that nobody '.
                    'agreed to buy, and the supplier has invoiced for it.',
                    (string) $billedInTotal->strippedOfTrailingZeros(),
                    (string) $ordered->strippedOfTrailingZeros(),
                ),
            ];
        }

        /*
         | The price, which a quantity-only match never sees.
         |
         | The common form of supplier overcharging: the units are right and
         | the rate crept up. Only an increase is an exception - a supplier
         | billing less than agreed is their mistake and not ours, and
         | raising an exception would hold a payment the business owes.
         */
        $billedPrice = BigDecimal::of((string) $line->unit_price);
        $orderedPrice = $orderLine->price();
        $priceAllowance = $tolerance->priceAllowance($orderedPrice);

        if ($billedPrice->minus($orderedPrice)->isGreaterThan($priceAllowance)) {
            $found[] = [
                'kind' => 'price_above_order',
                'expected' => (string) $orderedPrice,
                'actual' => (string) $billedPrice,
                'tolerance' => (string) $priceAllowance,
                'detail' => sprintf(
                    'Billed at %s per unit against %s on the order, a difference of %s per unit on %s '.
                    'units%s. The quantity is right, which is what makes this the form of overcharging '.
                    'that gets paid.',
                    (string) $billedPrice->strippedOfTrailingZeros(),
                    (string) $orderedPrice->strippedOfTrailingZeros(),
                    (string) $billedPrice->minus($orderedPrice)->strippedOfTrailingZeros(),
                    (string) $billed->strippedOfTrailingZeros(),
                    $priceAllowance->isZero()
                        ? ''
                        : sprintf(' against a tolerance of %s', (string) $priceAllowance->strippedOfTrailingZeros()),
                ),
            ];
        }

        return $found;
    }

    /**
     * A bill line that went through no order at all.
     *
     * No order line to point at, so the exception carries only the bill
     * line - which is why `purchase_order_line_id` on the table is nullable.
     * The figures are left null too: there is nothing to compare against,
     * and inventing an expected figure of zero would sort this to the top of
     * a queue ordered by variance, above the over-billings that have one.
     */
    private function raiseWithoutAnOrder(Bill $bill, BillLine $line): ThreeWayMatchException
    {
        return ThreeWayMatchException::query()->updateOrCreate(
            [
                'bill_line_id' => (string) $line->getKey(),
                'kind' => 'no_order',
            ],
            [
                'entity_id' => (string) $bill->entity_id,
                'bill_id' => (string) $bill->getKey(),
                'purchase_order_line_id' => null,
                'detail' => sprintf(
                    'Line %d ("%s") was billed with no purchase order behind it, and this entity '.
                    'requires one. A purchase nobody ordered is a purchase nobody negotiated a price '.
                    'for, which is the whole point of having orders.',
                    (int) $line->line_no,
                    (string) $line->description,
                ),
            ],
        );
    }

    /** @param array<string, mixed> $discrepancy */
    private function raise(
        Bill $bill,
        BillLine $line,
        PurchaseOrderLine $orderLine,
        array $discrepancy,
    ): ThreeWayMatchException {
        /*
         | One exception per line and kind.
         |
         | The match runs again when somebody fixes the bill, and a second
         | run must not produce a second copy of a discrepancy somebody has
         | already answered - or the answer would have to be given again
         | every time anybody looked.
         */
        return ThreeWayMatchException::query()->updateOrCreate(
            [
                'bill_line_id' => (string) $line->getKey(),
                'kind' => (string) $discrepancy['kind'],
            ],
            [
                'entity_id' => (string) $bill->entity_id,
                'bill_id' => (string) $bill->getKey(),
                'purchase_order_line_id' => (string) $orderLine->getKey(),
                'expected' => $discrepancy['expected'] ?? null,
                'actual' => $discrepancy['actual'] ?? null,
                'tolerance' => $discrepancy['tolerance'] ?? null,
                'detail' => (string) $discrepancy['detail'],
            ],
        );
    }

    /**
     * The receipts a bill's lines point at, through their order lines.
     *
     * @return list<GoodsReceipt>
     */
    private function receiptsBehind(Bill $bill): array
    {
        $orderLineIds = $bill->lines
            ->pluck('purchase_order_line_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->all();

        if ($orderLineIds === []) {
            return [];
        }

        $receiptIds = DB::connection('tenant')->table('goods_receipt_lines')
            ->whereIn('purchase_order_line_id', $orderLineIds)
            ->pluck('goods_receipt_id')
            ->unique()
            ->all();

        return GoodsReceipt::query()
            ->whereIn('id', $receiptIds)
            ->with('lines.orderLine')
            ->get()
            ->all();
    }

    /**
     * Whether every line of a receipt has been billed.
     *
     * Against the order line's billed total, because that is where the
     * running count lives: a receipt of a hundred against which sixty have
     * been invoiced still has forty accrued.
     */
    private function isFullyBilled(GoodsReceipt $receipt): bool
    {
        foreach ($receipt->lines as $line) {
            $orderLine = $line->orderLine;

            if ($orderLine === null) {
                continue;
            }

            if ($orderLine->billed()->isLessThan($orderLine->received())) {
                return false;
            }
        }

        return true;
    }
}
