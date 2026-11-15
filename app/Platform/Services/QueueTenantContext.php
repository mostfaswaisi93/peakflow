<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * The platform-plane implementation of {@see TenantContext} - PF-048.
 *
 * Thin by design: every decision about what a tenant is, whether it is
 * servable and how its connection is shaped already lives in
 * {@see TenantConnectionResolver}, and duplicating any of it here would give
 * queued work a second, more permissive definition of "servable".
 *
 * ## A suspended tenant's queued work fails, and that is correct
 *
 * `resolve()` refuses a tenant that is suspended or migrating. A job for such
 * a tenant therefore throws and goes to the failed table, where somebody can
 * see it - rather than succeeding against a database that is mid-migration,
 * or being silently dropped.
 */
final class QueueTenantContext implements TenantContext
{
    public function __construct(
        private readonly TenantConnectionResolver $resolver,
    ) {}

    public function currentKey(): ?string
    {
        $tenant = $this->resolver->current();

        /*
         | The slug, not the id.
         |
         | Both resolve, and the slug is the one a human reads on a failed
         | job. A tenant's slug cannot change - it is the database name's
         | source - so it is as stable an identifier as the id is.
         */
        return $tenant === null ? null : (string) $tenant->slug;
    }

    public function within(string $tenantKey, Closure $work): mixed
    {
        $previous = $this->resolver->current();

        $this->resolver->resolve($tenantKey);

        try {
            return $work();
        } finally {
            /*
             | `point()` rather than `resolve()` on the way back.
             |
             | The previous tenant was servable when this started; re-running
             | the servability checks would mean a tenant suspended during a
             | long job turns the restore into an exception thrown from a
             | finally block, which replaces whatever the job was really
             | failing for.
             */
            $previous === null
                ? $this->resolver->forget()
                : $this->resolver->point($previous);
        }
    }

    public function forget(): void
    {
        $this->resolver->forget();
    }
}
