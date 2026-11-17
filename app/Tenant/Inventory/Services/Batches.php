<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Services;

use App\Support\Audit\AuditTrail;
use App\Support\Money\Money;
use App\Tenant\Inventory\Exceptions\StockRefused;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Batches, expiry and the suggestion nobody applies for you - PF-250.
 *
 * ## First-expiry-first-out is offered, never applied
 *
 * The right batch is usually the one that expires first and is sometimes
 * the one in the van. A product that picked silently would be wrong a few
 * times a week in a way nobody could see - and the person who loaded the
 * van is the one who knows.
 *
 * So {@see suggest()} returns an order with its reasoning and writes
 * nothing. Somebody names the batch.
 *
 * ## Expiry is reported before it happens
 *
 * A report of what expired yesterday is an accounting entry. A report of
 * what expires in three weeks is a decision: discount it, move it,
 * return it. The lead time is the whole value of the report, so it is a
 * parameter rather than a constant.
 *
 * ## Issuing from an expired batch is possible and never silent
 *
 * Sometimes right - a cosmetic best-before, a batch re-tested and
 * released. Never right without somebody's name on it, so the
 * acknowledgement is a column on the movement and the refusal names what
 * is needed.
 */
final class Batches
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Record a batch.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function record(
        string $entityId,
        string $itemId,
        string $number,
        Carbon $receivedOn,
        ?Carbon $expiresOn,
        string $userId,
        ?string $supplierId = null,
        ?string $supplierLot = null,
    ): array {
        $item = DB::connection('tenant')->table('items')->where('id', $itemId)->first();

        if ($item === null || ! $item->stocked) {
            throw StockRefused::because('A batch belongs to a stocked item, and that item is not one.');
        }

        if (! $item->batch_tracked) {
            throw StockRefused::because(sprintf(
                '%s is not under batch control, so a batch for it would be a number attached to nothing. '.
                'Turn batch tracking on for the item first - which is a decision about traceability, not '.
                'about this delivery.',
                (string) $item->code,
            ));
        }

        $number = trim($number);

        if ($number === '') {
            throw StockRefused::because(
                'A batch needs its number. It is what a recall notice names, and a blank one makes the '.
                'batch untraceable in exactly the situation batch tracking exists for.',
            );
        }

        $existing = DB::connection('tenant')->table('stock_batches')
            ->where('entity_id', $entityId)
            ->where('item_id', $itemId)
            ->where('number', $number)
            ->first();

        if ($existing !== null) {
            throw StockRefused::because(sprintf(
                'Batch %s of %s is already on record, received %s. Two rows for one batch split its '.
                'history, so a recall would return half the customers.',
                $number,
                (string) $item->code,
                (string) $existing->received_on,
            ));
        }

        $id = (string) Str::ulid();

        DB::connection('tenant')->table('stock_batches')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'item_id' => $itemId,
            'number' => $number,
            'received_on' => $receivedOn->toDateString(),
            'expires_on' => $expiresOn?->toDateString(),
            'supplier_id' => $supplierId,
            'supplier_lot' => $supplierLot,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'catalog',
            action: 'batch.recorded',
            subjectType: 'stock_batch',
            subjectId: $id,
            subjectLabel: sprintf('%s %s', (string) $item->code, $number),
            after: [
                'number' => $number,
                'received_on' => $receivedOn->toDateString(),
                'expires_on' => $expiresOn?->toDateString(),
                'supplier_lot' => $supplierLot,
            ],
            reason: 'a batch was received',
            entityId: $entityId,
        );

        return [
            'id' => $id,
            'number' => $number,
            'why' => $expiresOn === null
                ? sprintf('Batch %s is on record with no expiry.', $number)
                : sprintf(
                    'Batch %s expires on %s, which is %d days from when it was received.',
                    $number,
                    $expiresOn->toDateString(),
                    (int) $receivedOn->diffInDays($expiresOn),
                ),
        ];
    }

    /**
     * The order to take batches in, and why - a suggestion only.
     *
     * @return array<string, mixed>
     */
    public function suggest(string $entityId, string $itemId, string $locationId, string $quantity): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT b.id,
                   b.number,
                   b.expires_on,
                   b.received_on,
                   coalesce(sum(m.quantity), 0)::text AS on_hand
            FROM stock_batches b
            LEFT JOIN stock_movements m
                   ON m.batch_id = b.id
                  AND m.location_id = ?
            WHERE b.entity_id = ?
              AND b.item_id = ?
            GROUP BY b.id, b.number, b.expires_on, b.received_on
            HAVING coalesce(sum(m.quantity), 0) > 0
            /*
             | Expiry first, then arrival, then id.
             |
             | Nulls last: a batch with no expiry is not urgent, and
             | PostgreSQL sorts nulls first by default - which would
             | suggest the one batch with no deadline ahead of the one
             | expiring on Friday.
             */
            ORDER BY b.expires_on ASC NULLS LAST, b.received_on ASC, b.id ASC
        SQL, [$locationId, $entityId, $itemId]);

        $wanted = BigDecimal::of($quantity);
        $plan = [];
        $today = Carbon::now()->startOfDay();

        foreach ($rows as $row) {
            if (! $wanted->isPositive()) {
                break;
            }

            $available = BigDecimal::of((string) $row->on_hand);
            $take = $available->isLessThan($wanted) ? $available : $wanted;
            $wanted = $wanted->minus($take);

            $expires = $row->expires_on === null ? null : Carbon::parse((string) $row->expires_on);
            $days = $expires === null ? null : (int) $today->diffInDays($expires, false);

            $plan[] = [
                'batch_id' => (string) $row->id,
                'number' => (string) $row->number,
                'take' => (string) $take,
                'available' => (string) $available,
                'expires_on' => $expires?->toDateString(),
                'days_to_expiry' => $days,
                'expired' => $days !== null && $days < 0,
                'why' => match (true) {
                    $days === null => sprintf('batch %s has no expiry, so it is taken by arrival order', (string) $row->number),
                    $days < 0 => sprintf(
                        'batch %s expired %d day%s ago - it is listed because it is still on hand, and '.
                        'issuing it needs somebody to say so',
                        (string) $row->number,
                        abs($days),
                        abs($days) === 1 ? '' : 's',
                    ),
                    default => sprintf(
                        'batch %s expires in %d day%s, which is why it comes first',
                        (string) $row->number,
                        $days,
                        $days === 1 ? '' : 's',
                    ),
                },
            ];
        }

        return [
            'plan' => $plan,
            'short_by' => (string) $wanted,
            'why' => $plan === []
                ? 'There is none of this item in stock at that location, so there is nothing to suggest.'
                : sprintf(
                    'Taking first-expiry-first-out needs %d batch(es)%s. This is a suggestion and nothing '.
                    'has been written: the right batch is usually the one that expires first and is '.
                    'sometimes the one already in the van, and the person who loaded it is the one who '.
                    'knows.',
                    \count($plan),
                    $wanted->isPositive() ? sprintf(' and is still %s short', (string) $wanted) : '',
                ),
        ];
    }

    /**
     * Batches expiring within the lead time, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    public function expiring(string $entityId, int $leadDays = 30): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT b.id,
                   b.number,
                   b.expires_on,
                   i.code AS item_code,
                   coalesce(sum(m.quantity), 0)::text AS on_hand,
                   coalesce(sum(m.value), 0)::text AS value,
                   (b.expires_on - current_date) AS days
            FROM stock_batches b
            JOIN items i ON i.id = b.item_id
            LEFT JOIN stock_movements m ON m.batch_id = b.id
            WHERE b.entity_id = ?
              AND b.expires_on IS NOT NULL
              AND b.expires_on <= current_date + ?::int
            GROUP BY b.id, b.number, b.expires_on, i.code
            HAVING coalesce(sum(m.quantity), 0) > 0
            ORDER BY b.expires_on
        SQL, [$entityId, $leadDays]);

        return array_map(static fn (object $row): array => [
            'batch_id' => (string) $row->id,
            'number' => (string) $row->number,
            'item' => (string) $row->item_code,
            'expires_on' => (string) $row->expires_on,
            'days' => (int) $row->days,
            'on_hand' => (string) $row->on_hand,
            'value' => (string) $row->value,
            'why' => (int) $row->days < 0
                ? sprintf(
                    '%s of %s expired %d day%s ago and is still on hand - which is a write-off waiting '.
                    'to be recorded rather than a decision',
                    (string) $row->on_hand,
                    (string) $row->item_code,
                    abs((int) $row->days),
                    abs((int) $row->days) === 1 ? '' : 's',
                )
                : sprintf(
                    '%s of %s expires in %d day%s - still time to discount it, move it or return it, '.
                    'which is the whole point of reporting it before rather than after',
                    (string) $row->on_hand,
                    (string) $row->item_code,
                    (int) $row->days,
                    (int) $row->days === 1 ? '' : 's',
                ),
        ], $rows);
    }

    /**
     * Whether this batch may be issued, and what is needed if not.
     *
     * @return array{allowed: bool, expired: bool, why: string}
     */
    public function mayIssue(string $batchId, Carbon $on): array
    {
        $batch = DB::connection('tenant')->table('stock_batches')->where('id', $batchId)->first();

        if ($batch === null) {
            return ['allowed' => false, 'expired' => false, 'why' => 'There is no batch with that id.'];
        }

        if ($batch->expires_on === null || Carbon::parse((string) $batch->expires_on)->gte($on)) {
            return [
                'allowed' => true,
                'expired' => false,
                'why' => sprintf('Batch %s is within its date.', (string) $batch->number),
            ];
        }

        return [
            'allowed' => false,
            'expired' => true,
            'why' => sprintf(
                'Batch %s expired on %s. Issuing it is sometimes right - a cosmetic best-before, a batch '.
                're-tested and released - and never right silently, so it needs somebody\'s name and a '.
                'reason recorded against the movement.',
                (string) $batch->number,
                (string) $batch->expires_on,
            ),
        ];
    }

    /**
     * Who has a batch - the question a recall starts with.
     *
     * @return array<string, mixed>
     */
    public function traceOf(string $entityId, string $batchId): array
    {
        $batch = DB::connection('tenant')->table('stock_batches')->where('id', $batchId)->first();

        if ($batch === null) {
            throw StockRefused::because('There is no batch with that id.');
        }

        /*
         | Through the movement's source, to the document, to the party.
         |
         | The point of batch tracking is reaching the PEOPLE who have the
         | goods, not listing the stock. A trace that returned quantities
         | would answer a question nobody asks during a recall.
         */
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT m.id,
                   m.kind,
                   m.quantity::text AS quantity,
                   m.moved_on,
                   m.source_type,
                   m.source_id,
                   coalesce(p.legal_name, '-') AS party_name,
                   i.number AS document_number
            FROM stock_movements m
            LEFT JOIN invoices i
                   ON m.source_type = 'invoice' AND i.id = m.source_id
            LEFT JOIN parties p ON p.id = i.party_id
            WHERE m.entity_id = ?
              AND m.batch_id = ?
            ORDER BY m.moved_on, m.id
        SQL, [$entityId, $batchId]);

        $out = [];

        foreach ($rows as $row) {
            if (BigDecimal::of((string) $row->quantity)->isPositive()) {
                continue;
            }

            $out[] = [
                'movement_id' => (string) $row->id,
                'quantity' => (string) BigDecimal::of((string) $row->quantity)->abs(),
                'moved_on' => (string) $row->moved_on,
                'document' => $row->document_number === null ? null : (string) $row->document_number,
                'party' => (string) $row->party_name,
            ];
        }

        return [
            'batch' => (string) $batch->number,
            'expires_on' => $batch->expires_on === null ? null : (string) $batch->expires_on,
            'supplier_lot' => $batch->supplier_lot === null ? null : (string) $batch->supplier_lot,
            'went_to' => $out,
            'why' => $out === []
                ? sprintf('Nothing from batch %s has left yet.', (string) $batch->number)
                : sprintf(
                    'Batch %s went to %d customer movement(s)%s. A recall notice names the supplier\'s '.
                    'lot, so that is recorded beside ours.',
                    (string) $batch->number,
                    \count($out),
                    $batch->supplier_lot === null
                        ? ' - and no supplier lot is on record, which is what a recall notice will name'
                        : '',
                ),
        ];
    }
}
