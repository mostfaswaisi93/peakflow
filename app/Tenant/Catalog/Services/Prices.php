<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Catalog\Exceptions\PriceChangeRefused;
use App\Tenant\Catalog\Models\PriceListLine;
use App\Tenant\Catalog\Models\PricePoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Changing a price, and being able to say what it was - PF-254.
 *
 * ## A change is a new point, never an edit
 *
 * "What did this cost in March" is asked by a customer holding an
 * invoice, and the only way to answer it is to have kept March. So a
 * price change writes a row with the date it takes effect, the person
 * and the reason; the database refuses to edit or remove one.
 *
 * ## A future date needs nothing to run
 *
 * {@see schedule()} with a date next month is a scheduled change. On the
 * day it arrives it becomes the latest point at or before the document
 * date and the resolver reads it. There is no job, no flag to flip and
 * nothing to forget on a public holiday - and because it is an ordinary
 * row, {@see pending()} can show it today, which is what stops a rise
 * being discovered by a customer.
 *
 * ## Backdating is possible and has to be asked for
 *
 * Sometimes it is right: a price entered as 1500 that should have been
 * 15.00, caught before anything was invoiced. Usually it is not, because
 * a backdated price does not change the invoices that already quoted the
 * old one - so the books and the price list disagree, and the person who
 * finds out is a customer comparing two invoices.
 *
 * This module cannot tell whether anything was invoiced: `Catalog` may
 * use `Support` and `Tenancy` and nothing else, which is deliberate -
 * master data has no business reading documents. So instead of guessing,
 * a backdated change asks for a name and a sentence, the same shape as
 * an expired batch being issued in PF-250. The refusal says what the
 * risk is rather than simply saying no.
 */
final class Prices
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Record what a price becomes, from a date.
     *
     * @return array<string, mixed>
     *
     * @throws PriceChangeRefused
     */
    public function schedule(
        string $priceListLineId,
        Carbon $effectiveFrom,
        string $reason,
        string $userId,
        ?string $unitPrice = null,
        ?string $discountPercent = null,
        ?string $backdatedBy = null,
        ?string $backdatedReason = null,
    ): array {
        $line = PriceListLine::query()->with('list')->find($priceListLineId);

        if ($line === null) {
            throw PriceChangeRefused::because('There is no price list tier with that id.');
        }

        if (($unitPrice === null) === ($discountPercent === null)) {
            throw PriceChangeRefused::because(
                'A price point is either an amount or a percentage off the next list down, never both '.
                'and never neither. Both is two answers to one question, and whichever the resolver '.
                'reads first becomes a rule nobody wrote down.',
            );
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw PriceChangeRefused::because(
                'A price change needs its reason in a sentence. A supplier increase, a promotion and a '.
                'corrected typo are read differently when a customer disputes an invoice, and "price '.
                'updated" tells nobody which it was.',
            );
        }

        $existing = PricePoint::query()
            ->where('price_list_line_id', $priceListLineId)
            ->where('effective_from', $effectiveFrom->toDateString())
            ->first();

        if ($existing !== null) {
            throw PriceChangeRefused::aSecondPointOnOneDay(
                (string) $line->list?->code,
                $effectiveFrom->toDateString(),
            );
        }

        /*
         | Backdating: before the latest point this tier already has.
         |
         | Not "before today". A tier whose last change was in January
         | can take a change dated last week without rewriting anything,
         | because nothing was quoted from a price that did not exist
         | yet. What needs a name is a point that lands BENEATH one
         | already in force, because that is what changes an answer the
         | resolver has already given.
         */
        $latest = PricePoint::query()
            ->where('price_list_line_id', $priceListLineId)
            ->orderByDesc('effective_from')
            ->value('effective_from');

        $backdated = $latest !== null && $effectiveFrom->lt(Carbon::parse((string) $latest));

        if ($backdated && ($backdatedBy === null || mb_strlen(trim((string) $backdatedReason)) < 15)) {
            throw PriceChangeRefused::aBackdatedChange(
                (string) $line->list?->code,
                $effectiveFrom->toDateString(),
                Carbon::parse((string) $latest)->toDateString(),
            );
        }

        $point = PricePoint::create([
            'price_list_line_id' => $priceListLineId,
            'unit_price' => $unitPrice,
            'discount_percent' => $discountPercent,
            'effective_from' => $effectiveFrom->toDateString(),
            'reason' => trim($reason),
            'changed_by' => $userId,
        ]);

        $id = (string) $point->getKey();

        $this->audit->record(
            category: 'catalog',
            action: $backdated ? 'price.backdated' : 'price.scheduled',
            subjectType: 'price_point',
            subjectId: $id,
            subjectLabel: sprintf(
                '%s %s from %s',
                (string) $line->list?->code,
                $unitPrice ?? $discountPercent.'%',
                $effectiveFrom->toDateString(),
            ),
            after: [
                'unit_price' => $unitPrice,
                'discount_percent' => $discountPercent,
                'effective_from' => $effectiveFrom->toDateString(),
            ],
            reason: $backdated
                ? sprintf('%s | backdated: %s', trim($reason), trim((string) $backdatedReason))
                : trim($reason),
        );

        $today = Carbon::now()->startOfDay();

        return [
            'id' => $id,
            'effective_from' => $effectiveFrom->toDateString(),
            'scheduled' => $effectiveFrom->gt($today),
            'backdated' => $backdated,
            'why' => match (true) {
                $backdated => sprintf(
                    'Recorded from %s, beneath the point already in force from %s. The invoices that '.
                    'quoted the old price do not change with it.',
                    $effectiveFrom->toDateString(),
                    Carbon::parse((string) $latest)->toDateString(),
                ),
                $effectiveFrom->gt($today) => sprintf(
                    'Recorded, and takes effect on %s - %d day(s) from today. Nothing has to run on the '.
                    'day; it becomes the answer because it is the latest point at or before the '.
                    'document date.',
                    $effectiveFrom->toDateString(),
                    (int) $today->diffInDays($effectiveFrom),
                ),
                default => sprintf('In force from %s.', $effectiveFrom->toDateString()),
            },
        ];
    }

    /**
     * Changes that have not taken effect yet.
     *
     * The point of the task's third criterion: a rise is visible before
     * it happens. A product where tomorrow's price is invisible until
     * tomorrow is one where it is discovered by a customer.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(?string $listId = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT p.id,
                   pl.code AS list_code,
                   i.code AS item_code,
                   i.name_en AS item_name,
                   pll.min_quantity::text AS min_quantity,
                   p.unit_price::text AS unit_price,
                   p.discount_percent::text AS discount_percent,
                   p.effective_from,
                   p.reason,
                   /*
                    | What it is replacing, so the size of the change is
                    | readable without a second query.
                    |
                    | "94.50 becomes 99.00 on the first" is the sentence
                    | somebody acts on; "99.00 from the first" is one
                    | they have to go and compare.
                    */
                   (
                     SELECT q.unit_price::text
                     FROM price_points q
                     WHERE q.price_list_line_id = p.price_list_line_id
                       AND q.effective_from <= ?
                     ORDER BY q.effective_from DESC
                     LIMIT 1
                   ) AS current_price
            FROM price_points p
            JOIN price_list_lines pll ON pll.id = p.price_list_line_id
            JOIN price_lists pl ON pl.id = pll.price_list_id
            JOIN items i ON i.id = pll.item_id
            WHERE p.effective_from > ?
              AND (?::char(26) IS NULL OR pl.id = ?)
            ORDER BY p.effective_from, pl.code, i.code
        SQL, [
            $asOf->toDateString(),
            $asOf->toDateString(),
            $listId,
            $listId,
        ]);

        return array_map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'list' => (string) $row->list_code,
            'item' => (string) $row->item_code,
            'item_name' => (string) $row->item_name,
            'min_quantity' => (string) $row->min_quantity,
            'unit_price' => $row->unit_price === null ? null : (string) $row->unit_price,
            'discount_percent' => $row->discount_percent === null ? null : (string) $row->discount_percent,
            'current_price' => $row->current_price === null ? null : (string) $row->current_price,
            'effective_from' => (string) $row->effective_from,
            'reason' => (string) $row->reason,
        ], $rows);
    }

    /**
     * Everything this tier has ever cost, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function historyOf(string $priceListLineId): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT p.unit_price::text AS unit_price,
                   p.discount_percent::text AS discount_percent,
                   p.effective_from,
                   p.reason,
                   p.changed_by,
                   p.created_at
            FROM price_points p
            WHERE p.price_list_line_id = ?
            ORDER BY p.effective_from DESC, p.id DESC
        SQL, [$priceListLineId]);

        return array_map(static fn (object $row): array => [
            'unit_price' => $row->unit_price === null ? null : (string) $row->unit_price,
            'discount_percent' => $row->discount_percent === null ? null : (string) $row->discount_percent,
            'effective_from' => (string) $row->effective_from,
            'reason' => (string) $row->reason,
            'changed_by' => (string) $row->changed_by,
            'recorded_at' => (string) $row->created_at,
        ], $rows);
    }
}
