<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Support\Money\Money;
use App\Support\Money\RoundingBasis;
use App\Support\Money\RoundingPolicy;
use App\Support\Money\Scale;
use App\Tenant\Tax\DocumentAdjustment;
use App\Tenant\Tax\Enums\TaxCategory;
use App\Tenant\Tax\Exceptions\TaxComputationRefused;
use App\Tenant\Tax\Services\EvidenceGate;
use App\Tenant\Tax\TaxableLine;
use App\Tenant\Tax\TaxBreakdown;
use App\Tenant\Tax\TaxBreakdownEntry;
use Brick\Math\BigDecimal;

/**
 * Computes a document's tax - EN 16931 `BG-23` and `BR-CO-14`, PF-082.
 *
 * ## Accumulate unrounded, round once per group
 *
 * The order of operations is the whole of this class:
 *
 *   1. group the lines by `(category, rate)` - `BG-23`'s own key
 *   2. apportion document-level adjustments across the groups, before tax
 *   3. sum each group's net at the storage scale, unrounded
 *   4. compute and round that group's tax once, under the policy
 *   5. the document's tax total is the sum of the entries - `BR-CO-14`
 *
 * Rounding earlier than step 4 is the defect this exists to prevent, and it is
 * invisible on any single document: three lines of 10.03 at 15% are 4.51 one
 * way and 4.50 the other. One cent per document across fifty thousand
 * documents a quarter is a reconciliation difference between the ledger and
 * the filed return that nobody can locate, because every individual document
 * looks right.
 *
 * ## Recomputation is identical, and that matters more than being a cent right
 *
 * A document that recomputes to a different total cannot be reconciled with
 * anything, including itself. Every step here is integer and decimal
 * arithmetic with an explicit rounding policy, the apportionment residual goes
 * to a named group by rule, and the group order is the order the categories
 * and rates first appear. No iteration over a hash, no float, no `usort` on
 * equal keys.
 *
 * @see docs/architecture/money-and-precision.md
 */
final class TaxCalculator
{
    public function __construct(private readonly EvidenceGate $gate) {}

    /**
     * @param  list<TaxableLine>  $lines
     * @param  list<DocumentAdjustment>  $adjustments
     *
     * @throws TaxComputationRefused
     */
    public function compute(
        array $lines,
        RoundingPolicy $policy,
        array $adjustments = [],
        ?string $locale = null,
    ): TaxBreakdown {
        if ($lines === []) {
            throw TaxComputationRefused::withNoLines();
        }

        $currency = $lines[0]->net->currency;
        $groups = $this->group($lines, $currency);
        $groups = $this->apportion($groups, $adjustments, $currency);

        $entries = [];
        $tax = Money::zero($currency);
        $net = Money::zero($currency);

        foreach ($groups as $group) {
            $taxable = $group['net'];
            $groupTax = $this->taxFor($group, $policy, $currency);

            $code = $group['reason'];

            $entries[] = new TaxBreakdownEntry(
                $group['category'],
                $group['rate'],
                $taxable,
                $groupTax,
                $code,
                $code === null ? null : $this->gate->reasonTextFor($code, $locale),
            );

            $net = $net->plus($taxable);
            $tax = $tax->plus($groupTax);
        }

        return new TaxBreakdown($entries, $net, $tax);
    }

    /**
     * Group by category and rate, in the order they first appear.
     *
     * First-appearance order rather than sorted, because it is what a reader
     * of the document expects and because it is stable without a comparator -
     * a sort on equal keys is where "recomputing gives identical output"
     * quietly stops being true.
     *
     * @param  list<TaxableLine>  $lines
     * @return list<array{category: TaxCategory, rate: string, net: Money, lines: list<Money>, reason: string|null}>
     *
     * @throws TaxComputationRefused
     */
    private function group(array $lines, string $currency): array
    {
        $groups = [];
        $order = [];

        foreach ($lines as $line) {
            if ($line->net->currency !== $currency) {
                throw TaxComputationRefused::mixedCurrencies($currency, $line->net->currency);
            }

            $rate = $line->normalisedRate();
            $zero = BigDecimal::of($rate)->isZero();

            if ($line->category->requiresZeroRate() && ! $zero) {
                throw TaxComputationRefused::aZeroCategoryCarriesARate($line->category->value, $rate);
            }

            if (! $line->category->requiresZeroRate() && $zero) {
                throw TaxComputationRefused::aTaxableCategoryCarriesNoRate($line->category->value);
            }

            $key = $line->groupKey();

            if (! isset($groups[$key])) {
                $order[] = $key;
                $groups[$key] = [
                    'category' => $line->category,
                    'rate' => $rate,
                    'net' => Money::zero($currency),
                    'lines' => [],
                    'reason' => $line->reasonCode,
                ];
            }

            $groups[$key]['net'] = $groups[$key]['net']->plus($line->net);
            $groups[$key]['lines'][] = $line->net;
            $groups[$key]['reason'] ??= $line->reasonCode;
        }

        return array_map(static fn (string $key): array => $groups[$key], $order);
    }

    /**
     * Spread document-level adjustments across the groups, before tax.
     *
     * Weighted by each group's POSITIVE taxable amount. A credit line gets no
     * share of a discount: apportioning by absolute value would spread the
     * discount onto the credit and make the credit larger, which is the
     * opposite of what was asked for.
     *
     * The residual goes where `Money::allocate()` puts it - the heaviest
     * weight, first on a tie - which is a rule rather than an accident of loop
     * order, and is the same rule on every recomputation.
     *
     * @param  list<array{category: TaxCategory, rate: string, net: Money, lines: list<Money>, reason: string|null}>  $groups
     * @param  list<DocumentAdjustment>  $adjustments
     * @return list<array{category: TaxCategory, rate: string, net: Money, lines: list<Money>, reason: string|null}>
     *
     * @throws TaxComputationRefused
     */
    private function apportion(array $groups, array $adjustments, string $currency): array
    {
        if ($adjustments === []) {
            return $groups;
        }

        $total = Money::zero($currency);

        foreach ($adjustments as $adjustment) {
            if ($adjustment->amount->currency !== $currency) {
                throw TaxComputationRefused::adjustmentInAnotherCurrency(
                    $currency, $adjustment->amount->currency,
                );
            }

            $total = $total->plus($adjustment->signed());
        }

        if ($total->isZero()) {
            return $groups;
        }

        $weights = [];

        foreach ($groups as $group) {
            $weights[] = $group['net']->isPositive() ? $group['net']->amount : BigDecimal::zero();
        }

        $positive = BigDecimal::zero();

        foreach ($weights as $weight) {
            $positive = $positive->plus($weight);
        }

        if (! $positive->isPositive()) {
            throw TaxComputationRefused::nothingToApportionAcross((string) $total);
        }

        /*
         | Apportioned at the STORAGE scale, not the currency's.
         |
         | The shares are intermediate: they change a group's taxable amount,
         | which is itself rounded later. Rounding them to the currency first
         | would round twice, and the second rounding would be applied to an
         | already-rounded number - which is how a 7% discount on a hundred
         | lines ends up a cent away from 7%.
         */
        $shares = $total->allocate($weights, RoundingPolicy::down(Scale::Amount));

        foreach ($groups as $index => $group) {
            $groups[$index]['net'] = $group['net']->plus($shares[$index]);

            /*
             | And the lines move with it, so the per-line basis apportions
             | too. Spread within the group by the same rule, so a per-line
             | document and a per-group document see the same taxable amounts.
             */
            if ($shares[$index]->isZero()) {
                continue;
            }

            $lineWeights = [];

            foreach ($group['lines'] as $line) {
                $lineWeights[] = $line->isPositive() ? $line->amount : BigDecimal::zero();
            }

            $lineTotal = BigDecimal::zero();

            foreach ($lineWeights as $weight) {
                $lineTotal = $lineTotal->plus($weight);
            }

            if (! $lineTotal->isPositive()) {
                continue;
            }

            $lineShares = $shares[$index]->allocate($lineWeights, RoundingPolicy::down(Scale::Amount));

            foreach ($group['lines'] as $line => $amount) {
                $groups[$index]['lines'][$line] = $amount->plus($lineShares[$line]);
            }
        }

        return $groups;
    }

    /**
     * One group's tax, rounded once - or per line, where a regime says so.
     *
     * @param  array{category: TaxCategory, rate: string, net: Money, lines: list<Money>, reason: string|null}  $group
     */
    private function taxFor(array $group, RoundingPolicy $policy, string $currency): Money
    {
        if ($group['category']->requiresZeroRate()) {
            return Money::zero($currency);
        }

        $fraction = BigDecimal::of($group['rate'])->dividedBy('100', Scale::Rate->value);

        if ($policy->basis === RoundingBasis::PerLine) {
            $tax = Money::zero($currency);

            foreach ($group['lines'] as $line) {
                $tax = $tax->plus(Money::of(
                    $line->multipliedBy($fraction, $policy)->toPresentation($policy),
                    $currency,
                ));
            }

            return $tax;
        }

        return Money::of(
            $group['net']->multipliedBy($fraction, $policy)->toPresentation($policy),
            $currency,
        );
    }
}
