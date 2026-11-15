<?php

declare(strict_types=1);

namespace App\Support\Hashing;

use App\Support\Hashing\Exceptions\NotCanonicalizable;
use JsonSerializable;

/**
 * JSON Canonicalization Scheme, RFC 8785.
 *
 * A hash is only meaningful if the bytes that go into it are deterministic.
 * Two servers, two PHP versions or two insertion orders must produce the same
 * canonical form for the same data, or every hash chain in the system becomes
 * a source of false alarms.
 *
 * RFC 8785 requires:
 *
 * - Object members sorted by their **UTF-16 code unit** sequence, not by
 *   byte order and not by locale collation.
 * - No insignificant whitespace.
 * - Strings escaped minimally, with `/` left alone and non-ASCII emitted as
 *   literal UTF-8.
 * - Numbers in ECMAScript `Number::toString` form.
 *
 * ## The escaping is written out rather than delegated to `json_encode`
 *
 * It was `json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`,
 * which is nearly right and wrong in one measurable way: PHP escapes U+2028 and
 * U+2029 as `\u2028` and `\u2029` regardless of `JSON_UNESCAPED_UNICODE`, because
 * those two characters break JavaScript string literals. RFC 8785 requires them
 * literal - only `"`, `\` and U+0000..U+001F may be escaped.
 *
 * So a description containing a LINE SEPARATOR produced a canonical form that a
 * conformant implementation would not produce, and therefore a different hash.
 * That failure surfaces as a chain a counterparty cannot verify - which is the
 * exact class of problem the chain exists to detect, arriving from the thing
 * meant to detect it.
 *
 * Writing the escaper out also answers the other half of PF-009: the output
 * cannot drift with a change to PHP's JSON extension, because PHP's JSON
 * extension is no longer involved.
 *
 * ## Floats are refused rather than canonicalized
 *
 * The RFC's number rules exist to pin down IEEE 754 serialization. PeakFlow
 * has no float in its money path at all - amounts are decimal strings - so
 * rather than implement a shortest-round-trip float formatter that would only
 * ever be exercised by a mistake, a float in a canonical payload throws.
 *
 * That is a deliberate narrowing of RFC 8785, and it is the safe direction: it
 * cannot produce a wrong hash, only a loud refusal.
 */
final class Canonicalizer
{
    /**
     * 2^53 - 1: the largest integer an IEEE-754 double represents exactly.
     *
     * RFC 8785 processes numbers as ECMAScript doubles, so this is the edge of
     * what the canonical form can carry without two implementations disagreeing.
     */
    public const MAX_SAFE_INTEGER = 9007199254740991;

    /** 2^63, which is not an int64 and is exactly representable as a double. */
    private const TWO_TO_THE_63 = '9223372036854775808';

    /** The smallest int64 whose nearest double is 2^63. */
    private const ROUNDS_UP_TO_TWO_TO_THE_63 = 9223372036854775296;

    /**
     * @param  array<array-key, mixed>|JsonSerializable  $payload
     */
    public static function encode(array|JsonSerializable $payload): string
    {
        if ($payload instanceof JsonSerializable) {
            $payload = $payload->jsonSerialize();

            if (! is_array($payload)) {
                throw NotCanonicalizable::topLevel(get_debug_type($payload));
            }
        }

        return self::value($payload);
    }

    public static function hash(array|JsonSerializable $payload): string
    {
        return hash('sha256', self::encode($payload));
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => throw NotCanonicalizable::float($value),
            is_int($value) => self::integer($value),
            is_string($value) => self::checkedString($value),
            is_array($value) => self::arrayValue($value),
            $value instanceof JsonSerializable => self::value($value->jsonSerialize()),
            default => throw NotCanonicalizable::type(get_debug_type($value)),
        };
    }

    private static function checkedString(string $value): string
    {
        self::assertUtf8($value);

        return self::string($value);
    }

    /** @param array<array-key, mixed> $value */
    private static function arrayValue(array $value): string
    {
        return array_is_list($value)
            ? '['.implode(',', array_map(self::value(...), $value)).']'
            : self::object($value);
    }

    /** @param array<array-key, mixed> $value */
    private static function object(array $value): string
    {
        $keys = array_map(strval(...), array_keys($value));

        // RFC 8785 section 3.2.3: sort on the UTF-16 code unit sequence.
        // Sorting the UTF-8 bytes gives a DIFFERENT order for any key
        // containing a character above U+FFFF, so the conversion is not
        // optional even though it looks like it on ASCII keys.
        usort($keys, static fn (string $a, string $b): int => strcmp(
            self::toUtf16Be($a),
            self::toUtf16Be($b),
        ));

        $members = [];
        foreach ($keys as $key) {
            $members[] = self::checkedString($key).':'.self::value($value[$key]);
        }

        return '{'.implode(',', $members).'}';
    }

    private static function toUtf16Be(string $utf8): string
    {
        $converted = mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8');

        if (! is_string($converted)) {
            throw NotCanonicalizable::encoding($utf8);
        }

        return $converted;
    }

    /**
     * Integers, refusing the ones that cannot survive the round trip.
     *
     * RFC 8785 processes numbers as ECMAScript doubles, so an integer beyond
     * 2^53-1 is outside what the canonical form can represent: a conformant
     * implementation reading 9007199254740993 serializes 9007199254740992, and
     * the two hashes differ.
     *
     * Measured: PHP emits the odd value unchanged, so the previous
     * `(string) $value` produced a canonical form no conformant implementation
     * would agree with. Refused for the same reason floats are - it cannot
     * produce a wrong hash, only a loud refusal - and nothing in PeakFlow needs
     * an integer that large, because money is a decimal string and identifiers
     * are ULIDs.
     */
    private static function integer(int $value): string
    {
        if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
            throw NotCanonicalizable::unsafeInteger($value, self::nearestRepresentable($value));
        }

        return (string) $value;
    }

    /**
     * The integer a conformant implementation would serialize instead.
     *
     * Computed with integer arithmetic rather than a float cast, because the
     * architecture rules forbid a float anywhere in the money path and this
     * file is in it. `(float) $value` would be the obvious implementation and
     * would also be the thing the rule exists to stop - a cast that is
     * harmless here is a cast somebody copies to somewhere it is not.
     *
     * Above 2^53 the doubles are spaced 2^(e-52) apart, where e is the
     * position of the value's highest set bit. So the answer is the nearest
     * multiple of that spacing, ties going to the even multiple - which is
     * what IEEE-754 round-to-nearest does.
     */
    private static function nearestRepresentable(int $value): string
    {
        /*
         | Two values that break the arithmetic below, handled first.
         |
         | Anything within 2^9 of 2^63: rounding up lands on 2^63, which no
         | int64 can hold, so the product overflows into a float and the
         | message reads "9.2233720368548E+18" - measured. 2^63's decimal form
         | is a constant, so it is written down.
         |
         | The negative branch also covers PHP_INT_MIN, which matters for a
         | second reason: negating it overflows, and the result would silently
         | become a float - the exact thing this method exists to avoid. Its
         | magnitude is exactly 2^63, so it is its own nearest double.
         */
        if ($value >= self::ROUNDS_UP_TO_TWO_TO_THE_63) {
            return self::TWO_TO_THE_63;
        }

        if ($value <= -self::ROUNDS_UP_TO_TWO_TO_THE_63) {
            return '-'.self::TWO_TO_THE_63;
        }

        $magnitude = $value < 0 ? -$value : $value;
        $exponent = 0;

        /*
         | Bounded at 62, not merely terminating.
         |
         | Removing the guards above does not produce a wrong answer, it
         | produces a HANG: `-PHP_INT_MIN` overflows into a float, and shifting
         | a float never reaches zero, so this loop runs forever. Measured - the
         | process had to be killed. A wrong hash is bad; a request that never
         | returns is worse, and it would be a request from a worker holding a
         | transaction open.
         |
         | So the loop cannot run away even if somebody deletes the guards.
         */
        while ($exponent < 62 && $magnitude >> ($exponent + 1) !== 0) {
            $exponent++;
        }

        $spacing = 1 << ($exponent - 52);
        $quotient = intdiv($magnitude, $spacing);
        $remainder = $magnitude - ($quotient * $spacing);
        $half = intdiv($spacing, 2);

        if ($remainder > $half || ($remainder === $half && $quotient % 2 === 1)) {
            $quotient++;
        }

        $rounded = $quotient * $spacing;

        return ($value < 0 ? '-' : '').(string) $rounded;
    }

    /**
     * RFC 8785 section 3.2.2.2, written out.
     *
     * Escape `"` and `\`; use the five short forms for the control characters
     * that have them; emit every other character below U+0020 as `\u00xx` with
     * LOWER-CASE hex. Everything else - including `/`, U+007F, U+2028 and
     * U+2029 - is emitted as literal UTF-8.
     *
     * The lower case matters: `\u001F` and `\u001f` are different bytes and
     * therefore different hashes, and the RFC specifies the lower-case form.
     */
    private static function string(string $value): string
    {
        static $short = [
            "\x08" => '\\b',
            "\x09" => '\\t',
            "\x0a" => '\\n',
            "\x0c" => '\\f',
            "\x0d" => '\\r',
            '"' => '\\"',
            '\\' => '\\\\',
        ];

        $out = '"';

        /*
         | Byte by byte, deliberately.
         |
         | Everything that needs escaping is ASCII, and every byte of a
         | multi-byte UTF-8 sequence is >= 0x80 - so a byte walk cannot
         | mistake part of a character for one that needs escaping, and it
         | passes multi-byte sequences through untouched without needing to
         | decode them.
         */
        $length = \strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $byte = $value[$i];

            if (isset($short[$byte])) {
                $out .= $short[$byte];

                continue;
            }

            $ordinal = \ord($byte);

            $out .= $ordinal < 0x20
                ? sprintf('\\u%04x', $ordinal)
                : $byte;
        }

        return $out.'"';
    }

    /**
     * The validity of the string is checked once, at the top.
     *
     * A byte walk cannot detect malformed UTF-8, and a hash computed over
     * invalid UTF-8 is a hash of bytes nobody else will reproduce - every
     * other implementation would either refuse the input or replace the bad
     * sequence. So an invalid string is refused rather than hashed.
     */
    private static function assertUtf8(string $value): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw NotCanonicalizable::encoding($value);
        }
    }
}
