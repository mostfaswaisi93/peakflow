<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Iso\CurrencyRegistry;
use App\Support\Money\Exceptions\CurrencyMismatch;
use App\Support\Money\Exceptions\InvalidAllocation;
use App\Support\Money\Exceptions\PrecisionLoss;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use JsonSerializable;
use NumberFormatter;
use Stringable;

/**
 * An exact decimal amount in a known currency.
 *
 * Always held at {@see Scale::Amount} - four decimals - whatever the
 * currency's own exponent is. The currency's exponent is a PRESENTATION
 * concern applied by {@see self::toPresentation()}; a `JPY` amount still
 * carries four decimals in storage, and a `JOD` amount keeps its third.
 *
 * ## Addition needs no rounding policy. Multiplication does.
 *
 * Two amounts at the same scale add exactly, so `plus()` and `minus()` take no
 * policy - requiring a meaningless one would train people to pass whatever is
 * nearest. `multipliedBy()` and `dividedBy()` can lose precision, so they
 * demand one explicitly. There is no default anywhere in this class.
 *
 * @see docs/architecture/money-and-precision.md
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public BigDecimal $amount,
        public string $currency,
    ) {}

    /**
     * An exact amount.
     *
     * REFUSES a value carrying more precision than the amount scale rather
     * than rounding it. A silent round here is how an unrounded intermediate
     * becomes a legal total.
     */
    public static function of(BigDecimal|BigNumber|string|int $amount, string $currency): self
    {
        $currency = CurrencyRegistry::assertExists($currency);
        $decimal = BigDecimal::of($amount);

        try {
            $decimal = $decimal->toScale(Scale::Amount->value, RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException) {
            throw PrecisionLoss::atScale((string) $decimal, Scale::Amount->value);
        }

        return new self($decimal, $currency);
    }

    /** An amount rounded deliberately, under a named policy. */
    public static function ofRounded(
        BigDecimal|BigNumber|string|int $amount,
        string $currency,
        RoundingPolicy $policy,
    ): self {
        return new self(
            BigDecimal::of($amount)->toScale(Scale::Amount->value, $policy->mode),
            CurrencyRegistry::assertExists($currency),
        );
    }

    public static function zero(string $currency): self
    {
        return self::of('0', $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    public function multipliedBy(BigDecimal|BigNumber|string|int $factor, RoundingPolicy $policy): self
    {
        return new self(
            $this->amount->multipliedBy($factor)->toScale(Scale::Amount->value, $policy->mode),
            $this->currency,
        );
    }

    public function dividedBy(BigDecimal|BigNumber|string|int $divisor, RoundingPolicy $policy): self
    {
        return new self(
            $this->amount->dividedBy($divisor, Scale::Amount->value, $policy->mode),
            $this->currency,
        );
    }

    public function negated(): self
    {
        return new self($this->amount->negated(), $this->currency);
    }

    public function abs(): self
    {
        return new self($this->amount->abs(), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount->compareTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->amount->compareTo($other->amount) === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Apportion this amount across weights, exactly.
     *
     * A document-level 7% discount across three rate groups will not divide
     * evenly, and the residual has to go somewhere **by rule** - not to the
     * first group because the loop started there, and not left to floating
     * point. The shares are computed toward zero so the residual is explicit
     * and carries the amount's own sign, then the whole residual is assigned
     * to the heaviest weight.
     *
     * Stated once, tested, and identical on every recomputation: a document
     * that recomputes to a different total is worse than one that is a cent
     * off.
     *
     * **Tie-break:** where several weights are equally the largest, the
     * residual goes to the FIRST of them. Three equal groups therefore put it
     * on group one, every time. That is arbitrary but it is fixed, which is
     * the only property that matters here.
     *
     * @param  non-empty-list<BigDecimal|BigNumber|string|int>  $weights
     * @return list<self>
     */
    public function allocate(array $weights, RoundingPolicy $policy): array
    {
        if ($weights === []) {
            throw InvalidAllocation::noWeights();
        }

        $decimals = [];
        $total = BigDecimal::zero();

        foreach ($weights as $weight) {
            $decimal = BigDecimal::of($weight);

            if ($decimal->isNegative()) {
                throw InvalidAllocation::negativeWeight((string) $decimal);
            }

            $decimals[] = $decimal;
            $total = $total->plus($decimal);
        }

        if (! $total->isPositive()) {
            throw InvalidAllocation::nonPositiveTotal();
        }

        $shares = [];
        $allocated = BigDecimal::zero();

        foreach ($decimals as $decimal) {
            // Toward zero, so the residual has the same sign as the amount and
            // no share is ever rounded past the total.
            $share = $this->amount
                ->multipliedBy($decimal)
                ->dividedBy($total, Scale::Amount->value, RoundingMode::Down);

            $shares[] = $share;
            $allocated = $allocated->plus($share);
        }

        $residual = $this->amount->minus($allocated);

        if (! $residual->isZero()) {
            $index = $this->residualIndex($decimals);
            $shares[$index] = $shares[$index]->plus($residual);
        }

        return array_map(
            fn (BigDecimal $share): self => new self($share, $this->currency),
            $shares,
        );
    }

    /**
     * Rounded to the currency's own ISO 4217 exponent.
     *
     * This is a VIEW, computed at render and payment time. It is never stored
     * as a second total: a `JPY` invoice displays `¥1,235` while the stored
     * amount remains `1234.5600`, and a `JOD` invoice displays three decimals
     * while the stored amount keeps four. Storing the display value is how a
     * ledger stops balancing.
     */
    public function toPresentation(?RoundingPolicy $policy = null): BigDecimal
    {
        $exponent = CurrencyRegistry::exponent($this->currency);
        $mode = $policy?->mode ?? RoundingMode::HalfUp;

        return $this->amount->toScale($exponent, $mode);
    }

    /**
     * Localized, through ICU.
     *
     * Never `number_format`: the separators, the symbol, its position, the
     * accounting-negative form and the digit shape are all locale facts, and
     * none of them is derivable from the language alone.
     */
    public function format(?string $locale = null, bool $withSymbol = true): string
    {
        $locale = $this->withNumberingSystem($locale ?? $this->currentLocale());
        $value = (float) (string) $this->toPresentation();

        $formatter = new NumberFormatter(
            $locale,
            $withSymbol ? NumberFormatter::CURRENCY : NumberFormatter::DECIMAL,
        );

        $exponent = CurrencyRegistry::exponent($this->currency);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $exponent);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $exponent);

        $formatted = $withSymbol
            ? $formatter->formatCurrency($value, $this->currency)
            : $formatter->format($value);

        return \is_string($formatted) ? $formatted : (string) $this->toPresentation();
    }

    /**
     * The accounting negative: parentheses, a sign AND a colour at the call
     * site. A colour-blind user reading a variance column needs the
     * parentheses, not the hue - WCAG 1.4.1.
     */
    public function formatAccounting(?string $locale = null): string
    {
        $formatted = $this->abs()->format($locale);

        return $this->isNegative() ? '('.$formatted.')' : $formatted;
    }

    /**
     * Always an object with a STRING amount on the wire.
     *
     * A bare number is never returned, because a client parsing it as a float
     * is the bug this prevents: `JOD 1.234` arriving as JSON `1.234` will be
     * reformatted to `1.23` by any client that treats it as a number.
     *
     * @return array{amount: string, currency: string, presentation_exponent: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'presentation_exponent' => CurrencyRegistry::exponent($this->currency),
        ];
    }

    /** The exact stored value. Used for persistence and for hashing. */
    public function __toString(): string
    {
        return (string) $this->amount;
    }

    /** @param list<BigDecimal> $weights */
    private function residualIndex(array $weights): int
    {
        $strategy = \function_exists('config')
            ? (string) (config('money.apportionment_residual') ?? 'largest_group')
            : 'largest_group';

        if ($strategy === 'first_group') {
            return 0;
        }

        if ($strategy === 'last_group') {
            return \count($weights) - 1;
        }

        $index = 0;
        $largest = $weights[0];

        foreach ($weights as $i => $weight) {
            // Strictly greater, so the FIRST of several equal largest weights
            // keeps the residual. See the tie-break note on allocate().
            if ($weight->compareTo($largest) > 0) {
                $largest = $weight;
                $index = $i;
            }
        }

        return $index;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    /**
     * Pin the numbering system onto the locale.
     *
     * ICU's `ar` locale renders `١٢٣` by default. Financial documents in the
     * region conventionally use Western digits even in Arabic text, so the
     * default is `latn` - and the setting exists because institutional
     * convention differs.
     *
     * Applied as an ICU locale keyword, NEVER by replacing digit characters:
     * a string replacement also mangles any digit inside an identifier in the
     * same label, and it cannot be reversed for parsing.
     */
    private function withNumberingSystem(string $locale): string
    {
        $digits = \function_exists('config')
            ? (string) (config('money.display.digits') ?? 'latn')
            : 'latn';

        if ($digits === '' || str_contains($locale, '-u-') || str_contains($locale, '@')) {
            return $locale;
        }

        return $locale.'-u-nu-'.$digits;
    }

    private function currentLocale(): string
    {
        return \function_exists('app') && app()->bound('translator')
            ? app()->getLocale()
            : 'en';
    }
}
