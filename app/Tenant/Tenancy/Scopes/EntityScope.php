<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Scopes;

use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a financial model to the current entity - PF-026.
 *
 * ## It refuses rather than returning everything
 *
 * The obvious implementation of a scope with no entity set is to skip the
 * constraint. That is precisely wrong here: a query that forgot to establish
 * an entity would quietly return every company's rows, and the consequence -
 * a trial balance combining four legal entities - looks like data rather than
 * an error.
 *
 * So the absence of an entity is an exception. A caller that legitimately
 * needs to cross entities says so, with `withoutEntityScope()`, and that
 * phrase is greppable in review.
 *
 * The database says the same thing a second time and in a different way: the
 * RLS policy compares against a session variable that is NULL when unset, and
 * `entity_id = NULL` is never true. Eloquent refuses loudly; PostgreSQL
 * returns nothing. Neither trusts the other.
 */
final class EntityScope implements Scope
{
    public const WITHOUT = 'withoutEntityScope';

    public function apply(Builder $builder, Model $model): void
    {
        $entityId = app(EntityContext::class)->current();

        if ($entityId === null) {
            throw EntityNotInScope::forQueryOn($model::class);
        }

        $builder->where($model->qualifyColumn('entity_id'), $entityId);
    }
}
