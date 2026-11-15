<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\Enums\ProvisionMode;
use App\Platform\Enums\SubscriptionStatus;
use App\Platform\Enums\TenantStatus;
use App\Platform\Exceptions\ProvisioningFailed;
use App\Platform\Models\DatabaseConnection;
use App\Platform\Models\Plan;
use App\Platform\Models\Subscription;
use App\Platform\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates or attaches a tenant's database, end to end - PF-019, PF-020.
 *
 * ## It cannot be one transaction, so it is reversible instead
 *
 * `CREATE DATABASE` cannot run inside a transaction in PostgreSQL. So this is
 * a sequence of steps, each of which records what it did, with a teardown that
 * undoes them in reverse. A failure half way must leave NO ORPHAN DATABASE and
 * no half-registered tenant - an orphan database still counts against the
 * server's limits and nobody knows it is there.
 *
 * ## A generated role per tenant
 *
 * Not one shared application role with access to many databases. That is what
 * bounds a credential leak to a single customer, and it is why the password is
 * generated here and encrypted at the column level rather than configured.
 *
 * ## It runs as the MIGRATOR
 *
 * The application role has no `CREATE DATABASE` and no `CREATE ROLE`, so a
 * compromised application cannot provision anything. Everything here goes
 * through the `migrator` connection.
 */
final class TenantProvisioner
{
    /** @var list<callable():void> */
    private array $rollback = [];

    public function __construct(
        private readonly TenantConnectionResolver $resolver,
    ) {}

    /**
     * Provision a brand-new tenant with its own database and role.
     *
     * @throws ProvisioningFailed
     */
    public function create(string $name, string $planKey, ?string $slug = null): Tenant
    {
        $this->rollback = [];

        $slug = $this->normalizeSlug($slug ?? $name);
        $this->assertSlugAvailable($slug);

        $plan = Plan::query()->where('key', $planKey)->first()
            ?? throw ProvisioningFailed::unknownPlan($planKey);

        try {
            $tenant = $this->registerTenant($name, $slug, ProvisionMode::Create);

            $database = $this->databaseName($tenant);
            $username = $this->roleName($tenant);
            $password = Str::password(40, symbols: false);

            $this->createRole($username, $password);
            $this->createDatabase($database, $username);
            $this->grantSchema($database, $username);

            $connection = $this->recordConnection($tenant, $database, $username, $password);

            $this->resolver->point($tenant->fresh(['connection']) ?? $tenant);
            $this->migrate();
            $this->seedBaseline();

            $this->attachSubscription($tenant, $plan);

            $tenant->update([
                'status' => TenantStatus::Active,
                'provisioned_at' => now(),
                'schema_version' => $this->currentSchemaVersion(),
            ]);

            $connection->update(['verified_at' => now()]);

            $this->rollback = [];

            return $tenant->refresh();
        } catch (Throwable $e) {
            $this->tearDown();

            throw ProvisioningFailed::from($e);
        }
    }

    /**
     * Attach a pre-created database, for hosting without CREATE DATABASE.
     *
     * The connection is verified and the schema brought up to date before the
     * tenant is marked usable - a tenant pointed at a database that does not
     * answer is worse than no tenant, because it looks provisioned.
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string, sslmode?: string}  $credentials
     *
     * @throws ProvisioningFailed
     */
    public function link(string $name, string $planKey, array $credentials, ?string $slug = null): Tenant
    {
        $this->rollback = [];

        $slug = $this->normalizeSlug($slug ?? $name);
        $this->assertSlugAvailable($slug);

        $plan = Plan::query()->where('key', $planKey)->first()
            ?? throw ProvisioningFailed::unknownPlan($planKey);

        try {
            $tenant = $this->registerTenant($name, $slug, ProvisionMode::Link);

            $connection = $this->recordConnection(
                $tenant,
                $credentials['database'],
                $credentials['username'],
                $credentials['password'],
                $credentials['host'],
                $credentials['port'],
                $credentials['sslmode'] ?? 'prefer',
            );

            $this->resolver->point($tenant->fresh(['connection']) ?? $tenant);
            $this->verifyReachable();
            $this->migrate();
            $this->seedBaseline();

            $this->attachSubscription($tenant, $plan);

            $tenant->update([
                'status' => TenantStatus::Active,
                'provisioned_at' => now(),
                'schema_version' => $this->currentSchemaVersion(),
            ]);

            $connection->update(['verified_at' => now()]);

            $this->rollback = [];

            return $tenant->refresh();
        } catch (Throwable $e) {
            $this->tearDown();

            throw ProvisioningFailed::from($e);
        }
    }

    /** Run the tenant migrations against whichever tenant is currently pointed at. */
    public function migrate(): void
    {
        // Migrations run as the MIGRATOR, never as the application role. The
        // tenant connection is already pointed; this clones it with the
        // migrator's own credentials so the DDL has the privileges it needs
        // and the application's connection keeps none.
        $this->withMigratorConnection(function (): void {
            \Illuminate\Support\Facades\Artisan::call('migrate', [
                '--database' => 'tenant_migrator',
                '--path' => 'database/migrations/tenant',
                '--realpath' => false,
                '--force' => true,
            ]);

            // Hardening is part of migrating, not a step a caller can forget.
            // grantSchema() gives the tenant role default DML on every table
            // the migrator creates, which is right for almost all of them and
            // wrong for the append-only ones.
            $this->revokeAppendOnly();
        });
    }

    /**
     * Tables carrying a marker comment, plus their partitions.
     *
     * Partitions are listed individually because a privilege granted on a
     * partitioned table does not describe its existing partitions - revoking
     * only the parent would leave every month of the ledger writable.
     *
     * @return list<string>
     */
    private function tablesMarked(\Illuminate\Database\Connection $connection, string $marker): array
    {
        $rows = $connection->select(<<<'SQL'
            WITH marked AS (
                SELECT c.oid, c.relname
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public'
                  AND c.relkind IN ('r', 'p')
                  AND obj_description(c.oid, 'pg_class') LIKE ?
            )
            SELECT relname FROM marked
            UNION
            SELECT child.relname
            FROM pg_inherits i
            JOIN marked m ON m.oid = i.inhparent
            JOIN pg_class child ON child.oid = i.inhrelid
        SQL, [$marker.'%']);

        return array_map(static fn (object $row): string => (string) $row->relname, $rows);
    }

    /**
     * Revoke the privileges each marker declares.
     *
     * A table opts in with a `peakflow:append-only` comment. This reads the
     * marker rather than carrying a list, so a migration that adds an
     * append-only table - the journal, in Phase 02 - is hardened by the
     * comment it already needs for its own documentation, with nothing here to
     * update and nothing to forget.
     *
     * Partitions are revoked individually. A privilege granted on a
     * partitioned table does not describe its existing partitions, and the
     * audit log is partitioned by month, so revoking only the parent would
     * leave 25 writable back doors.
     *
     * Runs as the migrator, which owns the tables. Must run AFTER migrating:
     * default privileges apply at CREATE TABLE time.
     *
     * Safe to re-run. It is also the remedy for drift - a grant made during an
     * incident and never reverted - so it opens its own migrator connection
     * rather than requiring a caller to have one already.
     */
    public function harden(): void
    {
        $this->withMigratorConnection(function (): void {
            $this->revokeAppendOnly();
        });
    }

    /** The revoking itself. Assumes `tenant_migrator` is configured. */
    private function revokeAppendOnly(): void
    {
        $connection = DB::connection('tenant_migrator');
        $grammar = $connection->getQueryGrammar();

        $role = (string) config('database.connections.tenant.username');

        if ($role === '' || $role === (string) config('database.connections.migrator.username')) {
            // Nothing to revoke from, or we would be revoking from the owner.
            return;
        }

        /*
         | Two markers, two different revocations.
         |
         | `append-only` means a record is evidence: the audit log, where an
         | entry that can be edited is not evidence at all.
         |
         | `no-delete` means a record may change while it is a draft and must
         | never disappear once it is not: a journal entry is edited before it
         | is posted and corrected by reversal afterwards. Revoking UPDATE
         | there would make drafts unusable, so only DELETE goes.
         */
        foreach ([
            'peakflow:append-only' => 'UPDATE, DELETE',
            'peakflow:no-delete' => 'DELETE',
        ] as $marker => $privileges) {
            foreach ($this->tablesMarked($connection, $marker) as $table) {
                $connection->statement(sprintf(
                    'REVOKE %s ON %s FROM %s',
                    $privileges,
                    $grammar->wrapTable($table),
                    $grammar->wrap($role),
                ));
            }
        }
    }

    /**
     * What every new tenant database opens with: the permission catalogue, the
     * seeded roles, and the incompatible duty pairs.
     *
     * A workspace that opens empty is one whose owner has to invent a
     * permission model before they can issue an invoice.
     */
    public function seedBaseline(): void
    {
        \Illuminate\Support\Facades\Artisan::call('db:seed', [
            '--class' => \Database\Seeders\Tenant\TenantBaselineSeeder::class,
            '--force' => true,
        ]);
    }

    public function currentSchemaVersion(): string
    {
        $files = glob(base_path('database/migrations/tenant/*.php')) ?: [];

        sort($files);

        return $files === []
            ? 'none'
            : pathinfo((string) end($files), PATHINFO_FILENAME);
    }

    private function registerTenant(string $name, string $slug, ProvisionMode $mode): Tenant
    {
        $tenant = Tenant::query()->create([
            'slug' => $slug,
            'name' => $name,
            'status' => TenantStatus::Provisioning,
            'provision_mode' => $mode,
        ]);

        $this->rollback[] = static function () use ($tenant): void {
            $tenant->connection?->forceDelete();
            $tenant->subscriptions()->forceDelete();
            $tenant->forceDelete();
        };

        return $tenant;
    }

    private function createRole(string $username, string $password): void
    {
        $platform = DB::connection('migrator');

        // Quoted as an identifier and a literal respectively. Both values are
        // generated here from a ULID, so neither is user input - but a
        // provisioner that interpolates unquoted is one refactor away from
        // being one.
        $wrapped = $platform->getQueryGrammar()->wrap($username);

        $platform->statement(sprintf(
            'CREATE ROLE %s LOGIN PASSWORD %s',
            $wrapped,
            $platform->getPdo()->quote($password),
        ));

        /*
         | Grant the new role TO the migrator.
         |
         | PostgreSQL requires that to create a database OWNED BY a role, you
         | must be able to SET ROLE to it - being CREATEDB is not enough. The
         | migrator is therefore made a member of each tenant role.
         |
         | The direction matters: the migrator can act AS the tenant role, and
         | the tenant role gains nothing. A tenant's credentials still reach
         | only that tenant's own database, which is what bounds a credential
         | leak to one customer.
         */
        $platform->statement(sprintf(
            'GRANT %s TO %s',
            $wrapped,
            $platform->getQueryGrammar()->wrap((string) config('database.connections.migrator.username')),
        ));

        $this->rollback[] = static function () use ($platform, $wrapped): void {
            $platform->statement(sprintf('DROP ROLE IF EXISTS %s', $wrapped));
        };
    }

    private function createDatabase(string $database, string $owner): void
    {
        $platform = DB::connection('migrator');
        $grammar = $platform->getQueryGrammar();

        // CREATE DATABASE cannot run inside a transaction, which is the whole
        // reason this service is a reversible sequence rather than one.
        $platform->statement(sprintf(
            'CREATE DATABASE %s OWNER %s ENCODING %s',
            $grammar->wrap($database),
            $grammar->wrap($owner),
            $platform->getPdo()->quote('UTF8'),
        ));

        $this->rollback[] = static function () use ($platform, $grammar, $database): void {
            // Terminate stragglers first: a single open connection makes DROP
            // DATABASE fail, and the rollback would then leave the orphan it
            // exists to prevent.
            $platform->statement(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ?',
                [$database],
            );
            $platform->statement(sprintf('DROP DATABASE IF EXISTS %s', $grammar->wrap($database)));
        };
    }

    private function grantSchema(string $database, string $username): void
    {
        // Connect to the new database to set schema ownership - privileges on
        // `public` are per database and cannot be granted from outside it.
        config(['database.connections.tenant_bootstrap' => array_merge(
            (array) config('database.connections.migrator'),
            ['database' => $database],
        )]);

        DB::purge('tenant_bootstrap');

        $bootstrap = DB::connection('tenant_bootstrap');
        $grammar = $bootstrap->getQueryGrammar();
        $tenantRole = $grammar->wrap($username);
        $migrator = $grammar->wrap((string) config('database.connections.migrator.username'));

        /*
         | The whole point of PF-010, applied inside each tenant's database.
         |
         | The MIGRATOR owns the schema and is the only role that can create,
         | alter or drop anything in it. The TENANT role - which is what the
         | application connects as - gets USAGE and data privileges and no DDL
         | at all. Default privileges carry that forward to every table the
         | migrator creates from here on, so a new migration does not have to
         | remember to grant.
         |
         | Database ownership and schema ownership are separate: the tenant
         | role owns the database so that a per-tenant pg_dump is
         | straightforward, and owns nothing inside it.
         */
        $bootstrap->statement(sprintf('ALTER SCHEMA public OWNER TO %s', $migrator));

        $bootstrap->statement('REVOKE ALL ON SCHEMA public FROM PUBLIC');
        $bootstrap->statement(sprintf('REVOKE CREATE ON SCHEMA public FROM %s', $tenantRole));
        $bootstrap->statement(sprintf('GRANT USAGE ON SCHEMA public TO %s', $tenantRole));

        $bootstrap->statement(sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %s',
            $migrator,
            $tenantRole,
        ));

        $bootstrap->statement(sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %s',
            $migrator,
            $tenantRole,
        ));

        DB::purge('tenant_bootstrap');
    }

    private function recordConnection(
        Tenant $tenant,
        string $database,
        string $username,
        string $password,
        ?string $host = null,
        ?int $port = null,
        string $sslmode = 'prefer',
    ): DatabaseConnection {
        $connection = new DatabaseConnection([
            'tenant_id' => $tenant->getKey(),
            'host' => $host ?? (string) config('database.connections.platform.host'),
            'port' => $port ?? (int) config('database.connections.platform.port'),
            'database' => $database,
            'username' => $username,
            'sslmode' => $sslmode,
        ]);

        $connection->setPassword($password);
        $connection->save();

        return $connection;
    }

    private function attachSubscription(Tenant $tenant, Plan $plan): void
    {
        Subscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Trial,
            'starts_on' => today(),
            'trial_ends_on' => today()->addDays(30),
        ]);
    }

    private function verifyReachable(): void
    {
        try {
            DB::connection('tenant')->getPdo();
        } catch (Throwable $e) {
            throw ProvisioningFailed::unreachable($e->getMessage());
        }
    }

    /**
     * Run a callback against a migrator-credentialed clone of the current
     * tenant connection.
     */
    private function withMigratorConnection(callable $callback): void
    {
        $tenant = (array) config('database.connections.tenant');
        $migrator = (array) config('database.connections.migrator');

        config(['database.connections.tenant_migrator' => array_merge($tenant, [
            'username' => $migrator['username'],
            'password' => $migrator['password'],
        ])]);

        DB::purge('tenant_migrator');

        try {
            $callback();
        } finally {
            DB::purge('tenant_migrator');
        }
    }

    /** Undo every step that succeeded, newest first. */
    private function tearDown(): void
    {
        foreach (array_reverse($this->rollback) as $step) {
            try {
                $step();
            } catch (Throwable) {
                // A failing rollback step must not mask the original error.
                // The operator console surfaces tenants left in `provisioning`
                // so an orphan is visible rather than merely logged.
            }
        }

        $this->rollback = [];
        $this->resolver->forget();
    }

    private function databaseName(Tenant $tenant): string
    {
        return (string) config('tenancy.database_prefix', 'peakflow_t_')
            .strtolower((string) $tenant->getKey());
    }

    private function roleName(Tenant $tenant): string
    {
        return 'pf_t_'.strtolower((string) $tenant->getKey());
    }

    private function normalizeSlug(string $value): string
    {
        $slug = Str::slug($value);

        return $slug === '' ? 't'.Str::lower(Str::ulid()->toBase32()) : $slug;
    }

    private function assertSlugAvailable(string $slug): void
    {
        if (Tenant::query()->withTrashed()->where('slug', $slug)->exists()) {
            throw ProvisioningFailed::slugTaken($slug);
        }
    }
}
