<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Inventory\Exceptions\StockRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Serials, their history and the warranty on them - PF-251.
 *
 * ## Every change of state is an event
 *
 * Not an update. "Where is this unit" is the question a warranty claim
 * starts with, and the answer is a list - received here, sold to them,
 * came back, replaced by that one. An updated row answers "where is it
 * now" and nothing else, which is the question nobody asks during a
 * claim.
 *
 * ## The warranty's end is computed, never stored
 *
 * From the sale event plus the term's months. A stored end date is a
 * second place the truth lives: the first time somebody corrects a sale
 * date the warranty stops agreeing with it, and the customer is the one
 * who finds out - at the counter, holding a receipt.
 */
final class Serials
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Take a serial into stock.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function receive(
        string $entityId,
        string $itemId,
        string $number,
        string $locationId,
        Carbon $on,
        string $userId,
        ?string $batchId = null,
        ?string $variantId = null,
    ): array {
        $item = DB::connection('tenant')->table('items')->where('id', $itemId)->first();

        if ($item === null || ! $item->serial_tracked) {
            throw StockRefused::because(
                'A serial belongs to an item under serial control, and that item is not one. Turning it '.
                'on is a decision about traceability rather than about this delivery.',
            );
        }

        $number = trim($number);

        if ($number === '') {
            throw StockRefused::because('A serial needs its number - it is the only thing that identifies the unit.');
        }

        $existing = DB::connection('tenant')->table('serials')
            ->where('item_id', $itemId)
            ->where('number', $number)
            ->first();

        if ($existing !== null) {
            throw StockRefused::because(sprintf(
                'Serial %s of %s is already on record and is currently %s. Two rows for one unit split '.
                'its history, so a warranty claim would find half of it.',
                $number,
                (string) $item->code,
                (string) $existing->state,
            ));
        }

        $id = (string) Str::ulid();

        return DB::connection('tenant')->transaction(function () use (
            $id,
            $entityId,
            $itemId,
            $number,
            $locationId,
            $on,
            $userId,
            $batchId,
            $variantId,
            $item,
        ): array {
            DB::connection('tenant')->table('serials')->insert([
                'id' => $id,
                'entity_id' => $entityId,
                'item_id' => $itemId,
                'variant_id' => $variantId,
                'batch_id' => $batchId,
                'number' => $number,
                'state' => 'received',
                'location_id' => $locationId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->event($entityId, $id, 'received', $on, $userId, locationId: $locationId);

            return [
                'id' => $id,
                'number' => $number,
                'why' => sprintf('Serial %s of %s is in stock.', $number, (string) $item->code),
            ];
        });
    }

    /**
     * Sell a serial - once.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function sell(
        string $entityId,
        string $serialId,
        string $partyId,
        Carbon $on,
        string $userId,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $warrantyTermId = null,
    ): array {
        $serial = $this->serialOr404($serialId);

        if ((string) $serial->state === 'sold') {
            throw StockRefused::because(sprintf(
                'Serial %s was already sold. One unit cannot be sold twice - and the second sale '.
                'usually arrives from an import, a second till or a retried job, which is why a unique '.
                'index refuses it rather than a check somewhere.',
                (string) $serial->number,
            ));
        }

        if (! \in_array((string) $serial->state, ['received', 'returned'], true)) {
            throw StockRefused::because(sprintf(
                'Serial %s is %s, so it is not in stock to be sold.',
                (string) $serial->number,
                (string) $serial->state,
            ));
        }

        if ($warrantyTermId !== null) {
            $term = DB::connection('tenant')->table('warranty_terms')
                ->where('id', $warrantyTermId)
                ->where('entity_id', $entityId)
                ->where('active', true)
                ->first();

            if ($term === null) {
                throw StockRefused::because(
                    'The warranty term sold with that unit is not one this entity has on record, or is '.
                    'no longer active. A term nobody can read is cover nobody can honour.',
                );
            }
        }

        $this->event(
            $entityId,
            $serialId,
            'sold',
            $on,
            $userId,
            partyId: $partyId,
            sourceType: $sourceType,
            sourceId: $sourceId,
            warrantyTermId: $warrantyTermId,
        );

        return [
            'id' => $serialId,
            'number' => (string) $serial->number,
            'warranty' => $this->warrantyOf($entityId, $serialId),
            'why' => sprintf('Serial %s is sold. It cannot be sold again.', (string) $serial->number),
        ];
    }

    /**
     * When the warranty on a unit runs out, computed.
     *
     * @return array<string, mixed>|null
     */
    public function warrantyOf(string $entityId, string $serialId): ?array
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT e.happened_on AS sold_on,
                   s.number,
                   t.months,
                   t.name_en,
                   t.covers_en,
                   t.item_id AS term_item_id,
                   e.warranty_term_id AS sold_term_id
            FROM serials s
            JOIN serial_events e ON e.serial_id = s.id AND e.event = 'sold'
            LEFT JOIN warranty_terms t
                   ON t.entity_id = s.entity_id
                  AND t.active
                  /*
                   | Three places a term can come from, in this order:
                   | the sale, the item, the entity's default.
                   |
                   | The sale wins in the JOIN and not in the ORDER BY:
                   | when the sale names a term, nothing else joins at
                   | all. That is deliberate. A sort key would read as
                   | precedence and be dead - the first draft had
                   | `(t.id = e.warranty_term_id) DESC` here and a bite
                   | that removed it changed nothing, because there was
                   | never a second row to sort.
                   |
                   | The item beats the default in the ORDER BY, where
                   | there genuinely are two rows: `item_id IS NULL` is
                   | the default, and NULLS LAST is what keeps a fallback
                   | from making every item's warranty the default's.
                   */
                  AND (
                      t.id = e.warranty_term_id
                      OR (e.warranty_term_id IS NULL AND (t.item_id = s.item_id OR t.item_id IS NULL))
                  )
            WHERE s.entity_id = ?
              AND s.id = ?
            ORDER BY t.item_id NULLS LAST
            LIMIT 1
        SQL, [$entityId, $serialId]);

        if ($row === null || $row->months === null) {
            return null;
        }

        $soldOn = Carbon::parse((string) $row->sold_on);

        /*
         | The end is computed here and stored nowhere.
         |
         | A stored date stops agreeing with the sale the first time
         | somebody corrects it, and the customer finds out at the
         | counter holding a receipt.
         */
        $expires = $soldOn->copy()->addMonths((int) $row->months);
        $today = Carbon::now()->startOfDay();

        return [
            'sold_on' => $soldOn->toDateString(),
            'months' => (int) $row->months,
            'expires_on' => $expires->toDateString(),
            'in_warranty' => $expires->gte($today),
            'term' => (string) $row->name_en,
            'covers' => (string) $row->covers_en,
            'from' => $row->sold_term_id !== null ? 'the sale' : ($row->term_item_id !== null ? 'the item' : 'the entity default'),
            'why' => $expires->gte($today)
                ? sprintf(
                    'Sold on %s with %d months, so it is covered until %s - %d day(s) left. What it '.
                    'covers: %s',
                    $soldOn->toDateString(),
                    (int) $row->months,
                    $expires->toDateString(),
                    (int) $today->diffInDays($expires),
                    (string) $row->covers_en,
                )
                : sprintf(
                    'Sold on %s with %d months, so cover ended on %s - %d day(s) ago. The date is '.
                    'computed from the sale rather than stored, so it cannot disagree with it.',
                    $soldOn->toDateString(),
                    (int) $row->months,
                    $expires->toDateString(),
                    (int) $expires->diffInDays($today),
                ),
        ];
    }

    /**
     * Everything that happened to a unit, in order.
     *
     * @return array<string, mixed>
     */
    public function historyOf(string $entityId, string $serialId): array
    {
        $serial = $this->serialOr404($serialId);

        $events = DB::connection('tenant')->select(<<<'SQL'
            SELECT e.event,
                   e.happened_on,
                   e.note,
                   coalesce(p.legal_name, '-') AS party_name,
                   coalesce(l.code, '-') AS location_code
            FROM serial_events e
            LEFT JOIN parties p ON p.id = e.party_id
            LEFT JOIN stock_locations l ON l.id = e.location_id
            WHERE e.entity_id = ?
              AND e.serial_id = ?
            ORDER BY e.happened_on, e.id
        SQL, [$entityId, $serialId]);

        return [
            'number' => (string) $serial->number,
            'state' => (string) $serial->state,
            'warranty' => $this->warrantyOf($entityId, $serialId),
            'events' => array_map(static fn (object $row): array => [
                'event' => (string) $row->event,
                'on' => (string) $row->happened_on,
                'party' => (string) $row->party_name,
                'location' => (string) $row->location_code,
                'note' => $row->note === null ? null : (string) $row->note,
            ], $events),
        ];
    }

    /**
     * Record a claim against a unit.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function claim(
        string $entityId,
        string $serialId,
        string $fault,
        Carbon $on,
        string $userId,
        ?string $partyId = null,
    ): array {
        if (mb_strlen(trim($fault)) < 10) {
            throw StockRefused::because(
                'A claim needs the fault described. "Broken" is not a fault: the decision turns on '.
                'whether the warranty covers this, and a word cannot be held against a sentence about '.
                'what is covered.',
            );
        }

        $serial = $this->serialOr404($serialId);
        $warranty = $this->warrantyOf($entityId, $serialId);
        $id = (string) Str::ulid();

        DB::connection('tenant')->table('warranty_claims')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'serial_id' => $serialId,
            'party_id' => $partyId,
            'claimed_on' => $on->toDateString(),
            'fault' => trim($fault),
            'recorded_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'serial' => (string) $serial->number,
            'warranty' => $warranty,
            /*
             | The cover is reported and the claim is not refused.
             |
             | Out of warranty is a commercial decision, not a validation
             | failure: businesses honour claims past the date all the
             | time, and a product that refused to record one would have
             | the conversation happen outside it.
             */
            'why' => $warranty === null
                ? sprintf(
                    'Claim recorded against serial %s. There is no sale on record for it, so there is '.
                    'no warranty date to measure against - which is itself worth knowing.',
                    (string) $serial->number,
                )
                : sprintf(
                    'Claim recorded against serial %s. %s',
                    (string) $serial->number,
                    (string) $warranty['why'],
                ),
        ];
    }

    /**
     * Decide a claim.
     *
     * @return array<string, mixed>
     *
     * @throws StockRefused
     */
    public function decide(
        string $claimId,
        string $decision,
        string $reason,
        Carbon $on,
        string $userId,
        ?string $replacementSerialId = null,
    ): array {
        if (! \in_array($decision, ['repaired', 'replaced', 'refunded', 'rejected'], true)) {
            throw StockRefused::because(sprintf('"%s" is not a decision this release knows.', $decision));
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw StockRefused::because(
                'A decision needs its reason. Rejecting a claim is the one a customer argues with, and '.
                'the reason is what the conversation is held against six months later.',
            );
        }

        $claim = DB::connection('tenant')->table('warranty_claims')->where('id', $claimId)->first();

        if ($claim === null) {
            throw StockRefused::because('There is no claim with that id.');
        }

        if ($claim->decision !== null) {
            throw StockRefused::because(sprintf(
                'That claim was decided on %s: %s. Changing a decision would leave the stock movements '.
                'the first one caused describing something that did not happen.',
                (string) $claim->decided_on,
                (string) $claim->decision,
            ));
        }

        if ($decision === 'replaced' && $replacementSerialId === null) {
            throw StockRefused::because(
                'A replacement names the unit that went out in its place. Without it the decision says '.
                'a unit was replaced and the stock says nothing left, so the next count finds one too '.
                'many and writes it off.',
            );
        }

        return DB::connection('tenant')->transaction(function () use (
            $claim,
            $claimId,
            $decision,
            $reason,
            $on,
            $userId,
            $replacementSerialId,
        ): array {
            DB::connection('tenant')->table('warranty_claims')->where('id', $claimId)->update([
                'decision' => $decision,
                'decision_reason' => trim($reason),
                'decided_on' => $on->toDateString(),
                'decided_by' => $userId,
                'replacement_serial_id' => $replacementSerialId,
                'updated_at' => now(),
            ]);

            /*
             | A replacement is two events, not one.
             |
             | The faulty unit comes back and the new one goes out. A
             | product that recorded only the decision would leave the
             | stock wrong in both directions at once.
             */
            if ($decision === 'replaced') {
                $this->event(
                    (string) $claim->entity_id,
                    (string) $claim->serial_id,
                    'replaced',
                    $on,
                    $userId,
                    note: 'came back under warranty and was replaced',
                );

                /*
                 | And the cover the customer BOUGHT follows the unit.
                 |
                 | Read off the original sale, because an extended
                 | warranty is a thing they paid for and losing it
                 | because the first unit failed is the opposite of what
                 | it is for.
                 |
                 | It runs from the replacement, not from the original
                 | sale. The replacement is a different unit: measuring
                 | its cover from the sale of one the customer no longer
                 | has would answer about the wrong object, and the date
                 | would be unexplainable from any row. The other reading
                 | - the remainder of the original term - is defensible
                 | too, and whichever is chosen the two sale events hold
                 | enough to compute it.
                 */
                $this->event(
                    (string) $claim->entity_id,
                    (string) $replacementSerialId,
                    'sold',
                    $on,
                    $userId,
                    partyId: $claim->party_id === null ? null : (string) $claim->party_id,
                    note: 'went out as a warranty replacement',
                    warrantyTermId: $this->termSoldWith((string) $claim->serial_id),
                );
            }

            $this->audit->record(
                category: 'catalog',
                action: 'warranty.decided',
                subjectType: 'warranty_claim',
                subjectId: $claimId,
                subjectLabel: (string) $claim->serial_id,
                after: ['decision' => $decision, 'replacement' => $replacementSerialId],
                reason: trim($reason),
                entityId: (string) $claim->entity_id,
            );

            return [
                'id' => $claimId,
                'decision' => $decision,
                'why' => $decision === 'replaced'
                    ? 'Replaced: the faulty unit is back on the record and the new one has gone out, so '.
                      'the stock is right in both directions.'
                    : sprintf('%s: %s', ucfirst($decision), trim($reason)),
            ];
        });
    }

    private function event(
        string $entityId,
        string $serialId,
        string $event,
        Carbon $on,
        string $userId,
        ?string $partyId = null,
        ?string $locationId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $note = null,
        ?string $warrantyTermId = null,
    ): void {
        DB::connection('tenant')->table('serial_events')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $entityId,
            'serial_id' => $serialId,
            'event' => $event,
            'happened_on' => $on->toDateString(),
            'party_id' => $partyId,
            'location_id' => $locationId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $note,
            'warranty_term_id' => $warrantyTermId,
            'recorded_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The term named on a unit's sale, if one was - PF-251. */
    private function termSoldWith(string $serialId): ?string
    {
        $id = DB::connection('tenant')->table('serial_events')
            ->where('serial_id', $serialId)
            ->where('event', 'sold')
            ->value('warranty_term_id');

        return $id === null ? null : (string) $id;
    }

    /** @throws StockRefused */
    private function serialOr404(string $serialId): object
    {
        $serial = DB::connection('tenant')->table('serials')->where('id', $serialId)->first();

        if ($serial === null) {
            throw StockRefused::because('There is no serial with that id.');
        }

        return $serial;
    }
}
