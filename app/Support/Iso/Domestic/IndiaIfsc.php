<?php

declare(strict_types=1);

namespace App\Support\Iso\Domestic;

use App\Support\Iso\Exceptions\InvalidDomesticIdentifier;
use JsonSerializable;
use Stringable;

/**
 * An Indian Financial System Code - PF-008.
 *
 * Eleven characters: four letters for the bank, a reserved `0`, then six
 * alphanumerics for the branch. The fifth character being zero is the one
 * structural rule the format actually gives, and it is worth checking: it is
 * the character somebody drops when they transcribe `SBIN0001234` as
 * `SBIN001234`, and the result is ten characters, which a length check catches
 * - while `SBIN1000123` is eleven and is not a code.
 *
 * Normalized to upper case because the RBI publishes it that way and a
 * lower-case copy would not match a comparison the payment file makes.
 */
final readonly class IndiaIfsc implements JsonSerializable, Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtoupper((string) preg_replace('/[\s\-]+/', '', $value));

        if (preg_match('/\A[A-Z]{4}0[A-Z0-9]{6}\z/', $normalized) !== 1) {
            throw InvalidDomesticIdentifier::shape(
                'IFSC',
                $value,
                'four letters, then a zero, then six letters or digits',
            );
        }

        $this->value = $normalized;
    }

    public static function tryFrom(string $value): ?self
    {
        try {
            return new self($value);
        } catch (InvalidDomesticIdentifier) {
            return null;
        }
    }

    public function bankCode(): string
    {
        return substr($this->value, 0, 4);
    }

    public function branchCode(): string
    {
        return substr($this->value, 5);
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
