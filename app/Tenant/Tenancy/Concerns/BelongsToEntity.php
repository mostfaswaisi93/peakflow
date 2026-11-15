<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Concerns;

use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Scopes\EntityScope;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied by every model that holds financial data - PF-026.
 *
 * Three things, each of which is a bug someone has shipped before:
 *
 * 1. Reads are scoped to the current entity.
 * 2. `entity_id` is filled on create, so a row cannot be written without one.
 * 3. `entity_id` cannot be changed once set - moving a posted document between
 *    legal entities is not an edit, it is a reversal in one and a fresh
 *    document in the other.
 *
 * The architecture test from PF-005 requires that every model with an
 * `entity_id` column uses this trait AND has an RLS policy on its table, so a
 * new financial model cannot be added without both.
 */
trait BelongsToEntity
{
    public static function bootBelongsToEntity(): void
    {
        static::addGlobalScope(new EntityScope());

        static::creating(function (self $model): void {
            if ($model->getAttribute('entity_id') !== null) {
                return;
            }

            $entityId = app(EntityContext::class)->current()
                ?? throw EntityNotInScope::forQueryOn(static::class);

            $model->setAttribute('entity_id', $entityId);
        });

        static::updating(function (self $model): void {
            if (! $model->isDirty('entity_id')) {
                return;
            }

            throw new \DomainException(sprintf(
                'Refused to move a %s from entity %s to %s. A document belongs to the legal entity that '.
                'issued it; moving it is a reversal in one entity and a new document in the other, never a '.
                'change of column.',
                class_basename(static::class),
                (string) $model->getOriginal('entity_id'),
                (string) $model->getAttribute('entity_id'),
            ));
        });
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * Query across entities, deliberately and visibly.
     *
     * For group consolidation, an operator tool, or a migration. It removes
     * the Eloquent scope only - the row-level security policy is still in
     * force, so this alone does not reach another entity's rows. Crossing the
     * boundary for real also requires a connection that the policy exempts,
     * which the application role is not.
     *
     * @return Builder<static>
     */
    public static function withoutEntityScope(): Builder
    {
        return static::query()->withoutGlobalScope(EntityScope::class);
    }
}
