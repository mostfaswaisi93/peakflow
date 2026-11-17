<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Inventory\Services\Stock;
use App\Tenant\Ledger\Enums\ControlRole;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock, its cost and the account that has to agree with it - PF-247, PF-248.
 *
 * ## What a check here is for
 *
 * The failure this phase exists to prevent is two records of one event:
 * an invoice that sold a tracked item and a stock ledger that does not
 * know. The first symptom is a margin report nobody believes; the second
 * is a count that writes off the difference every quarter and calls it
 * shrinkage.
 *
 * So the things asserted are that stock has no second truth (quantity is
 * derived), that the cost is the cost it was and not the cost today, and
 * that the control account reconciles to the movements - as a critical
 * finding, because a control account that does not is the same class of
 * fault as a receivables control that disagrees with the customer ledger.
 */
final class StockRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;
    use \Tests\Support\Concerns\ProvesTheLedger;

    private string $locationId = '';

    private string $quarantineId = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['stock (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();
            $this->postTheOpeningEntry();
            $this->seedTheLocations();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- There is no quantity column ------------------------
                'quantity on hand is a sum over the movements' => fn (): array => $this->quantityIsDerived(),
                'and there is no column anybody could update' => fn (): array => $this->noQuantityColumn(),
                'a movement cannot be edited' => fn (): array => $this->movementsAreFacts(),
                'a movement cannot be deleted' => fn (): array => $this->movementsAreNotDeleted(),

                // ---- What can carry stock -------------------------------
                'a service cannot be stocked' => fn (): array => $this->onlyGoodsCarryStock(),
                'a stocked item declares its cost method' => fn (): array => $this->costMethodIsDeclared(),
                'an unstocked item has nothing to move' => fn (): array => $this->unstockedHasNoMovements(),

                // ---- The cost -------------------------------------------
                'issuing posts the cost of sales, not a revenue reduction' => fn (): array => $this->issuePostsCostOfSales(),
                'the weighted average is the value over the quantity' => fn (): array => $this->weightedAverage(),
                'FIFO consumes the oldest layer first' => fn (): array => $this->fifoConsumesOldest(),
                'the cost is frozen on the movement, not recomputed' => fn (): array => $this->costIsFrozen(),
                'a receipt credits goods received, not the payable' => fn (): array => $this->receiptCreditsGrni(),
                'issuing with no cost on record is refused' => fn (): array => $this->noCostIsRefused(),

                // ---- Negative stock -------------------------------------
                'negative stock is refused by the service' => fn (): array => $this->negativeIsRefused(),
                'and by the database, for the race the service cannot see' => fn (): array => $this->negativeIsRefusedInTheDatabase(),
                'an item that allows it says why' => fn (): array => $this->negativeNeedsABasis(),

                // ---- Adjustments ----------------------------------------
                'an adjustment needs a reason' => fn (): array => $this->adjustmentNeedsAReason(),
                'a shortfall is a cost and a surplus is not' => fn (): array => $this->adjustmentsGoBothWays(),
                'the sign of a movement matches its kind' => fn (): array => $this->signsMatchTheirKind(),

                // ---- Variants - PF-249 ---------------------------------
                'the axes are data, not columns' => fn (): array => $this->axesAreData(),
                'a variant inherits what it does not override' => fn (): array => $this->variantsInherit(),
                'a combination that does not exist is absent' => fn (): array => $this->combinationsAreAbsent(),
                'an axis value in use cannot be deleted' => fn (): array => $this->axisValuesWithHistoryStay(),

                // ---- Batches - PF-250 ----------------------------------
                'a tracked movement names its batch' => fn (): array => $this->trackedMovementsNameTheirBatch(),
                'first-expiry-first-out is offered, never applied' => fn (): array => $this->fefoIsOfferedNotApplied(),
                'expiry is reported before it happens' => fn (): array => $this->expiryIsReportedBefore(),
                'an expired batch needs somebody\'s name' => fn (): array => $this->expiredNeedsAName(),
                'a batch answers who has the goods' => fn (): array => $this->aBatchIsTraceable(),
                'a batch is recorded once' => fn (): array => $this->aBatchIsRecordedOnce(),

                // ---- The tie --------------------------------------------
                'the inventory account equals its movements' => fn (): array => $this->inventoryReconciles(),
                'and the check notices when it does not' => fn (): array => $this->inventoryDriftIsCaught(),
            ]));
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- There is no quantity column --------------------------------------

    /** @return list<string> */
    private function quantityIsDerived(): array
    {
        $item = $this->aStockedItem();
        $failures = [];

        $this->receive($item, '10', '4.0000');
        $this->receive($item, '5', '6.0000');

        $onHand = app(Stock::class)->onHand($this->entityId, $item, $this->locationId);

        if (! $onHand->isEqualTo('15')) {
            $failures[] = sprintf('after receiving 10 and 5 the quantity on hand is %s', (string) $onHand);
        }

        $this->issue($item, '4');

        if (! app(Stock::class)->onHand($this->entityId, $item, $this->locationId)->isEqualTo('11')) {
            $failures[] = 'issuing 4 of 15 did not leave 11';
        }

        /*
         | And a movement inserted behind the service's back counts.
         |
         | Which is the point of deriving it: an import, a correction or a
         | second till contributes to the figure without anything having
         | to remember to update a column.
         */
        DB::connection('tenant')->table('stock_movements')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'item_id' => $item,
            'location_id' => $this->locationId,
            'kind' => 'receipt',
            'quantity' => '3',
            'moved_on' => '2026-05-18',
            'moved_by' => $this->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! app(Stock::class)->onHand($this->entityId, $item, $this->locationId)->isEqualTo('14')) {
            $failures[] = 'a movement written directly at the table does not reach the quantity on hand, '.
                'so the figure is cached somewhere and the cache is the real answer';
        }

        return $failures;
    }

    /** @return list<string> */
    private function noQuantityColumn(): array
    {
        $columns = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('items');
        $failures = [];

        foreach (['quantity', 'quantity_on_hand', 'stock_on_hand', 'on_hand'] as $forbidden) {
            if (\in_array($forbidden, $columns, true)) {
                $failures[] = sprintf(
                    'items carries a "%s" column, which is a second place the truth lives - and it '.
                    'drifts the first time a movement is reversed or a job retries',
                    $forbidden,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function movementsAreFacts(): array
    {
        $item = $this->aStockedItem();
        $movement = $this->receive($item, '10', '4.0000');
        $failures = [];

        foreach ([
            ['quantity' => '99'],
            ['unit_cost' => '1.0000'],
            ['value' => '1.0000'],
            ['kind' => 'adjustment'],
            ['moved_on' => '2026-01-01'],
        ] as $change) {
            $refusal = $this->refusal(fn () => DB::connection('tenant')->table('stock_movements')
                ->where('id', $movement['id'])
                ->update($change));

            if ($refusal === null) {
                $failures[] = sprintf(
                    'a movement\'s %s can be changed - so the cost of sales posted from it is left '.
                    'describing a quantity that no longer exists, and the inventory control account '.
                    'stops agreeing with its own subsidiary record',
                    implode(' and ', array_keys($change)),
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function movementsAreNotDeleted(): array
    {
        $item = $this->aStockedItem();
        $movement = $this->receive($item, '10', '4.0000');

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('stock_movements')
            ->where('id', $movement['id'])
            ->delete());

        if ($refusal === null) {
            return ['a stock movement can be deleted, so the only record that the stock moved can be '.
                'removed while the cost of sales posted from it stays'];
        }

        return str_contains($refusal, 'adjustment that says what happened')
            ? []
            : ['the delete was refused without saying how to correct it instead: '.$refusal];
    }

    // ---- What can carry stock ---------------------------------------------

    /** @return list<string> */
    private function onlyGoodsCarryStock(): array
    {
        $refusal = $this->refusal(fn () => $this->anItem(type: 'service', stocked: true));

        if ($refusal === null) {
            return ['a service was marked stocked - which demands a movement for something that has no '.
                'units, and the first symptom is an invoice that cannot be issued'];
        }

        return [];
    }

    /** @return list<string> */
    private function costMethodIsDeclared(): array
    {
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('items')->insert([
            'id' => (string) Str::ulid(),
            'code' => 'NOMETHOD-'.Str::upper(Str::random(5)),
            'name_ar' => 'بلا طريقة',
            'name_en' => 'No method',
            'type' => 'good',
            'unit_code' => 'EA',
            'recognition' => 'immediate',
            'sellable' => true,
            'purchasable' => true,
            'active' => true,
            'stocked' => true,
            'cost_method' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $refusal === null
            ? ['a stocked item was created with no cost method, so the first issue has no defensible cost '.
                'and whichever one the code picks becomes the answer for ever']
            : [];
    }

    /** @return list<string> */
    private function unstockedHasNoMovements(): array
    {
        $item = $this->anItem(type: 'service', stocked: false);

        $refusal = $this->refusal(fn () => app(Stock::class)->receive(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '1',
            unitCost: '5.0000',
            on: $this->date->copy(),
            userId: $this->actorId(),
        ));

        if ($refusal === null) {
            return ['stock was received against an item that does not carry stock'];
        }

        return str_contains($refusal, 'does not carry stock')
            ? []
            : ['the refusal does not explain what a stocked item is: '.$refusal];
    }

    // ---- The cost ---------------------------------------------------------

    /** @return list<string> */
    private function issuePostsCostOfSales(): array
    {
        $item = $this->aStockedItem();
        $this->receive($item, '10', '4.0000');

        $before = [
            'inventory' => $this->balanceOf(ControlRole::Inventory),
            'cost' => $this->balanceOf(ControlRole::CostOfSales),
            'revenue' => $this->balanceOf(ControlRole::DeferredRevenue),
        ];

        $this->issue($item, '3');
        $failures = [];

        $inventory = $this->balanceOf(ControlRole::Inventory)->minus($before['inventory']);
        $cost = $this->balanceOf(ControlRole::CostOfSales)->minus($before['cost']);

        if (! $cost->equals(Money::of('12.0000', 'SAR'))) {
            $failures[] = sprintf(
                'issuing 3 at 4.00 put %s into cost of sales rather than 12.00',
                (string) $cost->amount,
            );
        }

        if (! $inventory->equals(Money::of('-12.0000', 'SAR'))) {
            $failures[] = sprintf(
                'the inventory account moved by %s rather than -12.00 - if it moved the other way, '.
                'selling stock increases the asset',
                (string) $inventory->amount,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function weightedAverage(): array
    {
        $item = $this->aStockedItem();

        /*
         | 10 at 4 and 10 at 6 is 200 over 20, so 5 each.
         |
         | A figure that is neither of the two input costs, which is the
         | whole point: a check using two equal costs would pass with the
         | average computed as "the last one in".
         */
        $this->receive($item, '10', '4.0000');
        $this->receive($item, '10', '6.0000');

        $movement = $this->issue($item, '4');
        $failures = [];

        if (\Brick\Math\BigDecimal::of($movement['unit_cost'])->compareTo('5.000000') !== 0) {
            $failures[] = sprintf(
                '10 at 4.00 and 10 at 6.00 gave a weighted average of %s rather than 5.00',
                (string) $movement['unit_cost'],
            );
        }

        if (! $movement['value']->equals(Money::of('-20.0000', 'SAR'))) {
            $failures[] = sprintf('issuing 4 at 5.00 valued the movement at %s', (string) $movement['value']->amount);
        }

        return $failures;
    }

    /** @return list<string> */
    private function fifoConsumesOldest(): array
    {
        $item = $this->aStockedItem(method: 'fifo');

        $this->receive($item, '10', '4.0000', on: '2026-05-10');
        $this->receive($item, '10', '6.0000', on: '2026-05-14');

        $failures = [];

        /*
         | The first 4 come out of the 4.00 layer, not at the average.
         |
         | Which is what distinguishes FIFO from weighted average at all -
         | and the fixture's two costs differ so that the two methods give
         | different answers.
         */
        $first = $this->issue($item, '4');

        if (\Brick\Math\BigDecimal::of($first['unit_cost'])->compareTo('4.000000') !== 0) {
            $failures[] = sprintf(
                'the first issue under FIFO cost %s rather than the oldest layer\'s 4.00 - at 5.00 it is '.
                'computing a weighted average and calling it FIFO',
                (string) $first['unit_cost'],
            );
        }

        /*
         | Then 8 spans the layers: 6 left at 4.00 and 2 at 6.00.
         |
         | (6 x 4 + 2 x 6) / 8 = 4.50
         */
        $second = $this->issue($item, '8');

        if (\Brick\Math\BigDecimal::of($second['unit_cost'])->compareTo('4.500000') !== 0) {
            $failures[] = sprintf(
                'an issue spanning two layers cost %s rather than 4.50, so the layers are not being '.
                'consumed in order',
                (string) $second['unit_cost'],
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function costIsFrozen(): array
    {
        $item = $this->aStockedItem();
        $this->receive($item, '10', '4.0000');
        $issue = $this->issue($item, '5');

        /*
         | The cost moves after the issue.
         |
         | A later receipt at a different price changes what the next
         | issue costs and must not touch this one: recomputing would make
         | the margin on a sale depend on when somebody asked, and a
         | reversal would reverse a different figure from the one posted.
         */
        $this->receive($item, '10', '20.0000');

        $stored = DB::connection('tenant')->table('stock_movements')
            ->where('id', $issue['id'])
            ->first();

        $failures = [];

        if (! BigDecimal::of((string) $stored->unit_cost)->isEqualTo('4.000000')) {
            $failures[] = sprintf(
                'after a later receipt at 20.00 the earlier issue reads as %s rather than 4.00, so the '.
                'cost is being recomputed and the margin on a sale depends on when somebody asks',
                (string) $stored->unit_cost,
            );
        }

        if (! BigDecimal::of((string) $stored->value)->isEqualTo('-20.0000')) {
            $failures[] = 'the issue\'s value moved with the cost';
        }

        return $failures;
    }

    /** @return list<string> */
    private function receiptCreditsGrni(): array
    {
        $item = $this->aStockedItem();
        $before = $this->balanceOf(ControlRole::GoodsReceivedNotInvoiced);
        $payableBefore = $this->balanceOf(ControlRole::AccountsPayable);

        $this->receive($item, '10', '4.0000');
        $failures = [];

        $grni = $this->balanceOf(ControlRole::GoodsReceivedNotInvoiced)->minus($before);

        if (! $grni->equals(Money::of('-40.0000', 'SAR'))) {
            $failures[] = sprintf(
                'receiving 40.00 of stock credited goods-received-not-invoiced by %s rather than 40.00',
                (string) $grni->amount,
            );
        }

        /*
         | And NOT accounts payable.
         |
         | The goods are here and the invoice is not. Crediting AP would
         | create a payable no supplier has sent us - so the creditors
         | report shows a figure nobody agrees with, and the bill posts
         | the payable a second time when it arrives.
         */
        $payable = $this->balanceOf(ControlRole::AccountsPayable)->minus($payableBefore);

        if (! $payable->isZero()) {
            $failures[] = sprintf(
                'receiving stock moved accounts payable by %s, so a payable exists that no supplier has '.
                'billed - and the bill will post it a second time',
                (string) $payable->amount,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function noCostIsRefused(): array
    {
        $item = $this->aStockedItem(allowsNegative: true);

        $refusal = $this->refusal(fn () => app(Stock::class)->issue(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '1',
            on: $this->date->copy(),
            userId: $this->actorId(),
        ));

        if ($refusal === null) {
            return ['stock was issued with no cost on record, so the cost of sale is zero and the whole '.
                'sale reports as margin'];
        }

        return str_contains($refusal, 'Receive it first')
            ? []
            : ['the refusal does not say what to do about it: '.$refusal];
    }

    // ---- Negative stock ---------------------------------------------------

    /** @return list<string> */
    private function negativeIsRefused(): array
    {
        $item = $this->aStockedItem();
        $this->receive($item, '3', '4.0000');

        $refusal = $this->refusal(fn () => $this->issue($item, '5'));

        if ($refusal === null) {
            return ['5 was issued from 3 on hand, so the quantity is negative - which makes the cost of '.
                'sales a guess, and the figure never comes back on its own'];
        }

        return str_contains($refusal, 'does not allow negative stock')
            ? []
            : ['the over-issue was refused for some other reason: '.$refusal];
    }

    /** @return list<string> */
    private function negativeIsRefusedInTheDatabase(): array
    {
        $item = $this->aStockedItem();
        $this->receive($item, '3', '4.0000');

        /*
         | Straight at the table, as a second till would.
         |
         | Two tills selling the last unit in the same second is a race
         | the service cannot see: each reads 1 on hand and each decides
         | it is allowed. The trigger takes the sum with the item row
         | locked, which serialises exactly the conflicting pair.
         */
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('stock_movements')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'item_id' => $item,
            'location_id' => $this->locationId,
            'kind' => 'issue',
            'quantity' => '-5',
            'moved_on' => '2026-05-18',
            'moved_by' => $this->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($refusal === null) {
            return ['the database accepted an issue that takes the stock negative, so two tills selling '.
                'the last unit both succeed'];
        }

        return [];
    }

    /** @return list<string> */
    private function negativeNeedsABasis(): array
    {
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('items')->insert([
            'id' => (string) Str::ulid(),
            'code' => 'NEG-'.Str::upper(Str::random(5)),
            'name_ar' => 'سالب',
            'name_en' => 'Negative',
            'type' => 'good',
            'unit_code' => 'EA',
            'recognition' => 'immediate',
            'sellable' => true,
            'purchasable' => true,
            'active' => true,
            'stocked' => true,
            'cost_method' => 'weighted_average',
            'allows_negative' => true,
            'negative_basis' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $refusal === null
            ? ['an item allows negative stock with no reason recorded - so a setting that makes the cost '.
                'of sales a guess was turned on by somebody nobody can ask about']
            : [];
    }

    // ---- Adjustments ------------------------------------------------------

    /** @return list<string> */
    private function adjustmentNeedsAReason(): array
    {
        $item = $this->aStockedItem();
        $this->receive($item, '10', '4.0000');

        $refusal = $this->refusal(fn () => app(Stock::class)->adjust(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '-2',
            reason: 'lost',
            on: $this->date->copy(),
            userId: $this->actorId(),
        ));

        if ($refusal === null) {
            return ['an adjustment was recorded with a one-word reason - and "adjustment" with no reason '.
                'is where shrinkage hides'];
        }

        /*
         | And the database refuses it too, because an import is the other
         | way an adjustment arrives.
         */
        $direct = $this->refusal(fn () => DB::connection('tenant')->table('stock_movements')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'item_id' => $item,
            'location_id' => $this->locationId,
            'kind' => 'adjustment',
            'quantity' => '-2',
            'moved_on' => '2026-05-18',
            'moved_by' => $this->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $direct === null
            ? ['the database accepted an adjustment with no reason']
            : [];
    }

    /** @return list<string> */
    private function adjustmentsGoBothWays(): array
    {
        $item = $this->aStockedItem();
        $this->receive($item, '10', '4.0000');

        $costBefore = $this->balanceOf(ControlRole::CostOfSales);

        app(Stock::class)->adjust(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '-2',
            reason: 'two units broken in the stockroom, witnessed by the shift supervisor',
            on: $this->date->copy(),
            userId: $this->actorId(),
        );

        $failures = [];
        $shortfall = $this->balanceOf(ControlRole::CostOfSales)->minus($costBefore);

        /*
         | A shortfall is a cost of trading the goods.
         |
         | Not its own "stock loss" account: that is a line nobody
         | includes in gross margin, growing quietly while the margin
         | looks correct.
         */
        if (! $shortfall->equals(Money::of('8.0000', 'SAR'))) {
            $failures[] = sprintf(
                'losing 2 units at 4.00 put %s into cost of sales rather than 8.00',
                (string) $shortfall->amount,
            );
        }

        $midpoint = $this->balanceOf(ControlRole::CostOfSales);

        app(Stock::class)->adjust(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '3',
            reason: 'three units found behind the racking during the quarterly count',
            on: $this->date->copy(),
            userId: $this->actorId(),
        );

        $surplus = $this->balanceOf(ControlRole::CostOfSales)->minus($midpoint);

        /*
         | A surplus reduces the cost, and is valued like the stock it is.
         |
         | Valuing it at zero would make a count that finds goods look
         | like a count that found nothing.
         */
        if (! $surplus->equals(Money::of('-12.0000', 'SAR'))) {
            $failures[] = sprintf(
                'finding 3 units at 4.00 moved cost of sales by %s rather than -12.00 - a surplus valued '.
                'at zero makes a count that finds goods look like one that found nothing',
                (string) $surplus->amount,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function signsMatchTheirKind(): array
    {
        $item = $this->aStockedItem();
        $failures = [];

        foreach ([
            ['receipt', '-5', 'a receipt that removes stock'],
            ['issue', '5', 'an issue that adds stock'],
            ['return_in', '-5', 'a return that removes stock'],
        ] as [$kind, $quantity, $what]) {
            $refusal = $this->refusal(fn () => DB::connection('tenant')->table('stock_movements')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'item_id' => $item,
                'location_id' => $this->locationId,
                'kind' => $kind,
                'quantity' => $quantity,
                'moved_on' => '2026-05-18',
                'moved_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            if ($refusal === null) {
                $failures[] = sprintf(
                    'the database accepted %s - which is somebody undoing a movement by entering another '.
                    'one backwards, and it makes the history unreadable',
                    $what,
                );
            }
        }

        return $failures;
    }

    // ---- The tie ----------------------------------------------------------

    /** @return list<string> */
    private function inventoryReconciles(): array
    {
        $item = $this->aStockedItem();
        $this->receive($item, '10', '4.0000');
        $this->receive($item, '10', '6.0000');
        $this->issue($item, '7');

        app(Stock::class)->adjust(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '-1',
            reason: 'one unit damaged in handling, recorded at the shift close',
            on: $this->date->copy(),
            userId: $this->actorId(),
        );

        $ledger = $this->balanceOf(ControlRole::Inventory);
        $movements = app(Stock::class)->value($this->entityId, 'SAR');

        $failures = [];

        if (! $ledger->equals($movements)) {
            $failures[] = sprintf(
                'after a receipt, a receipt, an issue and an adjustment the inventory account holds %s '.
                'and the movements add to %s',
                (string) $ledger->amount,
                (string) $movements->amount,
            );
        }

        if (app(\App\Tenant\Inventory\Verification\InventoryIsItsMovements::class)
            ->findings($this->entityId) !== []) {
            $failures[] = 'the ledger check reports a difference on a sequence that balances';
        }

        return $failures;
    }

    /** @return list<string> */
    private function inventoryDriftIsCaught(): array
    {
        $item = $this->aStockedItem();
        $this->receive($item, '10', '4.0000');

        /*
         | A movement with no posting, which is what a half-failed write
         | leaves - and the reason the two are written in one transaction.
         */
        DB::connection('tenant')->table('stock_movements')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'item_id' => $item,
            'location_id' => $this->locationId,
            'kind' => 'receipt',
            'quantity' => '5',
            'unit_cost' => '4.000000',
            'value' => '20.0000',
            'currency' => 'SAR',
            'moved_on' => '2026-05-18',
            'moved_by' => $this->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $findings = app(\App\Tenant\Inventory\Verification\InventoryIsItsMovements::class)
            ->findings($this->entityId);

        if ($findings === []) {
            return ['a movement written without its posting left the inventory account disagreeing with '.
                'the movements and the check said nothing - so the stock figure cannot be counted, and '.
                'every count from then on produces this difference plus the real variance'];
        }

        $failures = [];

        if ($findings[0]['severity'] !== 'critical') {
            $failures[] = 'a control account that does not reconcile to its own subsidiary record is '.
                'reported as advice rather than as a fault';
        }

        if (! str_contains((string) $findings[0]['consequence'], 'shrinkage')) {
            $failures[] = 'the finding does not say what the difference becomes, which is the thing that '.
                'makes somebody act on it';
        }

        return $failures;
    }


    // ---- Variants - PF-249 ------------------------------------------------

    /** @return list<string> */
    private function axesAreData(): array
    {
        $failures = [];
        $columns = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('item_variants');

        /*
         | No `size`, no `colour`, no enum of either.
         |
         | A product with a hard-coded size column cannot sell shoes and
         | coffee, and the next vertical always needs an axis nobody
         | thought of - grind, dosage, offset.
         */
        foreach (['size', 'colour', 'color', 'strength'] as $hardCoded) {
            if (\in_array($hardCoded, $columns, true)) {
                $failures[] = sprintf(
                    'item_variants carries a "%s" column, so the axes are in the schema - and the next '.
                    'vertical needs one nobody thought of',
                    $hardCoded,
                );
            }
        }

        $item = $this->aStockedItem();
        $size = $this->anAxis('SIZE', 1, ['S', 'M', 'L']);
        $colour = $this->anAxis('COLOUR', 2, ['RED', 'BLUE']);

        $variant = $this->aVariant($item, ['SIZE' => 'L', 'COLOUR' => 'RED']);

        if ($variant === null) {
            return [...$failures, 'a variant could not be created from two axes'];
        }

        /*
         | The same combination in the other key order is the same row.
         |
         | jsonb normalises key order, so "large red" and "red large" are
         | one value - and the unique index is on it, so the second insert
         | is refused rather than selling the same shoe twice.
         */
        $again = $this->refusal(fn () => $this->aVariant($item, ['COLOUR' => 'RED', 'SIZE' => 'L']));

        if ($again === null) {
            $failures[] = 'the same combination written with its keys in the other order created a second '.
                'variant, so the same shoe can be sold twice under two codes';
        }

        return $failures;
    }

    /** @return list<string> */
    private function variantsInherit(): array
    {
        $item = $this->aStockedItem();
        $this->anAxis('SIZE', 1, ['S', 'L']);

        $plain = $this->aVariant($item, ['SIZE' => 'S']);
        $dearer = $this->aVariant($item, ['SIZE' => 'L'], price: '150.00000000');

        $failures = [];

        $plainPrice = DB::connection('tenant')->table('item_variants')->where('id', $plain)->value('price_override');
        $dearerPrice = DB::connection('tenant')->table('item_variants')->where('id', $dearer)->value('price_override');

        /*
         | NULL means "whatever the item says".
         |
         | Which is the whole inheritance rule: changing the item's tax
         | code changes forty variants, and changing one variant's price
         | changes one. A variant that copied every field would be forty
         | rows to update and thirty-nine chances to miss one.
         */
        if ($plainPrice !== null) {
            $failures[] = 'a variant that overrides nothing still stores a price, so changing the item '.
                'leaves forty variants holding the old one';
        }

        if ($dearerPrice === null) {
            $failures[] = 'a variant that overrides its price did not keep the override';
        }

        return $failures;
    }

    /** @return list<string> */
    private function combinationsAreAbsent(): array
    {
        $item = $this->aStockedItem();
        $this->anAxis('SIZE', 1, ['S', 'M', 'L']);
        $this->anAxis('COLOUR', 2, ['RED', 'BLUE']);

        $this->aVariant($item, ['SIZE' => 'L', 'COLOUR' => 'RED']);
        $this->aVariant($item, ['SIZE' => 'S', 'COLOUR' => 'BLUE']);

        $count = DB::connection('tenant')->table('item_variants')
            ->where('item_id', $item)
            ->whereNull('deleted_at')
            ->count();

        /*
         | Two, not six.
         |
         | A matrix of three sizes and two colours generates six, and a
         | shop stocking two now has four rows that need stock records,
         | price lines and a reason for being inactive - and every report
         | has to remember to exclude them.
         */
        return $count === 2
            ? []
            : [sprintf(
                'three sizes and two colours produced %d variants for a shop that stocks two - the four '.
                'that do not exist would need stock records, price lines and a reason for being inactive, '.
                'and every report would have to remember to exclude them',
                $count,
            )];
    }

    /** @return list<string> */
    private function axisValuesWithHistoryStay(): array
    {
        $item = $this->aStockedItem();
        $axis = $this->anAxis('SIZE', 1, ['S', 'L']);
        $this->aVariant($item, ['SIZE' => 'L']);

        $value = DB::connection('tenant')->table('item_axis_values')
            ->where('axis_id', $axis)
            ->where('code', 'L')
            ->value('id');

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('item_axis_values')
            ->where('id', $value)
            ->delete());

        if ($refusal === null) {
            return ['an axis value in use was deleted, leaving a variant describing a combination that no '.
                'longer exists - and its stock would be stock of nothing'];
        }

        $failures = [];

        if (! str_contains($refusal, 'stock of nothing')) {
            $failures[] = 'the refusal does not say what the consequence is: '.$refusal;
        }

        /*
         | And it names what holds it.
         |
         | "Cannot delete" sends somebody looking; "1 variant is the large
         | of the size axis" tells them where to go.
         */
        if (! str_contains($refusal, 'variant(s) are the')) {
            $failures[] = 'the refusal does not name what holds the value, so somebody has to go looking: '.$refusal;
        }

        /*
         | An unused value goes.
         |
         | A check that only proved the refusal would pass with the
         | trigger refusing every delete, and then nobody could ever
         | remove a typo.
         */
        $unused = DB::connection('tenant')->table('item_axis_values')
            ->where('axis_id', $axis)
            ->where('code', 'S')
            ->value('id');

        if ($this->refusal(fn () => DB::connection('tenant')->table('item_axis_values')
            ->where('id', $unused)->delete()) !== null) {
            $failures[] = 'an axis value nothing uses cannot be deleted either, so a typo is permanent';
        }

        return $failures;
    }

    // ---- Batches - PF-250 -------------------------------------------------

    /** @return list<string> */
    private function trackedMovementsNameTheirBatch(): array
    {
        $item = $this->aTrackedItem();

        $refusal = $this->refusal(fn () => app(Stock::class)->receive(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '10',
            unitCost: '4.0000',
            on: $this->date->copy(),
            userId: $this->actorId(),
        ));

        if ($refusal === null) {
            return ['an item under batch control moved without naming a batch, which is a traceability '.
                'gap - and for food or medicine a regulatory one'];
        }

        $failures = [];

        if (! str_contains($refusal, 'who has this batch')) {
            $failures[] = 'the refusal does not say why a batch matters: '.$refusal;
        }

        /*
         | And a batch of another item is refused.
         |
         | A movement naming the wrong item's batch makes a recall return
         | the wrong customers, which is worse than returning none -
         | because somebody acts on it.
         */
        $other = $this->aTrackedItem();
        $otherBatch = $this->aBatch($other, 'OTHER-1');

        $wrong = $this->refusal(fn () => app(Stock::class)->receive(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '10',
            unitCost: '4.0000',
            on: $this->date->copy(),
            userId: $this->actorId(),
            batchId: $otherBatch,
        ));

        if ($wrong === null) {
            $failures[] = 'a movement named another item\'s batch, so a recall would return the wrong '.
                'customers - which is worse than returning none';
        }

        return $failures;
    }

    /** @return list<string> */
    private function fefoIsOfferedNotApplied(): array
    {
        $item = $this->aTrackedItem();

        $soon = $this->aBatch($item, 'SOON', expires: '2026-06-01');
        $later = $this->aBatch($item, 'LATER', expires: '2026-12-01');
        $never = $this->aBatch($item, 'NEVER', expires: null);

        $this->receiveBatch($item, $later, '10', '4.0000');
        $this->receiveBatch($item, $soon, '10', '4.0000');
        $this->receiveBatch($item, $never, '10', '4.0000');

        $before = DB::connection('tenant')->table('stock_movements')->count();
        $suggestion = app(\App\Tenant\Inventory\Services\Batches::class)
            /*
             | 25 of 30, so all three batches are needed.
             |
             | The first draft asked for 15, which SOON and LATER satisfy
             | between them - so the batch with no expiry never appeared
             | and the assertion about it being LAST was asserting
             | nothing. The check was wrong, not the ordering.
             */
            ->suggest($this->entityId, $item, $this->locationId, '25');
        $after = DB::connection('tenant')->table('stock_movements')->count();

        $failures = [];

        if ($before !== $after) {
            $failures[] = 'asking for a suggestion wrote movements, so the suggestion is an application - '.
                'and the right batch is sometimes the one already in the van';
        }

        $order = array_column($suggestion['plan'], 'number');

        if (($order[0] ?? '') !== 'SOON') {
            $failures[] = sprintf(
                'first-expiry-first-out suggested %s first rather than the batch expiring in June',
                (string) ($order[0] ?? 'nothing'),
            );
        }

        /*
         | And the batch with no expiry comes LAST.
         |
         | PostgreSQL sorts nulls first by default, which would put the
         | one batch with no deadline ahead of the one expiring on
         | Friday - the exact opposite of the rule.
         */
        if (($order[\count($order) - 1] ?? '') !== 'NEVER') {
            $failures[] = sprintf(
                'the batch with no expiry is not last in the suggestion (%s) - nulls sort first by '.
                'default, which puts the one batch with no deadline ahead of the one expiring on Friday',
                implode(', ', $order),
            );
        }

        if (! str_contains((string) $suggestion['why'], 'already in the van')) {
            $failures[] = 'the suggestion does not say it is a suggestion, so a screen would apply it';
        }

        return $failures;
    }

    /** @return list<string> */
    private function expiryIsReportedBefore(): array
    {
        $item = $this->aTrackedItem();

        $soon = $this->aBatch($item, 'SOON', expires: Carbon::now()->addDays(10)->toDateString());
        $far = $this->aBatch($item, 'FAR', expires: Carbon::now()->addDays(200)->toDateString());

        $this->receiveBatch($item, $soon, '10', '4.0000');
        $this->receiveBatch($item, $far, '10', '4.0000');

        $failures = [];
        $expiring = app(\App\Tenant\Inventory\Services\Batches::class)->expiring($this->entityId, 30);
        $numbers = array_column($expiring, 'number');

        if (! \in_array('SOON', $numbers, true)) {
            $failures[] = 'a batch expiring in ten days is not reported at a thirty-day lead time, so the '.
                'report arrives after the write-off rather than before the decision';
        }

        if (\in_array('FAR', $numbers, true)) {
            $failures[] = 'a batch expiring in two hundred days is reported at a thirty-day lead time, so '.
                'the report is everything and nobody reads it';
        }

        /*
         | And the lead time is a parameter, not a constant.
         |
         | A business selling fresh food wants seven days and one selling
         | medicine wants ninety. A constant is right for neither.
         */
        if (array_column(app(\App\Tenant\Inventory\Services\Batches::class)
            ->expiring($this->entityId, 365), 'number') === $numbers) {
            $failures[] = 'the lead time changes nothing, so it is a constant wearing a parameter\'s '.
                'clothes - and seven days is right for fresh food where ninety is right for medicine';
        }

        if ($expiring !== [] && ! str_contains((string) $expiring[0]['why'], 'still time to')) {
            $failures[] = 'the report does not say what can still be done, which is the only reason to '.
                'send it early: '.(string) $expiring[0]['why'];
        }

        return $failures;
    }

    /** @return list<string> */
    private function expiredNeedsAName(): array
    {
        $item = $this->aTrackedItem();
        /*
         | Received and expired inside the open period.
         |
         | The receipt posts on its own date, so a fixture reaching back
         | into April posts into a period nobody opened - which the ledger
         | refuses, correctly. The batch is received on the 2nd, expires
         | on the 10th, and the issue is attempted on the 18th.
         */
        $expired = $this->aBatch($item, 'OLD', expires: '2026-05-10', received: '2026-05-02');
        $this->receiveBatch($item, $expired, '10', '4.0000', on: '2026-05-02');

        $refusal = $this->refusal(fn () => app(Stock::class)->issue(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '2',
            on: Carbon::parse('2026-05-18'),
            userId: $this->actorId(),
            batchId: $expired,
        ));

        $failures = [];

        if ($refusal === null) {
            $failures[] = 'stock was issued from an expired batch with nobody\'s name on it';
        } elseif (! str_contains($refusal, 'never right silently')) {
            $failures[] = 'the refusal does not say that issuing it is sometimes right: '.$refusal;
        }

        /*
         | And with a name and a reason it goes through, recorded.
         |
         | A check that only proved the refusal would pass with the issue
         | refused outright - and then a batch re-tested and released
         | could never be sold.
         */
        $allowed = app(Stock::class)->issue(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '2',
            on: Carbon::parse('2026-05-18'),
            userId: $this->actorId(),
            batchId: $expired,
            expiryAcceptedBy: $this->secondActorId(),
            expiryAcceptedReason: 'the batch was re-tested by the laboratory on 12 May and released',
        );

        $stored = DB::connection('tenant')->table('stock_movements')
            ->where('id', $allowed['id'])
            ->first();

        if (($stored->expiry_accepted_by ?? null) === null) {
            $failures[] = 'the acknowledgement is not recorded on the movement, so who accepted the risk '.
                'is a question with no answer';
        }

        return $failures;
    }

    /** @return list<string> */
    private function aBatchIsTraceable(): array
    {
        $item = $this->aTrackedItem();
        $batch = $this->aBatch($item, 'TRACE-1', lot: 'SUP-LOT-99');
        $this->receiveBatch($item, $batch, '10', '4.0000');

        app(Stock::class)->issue(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: '3',
            on: $this->date->copy(),
            userId: $this->actorId(),
            batchId: $batch,
        );

        $trace = app(\App\Tenant\Inventory\Services\Batches::class)->traceOf($this->entityId, $batch);
        $failures = [];

        if (\count($trace['went_to']) !== 1) {
            $failures[] = sprintf(
                'the trace of a batch with one issue returned %d movement(s) - the point of batch '.
                'tracking is reaching the people who have the goods',
                \count($trace['went_to']),
            );
        }

        if (($trace['supplier_lot'] ?? null) !== 'SUP-LOT-99') {
            $failures[] = 'the trace does not carry the supplier\'s own lot, which is what a recall '.
                'notice names - ours has to be findable from theirs';
        }

        /*
         | And a receipt is not a destination.
         |
         | A trace that listed the receipt would answer "where did this
         | come from" during a recall, which is a different question from
         | the one being asked.
         */
        foreach ($trace['went_to'] as $went) {
            if (\Brick\Math\BigDecimal::of((string) $went['quantity'])->isNegative()) {
                $failures[] = 'the trace includes a receipt, so it answers where the stock came from '.
                    'rather than who has it';
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function aBatchIsRecordedOnce(): array
    {
        $item = $this->aTrackedItem();
        $this->aBatch($item, 'DUP-1');

        $refusal = $this->refusal(fn () => $this->aBatch($item, 'DUP-1'));

        if ($refusal === null) {
            return ['one batch was recorded twice, which splits its history - so a recall returns half '.
                'the customers'];
        }

        return str_contains($refusal, 'half the customers')
            ? []
            : ['the duplicate batch was refused without saying what it costs: '.$refusal];
    }

    // ---- Helpers ----------------------------------------------------------
    // ---- Variant and batch helpers ----------------------------------------

    /** @param list<string> $values */
    private function anAxis(string $code, int $position, array $values): string
    {
        $id = (string) Str::ulid();

        DB::connection('tenant')->table('item_axes')->insert([
            'id' => $id,
            'entity_id' => $this->entityId,
            'code' => $code,
            'name_ar' => $code,
            'name_en' => $code,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($values as $index => $value) {
            DB::connection('tenant')->table('item_axis_values')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => $this->entityId,
                'axis_id' => $id,
                'code' => $value,
                'name_ar' => $value,
                'name_en' => $value,
                'position' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** @param array<string, string> $axes */
    private function aVariant(string $itemId, array $axes, ?string $price = null): string
    {
        $id = (string) Str::ulid();

        DB::connection('tenant')->table('item_variants')->insert([
            'id' => $id,
            'entity_id' => $this->entityId,
            'item_id' => $itemId,
            'code' => 'V-'.Str::upper(Str::random(8)),
            'axes' => json_encode($axes, JSON_THROW_ON_ERROR),
            'price_override' => $price,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function aTrackedItem(): string
    {
        $id = $this->aStockedItem();

        DB::connection('tenant')->table('items')->where('id', $id)->update(['batch_tracked' => true]);

        return $id;
    }

    private function aBatch(
        string $itemId,
        string $number,
        ?string $expires = '2026-12-31',
        ?string $lot = null,
        string $received = '2026-05-01',
    ): string {
        return (string) app(\App\Tenant\Inventory\Services\Batches::class)->record(
            entityId: $this->entityId,
            itemId: $itemId,
            number: $number,
            receivedOn: Carbon::parse($received),
            expiresOn: $expires === null ? null : Carbon::parse($expires),
            userId: $this->actorId(),
            supplierLot: $lot,
        )['id'];
    }

    /** @return array<string, mixed> */
    private function receiveBatch(
        string $item,
        string $batch,
        string $quantity,
        string $cost,
        string $on = '2026-05-18',
    ): array {
        return app(Stock::class)->receive(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: $quantity,
            unitCost: $cost,
            on: Carbon::parse($on),
            userId: $this->actorId(),
            batchId: $batch,
        );
    }

    private function seedTheLocations(): void
    {
        $this->locationId = (string) Str::ulid();
        $this->quarantineId = (string) Str::ulid();

        foreach ([
            [$this->locationId, 'MAIN', 'المستودع الرئيسي', 'Main warehouse', true],
            [$this->quarantineId, 'QUAR', 'الحجر', 'Quarantine', false],
        ] as [$id, $code, $ar, $en, $sellable]) {
            DB::connection('tenant')->table('stock_locations')->insert([
                'id' => $id,
                'entity_id' => $this->entityId,
                'code' => $code,
                'name_ar' => $ar,
                'name_en' => $en,
                'sellable' => $sellable,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function aStockedItem(string $method = 'weighted_average', bool $allowsNegative = false): string
    {
        return $this->anItem(
            type: 'good',
            stocked: true,
            method: $method,
            allowsNegative: $allowsNegative,
        );
    }

    private function anItem(
        string $type,
        bool $stocked,
        string $method = 'weighted_average',
        bool $allowsNegative = false,
    ): string {
        $id = (string) Str::ulid();

        DB::connection('tenant')->table('items')->insert([
            'id' => $id,
            'code' => 'STK-'.Str::upper(Str::random(8)),
            'name_ar' => 'صنف مخزون',
            'name_en' => 'Stocked item',
            'type' => $type,
            'unit_code' => 'EA',
            'recognition' => 'immediate',
            'sellable' => true,
            'purchasable' => true,
            'active' => true,
            'stocked' => $stocked,
            'cost_method' => $stocked ? $method : null,
            'allows_negative' => $allowsNegative,
            'negative_basis' => $allowsNegative
                ? 'the bar pours against a delivery that is booked the following morning'
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function receive(string $item, string $quantity, string $cost, string $on = '2026-05-18'): array
    {
        return app(Stock::class)->receive(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: $quantity,
            unitCost: $cost,
            on: Carbon::parse($on),
            userId: $this->actorId(),
        );
    }

    /** @return array<string, mixed> */
    private function issue(string $item, string $quantity, string $on = '2026-05-18'): array
    {
        return app(Stock::class)->issue(
            entityId: $this->entityId,
            itemId: $item,
            locationId: $this->locationId,
            quantity: $quantity,
            on: Carbon::parse($on),
            userId: $this->actorId(),
        );
    }

    private function accountFor(ControlRole $role): string
    {
        return (string) DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', $role->value)
            ->value('account_id');
    }

    private function balanceOf(ControlRole $role): Money
    {
        $row = DB::connection('tenant')->selectOne(
            'SELECT coalesce(sum(functional_amount), 0)::text AS balance '.
            'FROM journal_lines WHERE entity_id = ? AND account_id = ?',
            [$this->entityId, $this->accountFor($role)],
        );

        return Money::of((string) ($row->balance ?? '0'), 'SAR');
    }
}
