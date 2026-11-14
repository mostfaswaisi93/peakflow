<?php

declare(strict_types=1);

namespace App\Support\Iso;

use App\Support\Iso\Exceptions\InvalidBic;
use JsonSerializable;
use Stringable;

/**
 * A Business Identifier Code.
 *
 * Validated in the constructor, like {@see Iban}. Structure only: ISO 9362
 * defines the shape, not which codes are live, so a structurally valid BIC
 * that no bank uses will pass here and be rejected by the bank. That is the
 * correct division - we do not pretend to hold the SWIFT directory.
 *
 * @see ISO 9362:2022
 */
final readonly class Bic implements JsonSerializable, Stringable
{
    public string $value;

    /** Institution code, 4 alphanumeric. */
    public string $institutionCode;

    /** ISO 3166-1 alpha-2. */
    public string $countryCode;

    /** Location code, 2 alphanumeric. */
    public string $locationCode;

    /** Branch code, 3 alphanumeric, or null for an 8-character BIC. */
    public ?string $branchCode;

    public function __construct(string $value)
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $value) ?? '');

        if ($normalized === '') {
            throw InvalidBic::empty();
        }

        if (preg_match('/\A([A-Z]{4})([A-Z]{2})([A-Z0-9]{2})([A-Z0-9]{3})?\z/', $normalized, $m) !== 1) {
            throw InvalidBic::malformed($normalized);
        }

        // Cross-checked against the country registry rather than accepting any
        // two letters: `ZZ` is a structurally valid BIC country and not a place.
        if (! CountryRegistry::exists($m[2])) {
            throw InvalidBic::unknownCountry($m[2]);
        }

        $this->value = $normalized;
        $this->institutionCode = $m[1];
        $this->countryCode = $m[2];
        $this->locationCode = $m[3];
        $this->branchCode = $m[4] ?? null;
    }

    public static function tryFrom(string $value): ?self
    {
        try {
            return new self($value);
        } catch (InvalidBic) {
            return null;
        }
    }

    /** A BIC8 identifies the institution; a BIC11 identifies a branch of it. */
    public function isBranch(): bool
    {
        return $this->branchCode !== null && $this->branchCode !== 'XXX';
    }

    public function toBic8(): self
    {
        return new self(substr($this->value, 0, 8));
    }

    /** Test-and-training BICs carry `0` in the last position of the location code. */
    public function isTestCode(): bool
    {
        return str_ends_with($this->locationCode, '0');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
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
