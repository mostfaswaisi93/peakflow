<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\DatabaseConnection;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Platform\Services\TenantProvisioner;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * What each database role can actually reach - PF-010.
 *
 * ## The criterion as written, and why it is reframed
 *
 * PF-010 asks that `peakflow_app` "cannot connect to the platform database at
 * all". Taken literally that is impossible in this architecture: a request
 * resolves its tenant by reading the platform database, so the role the
 * application connects as has to be able to connect to it. A role that cannot
 * is a role that cannot serve a request.
 *
 * What the criterion is reaching for is the separation, and the separation runs
 * the other way round - which is both achievable and stronger:
 *
 * - the PLATFORM credential reaches the tenant registry and **no tenant's
 *   data**, and
 * - a TENANT credential reaches one customer's database and **not the
 *   registry**.
 *
 * So that is what is asserted, in both directions, with real connection
 * attempts rather than with a reading of the grant script.
 *
 * ## What was found when it was first measured
 *
 * Both directions were open.
 *
 * A PostgreSQL database with an empty ACL is not one nobody may connect to: an
 * empty ACL means the built-in default applies, and the built-in default grants
 * CONNECT and TEMPORARY to PUBLIC. `GRANT CONNECT TO <role>` does not change
 * that, because there was never anything to revoke - so every login role on the
 * cluster could open a session in every database.
 *
 * The platform role opened a session in a tenant's database and read 129 table
 * names out of `pg_class` with their row estimates: `accounts (~336 rows)`,
 * `account_roles (~120 rows)`. The schema privileges held, so no business row
 * came back. That is not much comfort - how many accounts a customer has, which
 * modules they use and whether they have posted anything are facts about that
 * customer - and `pg_class` is world-readable and cannot be revoked, so the
 * boundary has to be the connection itself.
 *
 * ## Asked of the server, and then proved against it
 *
 * The matrix is built from `has_database_privilege()`, because parsing `datacl`
 * would read an empty ACL as "nobody" - which is exactly the misreading that
 * produced the hole. And because a check that only interrogates the catalog is
 * asserting that the catalog agrees with itself, the two crossings that matter
 * are also attempted for real, with a live PDO connection.
 */
final class DatabaseRoleRules
{
    /** @var list<string> */
    private array $cleanup = [];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        try {
            return [
                'the three roles exist, and only the migrator may create things' => $this->rolesHaveTheRightPowers(),
                'PUBLIC can connect to nothing' => $this->publicCanConnectToNothing(),
                'the connect matrix is one role, one database' => $this->theConnectMatrixIsRight(),
                'the server behaves the way the catalog says' => $this->theServerAgreesWithTheCatalog(),
                'the application role has no DDL at all' => $this->theApplicationRoleHasNoDdl(),
                'migrations run as the migrator, from its own credentials' => $this->migrationsRunAsTheMigrator(),
                'a newly provisioned database is sealed at creation' => $this->provisioningSealsWhatItCreates(),
            ];
        } finally {
            $this->tearDown();
        }
    }

    private function migrator(): Connection
    {
        return DB::connection('migrator');
    }

    /** @return list<string> */
    private function rolesHaveTheRightPowers(): array
    {
        $problems = [];

        $expected = [
            'peakflow_migrator' => ['createdb' => true, 'createrole' => true],
            'peakflow_app' => ['createdb' => false, 'createrole' => false],
            'peakflow_readonly' => ['createdb' => false, 'createrole' => false],
        ];

        foreach ($expected as $role => $powers) {
            $row = $this->migrator()->selectOne(
                'SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = ?',
                [$role],
            );

            if ($row === null) {
                $problems[] = sprintf('The role %s does not exist.', $role);

                continue;
            }

            if (! (bool) $row->rolcanlogin) {
                $problems[] = sprintf('%s cannot log in, so nothing connects as it.', $role);
            }

            if ((bool) $row->rolsuper) {
                $problems[] = sprintf(
                    '%s is a superuser, which makes every other grant in this file decorative - row-level '.
                    'security does not apply to a superuser and neither does anything else.',
                    $role,
                );
            }

            foreach ($powers as $power => $allowed) {
                $actual = (bool) $row->{'rol'.$power};

                if ($actual !== $allowed) {
                    $problems[] = sprintf(
                        '%s has %s = %s and should have %s.',
                        $role,
                        $power,
                        $actual ? 'true' : 'false',
                        $allowed ? 'true' : 'false',
                    );
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function publicCanConnectToNothing(): array
    {
        $problems = [];

        foreach ($this->ourDatabases() as $database) {
            /*
             | `public` as a role name, which is how PostgreSQL spells it.
             |
             | This is the whole finding in one assertion: a database created
             | without an explicit REVOKE answers `true` here, because the
             | built-in default grants CONNECT to PUBLIC - and nothing in the
             | grant script looked wrong.
             */
            $answer = $this->migrator()->selectOne(
                'SELECT has_database_privilege(?, ?, ?) AS yes',
                ['public', $database, 'CONNECT'],
            );

            if ($answer !== null && (bool) $answer->yes) {
                $problems[] = sprintf(
                    'PUBLIC can CONNECT to %s, so every login role on the cluster can open a session in '.
                    'it and read its table names and row estimates out of pg_class. Run '.
                    '`php artisan databases:seal`.',
                    $database,
                );
            }

            $answer = $this->migrator()->selectOne(
                'SELECT has_database_privilege(?, ?, ?) AS yes',
                ['public', $database, 'TEMPORARY'],
            );

            if ($answer !== null && (bool) $answer->yes) {
                $problems[] = sprintf(
                    'PUBLIC can create temporary tables in %s. A role with no business in a database but '.
                    'able to write temporary tables in it can fill that database\'s disk.',
                    $database,
                );
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function theConnectMatrixIsRight(): array
    {
        $problems = [];
        $migratorRole = (string) config('database.connections.migrator.username');
        $appRole = (string) config('database.connections.platform.username');
        $platformDatabase = (string) config('database.connections.platform.database');

        $tenantRoles = [];

        foreach (DatabaseConnection::all() as $connection) {
            $tenantRoles[$connection->database] = $connection->username;
        }

        foreach ($this->loginRoles() as $role) {
            foreach ($this->ourDatabases() as $database) {
                $allowed = $this->mayConnect($role, $database);

                $should = match (true) {
                    $role === $migratorRole => true,
                    $database === $platformDatabase => \in_array($role, [$appRole, 'peakflow_readonly'], true),
                    isset($tenantRoles[$database]) => $role === $tenantRoles[$database],
                    // A test database: the application role is allowed, nobody else.
                    default => $role === $appRole,
                };

                if ($allowed === $should) {
                    continue;
                }

                $problems[] = $allowed
                    ? sprintf(
                        '%s can connect to %s and should not. %s',
                        $role,
                        $database,
                        $database === $platformDatabase
                            ? 'A tenant credential that reaches the registry can enumerate every other '.
                              'customer on the cluster.'
                            : 'A credential that reaches a database it has no business in can read that '.
                              'database\'s table names and row estimates out of pg_class, whatever the '.
                              'schema grants say.',
                    )
                    : sprintf(
                        '%s cannot connect to %s and must be able to. Over-revoking is an outage: this is '.
                        'the role that serves %s.',
                        $role,
                        $database,
                        $database === $platformDatabase ? 'tenant resolution' : 'that customer',
                    );
            }
        }

        return $problems;
    }

    /**
     * The catalog's answer, checked against the server's behaviour.
     *
     * A rule built only from `has_database_privilege()` asserts that the
     * catalog agrees with itself. These four attempts are the same questions
     * asked of the connection handshake, which is what actually decides.
     *
     * @return list<string>
     */
    private function theServerAgreesWithTheCatalog(): array
    {
        $problems = [];

        $appRole = (string) config('database.connections.platform.username');
        $appPassword = (string) config('database.connections.platform.password');
        $platformDatabase = (string) config('database.connections.platform.database');

        /*
         | EVERY tenant, in a deterministic order.
         |
         | This rule used `DatabaseConnection::first()` - unordered, so one
         | arbitrary tenant - and a probe that widened a grant on a different
         | one left it passing while the matrix rule failed. One tenant is a
         | sample; a boundary that holds for one customer and not the next is
         | the failure this is for.
         */
        $tenants = DatabaseConnection::query()->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            return ['There is no provisioned tenant to test the boundary against, so this rule asserts '.
                'nothing. Run the development seeder.'];
        }

        $attempts = [
            [$appRole, $appPassword, $platformDatabase, true,
                'the platform role must reach the registry, or no request can resolve its tenant'],
        ];

        foreach ($tenants as $tenant) {
            $attempts[] = [$appRole, $appPassword, $tenant->database, false,
                'a leaked platform credential must not open a session in a customer\'s database - from '.
                'inside one it reads 129 table names and their row counts out of pg_class, measured'];
            $attempts[] = [$tenant->username, $tenant->password(), $tenant->database, true,
                'a tenant role must reach its own database'];
            $attempts[] = [$tenant->username, $tenant->password(), $platformDatabase, false,
                'a leaked tenant credential must not reach the registry, where every other customer is '.
                'listed'];

            foreach ($tenants as $other) {
                if ($other->is($tenant)) {
                    continue;
                }

                $attempts[] = [$tenant->username, $tenant->password(), $other->database, false,
                    'one customer\'s credential must not reach another customer\'s database - this is the '.
                    'boundary the whole database-per-tenant design exists to draw'];
            }
        }

        foreach ($attempts as [$role, $password, $database, $shouldConnect, $why]) {
            $connected = $this->canReallyConnect($role, $password, $database);

            if ($connected === $shouldConnect) {
                continue;
            }

            $problems[] = sprintf(
                '%s %s connect to %s, and %s: %s',
                $role,
                $connected ? 'can' : 'cannot',
                $database,
                $shouldConnect ? 'must' : 'must not',
                $why,
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private function theApplicationRoleHasNoDdl(): array
    {
        $problems = [];

        $forbidden = [
            'CREATE TABLE' => 'CREATE TABLE peakflow_probe_ddl (id int)',
            'ALTER TABLE' => 'ALTER TABLE tenants ADD COLUMN peakflow_probe int',
            'DROP TABLE' => 'DROP TABLE plans',
            'CREATE SCHEMA' => 'CREATE SCHEMA peakflow_probe_schema',
            'TRUNCATE' => 'TRUNCATE tenants',
        ];

        $connection = DB::connection('platform');

        foreach ($forbidden as $operation => $statement) {
            try {
                $connection->statement($statement);

                $problems[] = sprintf(
                    '%s succeeded as %s. The grant is what makes "the application cannot change the '.
                    'schema" a fact rather than an intention - an ORM cannot protect an invariant that a '.
                    'console command or somebody\'s REPL session can reach around.',
                    $operation,
                    (string) config('database.connections.platform.username'),
                );

                // Undo it, as the migrator, before anything else runs.
                $this->undo($operation);
            } catch (Throwable) {
                // Refused, as it must be.
            }
        }

        return $problems;
    }

    private function undo(string $operation): void
    {
        try {
            match ($operation) {
                'CREATE TABLE' => $this->migrator()->statement('DROP TABLE IF EXISTS peakflow_probe_ddl'),
                'ALTER TABLE' => $this->migrator()->statement('ALTER TABLE tenants DROP COLUMN IF EXISTS peakflow_probe'),
                'CREATE SCHEMA' => $this->migrator()->statement('DROP SCHEMA IF EXISTS peakflow_probe_schema'),
                default => null,
            };
        } catch (Throwable) {
            // Nothing to undo.
        }
    }

    /** @return list<string> */
    private function migrationsRunAsTheMigrator(): array
    {
        $problems = [];

        $appRole = (string) config('database.connections.platform.username');
        $migratorRole = (string) config('database.connections.migrator.username');

        if ($appRole === $migratorRole) {
            $problems[] = sprintf(
                'The platform and migrator connections both use %s, so "migrations run as the migrator" '.
                'is true and meaningless - the application has whatever the migrator has.',
                $appRole,
            );
        }

        /*
         | Separate credentials, not just a separate connection name.
         |
         | A `migrator` connection reading DB_USERNAME would be the same
         | credential under a second name, which is what makes this worth
         | asserting rather than assuming from the config file's shape.
         */
        $example = (string) file_get_contents(\dirname(__DIR__, 2).'/.env.example');

        foreach (['DB_MIGRATOR_USERNAME', 'DB_MIGRATOR_PASSWORD'] as $key) {
            if (! str_contains($example, $key)) {
                $problems[] = sprintf(
                    '.env.example does not mention %s, so a fresh deployment has no way to know the '.
                    'migrator needs its own credentials and will fall back to the application\'s.',
                    $key,
                );
            }
        }

        /*
         | Then the behavioural half, which is the one that matters.
         |
         | The first version of this rule grepped TenantsMigrate.php for the
         | word "migrator" and failed - correctly, in the sense that the word
         | is not there, and uselessly, because the command delegates to
         | TenantProvisioner::migrate() which calls `migrate --database
         | tenant_migrator`. A rule that reads files cannot tell delegation
         | from absence. So DDL is attempted on both connections instead.
         */
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->first();

        if ($tenant === null) {
            $problems[] = 'There is no provisioned tenant, so the DDL boundary could not be tested.';

            return $problems;
        }

        try {
            app(TenantConnectionResolver::class)->point($tenant);
        } catch (Throwable $e) {
            $problems[] = sprintf('Could not point at a tenant: %s', $e->getMessage());

            return $problems;
        }

        $tenantUser = (string) config('database.connections.tenant.username');
        $tenantMigratorUser = (string) config('database.connections.migrator.username');

        /*
         | The probe connection is built the way the provisioner builds its
         | own: the tenant's connection with the migrator's credentials.
         |
         | `tenant_migrator` is created at runtime inside
         | TenantProvisioner::withMigratorConnection() and does not exist
         | outside it - the first version of this rule asked for it by name
         | and got "Database connection [tenant_migrator] not configured",
         | which reads as a missing config entry rather than as a test asking
         | the wrong question.
         */
        $probe = 'probe_tenant_migrator';

        config(['database.connections.'.$probe => array_merge(
            (array) config('database.connections.tenant'),
            [
                'username' => $tenantMigratorUser,
                'password' => (string) config('database.connections.migrator.password'),
            ],
        )]);

        DB::purge($probe);

        if ($tenantUser === $tenantMigratorUser) {
            $problems[] = sprintf(
                'The tenant and tenant_migrator connections both use %s, so running migrations "as the '.
                'migrator" gives the application whatever the migrator has.',
                $tenantUser,
            );
        }

        try {
            DB::connection('tenant')->statement('CREATE TABLE peakflow_probe_tenant_ddl (id int)');

            $problems[] = sprintf(
                'The tenant connection (%s) created a table. That role serves every request, so DDL on '.
                'it means a bug in a controller can drop a ledger.',
                $tenantUser,
            );

            DB::connection('tenant_migrator')->statement('DROP TABLE IF EXISTS peakflow_probe_tenant_ddl');
        } catch (Throwable) {
            // Refused, as it must be.
        }

        try {
            DB::connection($probe)->statement('CREATE TABLE peakflow_probe_tenant_ddl (id int)');
            DB::connection($probe)->statement('DROP TABLE peakflow_probe_tenant_ddl');
        } catch (Throwable $e) {
            $problems[] = sprintf(
                'The migrator credential (%s) could not create a table in the tenant database: %s. '.
                'Over-revoking here is not caution, it is a tenant that can never be migrated again.',
                $tenantMigratorUser,
                $e->getMessage(),
            );
        }

        return $problems;
    }

    /**
     * Provision a real database and check it comes out sealed.
     *
     * Only `create()` runs - no migrations - because what is under test is the
     * CREATE DATABASE path, and running 30 migrations to assert a grant would
     * make this the slowest rule in the suite for no extra coverage.
     *
     * @return list<string>
     */
    private function provisioningSealsWhatItCreates(): array
    {
        $problems = [];
        $slug = 'probe-'.Str::lower(Str::random(10));

        try {
            $tenant = app(TenantProvisioner::class)->create('Seal probe '.$slug, 'starter', $slug);
        } catch (Throwable $e) {
            return [sprintf('Could not provision a probe tenant: %s', $e->getMessage())];
        }

        $connection = $tenant->connection;

        if ($connection === null) {
            $this->cleanup[] = $slug;

            return ['The probe tenant was provisioned without a database connection record.'];
        }

        $this->cleanup[] = $slug;

        $database = $connection->database;

        $answer = $this->migrator()->selectOne(
            'SELECT has_database_privilege(?, ?, ?) AS yes',
            ['public', $database, 'CONNECT'],
        );

        if ($answer !== null && (bool) $answer->yes) {
            $problems[] = sprintf(
                'The provisioner created %s with PUBLIC still able to CONNECT. Sealing it afterwards with '.
                'a command is a window, not a fix: every role on the cluster can reach a new customer\'s '.
                'database between provisioning and the next maintenance run.',
                $database,
            );
        }

        if (! $this->mayConnect($connection->username, $database)) {
            $problems[] = sprintf(
                'The provisioner created %s and its own role %s cannot connect to it, which is an outage '.
                'for that customer rather than a tightening.',
                $database,
                $connection->username,
            );
        }

        $appRole = (string) config('database.connections.platform.username');

        if ($this->mayConnect($appRole, $database)) {
            $problems[] = sprintf(
                'The platform role can connect to the newly provisioned %s.',
                $database,
            );
        }

        return $problems;
    }

    private function mayConnect(string $role, string $database): bool
    {
        $answer = $this->migrator()->selectOne(
            'SELECT has_database_privilege(?, ?, ?) AS yes',
            [$role, $database, 'CONNECT'],
        );

        return $answer !== null && (bool) $answer->yes;
    }

    private function canReallyConnect(string $role, string $password, string $database): bool
    {
        try {
            new PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    (string) config('database.connections.platform.host'),
                    (string) config('database.connections.platform.port'),
                    $database,
                ),
                $role,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The databases this cluster holds for PeakFlow.
     *
     * Derived from the catalog rather than from a list, so a database somebody
     * created by hand is included - which is the one most likely to be
     * unsealed.
     *
     * @return list<string>
     */
    private function ourDatabases(): array
    {
        $platformDatabase = (string) config('database.connections.platform.database');
        $names = [$platformDatabase];

        foreach (DatabaseConnection::all() as $connection) {
            $names[] = $connection->database;
        }

        foreach ($this->migrator()->select(
            "SELECT datname FROM pg_database WHERE datname LIKE 'peakflow%' ORDER BY datname",
        ) as $row) {
            $names[] = (string) $row->datname;
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private function loginRoles(): array
    {
        $roles = [];

        foreach ($this->migrator()->select(
            'SELECT rolname FROM pg_roles WHERE rolcanlogin AND NOT rolsuper ORDER BY rolname',
        ) as $row) {
            $roles[] = (string) $row->rolname;
        }

        return $roles;
    }

    private function tearDown(): void
    {
        foreach ($this->cleanup as $slug) {
            try {
                $tenant = Tenant::withTrashed()->where('slug', $slug)->first();

                if ($tenant === null) {
                    continue;
                }

                $connection = $tenant->connection;

                if ($connection !== null) {
                    $grammar = $this->migrator()->getQueryGrammar();

                    $this->migrator()->statement(
                        'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ?',
                        [$connection->database],
                    );
                    $this->migrator()->statement(
                        sprintf('DROP DATABASE IF EXISTS %s', $grammar->wrap($connection->database)),
                    );
                    $this->migrator()->statement(
                        sprintf('DROP ROLE IF EXISTS %s', $grammar->wrap($connection->username)),
                    );

                    $connection->forceDelete();
                }

                $tenant->subscriptions()->forceDelete();
                $tenant->forceDelete();
            } catch (Throwable) {
                // A probe left behind is noise, not a failure of the rule.
            }
        }

        $this->cleanup = [];
    }
}
