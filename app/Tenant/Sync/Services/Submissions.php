<?php

declare(strict_types=1);

namespace App\Tenant\Sync\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Sync\Exceptions\SyncRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes that arrived from a device, applied exactly once - PF-255.
 *
 * ## The contract
 *
 * A network that drops a RESPONSE is indistinguishable from one that
 * drops a REQUEST. The till cannot tell them apart, so it must retry;
 * the server must make retrying safe. The only way is for the client to
 * name the write before its first attempt, which is what `client_id`
 * is.
 *
 * {@see submit()} therefore has three outcomes and not two:
 *
 * - **applied**: new work, done, and the answer recorded;
 * - **replayed**: this id has been seen, and the ORIGINAL answer comes
 *   back - not a new one, because the till is showing a figure to a
 *   customer and a second total is worse than a slow one;
 * - **conflicted**: it cannot be applied, and a person has to decide.
 *
 * ## Why the stored result matters as much as the stored id
 *
 * A row that recorded only "we have seen this" makes the second attempt
 * answerable with nothing. The till then either shows an error for a
 * sale that went through, or retries forever. So the outcome is written
 * in the same transaction as the work, and a replay reads it.
 *
 * ## Nothing is ordered or dated by the device's clock
 *
 * `occurred_at` is recorded because the skew has to be visible - "the
 * till says 400 and the server says 380" is otherwise unanswerable -
 * and is used for nothing else. A tablet that spent a month in a drawer
 * comes back with last month's date, and a product that dated postings
 * by it would post into a closed period. The order is `received_seq`,
 * assigned by the database on arrival.
 *
 * ## Conflicts are surfaced
 *
 * Two tills selling the last unit is a fact. A last-write-wins rule
 * decides it by which shop has better wifi, and decides it invisibly.
 * So the submission becomes a conflict with both sides written on it,
 * and somebody resolves it.
 */
final class Submissions
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Take a write from a device. Safe to call twice with one id.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): array<string, mixed>  $apply
     * @return array<string, mixed>
     *
     * @throws SyncRefused
     */
    public function submit(
        string $entityId,
        string $deviceId,
        string $clientId,
        string $kind,
        array $payload,
        Carbon $occurredAt,
        callable $apply,
    ): array {
        $clientId = trim($clientId);

        if (mb_strlen($clientId) < 8) {
            throw SyncRefused::aWriteWithNoName();
        }

        /*
         | The replay check, before anything else.
         |
         | Read outside the transaction that does the work, so the
         | common case - a retry of something already done - costs one
         | indexed lookup rather than a transaction and a rollback.
         */
        $seen = DB::connection('tenant')->table('sync_submissions')
            ->where('client_id', $clientId)
            ->first();

        if ($seen !== null) {
            return $this->replay($seen);
        }

        $id = (string) Str::ulid();
        $receivedAt = Carbon::now();

        try {
            return DB::connection('tenant')->transaction(function () use (
                $id, $entityId, $deviceId, $clientId, $kind, $payload, $occurredAt, $receivedAt, $apply
            ): array {
                /*
                 | The row first, then the work.
                 |
                 | In that order so the unique index on `client_id` is
                 | what serialises two simultaneous attempts at the same
                 | write - which is the one case a read-then-write check
                 | cannot see, and the one that happens when a till
                 | retries while the first attempt is still in flight.
                 */
                DB::connection('tenant')->table('sync_submissions')->insert([
                    'id' => $id,
                    'entity_id' => $entityId,
                    'client_id' => $clientId,
                    'device_id' => $deviceId,
                    'kind' => $kind,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'occurred_at' => $occurredAt->toDateTimeString(),
                    'received_at' => $receivedAt->toDateTimeString(),
                    'state' => 'accepted',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $result = $apply($payload);

                DB::connection('tenant')->table('sync_submissions')->where('id', $id)->update([
                    'state' => 'applied',
                    'outcome' => (string) ($result['why'] ?? 'applied with no explanation recorded'),
                    'result' => json_encode($result, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);

                return [
                    'outcome' => 'applied',
                    'client_id' => $clientId,
                    'result' => $result,
                    'skew' => $this->skew($occurredAt, $receivedAt),
                ];
            });
        } catch (Throwable $e) {
            /*
             | A failure is not a conflict until somebody says what the
             | two sides are.
             |
             | `$apply` throws a refusal that names them - "the shelf
             | holds 1 and this sale is for 3" - and that sentence is
             | what makes the conflict decidable. An exception with no
             | sides to it is a rejection, recorded as one.
             */
            return $e instanceof SyncRefused && $e->conflict !== null
                ? $this->conflict($id, $entityId, $deviceId, $clientId, $kind, $payload, $occurredAt, $receivedAt, $e)
                : $this->reject($id, $entityId, $deviceId, $clientId, $kind, $payload, $occurredAt, $receivedAt, $e);
        }
    }

    /**
     * Resolve a conflict. A person decides; nothing is merged.
     *
     * @throws SyncRefused
     */
    public function resolve(string $conflictId, string $decision, string $userId): void
    {
        $conflict = DB::connection('tenant')->table('sync_conflicts')->where('id', $conflictId)->first();

        if ($conflict === null) {
            throw SyncRefused::because('There is no conflict with that id.');
        }

        if ((string) $conflict->state !== 'open') {
            throw SyncRefused::because(sprintf(
                'That conflict was already %s. Deciding it twice would leave two records of one '.
                'decision about the same money.',
                (string) $conflict->state,
            ));
        }

        if (mb_strlen(trim($decision)) < 10) {
            throw SyncRefused::because(
                'A conflict is a decision about money or stock - "we honoured the sale and wrote the '.
                'unit off" - and this row is the only record that it was made. It needs the sentence.',
            );
        }

        DB::connection('tenant')->table('sync_conflicts')->where('id', $conflictId)->update([
            'state' => 'resolved',
            'decision' => trim($decision),
            'decided_by' => $userId,
            'decided_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            category: 'sync',
            action: 'conflict.resolved',
            subjectType: 'sync_conflict',
            subjectId: $conflictId,
            subjectLabel: (string) $conflict->kind,
            before: ['state' => 'open'],
            after: ['state' => 'resolved'],
            reason: trim($decision),
            entityId: (string) $conflict->entity_id,
        );
    }

    /**
     * What a device has sent, in the order it ARRIVED.
     *
     * Which is what answers "the till says 400 and the server says 380":
     * the list, with each submission's own clock beside the moment it
     * reached us, so the skew is a column rather than an argument.
     *
     * @return list<array<string, mixed>>
     */
    public function logFor(string $entityId, ?string $deviceId = null, int $limit = 200): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT s.client_id,
                   s.device_id,
                   s.kind,
                   s.state,
                   s.outcome,
                   s.occurred_at,
                   s.received_at,
                   s.received_seq,
                   extract(epoch FROM (s.received_at - s.occurred_at))::bigint AS skew_seconds
            FROM sync_submissions s
            WHERE s.entity_id = ?
              AND (?::char(26) IS NULL OR s.device_id = ?)
            ORDER BY s.received_seq DESC
            LIMIT ?
        SQL, [$entityId, $deviceId, $deviceId, $limit]);

        return array_map(static fn (object $row): array => [
            'client_id' => (string) $row->client_id,
            'device_id' => (string) $row->device_id,
            'kind' => (string) $row->kind,
            'state' => (string) $row->state,
            'outcome' => $row->outcome === null ? null : (string) $row->outcome,
            'device_said' => (string) $row->occurred_at,
            'arrived' => (string) $row->received_at,
            'sequence' => (int) $row->received_seq,
            'skew_seconds' => (int) $row->skew_seconds,
        ], $rows);
    }

    /**
     * Conflicts somebody still has to decide.
     *
     * @return list<array<string, mixed>>
     */
    public function openConflicts(string $entityId): array
    {
        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT c.id,
                   c.kind,
                   c.what_the_device_said,
                   c.what_the_server_says,
                   s.client_id,
                   s.device_id,
                   s.occurred_at,
                   s.received_at
            FROM sync_conflicts c
            JOIN sync_submissions s ON s.id = c.submission_id
            WHERE c.entity_id = ?
              AND c.state = 'open'
            ORDER BY s.received_seq
        SQL, [$entityId]);

        return array_map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'kind' => (string) $row->kind,
            'device_said' => (string) $row->what_the_device_said,
            'server_says' => (string) $row->what_the_server_says,
            'client_id' => (string) $row->client_id,
            'device_id' => (string) $row->device_id,
            'occurred_at' => (string) $row->occurred_at,
            'received_at' => (string) $row->received_at,
        ], $rows);
    }

    /**
     * The original answer, not a new one.
     *
     * @return array<string, mixed>
     */
    private function replay(object $seen): array
    {
        /** @var array<string, mixed> $result */
        $result = $seen->result === null
            ? []
            : (array) json_decode((string) $seen->result, true, 512, JSON_THROW_ON_ERROR);

        return [
            'outcome' => 'replayed',
            'client_id' => (string) $seen->client_id,
            'state' => (string) $seen->state,
            'result' => $result,
            'why' => sprintf(
                'This write arrived before and was %s: %s. The same answer is returned rather than a '.
                'new one, because the till is showing a figure to a customer and a second total is '.
                'worse than a slow one.',
                (string) $seen->state,
                (string) ($seen->outcome ?? 'recorded with no outcome'),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function conflict(
        string $id,
        string $entityId,
        string $deviceId,
        string $clientId,
        string $kind,
        array $payload,
        Carbon $occurredAt,
        Carbon $receivedAt,
        SyncRefused $refusal,
    ): array {
        $conflictId = (string) Str::ulid();

        DB::connection('tenant')->transaction(function () use (
            $id, $conflictId, $entityId, $deviceId, $clientId, $kind,
            $payload, $occurredAt, $receivedAt, $refusal
        ): void {
            $this->record($id, $entityId, $deviceId, $clientId, $kind, $payload, $occurredAt, $receivedAt, [
                'state' => 'conflicted',
                'outcome' => $refusal->getMessage(),
            ]);

            DB::connection('tenant')->table('sync_conflicts')->insert([
                'id' => $conflictId,
                'entity_id' => $entityId,
                'submission_id' => $id,
                'kind' => $kind,
                'what_the_device_said' => (string) ($refusal->conflict['device'] ?? '-'),
                'what_the_server_says' => (string) ($refusal->conflict['server'] ?? '-'),
                'state' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return [
            'outcome' => 'conflicted',
            'client_id' => $clientId,
            'conflict_id' => $conflictId,
            'device_said' => (string) ($refusal->conflict['device'] ?? '-'),
            'server_says' => (string) ($refusal->conflict['server'] ?? '-'),
            'skew' => $this->skew($occurredAt, $receivedAt),
            'why' => sprintf(
                '%s This is not merged and not discarded: somebody decides it, and the decision is '.
                'recorded against this conflict.',
                $refusal->getMessage(),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function reject(
        string $id,
        string $entityId,
        string $deviceId,
        string $clientId,
        string $kind,
        array $payload,
        Carbon $occurredAt,
        Carbon $receivedAt,
        Throwable $error,
    ): array {
        $this->record($id, $entityId, $deviceId, $clientId, $kind, $payload, $occurredAt, $receivedAt, [
            'state' => 'rejected',
            'outcome' => $error->getMessage(),
        ]);

        return [
            'outcome' => 'rejected',
            'client_id' => $clientId,
            'skew' => $this->skew($occurredAt, $receivedAt),
            /*
             | Recorded as rejected, so a retry gets the same refusal.
             |
             | A rejection that left no row would be retried forever by
             | a till that cannot parse the error - and the queue on the
             | device never empties.
             */
            'why' => sprintf(
                '%s Recorded as rejected, so retrying this id returns the same refusal rather than '.
                'queueing on the device forever.',
                $error->getMessage(),
            ),
        ];
    }

    /**
     * Write the submission row outside the failed transaction.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $settled
     */
    private function record(
        string $id,
        string $entityId,
        string $deviceId,
        string $clientId,
        string $kind,
        array $payload,
        Carbon $occurredAt,
        Carbon $receivedAt,
        array $settled,
    ): void {
        DB::connection('tenant')->table('sync_submissions')->insert([
            'id' => $id,
            'entity_id' => $entityId,
            'client_id' => $clientId,
            'device_id' => $deviceId,
            'kind' => $kind,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt->toDateTimeString(),
            'received_at' => $receivedAt->toDateTimeString(),
            'state' => $settled['state'],
            'outcome' => $settled['outcome'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * How far out the device's clock is, said in words.
     *
     * @return array<string, mixed>
     */
    private function skew(Carbon $occurredAt, Carbon $receivedAt): array
    {
        $seconds = (int) $occurredAt->diffInSeconds($receivedAt, false);

        return [
            'seconds' => $seconds,
            'why' => abs($seconds) < 120
                ? 'The device\'s clock agrees with ours.'
                : sprintf(
                    'The device\'s clock is %s by about %d minute(s). Recorded, and used for nothing: '.
                    'the order and the dates come from when this arrived, because a till with the '.
                    'wrong date must not be able to post into a closed period.',
                    $seconds > 0 ? 'behind' : 'ahead',
                    (int) round(abs($seconds) / 60),
                ),
        ];
    }
}
