<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Exceptions\OrderRefused;
use App\Tenant\Purchasing\Models\PurchaseOrder;
use App\Tenant\Purchasing\Models\PurchaseOrderLine;
use App\Tenant\Purchasing\Models\ThreeWayMatchException;
use App\Tenant\Purchasing\Services\Bills;
use App\Tenant\Purchasing\Services\GoodsReceipts;
use App\Tenant\Purchasing\Services\MatchTolerances;
use App\Tenant\Purchasing\Services\PurchaseOrders;
use App\Tenant\Purchasing\Services\ThreeWayMatch;
use App\Tenant\Tenancy\Services\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Orders, receipts and the three-way match - PF-131, PF-132, PF-135.
 *
 * ## The three documents, and why all three
 *
 * The order says what was agreed, the receipt says what arrived, the bill
 * says what is being charged. Any two can agree while the third does not,
 * and each pairing hides a different error: order against bill pays for
 * goods that never came, receipt against bill pays a price nobody
 * negotiated.
 *
 * ## What gets its own check here
 *
 * The three that are expensive to get wrong and invisible when you do:
 *
 * - **An order posts nothing.** A commitment in the ledger is a liability
 *   nobody owes, and it reconciles to nothing.
 * - **The accrual is released by the matching bill, not by the calendar.**
 *   Which is the only thing that makes "received and never billed" a
 *   question the balance sheet can answer.
 * - **Price is matched as well as quantity.** A bill for the right quantity
 *   at the wrong price passes a quantity-only match, and that is the common
 *   form of supplier overcharging.
 */
final class ProcurementRules
{
    use \Tests\Support\Concerns\ProvesTheLedger;
    use \Tests\Support\Concerns\RaisesOrders;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['procurement (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- Orders - PF-131 -------------------------------------
                'an order is raised with its lines and totals' => fn (): array => $this->raising(),
                'an order posts nothing to the ledger' => fn (): array => $this->anOrderPostsNothing(),
                'the commitment is tracked per line' => fn (): array => $this->commitmentPerLine(),
                'a partial receipt and a partial bill are both per line' => fn (): array => $this->partials(),
                'closing an order with uninvoiced receipts is refused' => fn (): array => $this->closingWithUninvoicedReceipts(),
                'closing an order needs a reason' => fn (): array => $this->closingNeedsAReason(),
                'an order with receipts cannot be cancelled' => fn (): array => $this->cancellingAReceivedOrder(),
                'receiving against an unconfirmed order is refused' => fn (): array => $this->receivingBeforeConfirmation(),

                // ---- Receipts - PF-132 -----------------------------------
                'a receipt moves the line by what was accepted' => fn (): array => $this->acceptedNotDelivered(),
                'more rejected than delivered is refused' => fn (): array => $this->overRejection(),
                'a rejection with no reason is refused by the database' => fn (): array => $this->rejectionNeedsAReason(),
                'over-delivery beyond the tolerance is refused' => fn (): array => $this->overDelivery(),
                'the accrual debits the expense and credits GRNI' => fn (): array => $this->theAccrual(),
                'the accrual carries no tax' => fn (): array => $this->theAccrualHasNoTax(),
                'the matching bill releases the accrual' => fn (): array => $this->theBillReleasesTheAccrual(),
                'a partly billed receipt keeps its accrual' => fn (): array => $this->partlyBilledKeepsTheAccrual(),
                'the aged report surfaces a receipt never billed' => fn (): array => $this->agedAccruals(),
                'a service receipt is a confirmation of performance' => fn (): array => $this->serviceConfirmation(),

                // ---- The match - PF-135 ----------------------------------
                'a clean bill matches and records what it matched' => fn (): array => $this->aCleanMatch(),
                'billed more than received names the figures' => fn (): array => $this->overBilledNamesTheFigures(),
                'a price above the order is an exception' => fn (): array => $this->priceAboveTheOrder(),
                'a price below the order is not' => fn (): array => $this->priceBelowTheOrder(),
                'a tolerance within range passes and outside it does not' => fn (): array => $this->tolerances(),
                'the absolute tolerance carries the small orders' => fn (): array => $this->absoluteTolerance(),
                'the most specific tolerance wins' => fn (): array => $this->tolerancePrecedence(),
                'a tolerance above the platform ceiling is refused' => fn (): array => $this->theCeiling(),
                'a bill with nothing received is an exception' => fn (): array => $this->nothingReceived(),
                'a service bill with no confirmation has its own kind' => fn (): array => $this->serviceNotConfirmed(),
                'one bill across two orders matches each line to its own' => fn (): array => $this->acrossTwoOrders(),
                'a second bill for the same goods is caught' => fn (): array => $this->theSecondBill(),
                'an open exception blocks posting and names it' => fn (): array => $this->exceptionBlocksPosting(),
                'a draft bill does not consume the order line' => fn (): array => $this->draftDoesNotConsume(),
                'the queue is ordered worst first' => fn (): array => $this->queueOrder(),
                'billed beyond what was ordered is its own question' => fn (): array => $this->overOrdered(),
                'resolving an exception needs an explanation' => fn (): array => $this->resolution(),
                'matching across suppliers is refused' => fn (): array => $this->acrossSuppliers(),
                'a bill with no order is an exception only where one is required' => fn (): array => $this->noOrder(),
                'another entity cannot see these orders' => fn (): array => $this->entityScope(),
                'and the ledger still proves its own invariants' => fn (): array => [],
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function raising(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();

        if ($order->number === null) {
            $violations[] = 'the order has no number, so nothing can reference it';
        }

        if (! BigDecimal::of((string) $order->net_amount)->isEqualTo('1000.0000')) {
            $violations[] = sprintf('the order nets %s and 100 at 10 is 1,000', (string) $order->net_amount);
        }

        if (! BigDecimal::of((string) $order->gross_amount)->isEqualTo('1150.0000')) {
            $violations[] = sprintf(
                'the order grosses %s. The tax is indicative on an order - the supplier\'s invoice '.
                'decides - but a buyer still needs to know roughly what it will cost',
                (string) $order->gross_amount,
            );
        }

        if (! $order->isConfirmed() || $order->confirmed_by === null) {
            $violations[] = 'a confirmed order does not record who confirmed it';
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function anOrderPostsNothing(): array
    {
        $order = $this->confirmedOrder();

        $entries = DB::connection('tenant')->table('journal_entries')
            ->where('source_type', 'purchase_order')
            ->count();

        if ($entries > 0) {
            return [sprintf(
                'raising an order posted %d journal entries. An order is a promise, not a transaction: '.
                'nothing has been delivered, nobody is owed anything, and a commitment in the ledger is '.
                'a liability nobody owes that reconciles to nothing and has to be explained to an '.
                'auditor every year',
                $entries,
            )];
        }

        if ($order->remainingCommitment()->isZero()) {
            return ['the order has no remaining commitment, so the figure the report exists for is zero'];
        }

        return [];
    }

    /** @return list<string> */
    private function commitmentPerLine(): array
    {
        $violations = [];

        $order = $this->confirmedOrder(lines: [
            [
                'description' => 'Brackets',
                'quantity_ordered' => '100',
                'unit_price' => '10',
                'expense_account_id' => $this->expenseAccountId,
                'tax_rate' => '15.000000',
            ],
            [
                'description' => 'Fixings',
                'quantity_ordered' => '40',
                'unit_price' => '25',
                'expense_account_id' => $this->expenseAccountId,
                'tax_rate' => '15.000000',
            ],
        ]);

        $report = app(PurchaseOrders::class)->commitment($this->entityId);

        $mine = array_values(array_filter(
            $report['lines'],
            static fn (array $row): bool => $row['order'] === $order->label(),
        ));

        if (\count($mine) !== 2) {
            $violations[] = sprintf(
                'the commitment report shows %d lines for a two-line order. "60%% received" says nothing '.
                'about which 60%%, and a buyer reads the report to find which lines are still open',
                \count($mine),
            );
        }

        if (($report['totals']['SAR'] ?? null) === null) {
            $violations[] = 'the commitment has no total in the order currency';
        }

        // 100 x 10 + 40 x 25 = 2,000
        if (! BigDecimal::of((string) $order->remainingCommitment()->amount)->isEqualTo('2000.0000')) {
            $violations[] = sprintf(
                'the remaining commitment is %s and the order is worth 2,000 net',
                (string) $order->remainingCommitment()->amount,
            );
        }

        /*
         | And it falls as the bill is POSTED, not as goods arrive.
         |
         | A receipt without a bill is money already spent and not yet
         | invoiced, which is the accrual's job. Releasing the commitment on
         | receipt would release it before the obligation existed.
         */
        $line = $order->lines->first();
        $this->receive($order, [[
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => '100',
        ]]);

        $afterReceipt = $order->refresh()->load('lines')->remainingCommitment();

        if (! BigDecimal::of((string) $afterReceipt->amount)->isEqualTo('2000.0000')) {
            $violations[] = sprintf(
                'receiving the goods moved the commitment to %s. A receipt without a bill is money '.
                'already spent and not yet invoiced - that is the accrual, not the commitment - and '.
                'releasing it here would release the commitment before the obligation existed',
                (string) $afterReceipt->amount,
            );
        }

        $bill = $this->billAgainst([[$line, '100']]);
        $this->postBill($bill);

        $afterBill = $order->refresh()->load('lines')->remainingCommitment();

        if (! BigDecimal::of((string) $afterBill->amount)->isEqualTo('1000.0000')) {
            $violations[] = sprintf(
                'after billing the first line the commitment is %s and should be the second line\'s 1,000',
                (string) $afterBill->amount,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function partials(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();
        $line = $order->lines->first();

        $this->receive($order, [[
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => '60',
        ]]);

        $bill = $this->billAgainst([[$line, '40']]);
        $this->postBill($bill);

        $line = $line->refresh();

        if (! $line->received()->isEqualTo('60')) {
            $violations[] = sprintf('the line records %s received and 60 arrived', (string) $line->received());
        }

        if (! $line->billed()->isEqualTo('40')) {
            $violations[] = sprintf('the line records %s billed and 40 was invoiced', (string) $line->billed());
        }

        if (! $line->receivedNotBilled()->isEqualTo('20')) {
            $violations[] = sprintf(
                'the line says %s is received and not billed, and 60 arrived against 40 invoiced. That '.
                'figure is the GRNI quantity and the reason the accrual exists',
                (string) $line->receivedNotBilled(),
            );
        }

        if ($line->isFullyReceived() || $line->isFullyBilled()) {
            $violations[] = 'a line 60 received of 100 and 40 billed reports itself as complete';
        }

        return $violations;
    }

    /** @return list<string> */
    private function closingWithUninvoicedReceipts(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();

        $this->receive($order, [[
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => '60',
        ]]);

        try {
            app(PurchaseOrders::class)->close(
                $order->refresh(),
                $this->actorId(),
                'the project was cancelled and the remainder is not needed',
            );

            return [
                'an order with 60 received and nothing billed was closed. That quantity has a GRNI '.
                'balance behind it, and closing the order leaves the balance in the control account with '.
                'no open document pointing at it - the only way to find out what it was would be to '.
                'read the ledger backwards',
            ];
        } catch (OrderRefused $e) {
            $violations = [];

            if (! str_contains($e->getMessage(), '60')) {
                $violations[] = sprintf(
                    'the refusal does not name the quantity: "%s". "Cannot close" without the figures '.
                    'sends somebody hunting through three documents',
                    $e->getMessage(),
                );
            }

            return $violations;
        }
    }

    /** @return list<string> */
    private function closingNeedsAReason(): array
    {
        $order = $this->confirmedOrder();
        $violations = [];

        try {
            app(PurchaseOrders::class)->close($order, $this->actorId(), 'done');

            $violations[] = 'an order was closed with "done" as the reason, which is what somebody finds '.
                'in a year while asking what happened to the undelivered units';
        } catch (OrderRefused) {
            // Expected.
        }

        /*
         | And the database agrees, which is the guarantee.
         |
         | A service-level check is bypassed by an import or a script, and
         | this is the state somebody has to explain in a year.
         */
        try {
            DB::connection('tenant')->table('purchase_orders')
                ->where('id', (string) $order->getKey())
                ->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->actorId()]);

            $violations[] = 'the database accepted a closed order with no reason, so an import can '.
                'produce the state the service refuses';
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'purchase_orders_closure_is_explained')) {
                $violations[] = 'the closure was refused by something other than the constraint that '.
                    'exists for it: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function cancellingAReceivedOrder(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();

        $this->receive($order, [[
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => '100',
        ]]);

        $bill = $this->billAgainst([[$line, '100']]);
        $this->postBill($bill);

        try {
            app(PurchaseOrders::class)->close(
                $order->refresh(),
                $this->actorId(),
                'cancelled by the supplier after delivery',
                cancelled: true,
            );

            return [
                'an order with a full delivery against it was cancelled. Something arrived: the supplier '.
                'either keeps it or takes it back, and both are events that need recording',
            ];
        } catch (OrderRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function receivingBeforeConfirmation(): array
    {
        $order = app(PurchaseOrders::class)->raise(
            entity: $this->entity(),
            partyId: (string) $this->supplier?->getKey(),
            orderedOn: $this->date->copy(),
            currency: 'SAR',
            lines: $this->defaultOrderLines(),
            createdBy: $this->actorId(),
        );

        try {
            $this->receive($order);

            return [
                'goods were received against a draft order, which records an arrival nobody authorised',
            ];
        } catch (OrderRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function acceptedNotDelivered(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();
        $line = $order->lines->first();

        $receipt = $this->receive($order, [[
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => '100',
            'quantity_rejected' => '20',
            'rejection_reason' => 'twenty arrived with the housing cracked and go back on the lorry',
        ]]);

        if (! $line->refresh()->received()->isEqualTo('80')) {
            $violations[] = sprintf(
                'the line moved by %s and 20 of the 100 were rejected. A rejected unit is going back, '.
                'and counting it would make the match pass a bill for goods nobody kept',
                (string) $line->refresh()->received(),
            );
        }

        $receiptLine = $receipt->lines->first();

        if (! BigDecimal::of((string) $receiptLine->quantity)->isEqualTo('100')) {
            $violations[] = sprintf(
                'the receipt records %s delivered. Both figures are kept: "we received 80" loses the '.
                'fact that the supplier shipped 100 and owes a replacement',
                (string) $receiptLine->quantity,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function overRejection(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();

        try {
            $this->receive($order, [[
                'purchase_order_line_id' => (string) $line->getKey(),
                'quantity' => '10',
                'quantity_rejected' => '20',
                'rejection_reason' => 'more rejected than ever arrived, which is a counting error',
            ]]);

            return ['a receipt rejected 20 of 10 delivered, which would move the order line backwards'];
        } catch (OrderRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function rejectionNeedsAReason(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $receipt = $this->receive($order, [[
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => '10',
        ]]);

        try {
            DB::connection('tenant')->table('goods_receipt_lines')
                ->where('goods_receipt_id', (string) $receipt->getKey())
                ->update(['quantity_rejected' => '4', 'rejection_reason' => null]);

            return [
                'the database accepted four rejected units with no reason. "Four rejected" with nothing '.
                'behind it is what the warehouse cannot take back to the supplier',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'goods_receipt_lines_rejection_is_explained')) {
                return ['the rejection was refused by something other than the constraint for it: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function overDelivery(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();
        $line = $order->lines->first();

        /*
         | With no tolerance configured, 101 of 100 is refused.
         |
         | Zero rather than a lenient default, because a tolerance nobody
         | configured is a tolerance nobody decided on.
         */
        try {
            $this->receive($order, [[
                'purchase_order_line_id' => (string) $line->getKey(),
                'quantity' => '101',
            ]]);

            $violations[] = 'with no tolerance set, 101 of 100 was accepted. Accepting a quantity nobody '.
                'agreed to buy commits the business to paying for it: the supplier will invoice what '.
                'they delivered, and by then the goods are in the warehouse';
        } catch (OrderRefused $e) {
            if (! str_contains($e->getMessage(), '101')) {
                $violations[] = 'the over-delivery refusal does not name the quantity: '.$e->getMessage();
            }
        }

        // And within a tolerance it is accepted.
        app(MatchTolerances::class)->set(
            entityId: $this->entityId,
            partyId: (string) $this->supplier?->getKey(),
            lineType: 'goods',
            quantityPercent: '2',
            userId: $this->actorId(),
            reason: 'bulk brackets are counted by weight',
        );

        try {
            $this->receive($order->refresh(), [[
                'purchase_order_line_id' => (string) $line->getKey(),
                'quantity' => '101',
            ]]);
        } catch (OrderRefused $e) {
            $violations[] = 'with a 2% tolerance, 101 of 100 was still refused: '.$e->getMessage();
        }

        return $violations;
    }

    /** @return list<string> */
    private function theAccrual(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();
        $receipt = $this->receive($order, accrue: true);

        if (! $receipt->isAccrued()) {
            return ['the receipt posted no accrual, so a period closed between the delivery and the '.
                'invoice would put the expense in the wrong month and omit a liability that exists'];
        }

        $lines = $this->linesOfEntry((string) $receipt->journal_entry_id);

        $grni = $this->amountOnRole($lines, 'goods_received_not_invoiced');
        $expense = $this->amountOnAccount($lines, $this->expenseAccountId);

        if (! BigDecimal::of($grni)->isEqualTo('-1000.0000')) {
            $violations[] = sprintf(
                'GRNI is credited %s and 100 at 10 arrived. A balance that does not clear is a receipt '.
                'nobody was ever billed for, and that is the question the control account exists to ask',
                $grni,
            );
        }

        if (! BigDecimal::of($expense)->isEqualTo('1000.0000')) {
            $violations[] = sprintf('the expense is debited %s and the goods are worth 1,000', $expense);
        }

        return $violations;
    }

    /** @return list<string> */
    private function theAccrualHasNoTax(): array
    {
        $order = $this->confirmedOrder();
        $receipt = $this->receive($order, accrue: true);
        $lines = $this->linesOfEntry((string) $receipt->journal_entry_id);

        $input = $this->amountOnRole($lines, 'vat_input');

        if (! BigDecimal::of($input)->isZero()) {
            return [sprintf(
                'the accrual claimed %s of input tax. A delivery note is not a tax document: the tax '.
                'point may not have arrived, no tax invoice exists, and nothing can be claimed from an '.
                'authority on the strength of it. Putting input tax into a return before the document '.
                'that supports it exists is the one thing a tax authority reliably notices',
                $input,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function theBillReleasesTheAccrual(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $receipt = $this->receive($order, accrue: true);

        $bill = $this->billAgainst([[$line, '100']]);
        $this->postBill($bill);

        $receipt = $receipt->refresh();

        if (! $receipt->isReversed()) {
            return [
                'the bill that matched the receipt left the accrual standing. The GRNI balance would '.
                'then carry both the accrual and the payable for the same goods, which overstates the '.
                'liability by the whole delivery',
            ];
        }

        $reversal = DB::connection('tenant')->table('journal_entries')
            ->where('id', (string) $receipt->reversal_entry_id)
            ->first();

        if ($reversal === null) {
            return ['the receipt names a reversal entry that does not exist'];
        }

        if ((string) $reversal->accounting_date !== $bill->posting_date->toDateString()) {
            $violations[] = sprintf(
                'the accrual was released on %s and the bill posts on %s. The accrual belongs to the '.
                'month the goods arrived in and its release belongs to the month the invoice arrived; '.
                'reversing it in the original month would reopen a closed period',
                (string) $reversal->accounting_date,
                $bill->posting_date->toDateString(),
            );
        }

        $net = DB::connection('tenant')->table('journal_lines as l')
            ->join('account_roles as r', 'r.account_id', '=', 'l.account_id')
            ->whereIn('l.journal_entry_id', [
                (string) $receipt->journal_entry_id,
                (string) $receipt->reversal_entry_id,
            ])
            ->where('r.role', 'goods_received_not_invoiced')
            ->sum('l.amount');

        if (! BigDecimal::of((string) $net)->isZero()) {
            $violations[] = sprintf(
                'the accrual and its reversal leave %s in GRNI, so the balance would not clear for a '.
                'receipt that was billed in full',
                (string) $net,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function partlyBilledKeepsTheAccrual(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $receipt = $this->receive($order, accrue: true);

        $bill = $this->billAgainst([[$line, '60']]);
        $this->postBill($bill);

        if ($receipt->refresh()->isReversed()) {
            return [
                'a bill for 60 of a 100-unit receipt released the whole accrual. The remaining 40 units '.
                'are goods the business is holding and has not been invoiced for, and dropping their '.
                'accrual understates a liability that exists',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function agedAccruals(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();

        /*
         | Received four months before the report date.
         |
         | Which is the row the report exists for: a balance that has not
         | cleared in ninety days is an invoice never sent, a receipt for a
         | delivery that never came, or a bill entered without matching it.
         */
        $receipt = $this->receive($order, accrue: true);

        /*
         | Aged forward rather than received in the past.
         |
         | A receipt dated 120 days before the fixture's date falls in a
         | period nobody has opened, and the ledger refuses to post into one
         | - correctly. The report takes an `asOf`, so the age is produced by
         | asking the question later rather than by back-dating the
         | delivery.
         */
        $report = app(GoodsReceipts::class)->agedAccruals(
            $this->entityId,
            $this->date->copy()->addDays(120),
        );

        $mine = array_values(array_filter(
            $report['rows'],
            static fn (array $row): bool => $row['receipt_id'] === (string) $receipt->getKey(),
        ));

        if ($mine === []) {
            return ['a receipt accrued 120 days ago and never billed does not appear in the aged report'];
        }

        if ($mine[0]['bucket'] !== '90+') {
            $violations[] = sprintf(
                'a receipt 120 days old is bucketed as %s',
                (string) $mine[0]['bucket'],
            );
        }

        if (! BigDecimal::of((string) $mine[0]['value'])->isEqualTo('1000.0000')) {
            $violations[] = sprintf(
                'the aged row is worth %s and the delivery was 100 at 10',
                (string) $mine[0]['value'],
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function serviceConfirmation(): array
    {
        $violations = [];
        $order = $this->confirmedOrder(lines: $this->serviceOrderLines());
        $line = $order->lines->first();

        try {
            $this->receive($order, [[
                'purchase_order_line_id' => (string) $line->getKey(),
                'quantity' => '12',
            ]]);

            $violations[] = 'a service was received with no confirmation of performance. There is '.
                'nothing on a loading bay to count, so that confirmation is the only evidence a '.
                'three-way match will ever have for a service line';
        } catch (OrderRefused) {
            // Expected.
        }

        $receipt = $this->receive($order, [$this->confirmedServiceLine($line, '12')]);

        if (! $receipt->lines->first()->isConfirmed()) {
            $violations[] = 'a confirmed service receipt does not record who confirmed it';
        }

        return $violations;
    }

    /** @return list<string> */
    private function aCleanMatch(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $receipt = $this->receive($order);

        $bill = $this->billAgainst([[$line, '100']]);
        $bill = $this->postBill($bill);

        if ((string) $bill->match_status !== 'matched') {
            $violations[] = sprintf('a clean bill is %s rather than matched', (string) $bill->match_status);
        }

        $billLine = $bill->lines->first();

        if ($billLine->purchase_order_line_id === null) {
            $violations[] = 'the matched bill does not record what it matched against, so "was this '.
                'bill ever checked against anything" has no answer six months later';
        }

        if ($this->openExceptionsOn($bill) !== 0) {
            $violations[] = 'a clean bill raised a match exception';
        }

        if (! $line->refresh()->billed()->isEqualTo('100')) {
            $violations[] = 'posting a matched bill did not advance the order line\'s billed quantity, '.
                'so a second bill for the same goods would find the order untouched';
        }

        return $violations;
    }

    /** @return list<string> */
    private function overBilledNamesTheFigures(): array
    {
        $violations = [];
        $order = $this->confirmedOrder(lines: [[
            'description' => 'Brackets',
            'quantity_ordered' => '200',
            'unit_price' => '10',
            'expense_account_id' => $this->expenseAccountId,
            'tax_rate' => '15.000000',
        ]]);

        $line = $order->lines->first();

        $this->receive($order, [[
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => '100',
        ]]);

        $bill = $this->billAgainst([[$line, '120']]);
        $exceptions = app(ThreeWayMatch::class)->match($bill);

        $quantity = $this->exceptionOfKind($exceptions, 'quantity_over_received');

        if ($quantity === null) {
            return ['billing 120 against 100 received raised no quantity exception'];
        }

        if (! BigDecimal::of((string) $quantity->expected)->isEqualTo('100')
            || ! BigDecimal::of((string) $quantity->actual)->isEqualTo('120')) {
            $violations[] = sprintf(
                'the exception records expected %s and actual %s, and the figures are 100 received '.
                'against 120 billed',
                (string) $quantity->expected,
                (string) $quantity->actual,
            );
        }

        foreach (['120', '100'] as $figure) {
            if (! str_contains((string) $quantity->detail, $figure)) {
                $violations[] = sprintf(
                    'the detail does not contain %s: "%s". A generic hold makes somebody open three '.
                    'documents to find out what is wrong, and the person who opens them is not the '.
                    'person who knows',
                    $figure,
                    (string) $quantity->detail,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function priceAboveTheOrder(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $this->receive($order);

        // The right quantity at the wrong price - what a quantity-only match misses.
        $bill = $this->billAgainst([[$line, '100', '11']]);
        $exceptions = app(ThreeWayMatch::class)->match($bill);

        $price = $this->exceptionOfKind($exceptions, 'price_above_order');

        if ($price === null) {
            return [
                'a bill at 11 against an order at 10 matched. The quantity is right, which is what '.
                'makes this the form of overcharging that gets paid: a quantity-only match passes it '.
                'and the rate crept up',
            ];
        }

        if ($this->exceptionOfKind($exceptions, 'quantity_over_received') !== null) {
            $violations[] = 'a price-only difference also raised a quantity exception, which would send '.
                'somebody looking for goods that are not missing';
        }

        if (! str_contains((string) $price->detail, '11') || ! str_contains((string) $price->detail, '10')) {
            $violations[] = 'the price exception does not name both prices: '.(string) $price->detail;
        }

        return $violations;
    }

    /** @return list<string> */
    private function priceBelowTheOrder(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $this->receive($order);

        $bill = $this->billAgainst([[$line, '100', '9']]);
        $exceptions = app(ThreeWayMatch::class)->match($bill);

        if ($this->exceptionOfKind($exceptions, 'price_above_order') !== null) {
            return [
                'a supplier billing LESS than agreed raised an exception. That is their mistake and not '.
                'ours, and holding the payment would hold money the business owes',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function tolerances(): array
    {
        $violations = [];

        app(MatchTolerances::class)->set(
            entityId: $this->entityId,
            partyId: (string) $this->supplier?->getKey(),
            lineType: 'goods',
            quantityPercent: '2',
            pricePercent: '5',
            userId: $this->actorId(),
            reason: 'a negotiated tolerance for bulk brackets',
        );

        // 100 ordered, 100 received, 101 billed: 1 over, tolerance is 2.
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $this->receive($order);

        $bill = $this->billAgainst([[$line, '101']]);

        if ($this->exceptionOfKind(app(ThreeWayMatch::class)->match($bill), 'quantity_over_received') !== null) {
            $violations[] = 'a 1-unit difference inside a 2-unit tolerance raised an exception, which '.
                'means the queue fills with differences nobody wants to see';
        }

        // And 104 billed: 4 over, outside the tolerance.
        $second = $this->confirmedOrder();
        $secondLine = $second->lines->first();
        $this->receive($second);

        $overBill = $this->billAgainst([[$secondLine, '104']]);

        if ($this->exceptionOfKind(app(ThreeWayMatch::class)->match($overBill), 'quantity_over_received') === null) {
            $violations[] = 'a 4-unit difference outside a 2-unit tolerance matched, so the tolerance '.
                'is not being compared against anything';
        }

        return $violations;
    }

    /** @return list<string> */
    private function absoluteTolerance(): array
    {
        /*
         | Two per cent of a 20-unit order is 0.4, which rounds to nothing.
         |
         | Which is why both figures exist: the percentage carries scale and
         | the absolute carries the small orders where a percentage is less
         | than one unit.
         */
        app(MatchTolerances::class)->set(
            entityId: $this->entityId,
            partyId: (string) $this->supplier?->getKey(),
            lineType: 'goods',
            quantityPercent: '2',
            quantityAbsolute: '1',
            userId: $this->actorId(),
            reason: 'one unit either way on small orders, two per cent on large ones',
        );

        $order = $this->confirmedOrder(lines: [[
            'description' => 'Calibration jigs',
            'quantity_ordered' => '20',
            'unit_price' => '10',
            'expense_account_id' => $this->expenseAccountId,
            'tax_rate' => '15.000000',
        ]]);

        $line = $order->lines->first();
        $this->receive($order);

        $bill = $this->billAgainst([[$line, '21']]);

        if ($this->exceptionOfKind(app(ThreeWayMatch::class)->match($bill), 'quantity_over_received') !== null) {
            return [
                'one unit over a 20-unit order was an exception although the absolute tolerance is one. '.
                'Two per cent of twenty is 0.4, so a percentage-only tolerance flags every small order '.
                'that arrived a unit light',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function tolerancePrecedence(): array
    {
        $tolerances = app(MatchTolerances::class);
        $supplierId = (string) $this->supplier?->getKey();

        // The entity's default, then a supplier-and-type override.
        $tolerances->set(
            entityId: $this->entityId,
            partyId: null,
            lineType: null,
            quantityPercent: '1',
            userId: $this->actorId(),
            reason: 'the house default for everything with no arrangement',
        );

        $tolerances->set(
            entityId: $this->entityId,
            partyId: $supplierId,
            lineType: 'goods',
            quantityPercent: '5',
            userId: $this->actorId(),
            reason: 'this supplier ships by weight and the count varies',
        );

        $resolved = $tolerances->for($this->entityId, $supplierId, 'goods');

        if (! BigDecimal::of((string) $resolved->quantity_percent)->isEqualTo('5')) {
            return [sprintf(
                'the resolved tolerance is %s%% and the supplier-and-type arrangement says 5%%. Taking '.
                'the first match would make the answer depend on insertion order, which is how a '.
                'tolerance set for one supplier silently applies to all of them',
                (string) $resolved->quantity_percent,
            )];
        }

        $fallback = $tolerances->for($this->entityId, $supplierId, 'service');

        if (! BigDecimal::of((string) $fallback->quantity_percent)->isEqualTo('1')) {
            return [sprintf(
                'a service line resolved %s%% and only the entity default applies to it',
                (string) $fallback->quantity_percent,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function theCeiling(): array
    {
        $violations = [];

        try {
            app(MatchTolerances::class)->set(
                entityId: $this->entityId,
                partyId: (string) $this->supplier?->getKey(),
                lineType: 'goods',
                quantityPercent: '50',
                userId: $this->actorId(),
                reason: 'an attempt to turn the match off',
            );

            $violations[] = 'a 50% tolerance was accepted, which is the match turned off - and what a '.
                'three-way match exists for is the invoice that is quietly 20% too high';
        } catch (OrderRefused) {
            // Expected.
        }

        /*
         | And the database holds the ceiling, which is the guarantee.
         |
         | A limit enforced only in a service is a limit an import does not
         | have.
         */
        try {
            DB::connection('tenant')->table('supplier_match_tolerances')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'entity_id' => $this->entityId,
                'party_id' => (string) $this->supplier?->getKey(),
                'line_type' => 'service',
                'quantity_percent' => '50',
                'quantity_absolute' => '0',
                'price_percent' => '0',
                'price_absolute' => '0',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $violations[] = 'the database accepted a 50% tolerance, so an import can turn the match off';
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'within_the_platform_ceiling')) {
                $violations[] = 'the tolerance was refused by something other than the ceiling: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function nothingReceived(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();

        $bill = $this->billAgainst([[$line, '100']]);
        $exceptions = app(ThreeWayMatch::class)->match($bill);

        if ($this->exceptionOfKind($exceptions, 'no_receipt') === null) {
            return [
                'a bill for 100 units with nothing received against the line matched. Paying for goods '.
                'that never arrived is the error all three documents exist to prevent',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function serviceNotConfirmed(): array
    {
        $order = $this->confirmedOrder(lines: $this->serviceOrderLines());
        $line = $order->lines->first();

        $bill = $this->billAgainst([[$line, '12']]);
        $exceptions = app(ThreeWayMatch::class)->match($bill);

        if ($this->exceptionOfKind($exceptions, 'service_not_confirmed') === null) {
            return [sprintf(
                'a service bill with no confirmation raised %s rather than its own kind. "Received 0" '.
                'reads as a delivery that never came, and the question for a service is whether '.
                'anybody agreed the work was done',
                implode(', ', array_map(
                    static fn (ThreeWayMatchException $e): string => (string) $e->kind,
                    $exceptions,
                )) ?: 'nothing',
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function acrossTwoOrders(): array
    {
        $violations = [];

        $first = $this->confirmedOrder();
        $second = $this->confirmedOrder(lines: [[
            'description' => 'Fixings',
            'quantity_ordered' => '40',
            'unit_price' => '25',
            'expense_account_id' => $this->expenseAccountId,
            'tax_rate' => '15.000000',
        ]]);

        $firstLine = $first->lines->first();
        $secondLine = $second->lines->first();

        $this->receive($first);
        $this->receive($second);

        $bill = $this->billAgainst([[$firstLine, '100'], [$secondLine, '40']]);
        $bill = $this->postBill($bill);

        if ((string) $bill->match_status !== 'matched') {
            $violations[] = sprintf(
                'a bill spanning two orders is %s. One bill covering several orders is ordinary, and '.
                'each line has to match against its own order',
                (string) $bill->match_status,
            );
        }

        if (! $firstLine->refresh()->billed()->isEqualTo('100')
            || ! $secondLine->refresh()->billed()->isEqualTo('40')) {
            $violations[] = sprintf(
                'the two orders recorded %s and %s billed against 100 and 40. A bill-level pointer '.
                'could not say which line came from which order',
                (string) $firstLine->refresh()->billed(),
                (string) $secondLine->refresh()->billed(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function theSecondBill(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $this->receive($order);

        $this->postBill($this->billAgainst([[$line, '100']]));

        /*
         | The same goods, a second invoice, a different number.
         |
         | The duplicate detector cannot see this one - the numbers differ -
         | and the order line can: the first bill consumed the receipt and
         | there is nothing left to match.
         */
        $second = $this->billAgainst([[$line, '100']]);
        $exceptions = app(ThreeWayMatch::class)->match($second);

        if ($this->exceptionOfKind($exceptions, 'quantity_over_received') === null) {
            return [
                'a second bill for goods the first one already consumed matched. The duplicate detector '.
                'cannot catch this - the numbers differ - and the order line can, because the running '.
                'billed quantity is on the line',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function exceptionBlocksPosting(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $this->receive($order);

        /*
         | Two bills for one delivery, both clean when they were entered.
         |
         | Which is the scenario that makes this check mean something. A
         | bill that was already wrong at entry has an exception from the
         | entry-time match, so "the exception survived the refusal" would
         | be true whatever the posting path did with it.
         |
         | Here each bill saw an unbilled receipt when it was typed. The
         | first one posts and consumes the receipt; the second has nothing
         | left to match, and its discrepancy is raised for the first time
         | by the posting that refuses it.
         */
        $first = $this->billAgainst([[$line, '100']]);
        $second = $this->billAgainst([[$line, '100']]);

        if ($this->openExceptionsOn($second) !== 0) {
            return ['the second bill already had an exception at entry, so this check cannot tell '.
                'whether the posting path keeps one'];
        }

        $this->postBill($first);

        /*
         | The near-match warning is acknowledged, deliberately.
         |
         | Two bills from one supplier for the same amount on the same day
         | raise a duplicate warning - correctly - and `post()` refuses an
         | unanswered one before it gets as far as the match. So somebody
         | waves it through, saying it was a second delivery, which is
         | exactly what happens in an accounts payable department at four in
         | the afternoon.
         |
         | And the match catches it anyway, which is the point: the soft
         | check asks a person and the hard one asks the order.
         */
        foreach (app(\App\Tenant\Purchasing\Services\DuplicateBillDetector::class)->unacknowledged($second) as $warning) {
            app(\App\Tenant\Purchasing\Services\DuplicateBillDetector::class)->acknowledge(
                $warning,
                $this->actorId(),
                'the supplier says this is the second lorry and not a re-issue of the first invoice',
            );
        }

        try {
            app(Bills::class)->post($second, $this->entity(), $this->actorId());

            $violations[] = 'a second bill for goods the first one already consumed was posted. A match '.
                'exception cleared by posting through it is a control that exists only on paper';
        } catch (BillRefused $e) {
            if (! str_contains($e->getMessage(), '200') || ! str_contains($e->getMessage(), '100')) {
                $violations[] = sprintf(
                    'the refusal does not name the discrepancy: "%s". "3 match exceptions" sends '.
                    'somebody to a queue; "billed 200, received 100" tells them what to do',
                    $e->getMessage(),
                );
            }
        }

        /*
         | And the exception survived the refusal.
         |
         | Raising it inside the transaction the refusal rolls back would
         | hand the caller a message and keep no record of it, which is the
         | one failure mode an exception queue cannot have.
         */
        if ($this->openExceptionsOn($second) === 0) {
            $violations[] = 'the refusal left no exception behind, so the queue somebody works tomorrow '.
                'is empty and the only record of the discrepancy was the message in the moment';
        }

        if (! $line->refresh()->billed()->isEqualTo('100')) {
            $violations[] = sprintf(
                'the order line is at %s billed after one bill posted and one was refused. A refused '.
                'posting that still advanced the line would leave the next bill matching against a '.
                'quantity already spoken for',
                (string) $line->refresh()->billed(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function draftDoesNotConsume(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $this->receive($order);

        // Entered, matched at entry, not posted.
        $this->billAgainst([[$line, '100']]);

        if (! $line->refresh()->billed()->isZero()) {
            return [sprintf(
                'a draft bill advanced the order line to %s billed. A draft that consumed the line and '.
                'was then abandoned would leave a commitment released against a bill that never existed '.
                'and an order nobody could receive against again',
                (string) $line->refresh()->billed(),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function queueOrder(): array
    {
        $violations = [];

        // A small difference and a large one, entered in that order.
        app(MatchTolerances::class)->set(
            entityId: $this->entityId,
            partyId: (string) $this->supplier?->getKey(),
            lineType: 'goods',
            quantityPercent: '0',
            userId: $this->actorId(),
            reason: 'no tolerance, so every difference is a question',
        );

        $small = $this->confirmedOrder();
        $smallLine = $small->lines->first();
        $this->receive($small);
        app(ThreeWayMatch::class)->match($this->billAgainst([[$smallLine, '101']]));

        $large = $this->confirmedOrder();
        $largeLine = $large->lines->first();
        $this->receive($large, [[
            'purchase_order_line_id' => (string) $largeLine->getKey(),
            'quantity' => '50',
        ]]);
        app(ThreeWayMatch::class)->match($this->billAgainst([[$largeLine, '100']]));

        $queue = app(ThreeWayMatch::class)->queue($this->entityId);

        if (\count($queue) < 2) {
            return [sprintf('the queue holds %d rows and two discrepancies were raised', \count($queue))];
        }

        /*
         | The WHOLE queue, not its first two rows.
         |
         | Measured: comparing rows 0 and 1 passed with the sort removed,
         | because one bill produced two exceptions carrying the same
         | figures and they occupied both positions. A check that looks at a
         | fixed window of an ordering is a check that an ordering defect can
         | walk past.
         */
        $previous = null;

        foreach ($queue as $position => $row) {
            $variance = $row['variance_percent'] === null
                ? BigDecimal::of('-1')
                : BigDecimal::of((string) $row['variance_percent']);

            if ($previous !== null && $variance->isGreaterThan($previous)) {
                $violations[] = sprintf(
                    'row %d of the queue is %s%% out and the row above it is %s%%. A queue ordered by '.
                    'date puts a two-cent rounding difference above a thousand-unit over-billing, and '.
                    'whoever works it has to open every one to find out which matters',
                    $position,
                    (string) $variance,
                    (string) $previous,
                );

                break;
            }

            $previous = $variance;
        }

        /*
         | And one problem is one row.
         |
         | Billing 101 against an order of 100 that was fully received is a
         | single question - a unit was invoiced and never arrived - and two
         | rows carrying the same figures make somebody answer it twice.
         */
        $forTheSmallBill = array_values(array_filter(
            $queue,
            static fn (array $row): bool => (string) $row['expected'] === '100.00000000'
                && (string) $row['actual'] === '101.00000000',
        ));

        if (\count($forTheSmallBill) > 1) {
            $violations[] = sprintf(
                'one unit over a fully-received order raised %d exceptions with identical figures (%s). '.
                'Where the goods never arrived, chasing the order is the wrong question about the same '.
                'unit',
                \count($forTheSmallBill),
                implode(', ', array_map(static fn (array $r): string => (string) $r['kind'], $forTheSmallBill)),
            );
        }

        return $violations;
    }

    /**
     * Billed beyond what was ORDERED, which is a different question.
     *
     * Reachable only where the excess really arrived: a tolerance that
     * permitted the over-delivery and was then withdrawn, or a receipt
     * imported from elsewhere. That is the case the receipt comparison
     * cannot express - somebody has committed the business to goods nobody
     * ordered - and it is why the kind exists at all.
     *
     * @return list<string>
     */
    private function overOrdered(): array
    {
        $tolerances = app(MatchTolerances::class);
        $supplierId = (string) $this->supplier?->getKey();

        $tolerances->set(
            entityId: $this->entityId,
            partyId: $supplierId,
            lineType: 'goods',
            quantityPercent: '5',
            userId: $this->actorId(),
            reason: 'the supplier ships by weight, so five per cent either way',
        );

        $order = $this->confirmedOrder();
        $line = $order->lines->first();

        $this->receive($order, [[
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => '104',
        ]]);

        // The arrangement is withdrawn, and the goods are already here.
        $tolerances->set(
            entityId: $this->entityId,
            partyId: $supplierId,
            lineType: 'goods',
            quantityPercent: '0',
            userId: $this->actorId(),
            reason: 'the tolerance was withdrawn after a review of over-deliveries',
        );

        $exceptions = app(ThreeWayMatch::class)->match($this->billAgainst([[$line, '104']]));

        $violations = [];

        if ($this->exceptionOfKind($exceptions, 'quantity_over_ordered') === null) {
            $violations[] = sprintf(
                'billing 104 against an order of 100 that received 104 raised %s. The excess was '.
                'accepted, so somebody has committed the business to goods nobody ordered',
                implode(', ', array_map(
                    static fn (ThreeWayMatchException $e): string => (string) $e->kind,
                    $exceptions,
                )) ?: 'nothing',
            );
        }

        if ($this->exceptionOfKind($exceptions, 'quantity_over_received') !== null) {
            $violations[] = 'billing exactly what arrived also raised an over-receipt exception, which '.
                'would send somebody looking for goods that are not missing';
        }

        return $violations;
    }

    /** @return list<string> */
    private function resolution(): array
    {
        $violations = [];
        $order = $this->confirmedOrder();
        $line = $order->lines->first();
        $this->receive($order, [[
            'purchase_order_line_id' => (string) $line->getKey(),
            'quantity' => '60',
        ]]);

        $exceptions = app(ThreeWayMatch::class)->match($this->billAgainst([[$line, '100']]));

        if ($exceptions === []) {
            return ['no exception was raised, so there is nothing to resolve'];
        }

        try {
            app(ThreeWayMatch::class)->resolve($exceptions[0], $this->actorId(), 'ok');

            $violations[] = 'an exception was resolved with "ok", which is what somebody clicks to clear '.
                'a queue at four in the afternoon';
        } catch (OrderRefused) {
            // Expected.
        }

        $resolved = app(ThreeWayMatch::class)->resolve(
            $exceptions[0],
            $this->actorId(),
            'the remaining 40 arrived on a second lorry and the receipt was recorded late',
        );

        if ($resolved->isOpen()) {
            $violations[] = 'a resolved exception still reads as open';
        }

        $queue = app(ThreeWayMatch::class)->queue($this->entityId);

        foreach ($queue as $row) {
            if ((string) $row['id'] === (string) $resolved->getKey()) {
                $violations[] = 'a resolved exception is still in the queue';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function acrossSuppliers(): array
    {
        $order = $this->confirmedOrder();
        $line = $order->lines->first();

        $otherSupplier = \App\Tenant\Parties\Models\Party::create([
            'code' => 'SUP-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'legal_name' => 'Another Trading Co.',
            'is_supplier' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        $bill = $this->enterBill(partyId: (string) $otherSupplier->getKey());

        try {
            app(ThreeWayMatch::class)->link($bill->lines->first(), $line);

            return [
                'a bill from one supplier was linked to another supplier\'s order. Matching across '.
                'suppliers releases one supplier\'s commitment against another\'s invoice, and the '.
                'money goes to whoever the bill names',
            ];
        } catch (OrderRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function noOrder(): array
    {
        $violations = [];
        $settings = app(Settings::class);

        // With orders not required, an ordinary utility bill matches nothing.
        $bill = $this->enterBill();

        if ($this->openExceptionsOn($bill) !== 0) {
            $violations[] = 'a bill with no order raised an exception although this entity does not '.
                'require orders. A utility bill, a bank charge and a tax assessment do not go through '.
                'an order, and requiring one would mean raising orders after the fact all day';
        }

        if ((string) $bill->refresh()->match_status !== 'not_applicable') {
            $violations[] = sprintf(
                'a bill with no order lines is %s rather than not_applicable',
                (string) $bill->refresh()->match_status,
            );
        }

        $settings->set('purchasing.require_order', true, $this->entityId);
        $settings->flush();

        $second = $this->enterBill();

        if ($this->openExceptionsOn($second) === 0) {
            $violations[] = 'with orders required, a bill that bypassed procurement raised nothing. '.
                'That is the bill nobody negotiated a price for';
        }

        if ((string) $second->refresh()->status !== 'draft') {
            $violations[] = 'requiring an order refused the bill rather than flagging it, which would '.
                'stop the electricity bill';
        }

        $settings->set('purchasing.require_order', false, $this->entityId);
        $settings->flush();

        return $violations;
    }

    /** @return list<string> */
    private function entityScope(): array
    {
        $order = $this->confirmedOrder();

        $other = \App\Tenant\Tenancy\Models\Entity::create([
            'code' => 'PO'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'legal_name' => 'A second entity in the same tenant',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $context = app(\App\Tenant\Tenancy\Services\EntityContext::class);

        try {
            $context->use($other);

            $visible = PurchaseOrder::query()->whereKey((string) $order->getKey())->count();

            if ($visible !== 0) {
                return [sprintf(
                    'an order belonging to another entity is visible from this one. Row-level security '.
                    'is the guarantee here, not the query: a report that forgot its `where` would read '.
                    'another entity\'s commitments'
                )];
            }

            return [];
        } finally {
            $context->use($this->entity());
        }
    }

    // ---- Helpers -------------------------------------------------------

    private function postBill(\App\Tenant\Purchasing\Models\Bill $bill): \App\Tenant\Purchasing\Models\Bill
    {
        return app(Bills::class)->post($bill, $this->entity(), $this->actorId());
    }

    /** @param list<ThreeWayMatchException> $exceptions */
    private function exceptionOfKind(array $exceptions, string $kind): ?ThreeWayMatchException
    {
        foreach ($exceptions as $exception) {
            if ((string) $exception->kind === $kind) {
                return $exception;
            }
        }

        return null;
    }

    private function openExceptionsOn(\App\Tenant\Purchasing\Models\Bill $bill): int
    {
        return ThreeWayMatchException::query()
            ->where('bill_id', (string) $bill->getKey())
            ->whereNull('resolved_at')
            ->count();
    }

    /** @return list<array<string, mixed>> */
    private function linesOfEntry(string $entryId): array
    {
        return DB::connection('tenant')->table('journal_lines as l')
            ->leftJoin('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('l.journal_entry_id', $entryId)
            ->select(['l.account_id', 'l.amount', 'r.role'])
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /** @param list<array<string, mixed>> $lines */
    private function amountOnRole(array $lines, string $role): string
    {
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ((string) ($line['role'] ?? '') === $role) {
                $total = $total->plus((string) $line['amount']);
            }
        }

        return (string) $total->toScale(4);
    }

    /** @param list<array<string, mixed>> $lines */
    private function amountOnAccount(array $lines, string $accountId): string
    {
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ((string) ($line['account_id'] ?? '') === $accountId) {
                $total = $total->plus((string) $line['amount']);
            }
        }

        return (string) $total->toScale(4);
    }
}
