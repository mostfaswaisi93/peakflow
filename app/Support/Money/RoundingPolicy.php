<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Money\Exceptions\UnknownRoundingMode;
use Brick\Math\RoundingMode;

/**
 * A rounding mode bound to a scale, passed explicitly to every operation that
 * can lose precision.
 *
 * ## There is no default, anywhere
 *
 * No method in {@see Money} rounds without being handed one of these. That is
 * the single most important property of this layer: a default rounding mode is
 * a decision nobody made, applied to money, in a place nobody will look.
 *
 * `brick/math` helps here rather than hindering - its own default is
 * `RoundingMode::Unnecessary`, which THROWS if rounding would be needed. The
 * default is "refuse", not "guess", which is the right direction for a
 * financial system.
 *
 * ## Half-up, not banker's
 *
 * EN 16931 and most VAT regimes specify half **away from zero**. IEEE 754 and
 * most language defaults use half-even. The difference appears on roughly one
 * document in a hundred and compounds across a return - so the mode is
 * resolved per jurisdiction, as data, and never assumed.
 */
final readonly class RoundingPolicy
{
    private function __construct(
        public RoundingMode $mode,
        public Scale $scale,
    ) {}

    public static function of(RoundingMode $mode, Scale $scale): self
    {
        return new self($mode, $scale);
    }

    /** Round half away from zero. What EN 16931 and most VAT regimes specify. */
    public static function halfUp(Scale $scale): self
    {
        return new self(RoundingMode::HalfUp, $scale);
    }

    /** Banker's rounding. Only where a jurisdiction actually mandates it. */
    public static function halfEven(Scale $scale): self
    {
        return new self(RoundingMode::HalfEven, $scale);
    }

    /** Toward zero. Used by allocation, so the residual is explicit. */
    public static function down(Scale $scale): self
    {
        return new self(RoundingMode::Down, $scale);
    }

    /**
     * Refuse to round.
     *
     * The right policy when a value is expected to already fit the scale: it
     * turns a silent precision loss into an exception at the point of entry.
     */
    public static function exact(Scale $scale): self
    {
        return new self(RoundingMode::Unnecessary, $scale);
    }

    /**
     * The mode a jurisdiction profile declares, falling back to the platform
     * default - which is half-up, for the reason above.
     */
    public static function forJurisdiction(?string $mode, Scale $scale): self
    {
        $mode ??= self::configuredDefault();

        return new self(self::modeFromName($mode), $scale);
    }

    /**
     * The policy a named jurisdiction profile declares - PF-006.
     *
     * ## Why the profile and not the country
     *
     * A country can be served by more than one profile - an entity in Saudi
     * Arabia may issue under `zatca` for its domestic invoices and `generic`
     * for an export - and the rounding belongs to the rule set the document
     * conforms to, not to where the entity is registered.
     *
     * ## An unknown profile gets the default, and does not throw
     *
     * Refusing to round because a profile forgot to declare a mode would
     * make a new jurisdiction unusable until somebody noticed, and the
     * noticing would happen on an invoice. The platform default is half-up,
     * which is what almost every tax authority specifies - so the fallback
     * is the common case rather than a guess.
     */
    public static function forProfile(?string $profile, Scale $scale): self
    {
        if ($profile === null || $profile === '') {
            return self::forJurisdiction(null, $scale);
        }

        $declared = \function_exists('config')
            ? config('jurisdictions.rounding.'.$profile)
            : null;

        return self::forJurisdiction(
            is_string($declared) && $declared !== '' ? $declared : null,
            $scale,
        );
    }

    public function withScale(Scale $scale): self
    {
        return new self($this->mode, $scale);
    }

    public function describe(): string
    {
        return sprintf('%s @ %d dp', $this->mode->name, $this->scale->value);
    }

    private static function configuredDefault(): string
    {
        return \function_exists('config')
            ? (string) (config('money.default_rounding') ?? 'half_up')
            : 'half_up';
    }

    private static function modeFromName(string $name): RoundingMode
    {
        return match (strtolower($name)) {
            'half_up' => RoundingMode::HalfUp,
            'half_down' => RoundingMode::HalfDown,
            'half_even' => RoundingMode::HalfEven,
            'up' => RoundingMode::Up,
            'down' => RoundingMode::Down,
            'ceiling' => RoundingMode::Ceiling,
            'floor' => RoundingMode::Floor,
            'exact', 'unnecessary' => RoundingMode::Unnecessary,
            default => throw UnknownRoundingMode::name($name),
        };
    }
}
