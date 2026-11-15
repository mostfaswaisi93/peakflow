<?php

declare(strict_types=1);

namespace App\Support\Iso\Exceptions;

use RuntimeException;

/**
 * A domestic bank identifier that will not be stored - PF-008.
 *
 * Validated in the constructor for the same reason an IBAN is: the thing it
 * identifies is where money leaves the company, and a separate validator is
 * something a caller forgets exactly once.
 */
final class InvalidDomesticIdentifier extends RuntimeException
{
    public static function shape(string $scheme, string $value, string $expected): self
    {
        return new self(sprintf(
            'A %s must be %s. "%s" is not, so it was refused rather than stored - a beneficiary '.
            'identifier that is the wrong shape is a payment that is returned days later, by which '.
            'time the invoice is overdue and somebody has already been chased for it.',
            $scheme,
            $expected,
            $value,
        ));
    }

    public static function abaChecksum(string $value, int $sum): self
    {
        return new self(sprintf(
            'The routing number %s fails the ABA check digit: the weighted sum is %d, which is not a '.
            'multiple of ten. The checksum catches every single-digit error - and transpositions of '.
            'adjacent digits only where they do not differ by five, which is a blind spot measured '.
            'rather than assumed. So a routing number should also be read back to whoever supplied it.',
            $value,
            $sum,
        ));
    }

    public static function abaPrefix(string $value, string $prefix): self
    {
        return new self(sprintf(
            'The routing number %s begins "%s", which is not an allocated Federal Reserve range. The '.
            'allocated ranges are 01-12 (Reserve Banks), 21-32 (thrifts), 61-72 (electronic) and 80 '.
            '(travellers cheques). This matters because the checksum alone is not enough: 000000000 '.
            'passes it - measured - and is not a bank.',
            $value,
            $prefix,
        ));
    }
}
