<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Models\Tenant;
use App\Platform\Services\PlatformCeilings;
use App\Support\Audit\AuditChain;
use App\Support\Authorization\SegregatesDuties;
use App\Tenant\Identity\Services\AuditChainVerifier;
use App\Tenant\Identity\Services\SodEngine;
use App\Tenant\Tenancy\Services\Settings;
use App\Support\Limits\Ceilings;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\PermissionCatalogue;
use App\Tenant\Ledger\Listeners\OpenLedgerForEntity;
use App\Tenant\Tenancy\Events\EntityCreated;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the tenant plane: entity scope, permission resolution, and the one
 * listener that keeps PostgreSQL-side scoping honest across a reconnect.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            EntityContext::class,
            fn (): EntityContext => new EntityContext(EntityContext::variableName()),
        );

        $this->app->scoped(PermissionCatalogue::class);

        /*
         | The enforcers of a ceiling live in the tenant plane; the store
         | lives in the platform plane, which sits ABOVE them in the module
         | graph. Binding the interface here is what lets the dependency point
         | the right way - the SoD engine asks `Ceilings`, and never learns
         | that a platform database exists.
         */
        $this->app->scoped(Ceilings::class, PlatformCeilings::class);

        /*
         | Request-scoped, so a setting read in a loop over five hundred
         | invoice lines is one query - and, more importantly, is the SAME
         | value for every line. A per-read lookup cannot promise that once
         | somebody can change a setting mid-request.
         */
        $this->app->scoped(Settings::class);

        /*
         | Segregation of duties, by its Support contract.
         |
         | The duties belong to other modules - changing a supplier's bank
         | account is Parties, releasing a payment is Settlement - and every
         | one of them has to assert. Binding the interface here is what
         | keeps them from each depending on Identity for it.
         */
        $this->app->bind(SegregatesDuties::class, SodEngine::class);

        /*
         | The audit chain, by its Support contract - PF-054.
         |
         | The ledger's global proof has to include "the record of who did
         | what has not been edited", and the chain belongs to Identity,
         | which Ledger may not depend on. Same shape as the two above: the
         | capability is declared in Support and bound here.
         */
        $this->app->bind(AuditChain::class, AuditChainVerifier::class);
    }

    public function boot(): void
    {
        /*
         | Synchronous and inside the creating transaction, per PF-048: an
         | entity that exists without a chart is one nobody can post to, and
         | a queue catching up later is a window in which the product is
         | broken in a way the customer cannot diagnose.
         */
        Event::listen(EntityCreated::class, OpenLedgerForEntity::class);

        $this->reapplySessionStateOnReconnect();
        $this->resolvePermissionsThroughTheGate();
    }

    /**
     * Re-apply the tenant and entity session variables whenever a connection
     * is established, including a transparent reconnect after a lost one.
     *
     * `SET SESSION` belongs to the connection. Laravel retries a query that
     * failed on a dropped connection by reconnecting and running it again - on
     * a connection where neither variable is set.
     *
     * The failure mode is safe but confusing: row-level security compares
     * against NULL, which is never true, so the query returns nothing rather
     * than another entity's rows. A page that intermittently shows no data is
     * a bad way to find out about this, so it is repaired here instead.
     */
    private function reapplySessionStateOnReconnect(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connectionName !== 'tenant') {
                return;
            }

            $connection = $event->connection;

            if (! $connection instanceof Connection) {
                return;
            }

            $this->reapplyTenant($connection);

            app(EntityContext::class)->applyTo($connection);
        });
    }

    private function reapplyTenant(Connection $connection): void
    {
        $tenant = $this->app->bound('currentTenant') ? $this->app->make('currentTenant') : null;

        if (! $tenant instanceof Tenant) {
            return;
        }

        $connection->statement('SELECT set_config(?, ?, false)', [
            (string) config('tenancy.entity_scope.tenant_session_variable', 'peakflow.tenant_id'),
            (string) $tenant->getKey(),
        ]);
    }

    /**
     * Make `$user->can('invoices.issue')` resolve against the permission
     * catalogue, scoped to the entity the request is acting in - PF-028.
     *
     * `Gate::before` runs ahead of policies. Returning null from it means "not
     * my business, carry on", which is what an ability that is not a
     * permission key gets. A key that IS in the catalogue is answered here,
     * definitively, including a definitive `false`.
     */
    private function resolvePermissionsThroughTheGate(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            $catalogue = app(PermissionCatalogue::class);

            if (! $catalogue->has($ability)) {
                return null;
            }

            $entityId = app(EntityContext::class)->current();

            if ($entityId === null) {
                // No entity established: a permission is meaningless without
                // one, because every permission is held per entity. Refusing
                // is correct and the middleware is what should have prevented
                // reaching here.
                return false;
            }

            return $user->holds($ability, $entityId);
        });
    }
}
