<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * A per-tenant physical-format dump, and the restore that proves it - PF-244.
 *
 * ## Why this exists next to TenantExport
 *
 * `TenantExport` writes JSON Lines for a data request: a human-readable
 * answer to "give me everything you hold about us". It is deliberately
 * lossy about the things a reader does not want - no indexes, no
 * constraints, no sequence positions - and there is no importer, because a
 * data subject does not import anything.
 *
 * A migration is the other direction. Moving a tenant to another cluster
 * needs the sequences at their current values, the partitions as
 * partitions, and every constraint intact, and it needs a restore that has
 * been run rather than assumed. That is `pg_dump`'s custom format, which
 * `pg_restore` reads.
 *
 * ## Row-level security is the trap, and it has two sides
 *
 * Every financial table here sets `FORCE ROW LEVEL SECURITY`, so even the
 * owner is filtered, and the schema grants the migrator a permissive
 * `peakflow_migrator_bypass` policy instead.
 *
 * `pg_dump` defaults to `SET row_security = off`, which does NOT mean
 * "ignore policies": PostgreSQL raises an error if any policy would apply
 * to the query. That is the right failure - the alternative is a dump
 * missing rows - but it means the dump has to run with row security ON and
 * lean on the bypass policy, which is what `--enable-row-security` does.
 *
 * That moves the risk. With row security on, a table whose bypass policy is
 * MISSING does not fail: it dumps the rows the policy lets through, which
 * for an entity-scoped policy and no entity set is none. The dump succeeds,
 * the manifest looks healthy, and the table is empty - and nobody finds out
 * until the restore is the only copy left.
 *
 * So the dump refuses to start unless every table with row security enabled
 * carries a bypass policy for the dumping role. `preflight()` is not a
 * nicety; it is the thing standing between a green dump and a silent one.
 *
 * The migrator is also not the application's role: the tenant role owns the
 * database and nothing inside it - see `TenantProvisioner`.
 *
 * ## The password goes through the environment
 *
 * Never in argv. `/proc/<pid>/cmdline` is world-readable, so a password on
 * a `pg_dump` command line is a password every process on the host can
 * read for as long as the dump runs - and a dump of a large tenant runs for
 * a while.
 */
final class TenantDump
{
    /**
     * Seconds before a dump or restore is abandoned.
     *
     * A dump that hangs on a lock must not hang the scheduler with it.
     */
    private const TIMEOUT = 3600;

    /**
     * Write `<directory>/<slug>.dump` and its manifest, and return the manifest.
     *
     * @return array<string, mixed>
     */
    public function dump(Tenant $tenant, string $directory): array
    {
        $connection = $tenant->connection;

        if ($connection === null) {
            throw new \RuntimeException(sprintf('Tenant %s has no database connection.', $tenant->slug));
        }

        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create %s.', $directory));
        }

        $file = rtrim($directory, '/').'/'.$tenant->slug.'.dump';
        $migrator = $this->migrator();

        $unprotected = $this->preflight($tenant, $migrator['username']);

        if ($unprotected !== []) {
            throw new \RuntimeException(sprintf(
                'Refusing to dump %s: %d table(s) have row security enabled and no bypass policy for %s, '.
                'so they would dump as EMPTY rather than fail - %s',
                $tenant->slug,
                count($unprotected),
                $migrator['username'],
                implode(', ', array_slice($unprotected, 0, 10)).(count($unprotected) > 10 ? ', ...' : ''),
            ));
        }

        $process = new Process([
            'pg_dump',
            '--format=custom',
            '--compress=9',
            /*
             | Row security ON, deliberately.
             |
             | The default (`row_security = off`) makes PostgreSQL ERROR on
             | any table a policy touches, and every financial table here is
             | one. With it on, the migrator's bypass policy applies - which
             | is why `preflight()` has already proved that policy exists
             | everywhere it is needed.
             */
            '--enable-row-security',
            /*
             | --no-owner and --no-privileges on purpose.
             |
             | The roles are generated per tenant, so the role names in a
             | dump taken on one cluster do not exist on another, and a
             | restore that tries to set them fails on every object. Without
             | them the restoring role owns what it restores, which is what
             | a migration wants.
             |
             | The cost is that grants are NOT carried: a restored database
             | has the schema and the data and none of the tenant role's
             | privileges. `tenants:provision --attach` re-establishes those,
             | and the restore says so rather than leaving it to be found.
             */
            '--no-owner',
            '--no-privileges',
            '--file='.$file,
            '--dbname='.$connection->database,
            '--host='.$connection->host,
            '--port='.(string) $connection->port,
            '--username='.$migrator['username'],
        ], null, ['PGPASSWORD' => $migrator['password'], 'PGSSLMODE' => $connection->sslmode]);

        $process->setTimeout(self::TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'pg_dump failed for %s: %s',
                $tenant->slug,
                trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : 'no output',
            ));
        }

        $manifest = [
            'tenant' => $tenant->slug,
            'database' => $connection->database,
            'file' => basename($file),
            'bytes' => (int) filesize($file),
            'sha256' => (string) hash_file('sha256', $file),
            'format' => 'pg_dump custom, compressed, --no-owner --no-privileges',
            'server_version' => $this->serverVersion($tenant),
            'taken_at' => Carbon::now()->toIso8601String(),
            'restore_with' => 'php artisan tenants:restore '.basename($file).' --into=<new database>',
            'restores_without_grants' => true,
        ];

        file_put_contents(
            rtrim($directory, '/').'/'.$tenant->slug.'.manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );

        return $manifest;
    }

    /**
     * Restore `$file` into `$database`, which must not already hold a tenant.
     *
     * The database is created here. Restoring into an existing one is
     * refused outright: `pg_restore` without `--clean` would merge a dump
     * into whatever is already there, and the result is a tenant whose
     * ledger contains another tenant's rows and still passes every
     * per-row check, because every row is individually valid.
     *
     * @return array<string, mixed>
     */
    public function restore(string $file, string $database): array
    {
        if (! is_file($file)) {
            throw new \RuntimeException(sprintf('No dump at %s.', $file));
        }

        $this->refuseToOverwriteALiveDatabase($database);

        $migrator = $this->migrator();
        $platform = DB::connection('migrator');
        $grammar = $platform->getQueryGrammar();

        $exists = $platform->selectOne(
            'select 1 as found from pg_database where datname = ?',
            [$database],
        );

        if ($exists !== null) {
            throw new \RuntimeException(sprintf(
                'Database %s already exists. A restore creates its target, so that it cannot '.
                'merge one tenant into another.',
                $database,
            ));
        }

        $platform->statement(sprintf('CREATE DATABASE %s ENCODING %s', $grammar->wrap($database), $platform->getPdo()->quote('UTF8')));

        $config = config('database.connections.migrator');

        $process = new Process([
            'pg_restore',
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            '--single-transaction',
            '--dbname='.$database,
            '--host='.(string) $config['host'],
            '--port='.(string) $config['port'],
            '--username='.$migrator['username'],
            $file,
        ], null, ['PGPASSWORD' => $migrator['password']]);

        $process->setTimeout(self::TIMEOUT);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'pg_restore failed into %s: %s',
                $database,
                trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : 'no output',
            ));
        }

        return [
            'database' => $database,
            'from' => basename($file),
            'restored_at' => Carbon::now()->toIso8601String(),
            'grants' => 'not restored - attach the tenant role before serving traffic from this database',
        ];
    }

    /**
     * Refuse a target that is a live tenant's database, or the platform's.
     *
     * This is the only genuinely destructive thing in the class, so the
     * check is a positive list rather than a name convention: a database
     * called `scratch_acme` is not safe because of its name, it is safe
     * because no row in `database_connections` points at it.
     */
    private function refuseToOverwriteALiveDatabase(string $database): void
    {
        $platform = DB::connection('platform');

        $live = $platform->table('database_connections')
            ->where('database', $database)
            ->exists();

        if ($live) {
            throw new \RuntimeException(sprintf(
                'Refusing to restore into %s: a tenant is served from it.',
                $database,
            ));
        }

        foreach (['platform', 'migrator'] as $reserved) {
            if ($database === (string) config('database.connections.'.$reserved.'.database')) {
                throw new \RuntimeException(sprintf('Refusing to restore into %s: it is the platform database.', $database));
            }
        }
    }

    /**
     * Tables with row security enabled and no bypass policy for `$role`.
     *
     * Partitions are included on purpose. The policy helper walks them
     * recursively, so a missing one means something bypassed the helper -
     * and `pg_dump` names partitions directly, so a partition without a
     * bypass is a partition that dumps empty.
     *
     * @return list<string>
     */
    private function preflight(Tenant $tenant, string $role): array
    {
        app(TenantConnectionResolver::class)->point($tenant);

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT (n.nspname || '.' || c.relname) AS name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relrowsecurity
              AND c.relkind IN ('r', 'p')
              AND n.nspname = 'public'
              AND NOT EXISTS (
                  SELECT 1
                  FROM pg_policy p
                  WHERE p.polrelid = c.oid
                    AND p.polpermissive
                    AND p.polcmd = '*'
                    -- An unconditional policy: either no qualifier at all,
                    -- or one that is literally true. `USING (true)` stores
                    -- an expression, not a NULL - and requiring NULL here
                    -- made the preflight report all 292 tables as
                    -- unprotected, which is the right direction to be wrong
                    -- in but still wrong.
                    AND (p.polqual IS NULL OR pg_get_expr(p.polqual, p.polrelid) = 'true')
                    AND p.polroles @> ARRAY[(SELECT oid FROM pg_roles WHERE rolname = ?)]
              )
            ORDER BY 1
        SQL, [$role]);

        return array_map(static fn (object $row): string => (string) $row->name, $rows);
    }

    /**
     * @return array{username: string, password: string}
     */
    private function migrator(): array
    {
        $config = config('database.connections.migrator');

        return [
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
        ];
    }

    private function serverVersion(Tenant $tenant): string
    {
        try {
            app(TenantConnectionResolver::class)->point($tenant);

            return (string) DB::connection('tenant')->selectOne('select version() as v')->v;
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
