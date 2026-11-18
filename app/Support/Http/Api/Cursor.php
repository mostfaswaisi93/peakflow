<?php

declare(strict_types=1);

namespace App\Support\Http\Api;

use App\Support\Http\Exceptions\CursorNotReadable;

/**
 * An opaque, signed pagination cursor - PF-221.
 *
 * ## Why it is signed and not merely encoded
 *
 * Base64 is not a secret and nobody should pretend it is. An unsigned cursor
 * is a hand-editable WHERE clause: a client that reads
 * `{"k":"2026-03-01","i":"01JD..."}` out of one and sends back
 * `{"k":"1900-01-01"}` has written a query nobody reviewed, and the
 * interesting version of that is not an attack but an integration that
 * "helpfully" constructs cursors itself and then breaks the first time the
 * sort key changes.
 *
 * So the payload carries a keyed MAC, and a cursor that does not verify is a
 * 400 naming the condition rather than a page of something unexpected.
 *
 * ## It is bound to the listing that produced it
 *
 * `scope` is the operation id and the sort, and it is inside the signed
 * material. Without it, a cursor from `listJournalEntries?sort=-accounting_date`
 * verifies perfectly against `listAccounts` - and the keyset predicate then
 * compares an account code to a date. PostgreSQL either errors or, worse,
 * casts, and the client gets a plausible page from the wrong place in the
 * table.
 *
 * ## What it does NOT contain
 *
 * An offset, and nothing derived from one. The cursor is the last row's sort
 * key and its id, which is what makes the next page a seek rather than a
 * scan: `OFFSET 50000` reads fifty thousand rows in order to discard them,
 * and over a journal that is still being written to it also DRIFTS - a row
 * inserted between two requests shifts every later page by one, so a client
 * paging through a month silently skips an entry.
 */
final readonly class Cursor
{
    public const FORWARD = 'f';

    public const BACKWARD = 'b';

    private function __construct(
        /** The sort key of the row this cursor sits after (or before). */
        public string $key,
        /** The id of that row, which breaks ties in a non-unique sort key. */
        public string $id,
        /** Which way the page runs from here. */
        public string $direction,
    ) {}

    public static function after(string $key, string $id): self
    {
        return new self($key, $id, self::FORWARD);
    }

    public static function before(string $key, string $id): self
    {
        return new self($key, $id, self::BACKWARD);
    }

    public function isForward(): bool
    {
        return $this->direction === self::FORWARD;
    }

    /**
     * The opaque string a client receives.
     *
     * @param  string  $scope  the listing this cursor belongs to, inside the signature
     */
    public function encode(string $scope): string
    {
        $payload = self::base64Url((string) json_encode([
            'k' => $this->key,
            'i' => $this->id,
            'd' => $this->direction,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $payload.'.'.self::base64Url(self::mac($payload, $scope));
    }

    /**
     * Read a cursor a client sent back, or refuse it.
     *
     * @throws CursorNotReadable
     */
    public static function decode(string $cursor, string $scope): self
    {
        $parts = explode('.', $cursor);

        if (\count($parts) !== 2) {
            throw CursorNotReadable::malformed();
        }

        [$payload, $signature] = $parts;

        /*
         | `hash_equals`, not `===`.
         |
         | A short-circuiting comparison leaks how many leading bytes were
         | right through its own timing, which over enough requests is a
         | forgeable signature. The cost of being careful here is nothing.
         */
        if (! hash_equals(self::base64Url(self::mac($payload, $scope)), $signature)) {
            throw CursorNotReadable::signatureDoesNotVerify();
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($decoded === false) {
            throw CursorNotReadable::malformed();
        }

        /** @var mixed $data */
        $data = json_decode($decoded, true);

        if (! \is_array($data)
            || ! \is_string($data['k'] ?? null)
            || ! \is_string($data['i'] ?? null)
            || ! \in_array($data['d'] ?? null, [self::FORWARD, self::BACKWARD], true)) {
            throw CursorNotReadable::malformed();
        }

        return new self((string) $data['k'], (string) $data['i'], (string) $data['d']);
    }

    /**
     * The MAC over the payload and the listing it belongs to.
     *
     * Keyed with the application key, through a derived purpose string. The
     * derivation matters: reusing `APP_KEY` directly for several unrelated
     * signatures means a value signed for one purpose verifies for another,
     * and the cheapest way to never have that conversation is to never share
     * a key between purposes.
     */
    private static function mac(string $payload, string $scope): string
    {
        return hash_hmac(
            'sha256',
            $scope."\0".$payload,
            hash_hmac('sha256', 'peakflow/api/cursor', self::signingKey(), true),
            true,
        );
    }

    private static function signingKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? $key : $decoded;
        }

        return $key;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
