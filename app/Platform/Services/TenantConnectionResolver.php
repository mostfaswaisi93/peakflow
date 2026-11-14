<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\Exceptions\TenantNotResolvable;
use App\Platform\Models\Tenant;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;

/**
 * Points Laravel's `tenant` connection at a specific tenant's database - PF-021.
 *
 * There is ONE `tenant` connection declared in config/database.php, and its
 * settings are rewritten here, per request. Every model under `app/Tenant`
 * hard-codes `getConnectionName(): 'tenant'`, which is asserted by an
 * architecture test, so a tenant query automatically follows whichever
 * database was most recently selected and can never read platform data even if
 * a developer forgets a scope.
 *
 * ## The order of the checks is the order of the remedies
 *
 * Look up, then refuse an unservable tenant, then rewrite. Rewriting first and
 * checking afterwards would leave a usable connection to a suspended tenant's
 * database sitting in the container for the rest of the request.
 */
final class TenantConnectionResolver
{
    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * Resolve by slug or id, point the connection, and bind it.
     *
     * @throws TenantNotResolvable
     */
    public function resolve(string $key): Tenant
    {
        $tenant = Tenant::query()
            ->with(['connection'])
            ->where('slug', $key)
            ->orWhere('id', $key)
            ->first();

        if ($tenant === null) {
            throw TenantNotResolvable::unknown();
        }

        // A maintenance window is a DOCUMENTED STATE, not an error. Answering
        // 503 with Retry-After is what keeps a migration from presenting as
        // random failures on a missing column.
        if ($tenant->isMigrating()) {
            throw TenantNotResolvable::migrating(
                (int) config('tenancy.migrating_retry_after_seconds', 30),
            );
        }

        if (! $tenant->isServable()) {
            throw TenantNotResolvable::inactive($this->explainUnservable($tenant));
        }

        $this->point($tenant);

        return $tenant;
    }

    /**
     * Rewrite the connection and bind the tenant into the container.
     *
     * `DB::purge` is not optional: without it Laravel keeps the already-opened
     * PDO handle for the `tenant` name and the next query goes to the PREVIOUS
     * tenant's database. On a long-lived worker - Octane, a queue process -
     * that is a cross-tenant read, and it is silent.
     */
    public function point(Tenant $tenant): void
    {
        $connection = $tenant->connection;

        if ($connection === null) {
            throw TenantNotResolvable::unknown();
        }

        config(['database.connections.tenant' => $connection->toConfig()]);

        DB::purge('tenant');

        $this->app->instance('currentTenant', $tenant);

        // Row-level security inside the tenant database reads these. Set on
        // every resolve rather than once per connection, because a pooled
        // connection outlives the request that set it.
        $this->applySessionVariables($tenant);
    }

    /**
     * Forget the current tenant entirely.
     *
     * Used between queued jobs and between Octane requests. Leaving a resolved
     * tenant bound is how a job for tenant A reads tenant B's data.
     */
    public function forget(): void
    {
        DB::purge('tenant');

        config(['database.connections.tenant.database' => null]);
        config(['database.connections.tenant.username' => null]);
        config(['database.connections.tenant.password' => null]);

        $this->app->forgetInstance('currentTenant');
        $this->app->forgetInstance('currentEntity');
    }

    public function current(): ?Tenant
    {
        return $this->app->bound('currentTenant')
            ? $this->app->make('currentTenant')
            : null;
    }

    private function applySessionVariables(Tenant $tenant): void
    {
        $variable = (string) config('tenancy.entity_scope.tenant_session_variable', 'peakflow.tenant_id');

        // A literal is used rather than a bound parameter because SET LOCAL
        // does not accept one. The value is a ULID from our own database and
        // is validated by the cast, but it is quoted defensively regardless.
        DB::connection('tenant')->statement(sprintf(
            'SET SESSION %s = %s',
            $variable,
            DB::connection('tenant')->getPdo()->quote((string) $tenant->getKey()),
        ));
    }

    private function explainUnservable(Tenant $tenant): string
    {
        $subscription = $tenant->currentSubscription();

        if ($subscription === null) {
            return 'This workspace has no active subscription.';
        }

        if ($subscription->hasExpired()) {
            return 'This workspace\'s subscription ended on '.$subscription->ends_on?->toDateString().'.';
        }

        return 'This workspace is currently '.$tenant->status->value.'.';
    }
}
