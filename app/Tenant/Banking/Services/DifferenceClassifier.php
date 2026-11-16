<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Services;

use App\Support\Money\Money;
use App\Tenant\Banking\Enums\DifferenceType;

/**
 * What kind of difference this is - PF-164.
 *
 * ## It proposes; it never decides
 *
 * The same division as the matching engine, for the same reason. A
 * classifier that applied its own answer would write a customer's
 * withholding off to bad debt with the confidence of a machine, and the
 * only sign would be a tax credit never claimed.
 *
 * So this returns a type, a confidence and **why** - and something with a
 * person behind it records the difference.
 *
 * ## The order is not arbitrary
 *
 * Withholding is tested first, before anything else can claim the
 * difference, because it is the one whose misclassification costs real
 * money rather than reporting quality: treated as a short payment it is
 * written off to bad debt, the credit is never claimed, and the loss is a
 * few percent of revenue in every withholding jurisdiction the business
 * sells into - quietly, every individual write-off looking like a small
 * dispute.
 *
 * Rounding is tested next, because it is the only one that can be
 * recognised with certainty: a difference below the smallest unit the two
 * currencies can express is arithmetic and nothing else.
 *
 * Bank charges, exchange differences and short payments come after, in
 * decreasing order of how recognisable they are. The last of them is also
 * the default, and that is deliberate: when nothing else fits, "the
 * customer did not pay all of it" is the answer that gets somebody to
 * look, and the alternatives are answers that get it filed.
 */
final class DifferenceClassifier
{
    /**
     * @param  array{
     *     expected: Money,
     *     received: Money,
     *     withholding_rate: string|null,
     *     counterparty_is_a_bank: bool,
     *     rate_moved: bool,
     * }  $facts
     * @return array{type: DifferenceType, amount: Money, confidence: float, why: string, alternatives: list<string>}
     */
    public function classify(array $facts): array
    {
        $expected = $facts['expected'];
        $received = $facts['received'];

        /*
         | The shortfall, signed from our side.
         |
         | Positive where it costs us. Every rule below reads this one
         | figure, so a classifier cannot be right about the kind of
         | difference and wrong about its direction.
         */
        $difference = $expected->minus($received);
        $magnitude = $difference->abs();

        if ($difference->isNegative()) {
            return $this->answer(
                DifferenceType::Overpayment,
                $difference,
                0.95,
                sprintf(
                    'the payment exceeded the document by %s, which is money owed back to the payer '.
                    'rather than ours',
                    (string) $magnitude->toPresentation(),
                ),
                [],
            );
        }

        $rate = $facts['withholding_rate'] ?? null;

        if ($rate !== null && $this->matchesTheRate($expected, $magnitude, $rate)) {
            /*
             | Tested first, and on the arithmetic rather than on a guess.
             |
             | The shortfall is the withholding rate applied to the
             | document. That is a far stronger signal than "this customer
             | usually withholds", and it is the one that stops a genuine
             | short payment from a withholding customer being filed as a
             | tax credit nobody can certify.
             */
            return $this->answer(
                DifferenceType::Withholding,
                $difference,
                0.90,
                sprintf(
                    'the shortfall is exactly %s%% of the document, which is the withholding rate '.
                    'on file for this party - so this is tax they deducted and will certify, not '.
                    'money they declined to pay',
                    $rate,
                ),
                ['a short payment that happens to equal the withholding rate, which is worth a glance at the certificate'],
            );
        }

        if ($this->isRounding($magnitude, $expected->currency)) {
            return $this->answer(
                DifferenceType::Rounding,
                $difference,
                0.99,
                sprintf(
                    'the difference is %s, which is the smallest unit this currency expresses - two '.
                    'correct figures that disagree by one',
                    (string) $magnitude->toPresentation(),
                ),
                [],
            );
        }

        if ($facts['counterparty_is_a_bank'] === true) {
            return $this->answer(
                DifferenceType::BankCharge,
                $difference,
                0.80,
                'the counterparty on this line is the bank itself, so the difference is what the '.
                'bank took rather than anything the payer decided',
                ['a transfer fee the payer deducted, which is also a bank charge but is theirs and not ours'],
            );
        }

        if ($facts['rate_moved'] === true) {
            return $this->answer(
                DifferenceType::FxDifference,
                $difference,
                0.75,
                'the document and the payment are in different currencies and the rate moved between '.
                'them, so the shortfall is what the movement is worth rather than money unpaid',
                ['a short payment in the payment currency, which the rate movement would be hiding'],
            );
        }

        /*
         | The default, and chosen as the default deliberately.
         |
         | When nothing else fits, "the customer did not pay all of it" is
         | the answer that gets somebody to look at it. Every alternative -
         | rounding, a charge, a rate - is an answer that gets it filed,
         | and the one difference somebody should have chased would be the
         | one nobody ever sees.
         */
        return $this->answer(
            DifferenceType::ShortPayment,
            $difference,
            0.50,
            sprintf(
                'nothing explains the %s shortfall - no withholding rate on file matches it, it is '.
                'larger than rounding, the counterparty is not the bank and no rate moved. That '.
                'leaves the payer having paid less than the document',
                (string) $magnitude->toPresentation(),
            ),
            [
                'a bank charge the payer deducted, if their bank takes a fee from the transfer',
                'withholding, if this party deducts tax and no rate is on file for them yet',
            ],
        );
    }

    /**
     * @param  list<string>  $alternatives
     * @return array{type: DifferenceType, amount: Money, confidence: float, why: string, alternatives: list<string>}
     */
    private function answer(
        DifferenceType $type,
        Money $amount,
        float $confidence,  // @arch-allow: a confidence is a proportion, not money - the same as a matching candidate's.
        string $why,
        array $alternatives,
    ): array {
        return [
            'type' => $type,
            'amount' => $amount,
            'confidence' => $confidence,
            'why' => $why,
            'alternatives' => $alternatives,
        ];
    }

    /**
     * Whether the shortfall is the withholding rate applied to the document.
     *
     * Compared at the currency's own scale rather than exactly: a customer
     * computing 5% of 10,000.00 and rounding it their way produces 500.00
     * and so do we, but 5% of 333.33 is 16.6665 and which side rounds
     * decides whether it is 16.67 or 16.66. Demanding an exact match would
     * reject the rate on every document whose total is not round, which is
     * most of them.
     */
    private function matchesTheRate(Money $expected, Money $shortfall, string $rate): bool
    {
        $expectedWithholding = $expected->amount
            ->multipliedBy($rate)
            ->dividedBy('100', 4, \Brick\Math\RoundingMode::HalfUp);

        $gap = $expectedWithholding->minus($shortfall->amount)->abs();

        /*
         | Within one unit of the currency's smallest.
         |
         | Not a tolerance anybody configures: it is the arithmetic of two
         | people rounding the same percentage, and making it adjustable
         | would turn a recognition rule into a lever for classifying
         | short payments as tax credits.
         */
        return $gap->isLessThanOrEqualTo($this->smallestUnit($expected->currency));
    }

    private function isRounding(Money $magnitude, string $currency): bool
    {
        return $magnitude->amount->isLessThanOrEqualTo($this->smallestUnit($currency));
    }

    /**
     * One unit at the currency's own scale: 0.01 for SAR, 1 for JPY.
     *
     * From the registry rather than a constant, because the constant would
     * be 0.01 and a three-decimal currency's smallest unit is 0.001. A
     * rounding rule that called 0.009 "rounding" in JOD would absorb nine
     * times the unit and call it arithmetic.
     */
    private function smallestUnit(string $currency): \Brick\Math\BigDecimal
    {
        $exponent = \App\Support\Iso\CurrencyRegistry::exponent($currency);

        /*
         | One, at the currency's scale: 0.01 for SAR, 1 for JPY.
         |
         | Built from an unscaled value rather than by dividing, which is
         | exact and also keeps this out of the way of the rule that
         | refuses `one()->dividedBy(...)` anywhere in the money path. That
         | rule is about inverse exchange rates and this is not one, but a
         | pattern that has to be argued about each time it appears is a
         | pattern worth not writing.
         */
        return \Brick\Math\BigDecimal::ofUnscaledValue(1, $exponent);
    }
}
