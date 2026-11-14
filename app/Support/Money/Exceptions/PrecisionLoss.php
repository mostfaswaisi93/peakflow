<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

final class PrecisionLoss extends MoneyException
{
    public static function atScale(string $value, int $scale): self
    {
        return new self(sprintf(
            'The value "%s" carries more precision than the %d-decimal scale it is being stored at. This is refused rather than rounded silently: use ofRounded() with an explicit RoundingPolicy if rounding here is intended.',
            $value,
            $scale,
        ));
    }
}
