<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Iso\CurrencyRegistry;
use App\Support\Money\Exceptions\CurrencyMismatch;
use App\Support\Money\Exceptions\InvalidAllocation;
use App\Support\Money\Exceptions\PrecisionLoss;
use Brick\Math\BigDecimal;
use App\Support\Lang\NumberingSystem;
use Brick\Math\Exception\MathException;
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
        $locale = self::localeWithDigits($locale ?? self::localeNow());
        // @arch-allow: ICU's NumberFormatter takes a float and there is no
        // decimal-aware alternative. Safe here and only here: the value has
        // already been rounded to the currency's own exponent, so the float
        // is a presentation artefact that is never read back or stored.
        $value = (float) (string) $this->toPresentation(); // @arch-allow

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
    /**
     * Read a formatted amount back into a `Money` - PF-006.
     *
     * ## Why this exists at all
     *
     * Because a form shows what `format()` produced and takes back what
     * somebody typed into it, and those two have to be the same value. A
     * field that renders `١٢٬٥٠٠٫٥٠ ر.س.‏` and cannot read it back is a field
     * that loses the amount the moment anybody edits anything else on the
     * page.
     *
     * ## ICU first, then a deliberate fallback
     *
     * `NumberFormatter::parseCurrency()` understands the locale's separators,
     * its digit shape and its accounting-negative form, which is a long list
     * of things not to reimplement. What it does not do is cope with the
     * forms people actually type: a Latin-digit amount pasted into an Arabic
     * form, a thousands separator left in, a space where a non-breaking space
     * belongs.
     *
     * So ICU is tried, and a normalising pass handles the rest. The order
     * matters: ICU is correct where it applies, and the fallback is a
     * concession to how typing works rather than a replacement for it.
     *
     * ## It returns null rather than guessing
     *
     * `about 12k` is not an amount. A figure the software invented is a
     * figure nobody can trace, and the caller - a form - has a field to put
     * the complaint next to.
     */
    public static function parse(string $formatted, string $currency, ?string $locale = null): ?self
    {
        $currency = mb_strtoupper(trim($currency));

        if ($formatted === '') {
            return null;
        }

        $decimal = self::parseToDecimal($formatted, $currency, $locale);

        if ($decimal === null) {
            return null;
        }

        /*
         | Scaled to the Amount scale with HalfUp, not Unnecessary.
         |
         | A formatted value carries the currency's own exponent - two places
         | for SAR, three for JOD, none for JPY - and the stored scale is
         | four. Going from fewer places to more is exact, so the rounding
         | mode never engages; it is named rather than left to a default
         | because `Unnecessary` would throw on an input with five decimals,
         | and a form should refuse that with a message rather than a
         | stack trace.
         */
        return new self($decimal->toScale(Scale::Amount->value, RoundingMode::HalfUp), $currency);
    }

    /**
     * Whether a formatted amount reads back as the same value.
     *
     * For a check, and for a form deciding whether to re-render the field
     * from the stored value or leave what somebody typed.
     */
    public function roundTrips(?string $locale = null): bool
    {
        $read = self::parse($this->format($locale), $this->currency, $locale);

        if ($read === null) {
            return false;
        }

        /*
         | Compared at the PRESENTATION scale, because that is what was
         | formatted.
         |
         | `format()` renders the currency's exponent - two places for SAR -
         | so 1234.5678 formats as 1,234.57 and reads back as 1234.5700. The
         | round trip is of the DISPLAYED value, and claiming otherwise would
         | make the property false for every amount with more precision than
         | its currency shows.
         */
        return $this->toPresentation()->isEqualTo($read->toPresentation());
    }

    private static function parseToDecimal(string $formatted, string $currency, ?string $locale): ?BigDecimal
    {
        $locale = self::localeWithDigits($locale ?? self::localeNow());

        /*
         | ICU, with the currency parser first.
         |
         | `parseCurrency` strips the symbol wherever the locale puts it and
         | understands the accounting-negative parenthesis form, which a
         | regular expression gets wrong for exactly the locales this
         | product serves.
         */
        $trimmed = trim($formatted);

        foreach ([NumberFormatter::CURRENCY, NumberFormatter::DECIMAL] as $style) {
            $formatter = new NumberFormatter($locale, $style);

            $parsedCurrency = $currency;
            $position = 0;

            $value = $style === NumberFormatter::CURRENCY
                ? $formatter->parseCurrency($trimmed, $parsedCurrency, $position)
                : $formatter->parse($trimmed, NumberFormatter::TYPE_DOUBLE, $position);

            if ($value === false || ! is_numeric((string) $value)) {
                continue;
            }

            /*
             | ICU has to have consumed the WHOLE string.
             |
             | It parses as far as it can and reports where it stopped - so
             | without this it reads `about 12k` as 12 and
             | `١٢٬٠٠٠٫٥٠` under an English locale as 12, stopping at the
             | first character it does not recognise. Both were measured, and
             | a form accepting "about 12k" as twelve riyals is the worse of
             | the two.
             |
             | The remainder is allowed to be whitespace and the
             | bidirectional marks ICU itself emits, and nothing else.
             */
            $remainder = (string) preg_replace(
                '/[\s\x{200E}\x{200F}\x{061C}\x{00A0}\x{202F}]+/u',
                '',
                mb_substr($trimmed, $position),
            );

            if ($remainder !== '') {
                continue;
            }

            /*
             | ICU hands back a FLOAT, and a float is not a value to store.
             |
             | It is used only to establish the digits, which are then taken
             | from the string form: the amount has already been rounded to
             | the currency's exponent by whatever formatted it, so the float
             | is exact at that scale and the conversion is a transcription
             | rather than an arithmetic.
             */
            try {
                // @arch-allow: ICU returns a float and has no decimal API.
                // Safe here and only here: the value came from a string that
                // was formatted at the currency's own exponent, so the float
                // is exact at that scale, and the result is immediately
                // scaled and stored as a decimal. @arch-allow
                return BigDecimal::of(sprintf('%.6F', $value)); // @arch-allow
            } catch (MathException) {
                continue;
            }
        }

        /*
         | The fallback: normalise what people type.
         |
         | Arabic-Indic and Eastern Arabic-Indic digits, the Arabic decimal
         | and thousands separators, the Latin separators, the spaces -
         | including the non-breaking and narrow non-breaking spaces that
         | ICU itself emits - and the currency's own symbol or code left in
         | place by somebody who copied the whole field.
         */
        $normalised = self::normaliseTypedAmount($formatted, $currency);

        if ($normalised === null) {
            return null;
        }

        try {
            return BigDecimal::of($normalised);
        } catch (MathException) {
            return null;
        }
    }

    /**
     * Turn what somebody typed into something `BigDecimal` accepts.
     *
     * Returns null when what is left is not a number, rather than returning
     * zero - a silent zero in a journal line is a line that balances against
     * nothing.
     */
    public static function normaliseTypedAmount(string $amount, ?string $currency = null): ?string
    {
        $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $easternArabicIndic = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        $value = str_replace($arabicIndic, range(0, 9), $amount);
        $value = str_replace($easternArabicIndic, range(0, 9), $value);

        // The Arabic decimal separator becomes a point; the Arabic thousands
        // separator goes.
        $value = str_replace(['٫', '٬'], ['.', ''], $value);

        if ($currency !== null && $currency !== '') {
            $value = str_ireplace($currency, '', $value);
        }

        /*
         | The currency SYMBOL as well as its code, from the registry.
         |
         | Somebody who selects the whole field and pastes it brings the
         | symbol, and `ر.س.` is three characters and two full stops - which
         | a digits-and-points filter would turn into part of the number.
         */
        foreach (self::symbolsFor($currency) as $symbol) {
            if ($symbol !== '') {
                $value = str_replace($symbol, '', $value);
            }
        }

        /*
         | The accounting negative, before the digits are isolated.
         |
         | `(1,234.56)` is how an accounting locale renders a credit, and
         | stripping the parentheses as punctuation would turn it into a
         | positive - which is the sign error that balances.
         */
        $negative = false;

        if (preg_match('/^\s*\(.*\)\s*$/u', $value) === 1) {
            $negative = true;
            $value = (string) preg_replace('/[()]/u', '', $value);
        }

        if (str_contains($value, '-') || str_contains($value, "\u{2212}")) {
            $negative = true;
        }

        /*
         | A WHITELIST of permitted characters, not a strip of the rest.
         |
         | Stripping everything that is not a digit turned `about 12k` into
         | 12 - measured, and a form accepting "about 12k" as twelve riyals
         | is the worst outcome available here: the figure is plausible, it
         | posts, and nobody can trace where it came from.
         |
         | So anything outside this set refuses the whole value. The set is
         | the separators people and ICU actually produce: the comma, the
         | apostrophe some locales use, the four space widths, the Arabic
         | thousands separator (already replaced above), the bidirectional
         | marks, the minus signs.
         */
        $stripped = (string) preg_replace(
            '/[,\x{2019}\x{0027} \x{00A0}\x{202F}\x{2009}\x{200E}\x{200F}\x{061C}\x{2212}+-]/u',
            '',
            $value,
        );

        if ($stripped === '' || preg_match('/^[0-9]+(?:\.[0-9]+)?$|^\.[0-9]+$/', $stripped) !== 1) {
            return null;
        }

        return ($negative ? '-' : '').$stripped;
    }

    /**
     * The symbols a currency might arrive wearing.
     *
     * Asked of ICU rather than listed, because a symbol is a locale fact:
     * the riyal is `ر.س.` in Arabic and `SAR` in English, and the euro sign
     * is the same in both. Both locales are tried because a pasted value
     * does not say which one rendered it.
     *
     * @return list<string>
     */
    private static function symbolsFor(?string $currency): array
    {
        if ($currency === null || $currency === '') {
            return [];
        }

        $symbols = [];

        foreach (['en', 'ar'] as $locale) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

            $symbol = $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

            if (is_string($symbol) && $symbol !== '') {
                $symbols[] = $symbol;
            }
        }

        return array_values(array_unique($symbols));
    }

    /**
     * Delegated, so that `-u-nu-` is appended in exactly one place.
     *
     * It used to be appended here, which made `Money` the authority on a
     * decision every other formatter also has to make. An architecture rule
     * now asserts there is only one such place, so the next formatter extends
     * it rather than guessing.
     */
    private static function localeWithDigits(string $locale): string
    {
        return NumberingSystem::apply($locale);
    }

    /**
     * The locale in force, or `en` where no application is booted.
     *
     * One method, where there were two identical ones - a static
     * `localeNow()` used by `parse()` and an instance `currentLocale()` used
     * by `format()`. Two copies of "which locale are we in" is how a parser
     * and a formatter end up answering differently in the same request.
     */
    private static function localeNow(): string
    {
        return \function_exists('app') && app()->bound('translator')
            ? app()->getLocale()
            : 'en';
    }

}
