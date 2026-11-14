<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * The decimal scales. There are exactly three, and that is not negotiable.
 *
 * Changing a scale after there is data means rewriting every money column in
 * every tenant database and re-deriving every stored total, so the set is
 * closed and an architecture test fails a migration that introduces a fourth.
 *
 * ## Why these three
 *
 * - **Amount (4)** absorbs the three-decimal currencies - BHD, IQD, JOD, KWD,
 *   LYD, OMR, TND - plus one guard digit. The presentation layer rounds to the
 *   currency's own ISO 4217 exponent for display and payment; the stored value
 *   keeps four. Storing the display value is how a ledger stops balancing.
 *
 * - **UnitPrice (8)** covers per-litre, per-kWh, per-minute and per-thousand
 *   pricing with no scaling factor. A price of `0.0725` per unit is ordinary in
 *   utilities, telecoms and fuel.
 *
 * - **Rate (12)** is enough that a round trip through an inverse rate does not
 *   drift. Not that we ever invert one - see {@see Rate} - but tax percentages
 *   and allocation ratios live here too.
 *
 * @see docs/architecture/money-and-precision.md
 * @see docs/architecture/decisions/ADR-0004-money-representation.md
 */
enum Scale: int
{
    case Amount = 4;
    case UnitPrice = 8;
    case Rate = 12;

    /** The PostgreSQL column type this scale is stored as. */
    public function columnType(): string
    {
        return match ($this) {
            self::Amount => 'numeric(21,4)',
            self::UnitPrice => 'numeric(21,8)',
            self::Rate => 'numeric(21,12)',
        };
    }

    /**
     * Total precision. 21 digits with the scale subtracted leaves 17 integer
     * digits for an amount - about ten quadrillion, which is past any real
     * general ledger and well inside what `numeric` itself allows.
     */
    public function precision(): int
    {
        return 21;
    }

    public function decimals(): int
    {
        return $this->value;
    }
}
