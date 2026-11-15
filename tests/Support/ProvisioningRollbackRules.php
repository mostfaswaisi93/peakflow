<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\DatabaseConnection;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantProvisioner;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;

/**
 * Inject a failure at each provisioning step and prove the rollback leaves
 * nothing behind - PF-019.
 *
 * ## Why this is a separate suite
 *
 * It provisions five times, each attempt creating and dropping a real
 * database and role. That is slow and it writes to the cluster, so it is not
 * part of `bin/verify` - a check people avoid running is a check that stops
 * being run. `bin/verify rollback` runs it, and so should CI.
 *
 * ## The injection seam
 *
 * `Connection::beforeExecuting`, attached to every connection as it is
 * ESTABLISHED rather than to the ones that exist when the probe starts. The
 * provisioner creates `tenant_bootstrap`, `tenant_migrator` and `tenant` as
 * it goes and purges them, so a callback registered up front survives only
 * the first two steps.
 *
 * That is not hypothetical: the first version registered up front, the last
 * three injections never fired, provisioning SUCCEEDED, and the run left
 * three real tenants in the cluster while reporting that no failure had been
 * injected. A probe that cannot fail the thing it is probing reports the
 * absence of its own effect as success.
 *
 * Nothing test-only is added to the provisioner. A `failAfter` property in
 * production code is a property that eventually fires in production.
 */
final class ProvisioningRollbackRules
{
    /**
     * Each step, and a pattern matching the statement that performs it.
     *
     * @var array<string, string>
     */
    private const STEPS = [
        'CREATE ROLE' => '/^\s*create\s+role/i',
        'CREATE DATABASE' => '/^\s*create\s+database/i',
        'granting the schema' => '/alter\s+schema\s+public\s+owner/i',
        'the tenant migrations' => '/create\s+table\s+"?users"?/i',
        'the baseline seed' => '/insert\s+into\s+"?permissions"?/i',
    ];

    private const CONNECTIONS = ['migrator', 'tenant_bootstrap', 'tenant_migrator', 'tenant'];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $results = [];

        foreach (self::STEPS as $label => $pattern) {
            $results['rollback is clean when provisioning fails at '.$label] = $this->failAt($label, $pattern);
        }

        return $results;
    }

    /** @return list<string> */
    private function failAt(string $label, string $pattern): array
    {
        $before = $this->cluster();

        $attach = static function (ConnectionEstablished $event) use ($pattern, $label): void {
            $event->connection->beforeExecuting(static function (string $query) use ($pattern, $label): void {
                if (preg_match($pattern, $query) === 1) {
                    throw new RuntimeException('injected failure at: '.$label);
                }
            });
        };

        Event::listen($attach);
        $this->purge();

        $threw = null;

        try {
            app(TenantProvisioner::class)->create(
                name: 'Rollback Probe',
                slug: 'rb-'.bin2hex(random_bytes(5)),
                planKey: 'starter',
            );
        } catch (Throwable $e) {
            $threw = $e;
        } finally {
            Event::forget(ConnectionEstablished::class);
            $this->purge();
        }

        $violations = [];

        if ($threw === null) {
            return [sprintf(
                'provisioning SUCCEEDED although a failure was injected at %s - the probe did not reach the '.
                'statement it meant to fail, so this step is not actually being tested',
                $label,
            )];
        }

        $after = $this->cluster();

        foreach ([
            'databases' => 'an orphan database was left behind',
            'roles' => 'an orphan role was left behind',
            'tenants' => 'a half-registered tenant row was left behind',
            'connections' => 'a dangling connection row was left behind',
        ] as $key => $message) {
            if ($after[$key] !== $before[$key]) {
                $violations[] = sprintf('%s (%d -> %d)', $message, $before[$key], $after[$key]);
            }
        }

        return $violations;
    }

    /** @return array{databases: int, roles: int, tenants: int, connections: int} */
    private function cluster(): array
    {
        $row = DB::connection('migrator')->selectOne(
            "SELECT (SELECT count(*) FROM pg_database WHERE datname LIKE 'peakflow\\_t\\_%') AS dbs,
                    (SELECT count(*) FROM pg_roles   WHERE rolname LIKE 'pf\\_t\\_%')        AS roles"
        );

        return [
            'databases' => (int) $row->dbs,
            'roles' => (int) $row->roles,
            'tenants' => Tenant::withTrashed()->count(),
            'connections' => DatabaseConnection::count(),
        ];
    }

    private function purge(): void
    {
        foreach (self::CONNECTIONS as $name) {
            try {
                DB::purge($name);
            } catch (Throwable) {
                // Not configured yet, which is fine - the provisioner creates
                // it when it gets there.
            }
        }
    }
}
