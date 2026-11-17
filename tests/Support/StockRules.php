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

    // ---- Helpers ----------------------------------------------------------

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
