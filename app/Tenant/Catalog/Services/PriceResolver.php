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
        if ($winner->unit_price === null || $winner->discount_percent !== null) {
            return $this->applyDiscount($winner, $candidates, $itemId, $currency, $documentDate);
        }

        return $this->describe($winner, $candidates, (string) $winner->unit_price, null);
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
                pl.code,
                pl.currency,
                pl.name_en,
                pl.name_ar,
                pl.party_id,
                pl.party_group,
                pl.priority,
                pl.valid_from,
                pl.valid_to,
                pll.unit_price,
                pll.discount_percent,
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
            FROM price_list_lines pll
            JOIN price_lists pl ON pl.id = pll.price_list_id
            WHERE pll.item_id = cast(? AS char(26))
              AND pl.currency = cast(? AS char(3))
              AND pl.active
              AND pl.valid_from <= ?
              AND (pl.valid_to IS NULL OR pl.valid_to >= ?)
              AND pll.min_quantity <= cast(? AS numeric(21,8))
              AND (
                    (pl.party_id IS NULL AND pl.party_group IS NULL)
                 OR pl.party_id = cast(? AS char(26))
                 OR pl.party_group = ?
              )
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
                 */
                pll.min_quantity DESC,
                pl.valid_from DESC
        SQL, [
            $itemId,
            $currency,
            $documentDate->toDateString(),
            $documentDate->toDateString(),
            $quantity,
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
     * @param  list<object>  $remaining
     * @return array{
     *     unit_price: string, currency: string, list_code: string, list_name: string,
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
     *     unit_price: string, currency: string, list_code: string, list_name: string,
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
            $derivation .= sprintf(
                '; %s%% off %s',
                rtrim(rtrim((string) $winner->discount_percent, '0'), '.'),
                $baseNote,
            );
        }

        return [
            'unit_price' => $price,
            'currency' => (string) ($winner->currency ?? ''),
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
