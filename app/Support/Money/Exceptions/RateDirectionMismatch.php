<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

final class RateDirectionMismatch extends MoneyException
{
    public static function inverseRequested(string $from, string $to): self
    {
        return new self(sprintf(
            'This rate converts %s to %s, and PeakFlow does not invert a rate to go the other way. Inverting a rounded rate does not round-trip, and the resulting drift is small, persistent and appears in every consolidation. Fetch the %s/%s rate instead.',
            $from,
            $to,
            $to,
            $from,
        ));
    }

    public static function wrongCurrency(string $given, string $from, string $to): self
    {
        return new self(sprintf(
            'Cannot convert %s with a %s/%s rate.',
            $given,
            $from,
            $to,
        ));
    }
}
