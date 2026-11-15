<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Exceptions\TenantNotResolvable;
use App\Platform\Services\TenantConnectionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for a browser request, from the session.
 *
 * Runs BEFORE any auth middleware - see the priority pinning in
 * bootstrap/app.php. The `users` table lives in the tenant's own database, so
 * a guard that ran first would query whichever connection happened to be
 * configured.
 *
 * ## A subscription can lapse between page loads
 *
 * An operator suspends a tenant, or a subscription ends, while someone has the
 * application open. The next request must tear the session DOWN and send them
 * somewhere that explains it - not let a stale connection blow up on the next
 * query, and not silently log them out as though they mistyped a password.
 */
final class ResolveTenantFromSession
{
    public function __construct(
        private readonly TenantConnectionResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->session()->get('tenant');

        if (! \is_string($key) || $key === '') {
            // Not an error: an unauthenticated visitor on the login page has
            // no tenant yet. Downstream auth will send them where they belong.
            return $next($request);
        }

        try {
            $this->resolver->resolve($key);
        } catch (TenantNotResolvable $e) {
            $request->session()->forget('tenant');
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                throw $e;
            }

            // A DEDICATED page, not the login form. A user whose
            // subscription lapsed between page loads has done nothing wrong,
            // and sending them to a login form teaches them their password is
            // broken - so they retry it, reset it, and call support about the
            // wrong problem entirely.
            return redirect()
                ->route('workspace.unavailable')
                ->with('tenant_unavailable', $e->getMessage());
        }

        return $next($request);
    }
}
