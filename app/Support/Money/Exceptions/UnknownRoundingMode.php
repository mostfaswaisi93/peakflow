<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

final class UnknownRoundingMode extends MoneyException
{
    public static function name(string $name): self
    {
        return new self(sprintf(
            '"%s" is not a rounding mode PeakFlow recognises. Supported: half_up, half_down, half_even, up, down, ceiling, floor, exact.',
            $name,
        ));
    }
}
