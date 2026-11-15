<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Services;

use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Which entity the current request is acting in - PF-026.
 *
 * This is one of the two independent mechanisms that scope data to an entity.
 * It drives the PostgreSQL side: a session variable that every row-level
 * security policy reads. The other is the Eloquent global scope in
 * {@see \App\Tenant\Tenancy\Scopes\EntityScope}.
 *
 * Two mechanisms because one of them will eventually have a hole. A raw query,
 * a console command, a `DB::table()` call written in a hurry - each bypasses
 * Eloquent entirely, and none of them bypasses the database.
 *
 * ## Why the session variable is re-applied on reconnect
 *
 * `SET SESSION` lives on the connection, not the request. If the connection
 * drops mid-request and Laravel transparently reconnects, the variable is gone
 * from the new one.
 *
 * The failure is safe - `entity_id = NULL` is NULL, never true, so every
 * policy returns nothing and the request errors rather than leaking another
 * entity's rows. Safe, but still a bug, and an intermittent one that would
 * present as "the page sometimes shows no data". So a listener on
 * `ConnectionEstablished` re-applies it, and this class is the one place that
 * knows how.
 */
final class EntityContext
{
    private ?string $entityId = null;

    public function __construct(private readonly string $sessionVariable) {}

    public static function variableName(): string
    {
        return (string) config('tenancy.entity_scope.session_variable', 'peakflow.entity_id');
    }

    /**
     * Act in this entity for the rest of the request.
     *
     * Authorization is NOT checked here. Deciding whether a user may act in an
     * entity is the middleware's job (PF-026); this class only carries the
     * decision to the database. Keeping them apart means a console command can
     * set the scope without inventing a user to authorize.
     */
    public function use(Entity|string $entity): void
    {
        $this->entityId = $entity instanceof Entity ? (string) $entity->getKey() : $entity;

        app()->instance('currentEntity', $entity instanceof Entity ? $entity : null);

        $this->applyTo(DB::connection('tenant'));
    }

    public function current(): ?string
    {
        return $this->entityId;
    }

    public function entity(): ?Entity
    {
        if ($this->entityId === null) {
            return null;
        }

        $bound = app()->bound('currentEntity') ? app('currentEntity') : null;

        if ($bound instanceof Entity && (string) $bound->getKey() === $this->entityId) {
            return $bound;
        }

        $entity = Entity::query()->find($this->entityId);

        app()->instance('currentEntity', $entity);

        return $entity;
    }

    /**
     * Stop acting in any entity.
     *
     * Sets the variable to the empty string rather than issuing `RESET`,
     * because the SQL function treats empty as unset and a pooled connection
     * must not carry the last request's entity into the next one.
     */
    public function forget(): void
    {
        $this->entityId = null;

        app()->forgetInstance('currentEntity');

        $this->applyTo(DB::connection('tenant'));
    }

    /**
     * Write the current entity onto a connection.
     *
     * `set_config` is used rather than `SET SESSION` because the variable name
     * cannot be a bound parameter in a `SET` statement, and interpolating one
     * is how a configuration key becomes an injection point. `set_config`
     * takes both as ordinary arguments.
     */
    public function applyTo(Connection $connection): void
    {
        $connection->statement('SELECT set_config(?, ?, false)', [
            $this->sessionVariable,
            $this->entityId ?? '',
        ]);
    }

    /** Run a callback in another entity, then restore. For jobs that span entities. */
    public function during(Entity|string $entity, callable $callback): mixed
    {
        $previous = $this->entityId;

        $this->use($entity);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->forget() : $this->use($previous);
        }
    }
}
