<?php

declare(strict_types=1);

namespace App\Support\Iso\Exceptions;

use InvalidArgumentException;

final class UnknownCurrency extends InvalidArgumentException
{
    public static function code(string $code): self
    {
        return new self(sprintf(
            '"%s" is not an active ISO 4217 currency code. A currency PeakFlow does not know has no exponent, and an amount without an exponent cannot be presented or paid.',
            $code,
        ));
    }
}
