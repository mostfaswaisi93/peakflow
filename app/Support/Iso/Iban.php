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
 * @see ISO 13616-1 structure and per-country length
 * @see ISO/IEC 7064 MOD 97-10 check digits
 */
final readonly class Iban implements JsonSerializable, Stringable
{
    /**
     * Length per country, from the ISO 13616 registry maintained by SWIFT.
     *
     * Provenance: IBAN Registry, SWIFT. Vendored deliberately rather than
     * fetched - a registry update must be a reviewed change, not a surprise.
     *
     * @var array<string, int>
     */
    private const LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20,
        'BE' => 16, 'BG' => 22, 'BH' => 22, 'BI' => 27, 'BR' => 29, 'BY' => 28,
        'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DJ' => 27,
        'DK' => 18, 'DO' => 28, 'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18,
        'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18,
        'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23,
        'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20,
        'LB' => 28, 'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21,
        'LY' => 25, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19, 'MN' => 20,
        'MR' => 27, 'MT' => 31, 'MU' => 30, 'NI' => 28, 'NL' => 18, 'NO' => 15,
        'OM' => 23, 'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29,
        'RO' => 24, 'RS' => 22, 'RU' => 33, 'SA' => 24, 'SC' => 31, 'SD' => 18,
        'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'SO' => 23, 'ST' => 25,
        'SV' => 28, 'TL' => 23, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22,
        'VG' => 24, 'XK' => 20, 'YE' => 30,
    ];

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

        if (! isset(self::LENGTHS[$country])) {
            throw InvalidIban::unknownCountry($country);
        }

        if (strlen($normalized) !== self::LENGTHS[$country]) {
            throw InvalidIban::wrongLength($country, self::LENGTHS[$country], strlen($normalized));
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
        return isset(self::LENGTHS[strtoupper($countryCode)]);
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
