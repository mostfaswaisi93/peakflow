<?php

declare(strict_types=1);

namespace App\Support\Hashing\Exceptions;

use RuntimeException;

final class NotCanonicalizable extends RuntimeException
{
    public static function float(float $value): self // @arch-allow: this method exists to REJECT a float; it has to be able to receive one
    {
        return new self(sprintf(
            'A float (%s) reached the canonicalizer. PeakFlow has no float in its money path, so this is a bug rather than an input problem: an amount must be a decimal string and a quantity must be a string or an integer. Hashing a float would make the hash depend on the platform.',
            var_export($value, true),
        ));
    }

    public static function type(string $type): self
    {
        return new self(sprintf('A value of type %s cannot be canonicalized.', $type));
    }

    public static function topLevel(string $type): self
    {
        return new self(sprintf(
            'A canonical payload must serialize to an array or object; %s was given.',
            $type,
        ));
    }

    public static function encoding(string $value): self
    {
        return new self(sprintf('"%s" is not valid UTF-8 and cannot be ordered.', $value));
    }
}
