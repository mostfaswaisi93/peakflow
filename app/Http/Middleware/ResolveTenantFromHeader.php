<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Exceptions\TenantNotResolvable;
use App\Platform\Services\TenantConnectionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for an API request, from `X-Tenant`.
 *
 * Runs BEFORE `auth:sanctum`, for the same reason as its session counterpart:
 * a Sanctum token for a tenant user is validated against that tenant's own
 * database, so the connection has to be pointed first.
 *
 * Unlike the session variant, a missing header here is a hard 400. An API
 * client that does not say which workspace it means has made a mistake, and
 * guessing - "they only have one tenant, so use that" - is how an integration
 * silently writes into the wrong company after the customer adds a second one.
 */
final class ResolveTenantFromHeader
{
    public const HEADER = 'X-Tenant';

    public function __construct(
        private readonly TenantConnectionResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! \is_string($key) || trim($key) === '') {
            throw TenantNotResolvable::missing();
        }

        $this->resolver->resolve(trim($key));

        return $next($request);
    }
}
