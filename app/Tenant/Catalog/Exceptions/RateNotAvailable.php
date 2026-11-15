<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Exceptions;

/**
 * No rate for this pair, date and set - PF-068.
 *
 * ## This exception IS the requirement
 *
 * "A missing rate is an explicit, surfaced condition, never a silent fallback
 * to 1." The temptation to return 1 is strong because it makes the code
 * continue, and continuing is exactly what must not happen: a rate of 1
 * between two different currencies asserts that 4,000 EUR is 4,000 SAR,
 * balances perfectly, and is indistinguishable afterwards from a real
 * translation.
 *
 * So there is no "default rate", no `?? 1`, and no nullable return from the
 * lookup. The caller either has a rate or has a refusal that names the pair,
 * the date, the set and what to do about it.
 *
 * ## Why the message says so much
 *
 * Whoever reads this is mid-task - issuing an invoice, running a close - and
 * needs to know which rate to go and get. A message saying "rate not found"
 * sends them to a developer; this one sends them to the rate screen.
 */
final class RateNotAvailable extends CatalogException
{
    public static function forPair(
        string $from,
        string $to,
        string $date,
        string $rateSet,
    ): self {
        return new self(sprintf(
            'No %s rate for %s to %s on %s. This was refused rather than converted at 1, because a rate of 1 '.
            'between two currencies asserts they are worth the same and balances while doing it. Record the '.
            'rate for that date, or run fx:fetch-rates, and try again.',
            $rateSet,
            $from,
            $to,
            $date,
        ));
    }

    /**
     * A rate exists but only in the other direction.
     *
     * Named separately because the fix is different and because this is the
     * case where someone will reach for `1 / x`. The reciprocal of a rounded
     * rate does not round-trip: 1,000 USD through a stored USD/EUR of 0.92
     * and back through its inverse returns 1,000.0000000004, in the same
     * direction, every time, in every consolidation.
     */
    public static function onlyTheOtherWay(
        string $from,
        string $to,
        string $date,
        string $rateSet,
    ): self {
        return new self(sprintf(
            'No %s rate for %s to %s on %s, though %s to %s is on file. The inverse was NOT used: 1 divided by '.
            'a rounded rate does not round-trip, and the drift is small, one-directional and permanent - it '.
            'shows up as a consolidation difference nobody can account for. Record the %s to %s rate.',
            $rateSet,
            $from,
            $to,
            $date,
            $to,
            $from,
            $from,
            $to,
        ));
    }
}
