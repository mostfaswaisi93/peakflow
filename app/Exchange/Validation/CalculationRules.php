<?php

declare(strict_types=1);

namespace App\Exchange\Validation;

use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticNode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The EN 16931 calculation rules, asserted directly - PF-116.
 *
 * ## Why these are arithmetic in PHP and not left to the Schematron
 *
 * They are the rules a document fails when the totals were computed wrong,
 * and the Schematron reports them as XPath comparisons that say which two
 * numbers disagree but not which part of the system produced them.
 *
 * More importantly: a document reaching stage 3 with a calculation error is
 * **a bug in `Tax` or in the serializer**, not a customer-facing validation
 * failure. The invoice was built from stored, frozen figures that the ledger
 * has already posted - if `BT-110` does not equal the sum of the breakdown,
 * the document and the journal entry disagree, and asking the customer to
 * correct it would be asking them to fix our arithmetic. So every failure
 * here is reported as internal, and the pipeline treats it as an error to
 * be fixed rather than a message to be shown.
 *
 * ## Tolerance
 *
 * `BR-CO-17` is the only rule with a stated tolerance: VAT per group is
 * `taxable × rate / 100` rounded to two decimals, and EN 16931 allows the
 * stated amount to differ from the recomputation by up to 0.02 - because
 * implementations round the rate, the base or the product, and all three are
 * defensible. The other rules are exact: a sum of stored figures that does
 * not add up is not a rounding question.
 *
 * Comparison is on `BigDecimal`, never on floats. `0.1 + 0.2 !== 0.3` in
 * binary floating point, and a tax total compared that way fails on a
 * document that is correct.
 */
final class CalculationRules
{
    /** EN 16931's stated tolerance on the per-group VAT recomputation. */
    private const BR_CO_17_TOLERANCE = '0.02';

    /** @return list<SemanticViolation> */
    public function check(SemanticNode $root): array
    {
        $totals = $root->first(BG::DocumentTotals);

        if ($totals === null) {
            // The cardinality walk has already reported BG-22 as missing,
            // and arithmetic over absent figures would report it twice in
            // a vocabulary that says less.
            return [];
        }

        return [
            ...$this->lineNetSum($root, $totals),
            ...$this->allowanceAndChargeSums($root, $totals),
            ...$this->netTotal($totals),
            ...$this->vatTotal($root, $totals),
            ...$this->grossTotal($totals),
            ...$this->amountDue($totals),
            ...$this->vatPerGroup($root),
        ];
    }

    /**
     * BR-CO-10: BT-106 = Σ BT-131.
     *
     * @return list<SemanticViolation>
     */
    private function lineNetSum(SemanticNode $root, SemanticNode $totals): array
    {
        $sum = BigDecimal::zero();

        foreach ($root->children(BG::InvoiceLine) as $line) {
            $sum = $sum->plus($this->decimal($line->get(BT::LineNetAmount)));
        }

        return $this->compare('BR-CO-10', BT::SumOfLineNetAmounts, $totals->get(BT::SumOfLineNetAmounts), $sum);
    }

    /**
     * BR-CO-11 and BR-CO-12: the allowance and charge sums.
     *
     * @return list<SemanticViolation>
     */
    private function allowanceAndChargeSums(SemanticNode $root, SemanticNode $totals): array
    {
        $violations = [];

        $pairs = [
            ['BR-CO-11', BG::DocumentLevelAllowances, BT::DocumentAllowanceAmount, BT::SumOfAllowances],
            ['BR-CO-12', BG::DocumentLevelCharges, BT::DocumentChargeAmount, BT::SumOfCharges],
        ];

        foreach ($pairs as [$rule, $group, $amount, $total]) {
            $instances = $root->children($group);

            if ($instances === [] && ! $totals->has($total)) {
                continue;
            }

            $sum = BigDecimal::zero();

            foreach ($instances as $instance) {
                $sum = $sum->plus($this->decimal($instance->get($amount)));
            }

            /*
             | An adjustment with no total is a violation, not an absence.
             |
             | `compare()` treats a missing stated value as a cardinality
             | question and says nothing, which is right for a document that
             | has no allowances at all. It is wrong here: the sum of a
             | non-empty set is not absent, and a document carrying a 40.00
             | discount whose BT-107 is missing reads to the recipient as a
             | document with no discount - while its net total has been
             | reduced by one.
             |
             | Found by writing a Peppol check that needed a document with an
             | allowance and no total, and getting a passing stage 3.
             */
            if ($instances !== [] && ! $totals->has($total)) {
                $violations[] = SemanticViolation::internal(
                    $rule,
                    term: $total,
                    value: (string) $sum,
                    detail: sprintf(
                        'The document carries %d %s totalling %s and %s is absent, so the recipient '.
                        'reads a document with none.',
                        \count($instances),
                        $group->label(),
                        (string) $sum,
                        $total->value,
                    ),
                );

                continue;
            }

            $violations = [
                ...$violations,
                ...$this->compare($rule, $total, $totals->get($total), $sum),
            ];
        }

        return $violations;
    }

    /**
     * BR-CO-13: BT-109 = BT-106 − BT-107 + BT-108.
     *
     * @return list<SemanticViolation>
     */
    private function netTotal(SemanticNode $totals): array
    {
        $expected = $this->decimal($totals->get(BT::SumOfLineNetAmounts))
            ->minus($this->decimal($totals->get(BT::SumOfAllowances)))
            ->plus($this->decimal($totals->get(BT::SumOfCharges)));

        return $this->compare('BR-CO-13', BT::TotalWithoutVat, $totals->get(BT::TotalWithoutVat), $expected);
    }

    /**
     * BR-CO-14: BT-110 = Σ BT-117.
     *
     * The rule the whole grouped-tax design exists for. Tax summed from
     * per-line amounts and tax summed from per-group amounts differ by cents
     * on any document with more than a few lines, and this is the rule that
     * rejects the first shape - which is why the engine accumulates a rate
     * group unrounded and rounds it once.
     *
     * @return list<SemanticViolation>
     */
    private function vatTotal(SemanticNode $root, SemanticNode $totals): array
    {
        $groups = $root->children(BG::VatBreakdown);

        if ($groups === [] && ! $totals->has(BT::TotalVatAmount)) {
            return [];
        }

        $sum = BigDecimal::zero();

        foreach ($groups as $group) {
            $sum = $sum->plus($this->decimal($group->get(BT::VatCategoryTaxAmount)));
        }

        return $this->compare('BR-CO-14', BT::TotalVatAmount, $totals->get(BT::TotalVatAmount), $sum);
    }

    /**
     * BR-CO-15: BT-112 = BT-109 + BT-110.
     *
     * @return list<SemanticViolation>
     */
    private function grossTotal(SemanticNode $totals): array
    {
        $expected = $this->decimal($totals->get(BT::TotalWithoutVat))
            ->plus($this->decimal($totals->get(BT::TotalVatAmount)));

        return $this->compare('BR-CO-15', BT::TotalWithVat, $totals->get(BT::TotalWithVat), $expected);
    }

    /**
     * BR-CO-16: BT-115 = BT-112 − BT-113 + BT-114.
     *
     * @return list<SemanticViolation>
     */
    private function amountDue(SemanticNode $totals): array
    {
        $expected = $this->decimal($totals->get(BT::TotalWithVat))
            ->minus($this->decimal($totals->get(BT::PaidAmount)))
            ->plus($this->decimal($totals->get(BT::RoundingAmount)));

        return $this->compare('BR-CO-16', BT::AmountDueForPayment, $totals->get(BT::AmountDueForPayment), $expected);
    }

    /**
     * BR-CO-17: BT-117 = BT-116 × BT-119 ÷ 100, within 0.02.
     *
     * @return list<SemanticViolation>
     */
    private function vatPerGroup(SemanticNode $root): array
    {
        $violations = [];
        $tolerance = BigDecimal::of(self::BR_CO_17_TOLERANCE);

        foreach ($root->children(BG::VatBreakdown) as $index => $group) {
            if (! $group->has(BT::VatCategoryRate)) {
                // A group with no rate - "not subject to VAT" - has nothing
                // to recompute, and BR-48 is the rule about a missing rate.
                continue;
            }

            $expected = $this->decimal($group->get(BT::VatCategoryTaxableAmount))
                ->multipliedBy($this->decimal($group->get(BT::VatCategoryRate)))
                ->dividedBy('100', 4, RoundingMode::HalfUp)
                ->toScale(2, RoundingMode::HalfUp);

            $stated = $this->decimal($group->get(BT::VatCategoryTaxAmount))->toScale(2, RoundingMode::HalfUp);
            $difference = $stated->minus($expected)->abs();

            if ($difference->isGreaterThan($tolerance)) {
                $violations[] = SemanticViolation::internal(
                    'BR-CO-17',
                    term: BT::VatCategoryTaxAmount,
                    value: (string) $group->get(BT::VatCategoryTaxAmount),
                    detail: sprintf(
                        '%s at %s%% on %s is %s and the breakdown states %s, a difference of %s against '.
                        'a tolerance of %s.',
                        (string) $group->get(BT::VatCategoryCode),
                        (string) $group->get(BT::VatCategoryRate),
                        (string) $group->get(BT::VatCategoryTaxableAmount),
                        (string) $expected,
                        (string) $stated,
                        (string) $difference,
                        self::BR_CO_17_TOLERANCE,
                    ),
                )->withPath(sprintf('document / BG-23[%d]', $index + 1));
            }
        }

        return $violations;
    }

    /**
     * Compare a stated total against the recomputation.
     *
     * Scaled to two decimals on both sides: the document states amounts at
     * the currency's own exponent, and comparing a stored `1150.0000`
     * against a computed `1150.00` would fail on a document that is right.
     *
     * @return list<SemanticViolation>
     */
    private function compare(string $rule, BT $term, ?string $stated, BigDecimal $expected): array
    {
        if ($stated === null) {
            // Absent is a cardinality question, answered by the other walk.
            return [];
        }

        $statedScaled = $this->decimal($stated)->toScale(2, RoundingMode::HalfUp);
        $expectedScaled = $expected->toScale(2, RoundingMode::HalfUp);

        if ($statedScaled->isEqualTo($expectedScaled)) {
            return [];
        }

        return [SemanticViolation::internal(
            $rule,
            term: $term,
            value: $stated,
            detail: sprintf(
                '%s states %s and the figures it is computed from give %s. The document and the journal '.
                'entry behind it disagree.',
                $term->value,
                (string) $statedScaled,
                (string) $expectedScaled,
            ),
        )];
    }

    private function decimal(?string $value): BigDecimal
    {
        if ($value === null || trim($value) === '') {
            return BigDecimal::zero();
        }

        try {
            return BigDecimal::of(trim($value));
        } catch (\Throwable) {
            return BigDecimal::zero();
        }
    }
}
