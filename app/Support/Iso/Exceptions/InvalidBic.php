<?php

declare(strict_types=1);

namespace App\Support\Iso\Exceptions;

use InvalidArgumentException;

final class InvalidBic extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('A BIC is required.');
    }

    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'A BIC is 8 or 11 characters: four institution letters, two country letters, two location characters, and optionally three branch characters. Received "%s".',
            $value,
        ));
    }

    public static function unknownCountry(string $country): self
    {
        return new self(sprintf('"%s" is not an ISO 3166-1 country code.', $country));
    }
}
