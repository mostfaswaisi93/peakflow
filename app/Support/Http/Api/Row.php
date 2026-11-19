<?php

declare(strict_types=1);

namespace App\Support\Http\Api;

/**
 * Typed reads from a query-builder row, for the API's shaping closures.
 *
 * A `stdClass` row is `mixed` all the way down, and PostgreSQL hands numerics
 * back as strings and booleans as booleans - or as `t`/`f` through some
 * drivers. One place that says how each is read, rather than a `(string)`
 * cast at every call site that is right until the first null.
 */
final class Row
{
    public static function text(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        return \is_string($value) ? $value : (\is_int($value) || \is_float($value) ? (string) $value : '');
    }

    public static function nullableText(object $row, string $field): ?string
    {
        $value = $row->{$field} ?? null;

        return $value === null ? null : self::text($row, $field);
    }

    public static function bool(object $row, string $field): bool
    {
        $value = $row->{$field} ?? null;

        return $value === true || $value === 1 || $value === 't' || $value === '1' || $value === 'true';
    }

    /**
     * A numeric(…,4) column as Money on the wire, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function money(object $row, string $field, string $currency): ?array
    {
        $value = self::nullableText($row, $field);

        return $value === null || $value === '' ? null : Wire::amount($value, $currency);
    }
}
