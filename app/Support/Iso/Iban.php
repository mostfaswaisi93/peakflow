<?php

declare(strict_types=1);

namespace App\Support\Iso;

use App\Support\Iso\Exceptions\InvalidIban;
use JsonSerializable;
use Stringable;

/**
 * An International Bank Account Number.
 *
 * Validation happens IN THE CONSTRUCTOR, so an invalid IBAN cannot be
 * constructed. That is deliberate: a separate validator is something a caller
 * can forget, and the thing it guards - a beneficiary account - is the
 * highest-value fraud surface in the product.
 *
 * The per-country lengths live in {@see IbanRegistry}, which reads them from
 * `database/iso/iban.tsv` - a readonly class cannot hold the static cache, and
 * the separation is the right shape anyway: this is one account number, and
 * that is the registry it is checked against.
 *
 * @see ISO 13616-1 structure and per-country length
 * @see ISO/IEC 7064 MOD 97-10 check digits
 */
final readonly class Iban implements JsonSerializable, Stringable
{
    /** Normalized: upper case, no separators. */
    public string $value;

    public string $countryCode;

    public function __construct(string $value)
    {
        $normalized = strtoupper(preg_replace('/[\s\-]+/', '', $value) ?? '');

        if ($normalized === '') {
            throw InvalidIban::empty();
        }

        if (preg_match('/\A[A-Z]{2}[0-9]{2}[A-Z0-9]+\z/', $normalized) !== 1) {
            throw InvalidIban::malformed($normalized);
        }

        $country = substr($normalized, 0, 2);
        $expected = IbanRegistry::lengthFor($country);

        if ($expected === null) {
            throw InvalidIban::unknownCountry($country);
        }

        if (strlen($normalized) !== $expected) {
            throw InvalidIban::wrongLength($country, $expected, strlen($normalized));
        }

        if (! self::checkDigitsValid($normalized)) {
            throw InvalidIban::checkDigits($normalized);
        }

        $this->value = $normalized;
        $this->countryCode = $country;
    }

    public static function tryFrom(string $value): ?self
    {
        try {
            return new self($value);
        } catch (InvalidIban) {
            return null;
        }
    }

    /** Does ISO 13616 define an IBAN for this country at all? */
    public static function appliesTo(string $countryCode): bool
    {
        return IbanRegistry::has($countryCode);
    }

    /**
     * MOD 97-10 per ISO/IEC 7064.
     *
     * Move the first four characters to the end, map letters to numbers
     * (A=10 … Z=35), then the whole value modulo 97 must be 1. The modulus is
     * computed in chunks because the expanded number is far wider than a
     * 64-bit integer, and this keeps the check free of bcmath.
     */
    private static function checkDigitsValid(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $expanded = '';
        foreach (str_split($rearranged) as $char) {
            $expanded .= ctype_alpha($char)
                ? (string) (ord($char) - 55)
                : $char;
        }

        $remainder = 0;
        foreach (str_split($expanded, 7) as $chunk) {
            $remainder = (int) ((string) $remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /**
     * Grouped in fours, which is how ISO 13616 says to present it on paper and
     * how a human actually verifies one.
     *
     * Callers rendering this inside Arabic text MUST isolate it - see
     * docs/design/rtl-and-i18n.md. `JO94-CBJO-0010` reordered is a different
     * string from the one the user has to check, and they will not notice.
     */
    public function forDisplay(): string
    {
        return trim(chunk_split($this->value, 4, ' '));
    }

    public function bankIdentifier(): string
    {
        return substr($this->value, 4);
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
