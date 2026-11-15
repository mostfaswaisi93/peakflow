<?php

declare(strict_types=1);

namespace App\Support\Iso\Domestic;

use App\Support\Iso\Exceptions\InvalidDomesticIdentifier;
use JsonSerializable;
use Stringable;

/**
 * An ABA routing transit number - PF-008.
 *
 * ## Two checks, because one is not enough
 *
 * **The check digit.** Nine digits, weighted 3-7-1 repeating, and the sum must
 * be a multiple of ten. It catches **every** single-digit error: a digit
 * changed by d at weight w moves the sum by w*d, and 1, 3 and 7 are all
 * coprime with 10, so no non-zero change can land back on a multiple of ten.
 *
 * It does NOT catch every transposition, and the first version of this class
 * claimed it did. Swapping adjacent digits a and b at weights (w1, w2) moves
 * the sum by (w2-w1)(a-b), and the adjacent weight pairs are (3,7), (7,1) and
 * (1,3) - differences of 4, -6 and 2, every one of which is even. So the swap
 * is invisible whenever a-b is +/-5. Checked exhaustively over all 90 ordered
 * pairs for all three weight positions: exactly the ten pairs differing by
 * five are undetected, and no others.
 *
 * That was found by a check, not by reading: `322271627` with its `2` and `7`
 * swapped is `322721627`, which passes. {@see transpositionIsUndetectable()}
 * exposes the limitation so a form can act on it - a routing number should be
 * read back or asked for twice, and a form that believes the checksum caught
 * everything will do neither.
 *
 * **The Federal Reserve prefix.** Measured: `000000000` passes the check digit.
 * So does any other string of nine zeros-and-balanced digits that is not a
 * bank. The first two digits name the Reserve district, and only four ranges
 * are allocated - 01-12, 21-32, 61-72 and 80. Without this the checksum would
 * accept a placeholder somebody typed to get past a required field, which is
 * how a test value reaches a payment file.
 */
final readonly class UsRoutingNumber implements JsonSerializable, Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = (string) preg_replace('/[\s\-]+/', '', $value);

        if (preg_match('/\A[0-9]{9}\z/', $normalized) !== 1) {
            throw InvalidDomesticIdentifier::shape('US routing number', $value, 'exactly nine digits');
        }

        $prefix = substr($normalized, 0, 2);

        if (! self::prefixIsAllocated((int) $prefix)) {
            throw InvalidDomesticIdentifier::abaPrefix($normalized, $prefix);
        }

        $sum = self::weightedSum($normalized);

        if ($sum % 10 !== 0) {
            throw InvalidDomesticIdentifier::abaChecksum($normalized, $sum);
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

    /**
     * Would swapping these two adjacent digits escape the check digit?
     *
     * True exactly when they differ by five, which is the complete set - see
     * the class docblock for the algebra and the exhaustive check. Exposed
     * because a caller that knows the checksum has a blind spot can cover it,
     * and a caller that does not cannot.
     */
    public static function transpositionIsUndetectable(int $first, int $second): bool
    {
        return abs($first - $second) === 5;
    }

    /** The 3-7-1 weighted sum, exposed so a failure can say what it was. */
    public static function weightedSum(string $digits): int
    {
        $d = array_map('intval', str_split($digits));

        return 3 * ($d[0] + $d[3] + $d[6])
            + 7 * ($d[1] + $d[4] + $d[7])
            + 1 * ($d[2] + $d[5] + $d[8]);
    }

    /**
     * The allocated Federal Reserve ranges.
     *
     * 01-12 are the Reserve Banks in district order; 21-32 are the same
     * districts' thrift institutions; 61-72 are the same districts again for
     * electronic transactions; 80 is travellers cheques. 00 and 13-20 are not
     * allocated, which is what makes a string of zeros refusable.
     */
    public static function prefixIsAllocated(int $prefix): bool
    {
        return ($prefix >= 1 && $prefix <= 12)
            || ($prefix >= 21 && $prefix <= 32)
            || ($prefix >= 61 && $prefix <= 72)
            || $prefix === 80;
    }

    /** The Federal Reserve district, 1-12, which every allocated range maps onto. */
    public function federalReserveDistrict(): int
    {
        $prefix = (int) substr($this->value, 0, 2);

        return match (true) {
            $prefix <= 12 => $prefix,
            $prefix <= 32 => $prefix - 20,
            $prefix <= 72 => $prefix - 60,
            default => 12,
        };
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
