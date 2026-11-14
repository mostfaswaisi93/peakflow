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
            is_int($value) => (string) $value,
            is_float($value) => throw NotCanonicalizable::float($value),
            is_string($value) => self::string($value),
            is_array($value) => self::arrayValue($value),
            $value instanceof JsonSerializable => self::value($value->jsonSerialize()),
            default => throw NotCanonicalizable::type(get_debug_type($value)),
        };
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
            $members[] = self::string($key).':'.self::value($value[$key]);
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
     * RFC 8785 section 3.2.2.2: escape only what must be escaped, keep `/`
     * literal, and emit non-ASCII as UTF-8 rather than as `\uXXXX`.
     */
    private static function string(string $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $encoded;
    }
}
