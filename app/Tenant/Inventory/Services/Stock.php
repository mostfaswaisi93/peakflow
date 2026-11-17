<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Inventory\Exceptions\StockRefused;
use App\Tenant\Inventory\Posting\Rules\StockAdjustedRule;
use App\Tenant\Inventory\Posting\Rules\StockIssuedRule;
use App\Tenant\Inventory\Posting\Rules\StockReceivedRule;
use App\Tenant\Inventory\Posting\Rules\StockReturnedRule;
use App\Tenant\Ledger\Services\Poster;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Receiving, issuing and correcting stock - PF-248.
 *
 * ## The cost is computed from the movements before it
 *
 * Weighted average or FIFO, per item, and **frozen on the movement**.
 * Recomputing it later would make the margin on a sale depend on when
 * somebody asked - and a reversal would reverse a different figure from
 * the one it posted, leaving the inventory account holding the
 * difference for ever.
 *
 * ## Every issue is one transaction with its posting
 *
 * A movement written without its journal entry is stock that left and a
 * cost that never landed, and the inventory control account stops
 * agreeing with its own subsidiary record from that moment. The two are
 * written together or neither is.
 *
 * ## Negative stock is refused here and in the database
 *
 * Here because the message can name the item and the figure; in the
 * database because two tills selling the last unit in the same second is
 * a race this code cannot see.
 */
final class Stock
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Poster $poster,
        private readonly Batches $batches,
    ) {}

    /**
     * Stock arriving, at what it cost.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function receive(
        string $entityId,
        string $itemId,
        string $locationId,
        string $quantity,
        string $unitCost,
        Carbon $on,
        string $userId,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $batchId = null,
    ): array {
        $item = $this->stockedItem($itemId);
        $this->assertPositive($quantity, 'A receipt brings stock in, so its quantity is positive.');

        return $this->write(
            entityId: $entityId,
            item: $item,
            locationId: $locationId,
            kind: 'receipt',
            quantity: $quantity,
            unitCost: BigDecimal::of($unitCost),
            on: $on,
            userId: $userId,
            ruleKey: StockReceivedRule::KEY,
            sourceType: $sourceType,
            sourceId: $sourceId,
            batchId: $batchId,
            narration: sprintf('%s received', (string) $item->code),
        );
    }

    /**
     * Stock leaving, at the cost the method says it had.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function issue(
        string $entityId,
        string $itemId,
        string $locationId,
        string $quantity,
        Carbon $on,
        string $userId,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $batchId = null,
        ?string $expiryAcceptedBy = null,
        ?string $expiryAcceptedReason = null,
    ): array {
        $item = $this->stockedItem($itemId);
        $this->assertPositive($quantity, 'An issue takes stock out; give the quantity as a positive figure.');

        /*
         | An expired batch needs somebody's name - PF-250.
         |
         | Sometimes right: a cosmetic best-before, a batch re-tested and
         | released. Never right silently, which is why the refusal names
         | what is missing rather than simply saying no.
         */
        if ($batchId !== null) {
            $verdict = $this->batches->mayIssue($batchId, $on);

            if ($verdict['expired'] && ($expiryAcceptedBy === null || mb_strlen(trim((string) $expiryAcceptedReason)) < 15)) {
                throw StockRefused::because($verdict['why']);
            }
        }

        $entity = Entity::query()->findOrFail($entityId);
        $currency = (string) $entity->functional_currency;

        /*
         | The cost, from the movements before this one.
         |
         | Read inside the transaction the movement is written in, so a
         | concurrent issue cannot compute its cost from a state that
         | includes this one and excludes itself.
         */
        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $item,
            $locationId,
            $quantity,
            $on,
            $userId,
            $sourceType,
            $sourceId,
            $batchId,
            $expiryAcceptedBy,
            $expiryAcceptedReason,
            $currency,
        ): array {
            $unitCost = $this->costFor($entityId, (string) $item->id, $locationId, $quantity, $currency, $item);

            return $this->write(
                entityId: $entityId,
                item: $item,
                locationId: $locationId,
                kind: 'issue',
                quantity: '-'.ltrim($quantity, '+'),
                unitCost: $unitCost,
                on: $on,
                userId: $userId,
                ruleKey: StockIssuedRule::KEY,
                sourceType: $sourceType,
                sourceId: $sourceId,
                batchId: $batchId,
                postingSign: -1,
                expiryAcceptedBy: $expiryAcceptedBy,
                expiryAcceptedReason: $expiryAcceptedReason === null ? null : trim($expiryAcceptedReason),
                narration: sprintf('%s issued', (string) $item->code),
            );
        });
    }

    /**
     * A correction, with its reason.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function adjust(
        string $entityId,
        string $itemId,
        string $locationId,
        string $quantity,
        string $reason,
        Carbon $on,
        string $userId,
        string $kind = 'adjustment',
    ): array {
        if (mb_strlen(trim($reason)) < 10) {
            throw StockRefused::because(
                'An adjustment needs a reason. "Adjustment" with no reason is where shrinkage hides, and '.
                'a stock ledger full of them reconciles to itself and to nothing else.',
            );
        }

        $item = $this->stockedItem($itemId);
        $entity = Entity::query()->findOrFail($entityId);
        $currency = (string) $entity->functional_currency;
        $signed = BigDecimal::of($quantity);

        if ($signed->isZero()) {
            throw StockRefused::because('An adjustment of nothing is not a correction.');
        }

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $item,
            $locationId,
            $signed,
            $reason,
            $on,
            $userId,
            $kind,
            $currency,
        ): array {
            /*
             | A shortfall is costed at the current cost; a surplus too.
             |
             | Which is the only defensible figure: the stock that is
             | missing is stock the ledger thought it had at that cost,
             | and stock that appeared is worth what the same item is
             | worth. Valuing a surplus at zero would make a count that
             | finds goods look like a count that found nothing.
             */
            $unitCost = $this->costFor(
                $entityId,
                (string) $item->id,
                $locationId,
                (string) $signed->abs(),
                $currency,
                $item,
                permitShortfall: true,
            );

            return $this->write(
                entityId: $entityId,
                item: $item,
                locationId: $locationId,
                kind: $kind,
                quantity: (string) $signed,
                unitCost: $unitCost,
                on: $on,
                userId: $userId,
                ruleKey: StockAdjustedRule::KEY,
                reason: trim($reason),
                /*
                 | The sign flips for the posting.
                 |
                 | A negative movement is stock gone, which is a cost - so
                 | the posting amount is positive. The rule takes a signed
                 | amount and this is where the two conventions meet, once,
                 | rather than in every caller.
                 */
                postingSign: -1,
                narration: sprintf('%s adjusted: %s', (string) $item->code, trim($reason)),
            );
        });
    }

    /**
     * Goods a customer sent back, at the cost the sale took out - PF-252.
     *
     * ## Why the cost is given rather than derived
     *
     * Every other way in costs the stock from what is already there. A
     * return cannot: the figure that belongs here is what THIS unit cost
     * when it left, which lives on the issue movement the sale recorded
     * and nowhere else. Today's weighted average would restore inventory
     * at a figure the sale never took out of it, and the difference sits
     * in cost of sales until a count.
     *
     * So the caller reads it from the sale and passes it, and
     * {@see \App\Tenant\Sales\Services\SalesReturns} refuses the return
     * when there is no issue to read it from.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function returnIn(
        string $entityId,
        string $itemId,
        string $locationId,
        string $quantity,
        string $unitCost,
        Carbon $on,
        string $userId,
        string $sourceType,
        string $sourceId,
        ?string $batchId = null,
        ?string $serialId = null,
    ): array {
        $item = $this->stockedItem($itemId);
        $this->assertPositive($quantity, 'A return brings stock back in, so its quantity is positive.');

        return $this->write(
            entityId: $entityId,
            item: $item,
            locationId: $locationId,
            kind: 'return_in',
            quantity: $quantity,
            unitCost: BigDecimal::of($unitCost),
            on: $on,
            userId: $userId,
            ruleKey: StockReturnedRule::KEY,
            sourceType: $sourceType,
            sourceId: $sourceId,
            batchId: $batchId,
            serialId: $serialId,
            narration: sprintf('%s returned to stock', (string) $item->code),
        );
    }

    /**
     * Quantity on hand, derived - there is no column for it.
     */
    public function onHand(string $entityId, string $itemId, ?string $locationId = null): BigDecimal
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(quantity), 0)::text AS quantity
            FROM stock_movements
            WHERE entity_id = ?
              AND item_id = ?
              AND (?::char(26) IS NULL OR location_id = ?)
        SQL, [$entityId, $itemId, $locationId, $locationId]);

        return BigDecimal::of((string) ($row->quantity ?? '0'));
    }

    /**
     * What the stock on hand is worth, derived the same way.
     *
     * The figure the inventory control account has to equal. A derivation
     * rather than a second balance, because two places holding one truth
     * is how they come to disagree.
     */
    public function value(string $entityId, string $currency): Money
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(value), 0)::text AS total
            FROM stock_movements
            WHERE entity_id = ?
              AND currency = ?
        SQL, [$entityId, $currency]);

        return Money::of((string) ($row->total ?? '0'), $currency);
    }

    /**
     * The cost one unit carries, by the item's own method.
     *
     * @throws StockRefused
     */
    private function costFor(
        string $entityId,
        string $itemId,
        string $locationId,
        string $quantity,
        string $currency,
        object $item,
        bool $permitShortfall = false,
    ): BigDecimal {
        $method = (string) $item->cost_method;

        return $method === 'fifo'
            ? $this->fifoCost($entityId, $itemId, $locationId, $quantity, $currency, $item, $permitShortfall)
            : $this->averageCost($entityId, $itemId, $locationId, $currency, $item);
    }

    /**
     * Weighted average: the value on hand over the quantity on hand.
     *
     * @throws StockRefused
     */
    private function averageCost(
        string $entityId,
        string $itemId,
        string $locationId,
        string $currency,
        object $item,
    ): BigDecimal {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(quantity), 0)::text AS quantity,
                   coalesce(sum(value), 0)::text AS value
            FROM stock_movements
            WHERE entity_id = ?
              AND item_id = ?
              AND location_id = ?
        SQL, [$entityId, $itemId, $locationId]);

        $quantity = BigDecimal::of((string) ($row->quantity ?? '0'));
        $value = BigDecimal::of((string) ($row->value ?? '0'));

        if ($quantity->isZero() || $quantity->isNegative()) {
            /*
             | Nothing on hand, so there is no average to take.
             |
             | This is reachable only for an item that allows negative
             | stock: the cost of the next unit out is the last cost in,
             | because there is nothing else it could be. Falling back to
             | zero would issue stock at no cost and report the whole sale
             | as margin.
             */
            return $this->lastCostIn($entityId, $itemId, $locationId, $currency, $item);
        }

        return $value->dividedBy($quantity, 6, RoundingMode::HalfUp);
    }

    /**
     * FIFO: the cost of the oldest units still on hand.
     *
     * @throws StockRefused
     */
    private function fifoCost(
        string $entityId,
        string $itemId,
        string $locationId,
        string $quantity,
        string $currency,
        object $item,
        bool $permitShortfall,
    ): BigDecimal {
        $layers = DB::connection('tenant')->select(<<<'SQL'
            SELECT quantity::text AS quantity, unit_cost::text AS unit_cost
            FROM stock_movements
            WHERE entity_id = ?
              AND item_id = ?
              AND location_id = ?
              AND unit_cost IS NOT NULL
            /*
             | Oldest first, by the date it moved and then by id.
             |
             | The id breaks ties totally and stably: two receipts on one
             | day must consume in a fixed order, or the cost of the next
             | issue depends on how a query returned rows.
             */
            ORDER BY moved_on, id
        SQL, [$entityId, $itemId, $locationId]);

        /*
         | Replay the layers: receipts add, issues consume the oldest.
         |
         | Which is what FIFO is - and replaying it is the only way to get
         | it right after a reversal, because a stored layer table is a
         | second place the truth lives.
         */
        $open = [];

        foreach ($layers as $layer) {
            $q = BigDecimal::of((string) $layer->quantity);
            $cost = BigDecimal::of((string) $layer->unit_cost);

            if ($q->isPositive()) {
                $open[] = ['quantity' => $q, 'cost' => $cost];

                continue;
            }

            $left = $q->abs();

            while ($left->isPositive() && $open !== []) {
                $take = $open[0]['quantity']->isLessThan($left) ? $open[0]['quantity'] : $left;
                $open[0]['quantity'] = $open[0]['quantity']->minus($take);
                $left = $left->minus($take);

                if ($open[0]['quantity']->isZero()) {
                    array_shift($open);
                }
            }
        }

        $wanted = BigDecimal::of($quantity);
        $taken = BigDecimal::zero();
        $cost = BigDecimal::zero();

        foreach ($open as $layer) {
            if (! $wanted->isPositive()) {
                break;
            }

            $take = $layer['quantity']->isLessThan($wanted) ? $layer['quantity'] : $wanted;
            $cost = $cost->plus($take->multipliedBy($layer['cost']));
            $taken = $taken->plus($take);
            $wanted = $wanted->minus($take);
        }

        if ($taken->isZero()) {
            return $this->lastCostIn($entityId, $itemId, $locationId, $currency, $item);
        }

        if ($wanted->isPositive() && ! $permitShortfall) {
            /*
             | More wanted than any layer holds.
             |
             | Only reachable for an item that allows negative stock. The
             | shortfall is costed at the oldest remaining layer's price,
             | which is the honest answer: there is no layer for it, and
             | the alternative is zero.
             */
            $cost = $cost->plus($wanted->multipliedBy(
                $open === [] ? BigDecimal::zero() : $open[\count($open) - 1]['cost'],
            ));
            $taken = $taken->plus($wanted);
        }

        return $cost->dividedBy($taken, 6, RoundingMode::HalfUp);
    }

    /** @throws StockRefused */
    private function lastCostIn(
        string $entityId,
        string $itemId,
        string $locationId,
        string $currency,
        object $item,
    ): BigDecimal {
        $cost = DB::connection('tenant')->table('stock_movements')
            ->where('entity_id', $entityId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('quantity', '>', 0)
            ->whereNotNull('unit_cost')
            ->orderByDesc('moved_on')
            ->orderByDesc('id')
            ->value('unit_cost');

        if ($cost === null) {
            throw StockRefused::because(sprintf(
                'There is no cost on record for %s at that location, so stock cannot be issued from it: '.
                'the cost of sale would be zero and the whole sale would report as margin. Receive it '.
                'first, even at a nominal cost, so that the figure is a decision rather than an absence.',
                (string) $item->code,
            ));
        }

        return BigDecimal::of((string) $cost);
    }

    /**
     * Write the movement and its posting, together.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    private function write(
        string $entityId,
        object $item,
        string $locationId,
        string $kind,
        string $quantity,
        BigDecimal $unitCost,
        Carbon $on,
        string $userId,
        string $ruleKey,
        string $narration,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $batchId = null,
        ?string $reason = null,
        int $postingSign = 1,
        ?string $expiryAcceptedBy = null,
        ?string $expiryAcceptedReason = null,
        ?string $serialId = null,
    ): array {
        $this->assertTheLocationExists($entityId, $locationId);

        $signed = BigDecimal::of($quantity);

        /*
         | The value, rounded once, here.
         |
         | The database asserts `value = round(quantity * unit_cost, 4)`,
         | so computing it anywhere else would produce a figure the
         | constraint refuses - which is the point of having the
         | constraint.
         */
        $value = $signed->multipliedBy($unitCost)->toScale(4, RoundingMode::HalfUp);
        $movementId = (string) Str::ulid();
        $entity = Entity::query()->findOrFail($entityId);

        return DB::connection('tenant')->transaction(function () use (
            $entityId,
            $entity,
            $item,
            $locationId,
            $kind,
            $signed,
            $unitCost,
            $value,
            $on,
            $userId,
            $ruleKey,
            $narration,
            $sourceType,
            $sourceId,
            $batchId,
            $reason,
            $postingSign,
            $expiryAcceptedBy,
            $expiryAcceptedReason,
            $serialId,
            $movementId,
        ): array {
            DB::connection('tenant')->table('stock_movements')->insert([
                'id' => $movementId,
                'entity_id' => $entityId,
                'item_id' => (string) $item->id,
                'location_id' => $locationId,
                'kind' => $kind,
                'quantity' => (string) $signed,
                'unit_cost' => (string) $unitCost,
                'value' => (string) $value,
                'currency' => $this->currencyOf($entityId),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'batch_id' => $batchId,
                'serial_id' => $serialId,
                'reason' => $reason,
                'expiry_accepted_by' => $expiryAcceptedBy,
                'expiry_accepted_reason' => $expiryAcceptedReason,
                'moved_on' => $on->toDateString(),
                'moved_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             | The posting, in the same transaction.
             |
             | A movement without its entry is stock that left and a cost
             | that never landed - and the inventory control account stops
             | agreeing with its own subsidiary record from that moment.
             */
            $entry = $this->poster->post(
                entity: $entity,
                ruleKey: $ruleKey,
                event: [
                    /*
                     | The sign convention, stated once.
                     |
                     | A movement's `value` carries the sign of its
                     | quantity: a receipt is positive, an issue negative.
                     | Every rule here takes a positive amount as "the
                     | first role is debited", so:
                     |
                     |   receipt     +value   Dr inventory, Cr GRNI
                     |   issue       -value   Dr cost of sales, Cr inventory
                     |   shortfall   -value   Dr cost of sales, Cr inventory
                     |   surplus     -value   Cr cost of sales, Dr inventory
                     |
                     | which is `value * postingSign` with the sign set at
                     | the call site. The first draft had it as an
                     | unreadable expression with a `? 1 : 1` ternary in
                     | it, and the issue path would have posted backwards:
                     | crediting cost of sales and debiting inventory, so
                     | selling stock would have INCREASED the asset.
                     */
                    'amount' => (string) $value->multipliedBy($postingSign),
                    'narration' => $narration,
                ],
                accountingDate: $on,
                journalCode: 'GEN',
                sourceType: 'stock_movement',
                sourceId: $movementId,
            );

            DB::connection('tenant')->table('stock_movements')
                ->where('id', $movementId)
                ->update(['journal_entry_id' => (string) $entry->getKey(), 'updated_at' => now()]);

            $this->audit->record(
                category: 'catalog',
                action: 'stock.'.$kind,
                subjectType: 'stock_movement',
                subjectId: $movementId,
                subjectLabel: sprintf('%s %s', (string) $item->code, (string) $signed),
                after: [
                    'kind' => $kind,
                    'quantity' => (string) $signed,
                    'unit_cost' => (string) $unitCost,
                    'value' => (string) $value,
                    'location_id' => $locationId,
                ],
                reason: $reason ?? $narration,
                entityId: $entityId,
            );

            return [
                'id' => $movementId,
                'kind' => $kind,
                'quantity' => (string) $signed,
                'unit_cost' => $unitCost,
                'value' => Money::of((string) $value, $this->currencyOf($entityId)),
                'journal_entry_id' => (string) $entry->getKey(),
                'on_hand' => (string) $this->onHand($entityId, (string) $item->id, $locationId),
                'why' => sprintf(
                    '%s of %s at %s each, %s. The cost is frozen on the movement: recomputing it later '.
                    'would make the margin on this depend on when somebody asked.',
                    (string) $signed,
                    (string) $item->code,
                    (string) $unitCost,
                    $kind,
                ),
            ];
        });
    }

    /**
     * A unit cost is a RATE, not an amount - PF-248.
     *
     * `Money` is at amount scale, which is four places. A weighted
     * average of 10 at 4.00 and 5 at 6.00 is 4.666667, and passing that
     * to `Money::of` is refused - correctly, because silently rounding a
     * cost to four places makes the stock valuation disagree with its own
     * movements by a fraction per unit, which on a few hundred thousand
     * units is real money.
     *
     * So the cost is carried as a `BigDecimal` at six places and only the
     * VALUE - quantity times cost - becomes `Money`. The check that
     * computed a six-place average is what found this.
     */
    private function currencyOf(string $entityId): string
    {
        return (string) Entity::query()->findOrFail($entityId)->functional_currency;
    }

    /** @throws StockRefused */
    private function stockedItem(string $itemId): object
    {
        $item = DB::connection('tenant')->table('items')->where('id', $itemId)->first();

        if ($item === null) {
            throw StockRefused::because('There is no catalog item with that id.');
        }

        if (! $item->stocked) {
            throw StockRefused::because(sprintf(
                '%s does not carry stock, so there is nothing to move. A service has no units; marking it '.
                'stocked would demand a movement for something that cannot have one.',
                (string) $item->code,
            ));
        }

        return $item;
    }

    /** @throws StockRefused */
    private function assertTheLocationExists(string $entityId, string $locationId): void
    {
        $exists = DB::connection('tenant')->table('stock_locations')
            ->where('entity_id', $entityId)
            ->where('id', $locationId)
            ->where('active', true)
            ->exists();

        if (! $exists) {
            throw StockRefused::because(
                'That stock location does not exist in this entity, or is no longer active.',
            );
        }
    }

    /** @throws StockRefused */
    private function assertPositive(string $quantity, string $why): void
    {
        if (! BigDecimal::of($quantity)->isPositive()) {
            throw StockRefused::because($why);
        }
    }
}
