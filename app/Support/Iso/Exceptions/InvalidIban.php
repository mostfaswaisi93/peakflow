<?php

declare(strict_types=1);

namespace App\Support\Iso\Exceptions;

use InvalidArgumentException;

/**
 * Messages name the business term rather than the regex, because these reach a
 * user who is checking a beneficiary account and needs to know what to fix.
 */
final class InvalidIban extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('An IBAN is required.');
    }

    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'An IBAN must be two country letters, two check digits, then alphanumeric characters. Received "%s".',
            $value,
        ));
    }

    public static function unknownCountry(string $country): self
    {
        return new self(sprintf(
            'ISO 13616 defines no IBAN for country "%s". Use the domestic account identifier instead.',
            $country,
        ));
    }

    public static function wrongLength(string $country, int $expected, int $actual): self
    {
        return new self(sprintf(
            'An IBAN for %s is %d characters; %d were given.',
            $country,
            $expected,
            $actual,
        ));
    }

    public static function checkDigits(string $value): self
    {
        return new self(sprintf(
            'The IBAN "%s" has the right shape but fails its MOD 97-10 check digits, so at least one character is wrong.',
            $value,
        ));
    }
}
