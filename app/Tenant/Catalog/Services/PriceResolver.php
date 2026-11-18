<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Services;

use App\Support\Iso\CurrencyRegistry;
use App\Support\Money\Money;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Catalog\Exceptions\NoPriceFound;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Which price, and why - PF-065.
 *
 * ## The resolution order, stated once
 *
 * Candidate lists are the active ones in the right currency whose window
 * covers the DOCUMENT's date. Among those:
 *
 *   1. a list for this party beats
 *   2. a list for this party's group, which beats
 *   3. a general list
 *   4. within a tier of specificity, the higher `priority` wins
 *   5. within one list, the highest quantity tier at or below the quantity
 *
 * Steps 1 to 3 are not configurable, and that is deliberate: a resolution
 * order a tenant can reorder is an order nobody can explain, and "why is
 * this price 94.50" is asked every week.
 *
 * ## Every answer carries its reasoning
 *
 * `resolve()` returns the price AND the list, the line, the tier and the
 * lists it beat. The requirement is that a screen can show which list and
 * which rule produced a given price; a resolver that returns only a number
 * cannot be made to do that afterwards, because by then the candidates are
 * gone.
 *
 * ## The date is the DOCUMENT's
 *
 * A credit note issued in April against a March invoice uses March's price.
 * A resolver reading "the current list" reprices history whenever somebody
 * edits a list, and the first sign of it is a credit note that does not
 * match the invoice it credits.
 *
 * ## Unit prices keep eight decimals
 *
 * Rounding 0.0725 to the currency's two before multiplying by 10,000 units
 * is a 250-unit error. The unit price stays at the UnitPrice scale and the
 * rounding to the currency's exponent happens once, on the line total -
 * which is what {@see extended()} does.
 */
final class PriceResolver
{
    /**
     * @return array{
     *     unit_price: string,
     *     currency: string,
     *     list_id: string,
     *     list_code: string,
     *     list_name: string,
     *     scope: string,
     *     tier: string,
     *     valid_from: string,
     *     valid_to: ?string,
     *     derivation: string,
     *     beaten: list<string>,
     * }
     *
     * @throws NoPriceFound
     */
    public function resolve(
        string $itemId,
        string $currency,
        Carbon $documentDate,
        ?string $partyId = null,
        ?string $partyGroup = null,
        string $quantity = '1',
    ): array {
        $currency = CurrencyRegistry::assertExists($currency);

        $candidates = $this->candidates($itemId, $currency, $documentDate, $partyId, $partyGroup, $quantity);

        if ($candidates === []) {
            throw NoPriceFound::forItem($itemId, $currency, $documentDate->toDateString(), $partyId);
        }

        $winner = array_shift($candidates);

        /*
         | A discount line prices against the next less specific candidate.
         |
         | "10% off list" is a real commercial agreement and cannot be
         | expressed as a number, because the number changes whenever the
         | underlying list does. Resolving it recursively against what is
         | left of the candidate stack is what keeps the two in step.
         */
        /*
         | A list of its own price first, then a discount, then a rule.
         |
         | In that order deliberately: a derived list may still carry
         | lines of its own - "retail less fifteen per cent, except
         | these three at a fixed price" - and the exception has to beat
         | the rule, or it is not an exception.
         */
        if ($winner->unit_price !== null && $winner->discount_percent === null) {
            return $this->describe($winner, $candidates, (string) $winner->unit_price, null);
        }

        if ($winner->discount_percent !== null) {
            return $this->applyDiscount($winner, $candidates, $itemId, $currency, $documentDate);
        }

        $derived = $this->derive($winner, $itemId, $currency, $documentDate);

        return $this->describe($winner, $candidates, $derived['unit_price'], $derived['note']);
    }

    /**
     * The line total: unit price times quantity, rounded once.
     *
     * Rounded to the AMOUNT scale, not to the currency's exponent - the
     * currency's own rounding happens at presentation and at payment, and
     * rounding here as well would round twice. See
     * docs/architecture/money-and-precision.md.
     */
    public function extended(string $unitPrice, string $quantity, string $currency): Money
    {
        return Money::ofRounded(
            BigDecimal::of($unitPrice)->multipliedBy(BigDecimal::of($quantity)),
            $currency,
            RoundingPolicy::halfUp(Scale::Amount),
        );
    }

    /**
     * Candidate lines, most specific first.
     *
     * One query. Fetching the lists and then their lines reads the database
     * twice and gets a different answer when a list is edited between the
     * two reads - rare, and the kind of rare that happens during a bulk
     * price update.
     *
     * @return list<object>
     */
    private function candidates(
        string $itemId,
        string $currency,
        Carbon $documentDate,
        ?string $partyId,
        ?string $partyGroup,
        string $quantity,
    ): array {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                pl.id,
                pl.code,
                pl.currency,
                pl.name_en,
                pl.name_ar,
                pl.party_id,
                pl.party_group,
                pl.priority,
                pl.valid_from,
                pl.valid_to,
                pl.derived_from_id,
                pl.derivation_kind,
                pl.derivation_percent,
                pt.unit_price,
                pt.discount_percent,
                pt.effective_from,
                pt.reason AS price_reason,
                pll.min_quantity,
                /*
                 | Specificity as a number, so the ordering is one ORDER BY
                 | rather than a sort in PHP that has to be kept in step
                 | with this query.
                 */
                CASE
                    WHEN pl.party_id IS NOT NULL THEN 3
                    WHEN pl.party_group IS NOT NULL THEN 2
                    ELSE 1
                END AS specificity
            /*
             | From LISTS, not from lines - PF-254.
             |
             | A derived list prices an item it has no line for, by
             | re-running its rule against its base. Driving the query
             | from `price_list_lines` made such a list no candidate at
             | all, so the derivation could never be reached.
             */
            FROM price_lists pl
            LEFT JOIN price_list_lines pll
                   ON pll.price_list_id = pl.id
                  AND pll.item_id = cast(? AS char(26))
                  AND pll.min_quantity <= cast(? AS numeric(21,8))
            /*
             | The price in force ON THE DOCUMENT'S DATE.
             |
             | The latest point at or before it. A point dated next month
             | is a scheduled change and is not this document's price;
             | the day it arrives it becomes the latest point at or
             | before the date, and that is the entire mechanism - no
             | job, nothing to forget on a public holiday.
             */
            LEFT JOIN LATERAL (
                SELECT p.unit_price, p.discount_percent, p.effective_from, p.reason
                FROM price_points p
                WHERE p.price_list_line_id = pll.id
                  AND p.effective_from <= ?
                ORDER BY p.effective_from DESC
                LIMIT 1
            ) pt ON TRUE
            WHERE pl.currency = cast(? AS char(3))
              AND pl.active
              AND pl.valid_from <= ?
              AND (pl.valid_to IS NULL OR pl.valid_to >= ?)
              AND (
                    (pl.party_id IS NULL AND pl.party_group IS NULL)
                 OR pl.party_id = cast(? AS char(26))
                 OR pl.party_group = ?
              )
              /*
               | A list qualifies if it has a price for this item on this
               | date, or if it can compute one.
               |
               | A tier whose first point starts next month is neither:
               | `pt` is null and the list is not derived, so it drops
               | out rather than resolving to a price of nothing.
               */
              AND (pt.effective_from IS NOT NULL OR pl.derived_from_id IS NOT NULL)
            ORDER BY
                specificity DESC,
                pl.priority DESC,
                /*
                 | The highest tier at or below the quantity.
                 |
                 | Ascending would pick the base price for every order,
                 | making every quantity break decorative - and the symptom
                 | is a customer invoiced at list price for a container
                 | load, which they notice and we do not.
                 |
                 | No NULLS clause, deliberately. A derived list with no
                 | line for this item produces a row whose `min_quantity`
                 | is null, and the first draft wrote NULLS LAST here to
                 | keep it behind the list's explicit tiers. A bite that
                 | flipped it to NULLS FIRST changed nothing: the LEFT
                 | JOIN yields the null row only when NO tier of that
                 | list matches, so the two never appear together and
                 | there was never anything to order. A clause that reads
                 | as precedence and cannot provide it is worse than
                 | none.
                 */
                pll.min_quantity DESC,
                pl.valid_from DESC
        SQL, [
            $itemId,
            $quantity,
            $documentDate->toDateString(),
            $currency,
            $documentDate->toDateString(),
            $documentDate->toDateString(),
            $partyId,
            $partyGroup,
        ]);

        /*
         | One line per LIST, keeping the best tier for each.
         |
         | The query returns every qualifying tier of every qualifying list,
         | and a discount line needs the next less specific LIST rather than
         | the next row - which would otherwise be another tier of the same
         | list, and a discount off itself.
         */
        $byList = [];

        foreach ($rows as $row) {
            $byList[$row->code] ??= $row;
        }

        return array_values($byList);
    }

    /**
     * A derived list's price: its base's price for the same date, re-run.
     *
     * Re-run and not copied. A wholesale list holding retail-less-fifteen
     * with the arithmetic already done stops agreeing with retail the
     * first time retail moves, and nobody notices because both numbers
     * look deliberate.
     *
     * Re-running also makes the derived price correct on dates in the
     * PAST: the base's point for that date is read and the rule applied,
     * so a credit note in April against a March invoice derives March's
     * figure.
     *
     * @param  list<string>  $seen  list ids already on this chain
     * @return array{unit_price: string, note: string}
     *
     * @throws NoPriceFound
     */
    private function derive(
        object $list,
        string $itemId,
        string $currency,
        Carbon $documentDate,
        array $seen = [],
    ): array {
        $baseId = (string) $list->derived_from_id;

        /*
         | A cycle is refused by name.
         |
         | The column constraint stops a list deriving from itself; two
         | lists deriving from each other is still expressible, and
         | without this the symptom is a stack overflow whose message
         | names a stack rather than a price list.
         */
        if (\in_array($baseId, $seen, true)) {
            throw NoPriceFound::aDerivationThatLoops((string) $list->code, $itemId);
        }

        $seen[] = (string) $list->id;

        $base = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT pl.id,
                   pl.code,
                   pl.derived_from_id,
                   pl.derivation_kind,
                   pl.derivation_percent,
                   pt.unit_price,
                   pt.effective_from
            FROM price_lists pl
            LEFT JOIN price_list_lines pll
                   ON pll.price_list_id = pl.id
                  AND pll.item_id = cast(? AS char(26))
            LEFT JOIN LATERAL (
                SELECT p.unit_price, p.effective_from
                FROM price_points p
                WHERE p.price_list_line_id = pll.id
                  AND p.effective_from <= ?
                  AND p.unit_price IS NOT NULL
                ORDER BY p.effective_from DESC
                LIMIT 1
            ) pt ON TRUE
            WHERE pl.id = cast(? AS char(26))
              AND pl.currency = cast(? AS char(3))
            /*
             | The base's own lowest tier, deliberately.
             |
             | A derivation is a rule about a list's PRICE, and a list
             | whose base has quantity breaks derives from the base
             | figure rather than from whichever break the order happens
             | to reach - otherwise the same order produces two
             | different derived prices depending on how much of it the
             | customer bought, which nobody can explain.
             */
            ORDER BY pll.min_quantity ASC NULLS LAST
            LIMIT 1
        SQL, [$itemId, $documentDate->toDateString(), $baseId, $currency]);

        if ($base === null) {
            throw NoPriceFound::aDerivationWithNoBase(
                (string) $list->code,
                $itemId,
                $currency,
                $documentDate->toDateString(),
            );
        }

        if ($base->unit_price === null) {
            if ($base->derived_from_id === null) {
                throw NoPriceFound::aDerivationWithNoBase(
                    (string) $list->code,
                    $itemId,
                    $currency,
                    $documentDate->toDateString(),
                );
            }

            // A chain: "export is wholesale less 5%, wholesale is retail
            // less 15%". Real, and resolved by recursion so the
            // derivation records every step.
            $beneath = $this->derive($base, $itemId, $currency, $documentDate, $seen);
            $baseAmount = $beneath['unit_price'];
            $baseNote = $beneath['note'];
        } else {
            $baseAmount = (string) $base->unit_price;
            $baseNote = sprintf('%s at %s', (string) $base->code, $baseAmount);
        }

        $percent = BigDecimal::of((string) $list->derivation_percent)
            ->dividedBy('100', 14, RoundingMode::HalfUp);

        /*
         | Three kinds, because operators use the three words
         | interchangeably and the arithmetic is not.
         |
         | A MARGIN is on the selling price - "we make 35% on it" - so
         | the base is divided by what is left of it. A MARKUP is on the
         | cost, so it is multiplied. A DISCOUNT is off the base. A
         | product that treated a 35% margin as a 35% markup underprices
         | every line by about a seventh, which is a year of profit
         | nobody can find.
         */
        $derived = match ((string) $list->derivation_kind) {
            'margin' => BigDecimal::of($baseAmount)
                ->dividedBy(BigDecimal::one()->minus($percent), Scale::UnitPrice->value, RoundingMode::HalfUp),
            'markup' => BigDecimal::of($baseAmount)
                ->multipliedBy(BigDecimal::one()->plus($percent))
                ->toScale(Scale::UnitPrice->value, RoundingMode::HalfUp),
            'discount' => BigDecimal::of($baseAmount)
                ->multipliedBy(BigDecimal::one()->minus($percent))
                ->toScale(Scale::UnitPrice->value, RoundingMode::HalfUp),
            default => throw NoPriceFound::anUnknownDerivation(
                (string) $list->code,
                (string) $list->derivation_kind,
            ),
        };

        return [
            'unit_price' => (string) $derived,
            'note' => sprintf(
                '%s derived as %s %s%% of %s',
                (string) $list->code,
                (string) $list->derivation_kind,
                (string) $list->derivation_percent,
                $baseNote,
            ),
        ];
    }

    /**
     * @param  list<object>  $remaining
     * @return array{
     *     unit_price: string, currency: string, list_id: string, list_code: string, list_name: string,
     *     scope: string, tier: string, valid_from: string, valid_to: ?string,
     *     derivation: string, beaten: list<string>,
     * }
     *
     * @throws NoPriceFound
     */
    private function applyDiscount(
        object $winner,
        array $remaining,
        string $itemId,
        string $currency,
        Carbon $documentDate,
    ): array {
        if ($remaining === []) {
            /*
             | A discount with nothing to discount.
             |
             | Refused rather than treated as the price itself or as zero:
             | "10% off" with no base is a configuration mistake, and both
             | alternatives invoice a number nobody chose.
             */
            throw NoPriceFound::discountWithNoBase(
                (string) $winner->code,
                $itemId,
                $currency,
                $documentDate->toDateString(),
            );
        }

        $base = array_shift($remaining);

        if ($base->unit_price === null || $base->discount_percent !== null) {
            // Two discounts stacked. Allowed deliberately - "10% off the
            // group list, which is itself 5% off general" is a real
            // arrangement - and resolved by recursion so the derivation
            // records both steps.
            $beneath = $this->applyDiscount($base, $remaining, $itemId, $currency, $documentDate);
            $baseAmount = $beneath['unit_price'];
            $baseNote = $beneath['derivation'];
        } else {
            $baseAmount = (string) $base->unit_price;
            $baseNote = sprintf('%s at %s', (string) $base->code, $baseAmount);
        }

        $factor = BigDecimal::one()->minus(
            BigDecimal::of((string) $winner->discount_percent)->dividedBy('100', 14, RoundingMode::HalfUp),
        );

        $discounted = BigDecimal::of($baseAmount)
            ->multipliedBy($factor)
            ->toScale(Scale::UnitPrice->value, RoundingMode::HalfUp);

        return $this->describe($winner, $remaining, (string) $discounted, $baseNote);
    }

    /**
     * @param  list<object>  $beaten
     * @return array{
     *     unit_price: string, currency: string, list_id: string, list_code: string, list_name: string,
     *     scope: string, tier: string, valid_from: string, valid_to: ?string,
     *     derivation: string, beaten: list<string>,
     * }
     */
    private function describe(object $winner, array $beaten, string $price, ?string $baseNote): array
    {
        $scope = match (true) {
            $winner->party_id !== null => 'this party',
            $winner->party_group !== null => sprintf('the "%s" group', (string) $winner->party_group),
            default => 'everyone',
        };

        $derivation = sprintf(
            'list %s (%s), for %s, priority %d, in force from %s%s; tier from %s units',
            (string) $winner->code,
            (string) $winner->name_en,
            $scope,
            (int) $winner->priority,
            (string) $winner->valid_from,
            $winner->valid_to === null ? ' with no end date' : ' to '.(string) $winner->valid_to,
            rtrim(rtrim((string) $winner->min_quantity, '0'), '.') ?: '0',
        );

        if ($baseNote !== null) {
            /*
             | A discount line and a derived LIST read differently.
             |
             | A discount line is a percentage off whatever the next
             | candidate resolved to, and `discount_percent` says how
             | much. A derived list computed its own figure and the note
             | already contains the rule, the percentage and the base -
             | so appending "% off" to it produced "; % off WHOLESALE
             | derived as discount 15% of RETAIL at 100", with an empty
             | percentage and the word "off" in front of a sentence that
             | was not a discount.
             |
             | A check asserted the note was in there and passed on
             | that, which is why the two branches are now separate.
             */
            $derivation .= $winner->discount_percent !== null
                ? sprintf('; %s%% off %s', rtrim(rtrim((string) $winner->discount_percent, '0'), '.'), $baseNote)
                : '; '.$baseNote;
        }

        return [
            'unit_price' => $price,
            'currency' => (string) ($winner->currency ?? ''),
            /*
             | The list's id as well as its code - PF-254.
             |
             | Because the snapshot on the invoice line is a foreign key:
             | a code is renameable, and a line that recorded only the
             | code cannot be traced back to the list a year later if
             | somebody tidied the codes up.
             */
            'list_id' => (string) ($winner->id ?? ''),
            'list_code' => (string) $winner->code,
            'list_name' => (string) $winner->name_en,
            'scope' => $scope,
            'tier' => (string) $winner->min_quantity,
            'valid_from' => (string) $winner->valid_from,
            'valid_to' => $winner->valid_to === null ? null : (string) $winner->valid_to,
            'derivation' => $derivation,
            // What it beat, so a screen can show the alternatives it
            // considered rather than only the answer.
            'beaten' => array_map(
                static fn (object $row): string => sprintf(
                    '%s (%s, priority %d)',
                    (string) $row->code,
                    $row->party_id !== null ? 'party' : ($row->party_group !== null ? 'group' : 'general'),
                    (int) $row->priority,
                ),
                $beaten,
            ),
        ];
    }
}
