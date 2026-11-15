<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;

/**
 * Establishing which tenant's database the current work belongs to - PF-048.
 *
 * ## Why a Support contract rather than a direct call
 *
 * Pointing the `tenant` connection is a platform-plane job: it reads the
 * tenant registry, rewrites a connection and sets the row-level-security
 * session variables. The modules that NEED it - a queued listener in `Ledger`,
 * a webhook dispatcher in `Exchange` - sit below the platform plane in the
 * module graph and may not call into it.
 *
 * So the capability is declared here and implemented there, the same way
 * {@see \App\Support\Audit\AuditTrail} and
 * {@see \App\Support\Limits\Ceilings} are. A listener asks for a tenant and
 * never learns that a platform database exists.
 *
 * ## The failure this exists to prevent
 *
 * A queue worker is long-lived and handles jobs for many tenants in
 * succession. Laravel caches the opened PDO handle per connection NAME, and
 * the name is `tenant` for every one of them - so a job that does not
 * re-point the connection reads whichever tenant's database the PREVIOUS job
 * left behind.
 *
 * That is a cross-tenant read, it returns plausible data, and nothing in it
 * looks like an error. It is the single worst bug this architecture can have,
 * which is why the tenant is carried on the job payload and restored before
 * the job runs rather than left to each job to remember.
 */
interface TenantContext
{
    /**
     * The tenant the current work belongs to, or null outside one.
     *
     * A key - slug or id - rather than a model, because this is what gets
     * written onto a queue payload and read back by a worker that may be
     * running the next release.
     */
    public function currentKey(): ?string;

    /**
     * Run a closure with a given tenant established, restoring what was
     * there before.
     *
     * Restoring matters even in a worker: a job that establishes tenant A
     * and throws must not leave A pointed at for the next job, and in a
     * request a tenant established for a background task must not move the
     * user.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function within(string $tenantKey, Closure $work): mixed;

    /**
     * Forget the tenant entirely, closing the connection behind it.
     *
     * Called between jobs. Leaving a resolved tenant bound is exactly how a
     * job for tenant A reads tenant B's data.
     */
    public function forget(): void;
}
