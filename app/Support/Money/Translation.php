<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Money\Exceptions\RateDirectionMismatch;
use JsonSerializable;

/**
 * An amount, the rate it was translated at, and the result - PF-068.
 *
 * ## Why these three travel together
 *
 * Every one of them is needed later and none of them can be derived from the
 * others afterwards:
 *
 *   - the ORIGINAL is what the customer was invoiced, and what the document
 *     must keep saying forever
 *   - the RATE is the answer to "why is this figure in the books what it is",
 *     which is asked at every audit
 *   - the FUNCTIONAL amount is what the ledger balances on, and it cannot be
 *     recomputed later because the rate may since have been corrected -
 *     recomputing would silently restate closed periods
 *
 * Passing them separately is what goes wrong in practice: a function takes an
 * amount and a rate, and somewhere between the two a different rate gets used
 * for the second line of the same invoice. Constructed together, the three
 * cannot disagree, because the only way to make one is to convert.
 *
 * ## The constructor is private on purpose
 *
 * `of()` performs the conversion. There is no way to assert a functional
 * amount that does not follow from the rate, which is the mistake the
 * database trigger on `journal_lines` also refuses - deliberately twice, at
 * the two places it can be made.
 *
 * ## There is no Translation at a rate of 1
 *
 * An amount that needed no translation is a plain `Money` and no Translation
 * at all. The absence is what records that nothing was converted, because a
 * rate of 1 asserts that a translation happened and came out even - a
 * different claim, and one that cannot be told apart from the first
 * afterwards. Every caller here holds a `?Translation`, and the null branch
 * is the functional-currency case.
 */
final readonly class Translation implements JsonSerializable
{
    private function __construct(
        public Money $original,
        public Rate $rate,
        public Money $functional,
    ) {}

    /**
     * Translate an amount, keeping the rate that did it.
     *
     * @throws RateDirectionMismatch if the rate does not go this way
     */
    public static function of(Money $original, Rate $rate, RoundingPolicy $policy): self
    {
        // Rate::convert refuses the wrong direction, including the case where
        // the amount is in the rate's `to` currency - which is where someone
        // would otherwise reach for the inverse.
        return new self($original, $rate, $rate->convert($original, $policy));
    }

    public function isFunctional(): bool
    {
        return $this->original->currency === $this->functional->currency;
    }

    public function describe(): string
    {
        return sprintf(
            '%s at %s (%s, %s, %s) = %s',
            $this->original,
            $this->rate->value,
            $this->rate->pair(),
            $this->rate->rateSet,
            $this->rate->date->format('Y-m-d'),
            $this->functional,
        );
    }

    /** @return array{original: array<string, string>, rate: array<string, string>, functional: array<string, string>} */
    public function jsonSerialize(): array
    {
        return [
            'original' => $this->original->jsonSerialize(),
            'rate' => $this->rate->jsonSerialize(),
            'functional' => $this->functional->jsonSerialize(),
        ];
    }
}
