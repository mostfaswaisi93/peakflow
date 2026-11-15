<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Exceptions\TenantNotResolvable;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant and entity for a customer-facing link - PF-089.
 *
 * ## Why they are in the URL at all
 *
 * The visitor is the customer. They have no login here, no session and no
 * cookie - the whole point of a quote link is that it works from an email on
 * a phone - so there is nowhere else for the tenant to come from.
 *
 * The entity is in the URL for a harder reason: every row of a quote is
 * protected by row-level security that compares `entity_id` to a session
 * variable, so a request with no entity established reads nothing at all.
 * Loading the quote to find its entity is circular; the entity has to be
 * known before the first query.
 *
 * Both are inside the signature, so neither can be changed without
 * invalidating the link. A visitor who edits the entity in the URL gets a
 * 403 from Laravel's signature check before this middleware runs.
 *
 * ## Why an unknown tenant is a 404 and not a redirect
 *
 * A redirect to an explanation page confirms which tenants exist to anybody
 * who tries slugs. There is nothing to explain to a visitor holding a link
 * that does not work, and "not found" is the honest answer.
 */
final class ResolveTenantFromPath
{
    public function __construct(
        private readonly TenantConnectionResolver $resolver,
        private readonly EntityContext $entities,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = (string) $request->route('tenant');
        $entity = (string) $request->route('entity');

        if ($tenant === '' || $entity === '') {
            abort(404);
        }

        try {
            $this->resolver->resolve($tenant);
        } catch (TenantNotResolvable) {
            abort(404);
        }

        $resolved = Entity::query()
            ->whereKey($entity)
            ->where('active', true)
            ->first();

        if ($resolved === null) {
            abort(404);
        }

        $this->entities->use($resolved);

        return $next($request);
    }
}
