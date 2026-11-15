<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Support\Hashing\ChainLink;
use App\Tenant\Identity\Models\User;
use App\Tenant\Tenancy\Services\EntityContext;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends to the tenant's audit log, hash-chained - PF-035.
 *
 * ## What the chain proves, and what it does not
 *
 * Each record's hash covers its own legally significant fields AND the hash of
 * the record before it. Changing or removing any record invalidates every
 * record after it, so the chain proves the log is CONSISTENT - that nothing
 * has been edited or excised since it was written.
 *
 * It does not prove AUTHORSHIP. Anyone who can write to this table can write a
 * well-formed record and extend the chain correctly. Detecting a forged record
 * requires a signature over a key the application does not hold, which is
 * PF-178's countersigning, not this. Saying so plainly matters: a chain
 * described as tamper-PROOF invites exactly the trust it cannot support.
 *
 * ## Reads are audited too
 *
 * Who LOOKED at a bank account is an auditable event. Unusual, and deliberate:
 * the question an investigation asks is frequently "who knew", not "who
 * changed".
 */
final class AuditLog
{
    public function __construct(
        private readonly EntityContext $entities,
    ) {}

    /**
     * Append a record, inside the caller's transaction.
     *
     * Participating in the caller's transaction is the right default: an
     * action that was rolled back did not happen, and logging that it did
     * would make the log wrong in the one direction that matters.
     *
     * A REFUSAL is the exception, and goes through {@see refusal()} - it must
     * survive the rollback that refusing causes.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $category,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $subjectLabel = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $entityId = null,
    ): string {
        return $this->append(
            DB::connection('tenant'),
            $category,
            $action,
            $subjectType,
            $subjectId,
            $subjectLabel,
            $before,
            $after,
            $reason,
            $entityId,
        );
    }

    /**
     * Append a record on a connection of its own, so it survives a rollback.
     *
     * For a refusal: a segregation-of-duties conflict, a failed step-up, a
     * permission denied. Refusing aborts the transaction, which would take an
     * in-transaction audit record with it - and a refusal nobody can see is
     * indistinguishable from an attempt nobody made. The exceptions report in
     * PF-034 is built entirely from these.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function refusal(
        string $category,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $subjectLabel = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $entityId = null,
    ): string {
        return $this->append(
            $this->outOfBandConnection(),
            $category,
            $action,
            $subjectType,
            $subjectId,
            $subjectLabel,
            $before,
            $after,
            $reason,
            $entityId,
        );
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function append(
        Connection $connection,
        string $category,
        string $action,
        ?string $subjectType,
        ?string $subjectId,
        ?string $subjectLabel,
        ?array $before,
        ?array $after,
        ?string $reason,
        ?string $entityId,
    ): string {
        $actor = $this->actor();
        $request = $this->request();

        $row = [
            'id' => (string) Str::ulid(),
            'entity_id' => $entityId ?? $this->entities->current(),
            'actor_user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'impersonated_by' => $this->impersonator(),
            'category' => $category,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'correlation_id' => $this->correlationId(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : Str::limit((string) $request->userAgent(), 500, ''),
            // From the application clock, not the database's, because the
            // value is hashed and must be known before the INSERT. Clock skew
            // between application servers is a monitoring concern; a hash that
            // cannot be computed until after the write is a design error.
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
        ];

        $write = function () use ($connection, $row): string {
            /*
             | Lock the head, allocate, insert, advance. Nothing else happens
             | between the lock and the release - no HTTP call, no file write,
             | no event dispatch - because every microsecond under this lock is
             | a microsecond no other request can append.
             */
            $head = $connection->selectOne('SELECT last_hash, last_index FROM audit_chain_head WHERE id = true FOR UPDATE');

            $previous = ChainLink::fromStored(
                hash: (string) $head->last_hash,
                previous: ChainLink::GENESIS,
                index: (int) $head->last_index,
            );

            $link = ChainLink::link(
                AuditRecordProjection::of($row),
                (int) $head->last_index === 0 ? null : $previous,
            );

            // The first record's `previous` is the genesis value, which is
            // what the head row is seeded with - so the link is correct
            // whether or not anything has been written yet.
            $connection->table('audit_log')->insert($row + [
                'canonical_hash' => $link->hash,
                'previous_hash' => (int) $head->last_index === 0 ? ChainLink::GENESIS : $previous->hash,
                'chain_index' => (int) $head->last_index + 1,
            ]);

            $connection->table('audit_chain_head')->where('id', true)->update([
                'last_hash' => $link->hash,
                'last_index' => (int) $head->last_index + 1,
                'updated_at' => now(),
            ]);

            return (string) $row['id'];
        };

        // Already inside a transaction? Join it - taking a nested one would
        // create a savepoint and the lock would be released early.
        return $connection->transactionLevel() > 0
            ? $write()
            : $connection->transaction($write);
    }

    /**
     * A second connection to the same tenant database, outside the
     * transaction that is about to be rolled back.
     */
    private function outOfBandConnection(): Connection
    {
        if (! config()->has('database.connections.tenant_audit')) {
            config(['database.connections.tenant_audit' => (array) config('database.connections.tenant')]);
        }

        $connection = DB::connection('tenant_audit');

        if (! $connection instanceof Connection) {
            throw new \RuntimeException('The out-of-band audit connection is not a database connection.');
        }

        return $connection;
    }

    private function actor(): ?User
    {
        $user = auth()->guard('web')->user();

        return $user instanceof User ? $user : null;
    }

    private function impersonator(): ?string
    {
        $operator = session('impersonated_by');

        return is_string($operator) && $operator !== '' ? $operator : null;
    }

    private function correlationId(): ?string
    {
        $request = $this->request();

        if ($request === null) {
            return null;
        }

        $header = $request->header('X-Correlation-Id');

        return is_string($header) && $header !== '' ? Str::limit($header, 64, '') : null;
    }

    private function request(): ?Request
    {
        return app()->bound('request') && app('request') instanceof Request ? app('request') : null;
    }
}
