<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;

/**
 * The one definition of what an audit record's hash covers - PF-035.
 *
 * ## Why this is a class and not two field lists
 *
 * It was two field lists: one in the writer, one in the verifier. They
 * drifted immediately and the chain could not verify a single record it had
 * just written. The writer and the verifier MUST agree exactly and forever,
 * and the only way to guarantee that is to make it impossible for them to
 * disagree. Hence one class, used by both.
 *
 * ## Why the values are normalized rather than hashed as they are
 *
 * The first version hashed the values as the writer happened to have them: a
 * PHP-formatted timestamp string and a `json_encode` result. Both change when
 * they come back out of PostgreSQL - `timestamptz` renders in the server's own
 * format, and `jsonb` reorders keys and drops insignificant whitespace, by
 * design.
 *
 * So the hash could never match on the way back. A hash over a value whose
 * text representation changes on a database round-trip is not a hash of
 * anything durable.
 *
 * Both directions therefore go through here first:
 *
 * - timestamps become UTC, microsecond precision, one fixed format
 * - JSON becomes a decoded structure, which the RFC 8785 canonicalizer then
 *   orders deterministically - so `jsonb` reordering the keys cannot matter
 * - everything else becomes a string, or null
 *
 * ## The field SET is permanent
 *
 * Key ORDER is irrelevant - canonicalization sorts it. The set of keys is not:
 * adding one changes every hash from that moment on, which requires a new
 * chain segment and a recorded boundary, never an edit to this list.
 */
final class AuditRecordProjection
{
    /** @var list<string> */
    public const FIELDS = [
        'id', 'entity_id', 'actor_user_id', 'actor_name', 'impersonated_by',
        'category', 'action', 'subject_type', 'subject_id', 'subject_label',
        'party_id', 'period_id',
        'before_state', 'after_state', 'reason', 'correlation_id',
        'ip_address', 'user_agent', 'created_at',
    ];

    /** @var list<string> Columns stored as jsonb. */
    private const JSON_FIELDS = ['before_state', 'after_state'];

    private const TIMESTAMP_FIELDS = ['created_at'];

    /**
     * @param  array<string, mixed>|object  $record  a row about to be written, or one read back
     * @return array<string, mixed>
     */
    public static function of(array|object $record): array
    {
        $projection = [];

        foreach (self::FIELDS as $field) {
            $raw = is_array($record)
                ? ($record[$field] ?? null)
                : ($record->{$field} ?? null);

            $projection[$field] = match (true) {
                in_array($field, self::TIMESTAMP_FIELDS, true) => self::normalizeTimestamp($raw),
                in_array($field, self::JSON_FIELDS, true) => self::normalizeJson($raw),
                $raw === null => null,
                default => (string) $raw,
            };
        }

        return $projection;
    }

    /**
     * One format, UTC, microseconds.
     *
     * PostgreSQL returns a `timestamptz` in the session's time zone and its
     * own text format, and the driver may or may not include microseconds.
     * None of that is part of what the record means, so none of it reaches the
     * hash.
     */
    private static function normalizeTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value);

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * A decoded structure, so key order cannot matter.
     *
     * `jsonb` does not preserve key order or whitespace - that is the point of
     * the type. Decoding here and letting the canonicalizer order the result
     * means the stored form and the written form project identically.
     *
     * @return array<array-key, mixed>|string|null
     */
    private static function normalizeJson(mixed $value): array|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Not decodable: hash the text rather than silently dropping it.
            // A column that is supposed to hold JSON and does not is itself
            // worth preserving in the evidence.
            return (string) $value;
        }

        return is_array($decoded) ? $decoded : (string) $value;
    }
}
