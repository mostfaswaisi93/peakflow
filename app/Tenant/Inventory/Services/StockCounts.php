<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Inventory\Exceptions\StockRefused;
use App\Tenant\Ledger\Services\NumberAllocator;
use App\Tenant\Tenancy\Models\Entity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Counting a warehouse that is still working - PF-253.
 *
 * ## The sheet does not carry the expected quantity
 *
 * {@see sheet()} returns what to count and where, and no figure. That is
 * the point of the whole task: a counter who knows the system says 40
 * writes 40, and the count becomes a transcription of the thing it was
 * meant to check. It is not dishonesty - finding 38 and writing 40 feels
 * like correcting a miscount - and the shelf is never recounted.
 *
 * The method cannot leak it by accident either, because the column it
 * would come from does not hold anything yet: `expected_quantity` is
 * written by {@see enter()}, in the same statement as the counted
 * figure, and a constraint refuses it on a line that has not been
 * counted.
 *
 * ## Nothing is frozen and nothing is blocked
 *
 * Goods move while counters walk, because stopping a business to count
 * is how counts stop happening. Every movement recorded while a count is
 * open is tagged with it, so the variance on a line whose goods moved
 * between the counter looking and the keying is explained by a list
 * rather than by arithmetic.
 *
 * Each line is measured against what the system believed when the line
 * was ENTERED, not when the count opened - so a delivery that arrived
 * mid-count is already in the expected figure and there is nothing to
 * reconcile by hand. What remains is the residue of a live warehouse,
 * and the product's job is to put the movements in front of the person
 * deciding rather than to pretend the shelves held still.
 *
 * ## Posting is one adjustment per line
 *
 * With the line's own reason, because one total write-off tells nobody
 * which items go missing. "Shrinkage, 4,200" is approved once a quarter
 * and acted on never; four lines each naming an item is a list somebody
 * can do something about.
 */
final class StockCounts
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly Stock $stock,
        private readonly OpenCount $openCount,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Open a count over a location.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function open(
        string $entityId,
        string $locationId,
        string $reason,
        Carbon $on,
        string $userId,
    ): array {
        if (mb_strlen(trim($reason)) < 10) {
            throw StockRefused::because(
                'A count needs its reason in a sentence. A cycle count, a year-end count and a count '.
                'because one margin looks wrong are read differently a year later, and "count" tells '.
                'nobody which it was.',
            );
        }

        $location = DB::connection('tenant')->table('stock_locations')
            ->where('id', $locationId)
            ->where('entity_id', $entityId)
            ->first();

        if ($location === null) {
            throw StockRefused::because('There is no stock location with that id to count.');
        }

        /*
         | Through the shared lookup, so that the count this refuses is
         | the same one `Stock` tags movements with.
         |
         | The unique index refuses it too - two counts over one shelf
         | produce two expected figures for the same goods and two
         | adjustments for one difference. This is here for the sentence.
         */
        $openId = $this->openCount->over($entityId, $locationId);

        if ($openId !== null) {
            $open = DB::connection('tenant')->table('stock_counts')->where('id', $openId)->first();

            throw StockRefused::because(sprintf(
                'Count %s is already open over %s. Two counts over one shelf produce two expected '.
                'figures for the same goods and two adjustments for one difference, and the stock ends '.
                'up wrong by exactly the variance.',
                (string) $open?->number,
                (string) $location->code,
            ));
        }

        $entity = Entity::query()->findOrFail($entityId);
        $currency = (string) $entity->functional_currency;

        $id = (string) Str::ulid();
        $number = $this->numbers->next($entityId, 'stock_count', 'CNT', $on);

        DB::connection('tenant')->table('stock_counts')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'number' => $number,
            'location_id' => $locationId,
            'state' => 'counting',
            'reason' => trim($reason),
            'opened_at' => now(),
            'opened_by' => $userId,
            /*
             | The threshold is COPIED, not read at approval time.
             |
             | A count approved under last year's materiality has to stay
             | explainable under it; reading configuration later would
             | make an old count's approval look wrong the day somebody
             | changes the number.
             */
            'materiality' => $this->materialityFor($currency),
            'currency' => $currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'inventory',
            action: 'count.opened',
            subjectType: 'stock_count',
            subjectId: $id,
            subjectLabel: sprintf('%s over %s', $number, (string) $location->code),
            after: ['location' => (string) $location->code, 'state' => 'counting'],
            reason: trim($reason),
            entityId: $entityId,
        );

        return ['id' => $id, 'number' => $number, 'state' => 'counting'];
    }

    /**
     * What to count, and no figure to count against.
     *
     * Every item the location has ever held, including the ones the
     * system says are at zero - a shelf the system believes is empty is
     * exactly where unrecorded stock sits, and a sheet that left those
     * lines out could never find it.
     *
     * @return list<array<string, mixed>>
     *
     * @throws StockRefused
     */
    public function sheet(string $countId): array
    {
        $count = $this->countOr404($countId);

        if (! \in_array((string) $count->state, ['draft', 'counting'], true)) {
            throw StockRefused::because(sprintf(
                'Count %s is %s, so its sheet is no longer what anybody is counting from.',
                (string) $count->number,
                (string) $count->state,
            ));
        }

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT i.id AS item_id,
                   i.code,
                   i.name_ar,
                   i.name_en,
                   i.unit_code,
                   m.batch_id,
                   b.number AS batch_number,
                   l.counted_quantity IS NOT NULL AS already_counted
            FROM stock_movements m
            JOIN items i ON i.id = m.item_id
            LEFT JOIN stock_batches b ON b.id = m.batch_id
            LEFT JOIN stock_count_lines l
                   ON l.count_id = ?
                  AND l.item_id = m.item_id
                  AND l.batch_id IS NOT DISTINCT FROM m.batch_id
            WHERE m.entity_id = ?
              AND m.location_id = ?
            /*
             | Grouped, not summed.
             |
             | There is deliberately no quantity in this result. The
             | aggregate is here to collapse the movements into one row
             | per item and batch, and the moment somebody adds
             | `sum(m.quantity)` to it this method stops being a count
             | sheet and becomes a transcription exercise.
             */
            GROUP BY i.id, i.code, i.name_ar, i.name_en, i.unit_code,
                     m.batch_id, b.number, l.counted_quantity
            ORDER BY i.code, b.number NULLS FIRST
        SQL, [$countId, (string) $count->entity_id, (string) $count->location_id]);

        return array_map(static fn (object $row): array => [
            'item_id' => (string) $row->item_id,
            'code' => (string) $row->code,
            'name_ar' => (string) $row->name_ar,
            'name_en' => (string) $row->name_en,
            'unit' => (string) $row->unit_code,
            'batch_id' => $row->batch_id === null ? null : (string) $row->batch_id,
            'batch' => $row->batch_number === null ? null : (string) $row->batch_number,
            'counted' => (bool) $row->already_counted,
        ], $rows);
    }

    /**
     * Enter what was found. The expected figure is written here, with it.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function enter(
        string $countId,
        string $itemId,
        string $quantity,
        string $userId,
        ?string $reason = null,
        ?string $batchId = null,
    ): array {
        $count = $this->countOr404($countId);

        if ((string) $count->state !== 'counting') {
            throw StockRefused::because(sprintf(
                'Count %s is %s. A figure can only be entered while it is being counted.',
                (string) $count->number,
                (string) $count->state,
            ));
        }

        $counted = BigDecimal::of($quantity);

        if ($counted->isNegative()) {
            throw StockRefused::because(
                'A shelf does not hold a negative quantity. The variance may be negative, and that is '.
                'what a count is for - but what was found is a number of things somebody looked at.',
            );
        }

        $entityId = (string) $count->entity_id;
        $currency = (string) $count->currency;

        /*
         | The expected figure, read NOW rather than when the count
         | opened - PF-253.
         |
         | So the two deliveries that arrived while the counter walked
         | are already in it, and the line does not report a variance
         | that is really a delivery.
         */
        $expected = $this->expectedAt($entityId, (string) $count->location_id, $itemId, $batchId);
        $variance = $counted->minus($expected);

        if (! $variance->isZero() && mb_strlen(trim((string) $reason)) < 10) {
            throw StockRefused::because(sprintf(
                'The shelf holds %s and the system says %s, so this line needs its reason - per line, '.
                'because one total write-off tells nobody which items go missing.',
                (string) $counted,
                (string) $expected,
            ));
        }

        /*
         | Valued at what the shelf's stock costs, for the quantity the
         | variance is about.
         |
         | The absolute figure, because FIFO is asked "what do N units
         | cost" and a negative N is not a question it can answer. The
         | sign belongs to the variance, not to the cost.
         */
        $unitCost = $this->stock->costOnRecord(
            $entityId,
            $itemId,
            (string) $count->location_id,
            (string) $variance->abs(),
            $currency,
        );
        $value = $variance->multipliedBy($unitCost)->toScale(4, RoundingMode::HalfUp);

        return DB::connection('tenant')->transaction(function () use (
            $countId, $entityId, $itemId, $batchId, $counted, $expected, $variance,
            $unitCost, $value, $reason, $userId, $count
        ): array {
            $existing = DB::connection('tenant')->table('stock_count_lines')
                ->where('count_id', $countId)
                ->where('item_id', $itemId)
                ->where(function ($q) use ($batchId): void {
                    $batchId === null ? $q->whereNull('batch_id') : $q->where('batch_id', $batchId);
                })
                ->first();

            $row = [
                'counted_quantity' => (string) $counted,
                'expected_quantity' => (string) $expected,
                'variance_quantity' => (string) $variance,
                'unit_cost' => (string) $unitCost,
                'variance_value' => (string) $value,
                'reason' => $reason === null ? null : trim($reason),
                'counted_at' => now(),
                'counted_by' => $userId,
                'updated_at' => now(),
            ];

            if ($existing === null) {
                DB::connection('tenant')->table('stock_count_lines')->insert(array_merge($row, [
                    'id' => (string) Str::ulid(),
                    'entity_id' => $entityId,
                    'count_id' => $countId,
                    'item_id' => $itemId,
                    'batch_id' => $batchId,
                    'created_at' => now(),
                ]));
            } else {
                /*
                 | An update here is the write-once trigger's business.
                 |
                 | The service does not check it: the counter who enters
                 | a figure, reads what it revealed and revises it is not
                 | going through this method twice, they are going
                 | through whatever screen is quickest.
                 */
                DB::connection('tenant')->table('stock_count_lines')
                    ->where('id', (string) $existing->id)
                    ->update($row);
            }

            $this->restate($countId);

            return [
                'counted' => (string) $counted,
                'expected' => (string) $expected,
                'variance' => (string) $variance,
                'value' => (string) $value,
                'why' => $variance->isZero()
                    ? sprintf('The shelf agrees with the books at %s.', (string) $counted)
                    : sprintf(
                        'The shelf holds %s and the books said %s, a difference of %s worth %s %s. %s',
                        (string) $counted,
                        (string) $expected,
                        (string) $variance,
                        (string) $value,
                        (string) $count->currency,
                        $this->movementNote($countId, $itemId),
                    ),
            ];
        });
    }

    /**
     * Movements recorded while this count has been open.
     *
     * @return list<array<string, mixed>>
     */
    public function movementsDuring(string $countId, ?string $itemId = null): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT m.id,
                   i.code,
                   m.kind,
                   m.quantity::text AS quantity,
                   m.moved_on,
                   m.created_at,
                   m.reason
            FROM stock_movements m
            JOIN items i ON i.id = m.item_id
            WHERE m.count_id = ?
              AND (?::char(26) IS NULL OR m.item_id = ?)
            ORDER BY m.created_at, m.id
        SQL, [$countId, $itemId, $itemId]);

        return array_map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'kind' => (string) $row->kind,
            'quantity' => (string) $row->quantity,
            'moved_on' => (string) $row->moved_on,
            'recorded_at' => (string) $row->created_at,
            'reason' => $row->reason === null ? null : (string) $row->reason,
        ], $rows);
    }

    /**
     * Finish counting: the state moves, the variance is what it is.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function finish(string $countId, string $userId): array
    {
        $count = $this->countOr404($countId);

        if ((string) $count->state !== 'counting') {
            throw StockRefused::because(sprintf(
                'Count %s is %s, not being counted.',
                (string) $count->number,
                (string) $count->state,
            ));
        }

        $uncounted = (int) DB::connection('tenant')->table('stock_count_lines')
            ->where('count_id', $countId)
            ->whereNull('counted_quantity')
            ->count();

        if ($uncounted > 0) {
            throw StockRefused::because(sprintf(
                '%d line(s) of this count have no figure against them. A count that finished with lines '.
                'uncounted would post the difference between what was found and what the system says '.
                'for shelves nobody looked at.',
                $uncounted,
            ));
        }

        $entered = (int) DB::connection('tenant')->table('stock_count_lines')
            ->where('count_id', $countId)
            ->count();

        if ($entered === 0) {
            throw StockRefused::because('Nothing was counted, so there is nothing to finish.');
        }

        $this->restate($countId);
        $count = $this->countOr404($countId);

        DB::connection('tenant')->table('stock_counts')->where('id', $countId)->update([
            'state' => 'counted',
            'counted_at' => now(),
            'counted_by' => $userId,
            'updated_at' => now(),
        ]);

        $variance = Money::of((string) ($count->variance_value ?? '0'), (string) $count->currency);
        $materiality = Money::of((string) $count->materiality, (string) $count->currency);
        $material = $variance->abs()->isGreaterThan($materiality);

        $this->audit->record(
            category: 'inventory',
            action: 'count.finished',
            subjectType: 'stock_count',
            subjectId: $countId,
            subjectLabel: sprintf('%s %s', (string) $count->number, (string) $variance->toPresentation()),
            before: ['state' => 'counting'],
            after: [
                'state' => 'counted',
                'variance_value' => (string) $variance->amount,
                'needs_approval' => $material,
            ],
            entityId: (string) $count->entity_id,
        );

        return [
            'state' => 'counted',
            'variance_value' => (string) $variance->amount,
            'needs_approval' => $material,
            'why' => $material
                ? sprintf(
                    'The variance is %s against a materiality of %s, so somebody other than the counter '.
                    'has to approve it before it posts.',
                    (string) $variance->toPresentation(),
                    (string) $materiality->toPresentation(),
                )
                : sprintf(
                    'The variance is %s, within the materiality of %s, so it posts without a second '.
                    'name - which is what keeps the second name worth something on the count where it '.
                    'matters.',
                    (string) $variance->toPresentation(),
                    (string) $materiality->toPresentation(),
                ),
        ];
    }

    /** @throws StockRefused */
    public function approve(string $countId, string $reason, string $userId): void
    {
        $count = $this->countOr404($countId);

        if ((string) $count->state !== 'counted') {
            throw StockRefused::because(sprintf(
                'Count %s is %s, so there is no variance to approve yet.',
                (string) $count->number,
                (string) $count->state,
            ));
        }

        if ((string) $count->counted_by === $userId) {
            throw StockRefused::because(
                'The person who counted cannot approve their own variance. A counter who can do both '.
                'can make missing stock disappear from the books by typing a reason.',
            );
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw StockRefused::because(
                'Approving a write-off needs the reason in a sentence - it is what the conversation '.
                'about this figure is held against next year.',
            );
        }

        DB::connection('tenant')->table('stock_counts')->where('id', $countId)->update([
            'approved_by' => $userId,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'inventory',
            action: 'count.approved',
            subjectType: 'stock_count',
            subjectId: $countId,
            subjectLabel: sprintf(
                '%s %s',
                (string) $count->number,
                (string) Money::of((string) ($count->variance_value ?? '0'), (string) $count->currency)
                    ->toPresentation(),
            ),
            after: ['approved_by' => $userId],
            reason: trim($reason),
            entityId: (string) $count->entity_id,
        );
    }

    /**
     * Post the variance: one adjustment per line, with the line's reason.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function post(string $countId, Carbon $on, string $userId): array
    {
        $count = $this->countOr404($countId);

        if ((string) $count->state !== 'counted') {
            throw StockRefused::because(sprintf(
                'Count %s is %s, so there is nothing settled to post.',
                (string) $count->number,
                (string) $count->state,
            ));
        }

        $variance = Money::of((string) ($count->variance_value ?? '0'), (string) $count->currency);
        $materiality = Money::of((string) $count->materiality, (string) $count->currency);

        if ($variance->abs()->isGreaterThan($materiality) && $count->approved_at === null) {
            throw StockRefused::because(sprintf(
                'The variance is %s against a materiality of %s and nobody has approved it. This is '.
                'the moment the books change, which is why the second name is asked for here.',
                (string) $variance->toPresentation(),
                (string) $materiality->toPresentation(),
            ));
        }

        $lines = DB::connection('tenant')->table('stock_count_lines')
            ->where('count_id', $countId)
            ->orderBy('id')
            ->get()
            ->all();

        return DB::connection('tenant')->transaction(function () use (
            $count, $countId, $lines, $on, $userId
        ): array {
            $posted = [];

            foreach ($lines as $line) {
                if (BigDecimal::of((string) $line->variance_quantity)->isZero()) {
                    continue;
                }

                $movement = $this->stock->adjust(
                    entityId: (string) $count->entity_id,
                    itemId: (string) $line->item_id,
                    locationId: (string) $count->location_id,
                    quantity: (string) $line->variance_quantity,
                    reason: sprintf('%s: %s', (string) $count->number, (string) $line->reason),
                    on: $on,
                    userId: $userId,
                    kind: 'count',
                );

                DB::connection('tenant')->table('stock_count_lines')
                    ->where('id', (string) $line->id)
                    ->update(['movement_id' => (string) $movement['id'], 'updated_at' => now()]);

                $posted[] = (string) $movement['id'];
            }

            /*
             | The state moves LAST.
             |
             | The posted trigger refuses any edit to a line of a posted
             | count, so writing `movement_id` has to happen before the
             | count becomes history - and a failure anywhere above
             | leaves the whole thing unposted rather than half posted.
             */
            DB::connection('tenant')->table('stock_counts')->where('id', $countId)->update([
                'state' => 'posted',
                'posted_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                category: 'inventory',
                action: 'count.posted',
                subjectType: 'stock_count',
                subjectId: $countId,
                subjectLabel: sprintf('%s %s', (string) $count->number, (string) $count->variance_value),
                before: ['state' => 'counted'],
                after: ['state' => 'posted', 'adjustments' => \count($posted)],
                entityId: (string) $count->entity_id,
            );

            return [
                'state' => 'posted',
                'adjustments' => $posted,
                'why' => sprintf(
                    '%d line(s) of %d moved the stock, each as its own adjustment with its own reason - '.
                    'one total write-off tells nobody which items go missing.',
                    \count($posted),
                    \count($lines),
                ),
            ];
        });
    }

    /**
     * The variance, by value and by quantity, naming what accounts for it.
     *
     * Both orderings, because they disagree and the disagreement is the
     * finding: a hundred missing screws and one missing laptop are the
     * same money and not the same problem. A report with only one of
     * them sends somebody to count screws.
     *
     * @return array<string, mixed>
     */
    public function variance(string $countId): array
    {
        $count = $this->countOr404($countId);
        $names = (int) config('inventory.variance_report_names', 10);

        $totals = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(variance_value), 0)::text AS net_value,
                   coalesce(sum(abs(variance_value)), 0)::text AS gross_value,
                   coalesce(sum(variance_quantity), 0)::text AS net_quantity,
                   coalesce(sum(abs(variance_quantity)), 0)::text AS gross_quantity,
                   count(*) FILTER (WHERE variance_quantity <> 0) AS lines_differing,
                   count(*) AS lines
            FROM stock_count_lines
            WHERE count_id = ?
        SQL, [$countId]);

        return [
            'number' => (string) $count->number,
            'currency' => (string) $count->currency,
            /*
             | Net AND gross, because they answer different questions.
             |
             | A count where 500 is missing from one shelf and 500 too
             | many is on another nets to zero - which reads as a clean
             | count and is two serious errors. The gross is what says
             | how much was wrong.
             */
            'net_value' => (string) $totals->net_value,
            'gross_value' => (string) $totals->gross_value,
            'net_quantity' => (string) $totals->net_quantity,
            'gross_quantity' => (string) $totals->gross_quantity,
            'lines' => (int) $totals->lines,
            'lines_differing' => (int) $totals->lines_differing,
            'by_value' => $this->worst($countId, 'abs(l.variance_value)', $names),
            'by_quantity' => $this->worst($countId, 'abs(l.variance_quantity)', $names),
        ];
    }

    /** @throws StockRefused */
    public function cancel(string $countId, string $reason, string $userId): void
    {
        $count = $this->countOr404($countId);

        if ((string) $count->state === 'posted') {
            throw StockRefused::because(
                'That count is posted. Its adjustments are in the ledger, and reversing them is a '.
                'reversal rather than a cancellation.',
            );
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw StockRefused::because(
                'Abandoning a count needs its reason: a count that was started and dropped is the one '.
                'somebody asks about at year end.',
            );
        }

        DB::connection('tenant')->table('stock_counts')->where('id', $countId)->update([
            'state' => 'cancelled',
            'cancelled_reason' => trim($reason),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'inventory',
            action: 'count.cancelled',
            subjectType: 'stock_count',
            subjectId: $countId,
            subjectLabel: (string) $count->number,
            before: ['state' => (string) $count->state],
            after: ['state' => 'cancelled'],
            reason: trim($reason),
            entityId: (string) $count->entity_id,
        );
    }

    /**
     * The worst lines by one measure.
     *
     * @return list<array<string, mixed>>
     */
    private function worst(string $countId, string $measure, int $names): array
    {
        /*
         | `$measure` is one of two literals from this class, never input.
         |
         | Interpolated because an ORDER BY expression cannot be bound.
         | The two call sites are directly above and both pass a
         | constant; anything reaching here from a request would be a
         | change to this file, which is where it would be reviewed.
         */
        $rows = DB::connection('tenant')->select(sprintf(<<<'SQL'
            SELECT i.code,
                   i.name_en,
                   l.counted_quantity::text AS counted,
                   l.expected_quantity::text AS expected,
                   l.variance_quantity::text AS variance_quantity,
                   l.variance_value::text AS variance_value,
                   l.reason
            FROM stock_count_lines l
            JOIN items i ON i.id = l.item_id
            WHERE l.count_id = ?
              AND l.variance_quantity <> 0
            ORDER BY %s DESC, i.code
            LIMIT %d
        SQL, $measure, $names), [$countId]);

        return array_map(static fn (object $row): array => [
            'code' => (string) $row->code,
            'name' => (string) $row->name_en,
            'counted' => (string) $row->counted,
            'expected' => (string) $row->expected,
            'variance_quantity' => (string) $row->variance_quantity,
            'variance_value' => (string) $row->variance_value,
            'reason' => $row->reason === null ? null : (string) $row->reason,
        ], $rows);
    }

    /** What the system believes is on this shelf, right now. */
    private function expectedAt(
        string $entityId,
        string $locationId,
        string $itemId,
        ?string $batchId,
    ): BigDecimal {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(quantity), 0)::text AS on_hand
            FROM stock_movements
            WHERE entity_id = ?
              AND item_id = ?
              AND location_id = ?
              AND (?::char(26) IS NULL OR batch_id = ?)
        SQL, [$entityId, $itemId, $locationId, $batchId, $batchId]);

        return BigDecimal::of((string) ($row->on_hand ?? '0'));
    }

    /** The count's variance, summed from its lines. */
    private function restate(string $countId): void
    {
        $row = DB::connection('tenant')->selectOne(
            'SELECT coalesce(sum(variance_value), 0)::text AS v FROM stock_count_lines WHERE count_id = ?',
            [$countId],
        );

        DB::connection('tenant')->table('stock_counts')->where('id', $countId)->update([
            'variance_value' => (string) ($row->v ?? '0'),
            'updated_at' => now(),
        ]);
    }

    private function movementNote(string $countId, string $itemId): string
    {
        $moved = \count($this->movementsDuring($countId, $itemId));

        return $moved === 0
            ? 'Nothing moved on this item while the count has been open, so the difference is not a delivery.'
            : sprintf(
                '%d movement(s) of this item were recorded while the count has been open - read them '.
                'before writing the difference off.',
                $moved,
            );
    }

    private function materialityFor(string $currency): string
    {
        /** @var array<string, string> $thresholds */
        $thresholds = (array) config('inventory.count_materiality', []);

        return (string) ($thresholds[$currency]
            ?? config('inventory.count_materiality_default', '0.0000'));
    }

    /** @throws StockRefused */
    private function countOr404(string $countId): object
    {
        $count = DB::connection('tenant')->table('stock_counts')->where('id', $countId)->first();

        if ($count === null) {
            throw StockRefused::because('There is no stock count with that id.');
        }

        return $count;
    }
}
