<?php

declare(strict_types=1);

namespace App\Support\Http\Api;

use App\Support\Http\Exceptions\IdempotencyRefused;
use App\Support\Http\Exceptions\ServerFailureNotStored;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What a request already did, so a retry does not do it twice - PF-220.
 *
 * ## The claim and the work are ONE transaction, and that is the whole design
 *
 * A network that drops a RESPONSE is indistinguishable from one that drops a
 * REQUEST, so a client must retry and the server must make retrying safe. The
 * obvious implementation records the key, commits, does the work, and records
 * the answer. It has a hole exactly the width of the work: two requests that
 * arrive together both find no record, both claim, and the unique index
 * refuses one of them with a database error at a moment when the other has
 * already posted.
 *
 * So the claim is inserted inside the same transaction as the work. Then the
 * race is answered by PostgreSQL rather than by us:
 *
 *   - The winner inserts the claim, does the work, writes its response into
 *     the claim row, and commits. One document.
 *   - The loser's `INSERT ... ON CONFLICT DO NOTHING` BLOCKS on the unique
 *     index - it cannot know whether the conflicting row will survive until
 *     the other transaction ends. When the winner commits, the loser's insert
 *     affects no rows, it reads the committed record, and it replays the
 *     winner's response byte for byte. Two identical responses.
 *
 * That is the concurrent case PF-220 asks for a test of, and it needs no
 * coordination of ours at all.
 *
 * ## `lock_timeout` is what separates "wait" from "still in flight"
 *
 * The loser cannot wait for ever: a slow write would hold a client's
 * connection open for as long as it takes. So the transaction sets a local
 * lock timeout, and a wait longer than that becomes the 409 with
 * `Retry-After` that PF-220 asks for - a refusal rather than a second
 * execution.
 *
 * The consequence is worth stating plainly: the `in_flight` state is never
 * visible to another request, because it is only ever uncommitted. What the
 * second request observes is the LOCK, and the 409 reports that. The state
 * column is still written, so that a record in a dump says what it is, and so
 * that the constraint forbidding a completed record with no body has
 * something to stand on.
 *
 * ## A 5xx is not an answer, so it is not stored
 *
 * A stored 500 would be replayed for 24 hours: the client could never succeed
 * with that key, and the key is the only thing it is allowed to retry with.
 * So any outcome at or above 500, and any uncaught throw, rolls the whole
 * transaction back - the claim with it - and the next attempt executes.
 *
 * A 4xx IS an answer and is stored. The same request sent twice gets the same
 * refusal, which is what idempotency means; re-running a validator to produce
 * the same 422 is work nobody needs done twice.
 *
 * ## The record is entity-scoped as well
 *
 * `api_idempotency_records` is behind forced row-level security, like every
 * other table here that holds figures - because the row holds a stored
 * RESPONSE BODY, which is a document from one company with its amounts on it.
 * The fingerprint comparison below already stops another entity reading one,
 * and that comparison is application code: one `hash_equals` away from a
 * mistake, and bypassed entirely by a raw query or a console session.
 *
 * The two mechanisms interact in one place worth knowing about. The unique
 * index spans entities and the policy does not, so a claim can conflict with
 * a row this entity cannot see - and that is not ambiguous: an invisible
 * conflicting row belongs to another entity, which means the same key was
 * used for a different request. It is reported as the conflict it is, and
 * never as a request in flight, which would tell the client to retry
 * something that is never going to finish.
 *
 * ## The fingerprint is a hash, not the body
 *
 * A request body holds amounts, party names and sometimes a bank identifier,
 * and this table lives for 24 hours in a database somebody may dump. A
 * SHA-256 answers the only question asked of it - is this the same request -
 * and leaks nothing.
 */
final class IdempotencyStore
{
    /**
     * The header, spelled as every client library spells it.
     *
     * Not `X-Idempotency-Key`: the de facto name has no prefix, the IETF
     * draft uses this one, and an API that invents its own spelling makes
     * every integrator write a line of configuration to find out.
     */
    public const HEADER = 'Idempotency-Key';

    /** Set when a response is a replay rather than a fresh execution. */
    public const REPLAY_HEADER = 'Idempotent-Replay';

    /**
     * Run the work once, or answer with what it answered the first time.
     *
     * @param  callable(): Response  $work
     *
     * @throws IdempotencyRefused
     */
    public function run(
        string $tokenId,
        string $key,
        string $entityId,
        string $fingerprint,
        callable $work,
    ): Response {
        $connection = DB::connection('tenant');

        /*
         | Expired records are cleared before the claim, not by a nightly job
         | alone.
         |
         | The window is 24 hours and the contract is that a retry after it
         | EXECUTES. If an expired row were left in place, the claim would
         | conflict with it and the request would be replayed from a response
         | the client is no longer entitled to - or refused as a conflict,
         | which is worse, because the client cannot tell why its own key has
         | become unusable.
         */
        $connection->table('api_idempotency_records')
            ->where('token_id', $tokenId)
            ->where('idempotency_key', $key)
            ->where('expires_at', '<', now())
            ->delete();

        $replay = null;

        try {
            $response = $connection->transaction(function () use (
                $connection,
                $tokenId,
                $key,
                $entityId,
                $fingerprint,
                $work,
                &$replay,
            ): Response {
                /*
                 | A lock timeout for the CLAIM, and for nothing else.
                 |
                 | LOCAL, so it dies with the transaction and cannot leak onto
                 | a pooled connection and shorten somebody else's lock wait -
                 | which would present as random `lock_not_available` errors
                 | in code that never asked for a timeout.
                 |
                 | And reset immediately after the claim, which matters more
                 | than it looks. The work that follows takes locks of its
                 | own, and one of them - the audit chain head - is a global
                 | serialization point every write in the tenant queues
                 | behind. A timeout covering the whole transaction would
                 | turn a busy morning into "the first request with this key
                 | is still running", which is a sentence that is not true and
                 | sends a client to wait for something that already finished.
                 |
                 | Measured, not imagined: with the timeout left in place for
                 | the transaction, a post behind an unrelated audit write
                 | failed with `canceling statement due to lock timeout` while
                 | locking `audit_chain_head`, and the store reported it as a
                 | request in flight.
                 */
                $connection->statement(sprintf(
                    'SET LOCAL lock_timeout = %d',
                    max(250, (int) config('api.idempotency.claim_wait_milliseconds', 2500)),
                ));

                /*
                 | Lower case, like every id Eloquent generates.
                 |
                 | `Str::ulid()` returns upper case and `HasUlids` lower-cases
                 | it, so a table written by hand and a table written by a
                 | model disagree about the case of their own keys. Harmless
                 | until something joins or compares them as strings, which
                 | `char(26)` does.
                 */
                $recordId = Str::lower((string) Str::ulid());

                try {
                    $claimed = $connection->table('api_idempotency_records')->insertOrIgnore([
                        'id' => $recordId,
                        'token_id' => $tokenId,
                        'idempotency_key' => $key,
                        'entity_id' => $entityId,
                        'request_fingerprint' => $fingerprint,
                        'state' => 'in_flight',
                        'expires_at' => now()->addHours((int) config('api.idempotency.retention_hours', 24)),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    /*
                     | The claim, and only the claim, can time out on the
                     | unique index - which is what "still in flight" means.
                     |
                     | And the reset is NOT in a `finally`. A statement
                     | PostgreSQL refuses aborts the whole transaction, so
                     | `SET LOCAL` in a finally block fails with "current
                     | transaction is aborted" and the new exception REPLACES
                     | this one - which turned the 409 this check exists for
                     | into a 500. The transaction is about to roll back
                     | anyway, so there is nothing to reset.
                     */
                    throw $this->lockTimeoutOrRethrow($e, $key);
                }

                $connection->statement('SET LOCAL lock_timeout = DEFAULT');

                if ($claimed === 0) {
                    $replay = $this->existing($tokenId, $key, $fingerprint);

                    return $replay;
                }

                $response = $work();

                /*
                 | A server failure is not an answer. Thrown rather than
                 | returned, so the transaction - and the claim with it -
                 | rolls back and the client's next attempt executes.
                 */
                if ($response->getStatusCode() >= 500) {
                    throw new ServerFailureNotStored($response);
                }

                $connection->table('api_idempotency_records')
                    ->where('id', $recordId)
                    ->update([
                        'state' => 'completed',
                        'response_status' => $response->getStatusCode(),
                        'response_body' => (string) $response->getContent(),
                        'subject_type' => $this->subjectTypeOf($response),
                        'subject_id' => $this->subjectIdOf($response),
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $response;
            });
        } catch (ServerFailureNotStored $failure) {
            // Returned, not re-thrown: the handler already decided what to
            // say, and nothing was stored.
            return $failure->response;
        }

        return $response;
    }

    /**
     * The fingerprint of a request: what it asked, where, and as whom.
     *
     * The entity is inside it deliberately. PF-220 keys the record on
     * `(tenant, token, key)`, so the same key sent with a different
     * `X-Entity` finds the same record - and if the entity were not part of
     * the fingerprint it would be REPLAYED, which means a client that
     * repointed its integration at a second company would be told its
     * invoice was created there and it would not exist. With the entity in
     * the fingerprint it is a 409, which is the client bug it is.
     */
    public static function fingerprint(Request $request, string $entityId): string
    {
        return hash('sha256', implode("\0", [
            $request->getMethod(),
            // The path, not the full URL: the host differs between a
            // customer's own domain and ours, and a retry through the other
            // one is the same request.
            $request->getPathInfo(),
            $entityId,
            (string) $request->getContent(),
        ]));
    }

    /**
     * The key on this request, refused if it is not one.
     *
     * @throws IdempotencyRefused
     */
    public static function keyFrom(Request $request): string
    {
        $key = $request->header(self::HEADER);
        $minimum = (int) config('api.idempotency.minimum_key_length', 16);
        $maximum = (int) config('api.idempotency.maximum_key_length', 128);

        if (! \is_string($key) || trim($key) === '') {
            throw IdempotencyRefused::keyRequired(sprintf(
                'This write posts to the ledger and needs an %s header. It is required rather than optional '.
                'because a client that retries without one posts the document twice, and the second one is '.
                'indistinguishable from a real transaction - so the request is refused instead of accepted.',
                self::HEADER,
            ));
        }

        $key = trim($key);

        if (mb_strlen($key) < $minimum || mb_strlen($key) > $maximum) {
            throw IdempotencyRefused::keyRequired(sprintf(
                'An %s must be between %d and %d characters. A short key is not a unique key: it collides '.
                'with this same token\'s next request, and the collision presents as a write that silently '.
                'did nothing.',
                self::HEADER,
                $minimum,
                $maximum,
            ));
        }

        return $key;
    }

    /**
     * A record that already exists: replay it, or refuse the request.
     *
     * @throws IdempotencyRefused
     */
    private function existing(string $tokenId, string $key, string $fingerprint): Response
    {
        $record = DB::connection('tenant')->table('api_idempotency_records')
            ->where('token_id', $tokenId)
            ->where('idempotency_key', $key)
            ->first();

        if ($record === null) {
            /*
             | The claim conflicted with a row this entity cannot see.
             |
             | The unique index spans entities and the row-level security
             | policy does not, so this is not ambiguous: a conflicting row
             | that is invisible belongs to another entity, which means the
             | same key was used for a different request. Reported as the
             | conflict it is.
             |
             | "Still in flight" would be the wrong answer and the dangerous
             | one: it tells the client to retry, and every retry would get
             | the same answer for ever because nothing is going to finish.
             |
             | The other way to reach this branch is a row that expired in the
             | instant between the failed claim and this read. A conflict is
             | still the better answer there - the remedy for both is a new
             | key - and it is a race measured in microseconds against a
             | 24-hour window.
             */
            throw IdempotencyRefused::bodyDiffers($key);
        }

        if (! hash_equals((string) $record->request_fingerprint, $fingerprint)) {
            throw IdempotencyRefused::bodyDiffers($key);
        }

        if ((string) $record->state !== 'completed') {
            throw IdempotencyRefused::stillRunning(
                $key,
                (int) config('api.idempotency.in_flight_retry_after_seconds', 2),
            );
        }

        /*
         | The stored bytes, not a re-rendered representation.
         |
         | Re-deriving the answer would drift the moment a representation
         | changes - and worse, it would run the queries that produce it,
         | which for a client retrying in a loop is the load idempotency was
         | supposed to remove.
         */
        return new Response(
            (string) $record->response_body,
            (int) $record->response_status,
            [
                'Content-Type' => 'application/json',
                self::REPLAY_HEADER => 'true',
            ],
        );
    }

    /**
     * Was that the claim timing out on the lock, or something else?
     *
     * `55P03` is `lock_not_available`, which here can only be the claim
     * waiting on another transaction holding the same key. Anything else is
     * re-thrown untouched: swallowing a constraint violation and reporting it
     * as "still in flight" would tell a client to retry a request that can
     * never succeed.
     */
    private function lockTimeoutOrRethrow(QueryException $e, string $key): Throwable
    {
        if ((string) $e->getCode() === '55P03' || str_contains($e->getMessage(), 'lock timeout')) {
            return IdempotencyRefused::stillRunning(
                $key,
                (int) config('api.idempotency.in_flight_retry_after_seconds', 2),
            );
        }

        return $e;
    }

    private function subjectTypeOf(Response $response): ?string
    {
        $subject = $this->decoded($response);

        return \is_string($subject['resource'] ?? null) ? (string) $subject['resource'] : null;
    }

    private function subjectIdOf(Response $response): ?string
    {
        $subject = $this->decoded($response);
        $id = $subject['id'] ?? null;

        return \is_string($id) && mb_strlen($id) === 26 ? $id : null;
    }

    /** @return array<string, mixed> */
    private function decoded(Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
