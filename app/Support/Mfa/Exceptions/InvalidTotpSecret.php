<?php

declare(strict_types=1);

namespace App\Support\Mfa\Exceptions;

use InvalidArgumentException;

/** A TOTP secret that cannot produce codes. Refused rather than used. */
final class InvalidTotpSecret extends InvalidArgumentException
{
    public static function notBase32(string $char): self
    {
        return new self(sprintf(
            'A TOTP secret contains "%s", which is not in the RFC 4648 base32 alphabet. The alphabet omits '.
            '0, 1, 8 and 9 so that they cannot be confused with O, I, B and g when read aloud or typed.',
            $char,
        ));
    }

    public static function tooShort(int $bytes): self
    {
        return new self(sprintf(
            'A TOTP secret of %d byte(s) is too short to use. RFC 4226 requires at least 128 bits and '.
            'recommends 160; a short secret weakens the HMAC rather than merely being inconvenient.',
            $bytes,
        ));
    }
}
