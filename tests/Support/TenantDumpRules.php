<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantDump;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * A dump is not a dump until it has been restored - PF-244.
 *
 * Every check here does the whole round trip against real PostgreSQL:
 * `pg_dump` out, `pg_restore` into a database created for the purpose, and
 * a comparison. Nothing here asserts that a file was written and a byte
 * count looked plausible, because that is what a dump missing 95% of its
 * rows also looks like.
 */
final class TenantDumpRules
{
    private const PROBE = 'peakflow_dump_probe';

    /** @var list<string> */
    private array $probes = [];

    private string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/peakflow-dump-'.bin2hex(random_bytes(4));
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        $results = [
            'a dump restores into a copy that holds the same rows' => [],
            'a restore refuses to write over a database a tenant is served from' => [],
            'a restore refuses a database that already exists' => [],
            'a table whose rows the dump could not see is refused, not dumped empty' => [],
        ];

        try {
            $results['a dump restores into a copy that holds the same rows'] = $this->roundTrips();
            $results['a restore refuses to write over a database a tenant is served from'] = $this->refusesALiveTenant();
            $results['a restore refuses a database that already exists'] = $this->refusesAnExistingDatabase();
            $results['a table whose rows the dump could not see is refused, not dumped empty'] = $this->refusesAnInvisibleTable();
        } finally {
            $this->cleanUp();
        }

        return $results;
    }

    /**
     * Dump a tenant, restore it, and compare every table and every sequence.
     *
     * @return list<string>
     */
    private function roundTrips(): array
    {
        $tenant = $this->aProvisionedTenant();

        if ($tenant === null) {
            return ['no provisioned tenant was available, so this check asserted nothing'];
        }

        try {
            $manifest = app(TenantDump::class)->dump($tenant, $this->directory);
        } catch (\Throwable $e) {
            return [sprintf('the dump failed: %s', $e->getMessage())];
        }

        $file = $this->directory.'/'.$manifest['file'];

        if (! is_file($file)) {
            return ['the manifest named a file that was not written'];
        }

        if ((string) hash_file('sha256', $file) !== (string) $manifest['sha256']) {
            return ['the manifest\'s sha256 does not match the file it describes, so a truncated '.
                'transfer would not be noticed by whoever receives it'];
        }

        try {
            app(TenantDump::class)->restore($file, self::PROBE);
            $this->probes[] = self::PROBE;
        } catch (\Throwable $e) {
            return [sprintf('the restore failed: %s', $e->getMessage())];
        }

        return $this->compare((string) $tenant->connection?->database, self::PROBE);
    }

    /**
     * Compare two databases as the SAME role.
     *
     * This is the trap that caught me while building it. Reading the source
     * through the `tenant` connection and the restored copy as the migrator
     * compares two row-level-security policies rather than two datasets: the
     * tenant role with no current entity sees 360 of 7,427 rows, so the
     * comparison reported the RESTORE as having gained rows the source
     * "did not have". Both sides are read as the migrator here.
     *
     * @return list<string>
     */
    private function compare(string $source, string $restored): array
    {
        $this->pointAt('dump_src', $source);
        $this->pointAt('dump_probe', $restored);

        $tables = DB::connection('dump_src')->select(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND NOT EXISTS (SELECT 1 FROM pg_inherits WHERE inhrelid = c.oid)
            ORDER BY 1
        SQL);

        if ($tables === []) {
            return ['the source database reported no tables, so the comparison was vacuous'];
        }

        $problems = [];
        $compared = 0;
        $populated = 0;
        $rows = 0;

        foreach ($tables as $table) {
            $name = (string) $table->name;

            try {
                $here = $this->countIn('dump_src', $name);
                $there = $this->countIn('dump_probe', $name);
            } catch (\Throwable $e) {
                $problems[] = sprintf('%s could not be counted on both sides: %s', $name, $e->getMessage());

                continue;
            }

            $compared++;
            $rows += $here;

            if ($here > 0) {
                $populated++;
            }

            if ($here !== $there) {
                $problems[] = sprintf('%s holds %d row(s) and the restore holds %d', $name, $here, $there);
            }
        }

        /*
         | A fixture with nothing in it would pass every count comparison.
         |
         | 0 === 0 for 187 tables is a green check over an empty dump, so the
         | comparison has to know it compared something.
         */
        if ($populated < 5 || $rows < 100) {
            $problems[] = sprintf(
                'only %d of %d table(s) held any row and %d row(s) were compared in total. Equal counts '.
                'over an empty database is what a dump of nothing also looks like',
                $populated,
                $compared,
                $rows,
            );
        }

        $problems = array_merge($problems, $this->sequencesCarriedOver());

        return $problems;
    }

    /**
     * A restored tenant that reissues an invoice number is a legal problem.
     *
     * `pg_dump` carries a sequence's position with `setval`, and a restore
     * that silently started every sequence at 1 would be found by the first
     * duplicate document number rather than here.
     *
     * @return list<string>
     */
    private function sequencesCarriedOver(): array
    {
        $sequences = DB::connection('dump_src')->select(
            'select sequencename, last_value from pg_sequences where schemaname = ? and last_value is not null order by 1',
            ['public'],
        );

        if ($sequences === []) {
            return [];
        }

        $problems = [];

        foreach ($sequences as $sequence) {
            $there = DB::connection('dump_probe')->selectOne(
                'select last_value from pg_sequences where schemaname = ? and sequencename = ?',
                ['public', (string) $sequence->sequencename],
            );

            if ($there === null) {
                $problems[] = sprintf('sequence %s is missing from the restore', (string) $sequence->sequencename);

                continue;
            }

            if ((int) $there->last_value !== (int) $sequence->last_value) {
                $problems[] = sprintf(
                    'sequence %s stands at %d and the restore has it at %d, so the restored tenant would '.
                    'reissue numbers it has already used',
                    (string) $sequence->sequencename,
                    (int) $sequence->last_value,
                    (int) $there->last_value,
                );
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function refusesALiveTenant(): array
    {
        $tenant = $this->aProvisionedTenant();

        if ($tenant === null) {
            return ['no provisioned tenant was available, so this check asserted nothing'];
        }

        $live = (string) $tenant->connection?->database;
        $file = $this->directory.'/'.$tenant->slug.'.dump';

        if (! is_file($file)) {
            return ['no dump was available to attempt the restore with'];
        }

        try {
            app(TenantDump::class)->restore($file, $live);
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'a tenant is served from it')) {
                return [sprintf(
                    'the restore refused %s, but for the wrong reason: %s. A refusal that happens to '.
                    'come from the "database already exists" branch would stop protecting a tenant the '.
                    'moment somebody dropped its database to restore it',
                    $live,
                    $e->getMessage(),
                )];
            }

            return [];
        }

        return [sprintf(
            'the restore wrote into %s, which a tenant is served from. pg_restore without --clean MERGES, '.
            'so that tenant\'s ledger now holds another tenant\'s rows and every per-row check still passes',
            $live,
        )];
    }

    /**
     * @return list<string>
     */
    private function refusesAnExistingDatabase(): array
    {
        $tenant = $this->aProvisionedTenant();
        $file = $this->directory.'/'.($tenant?->slug ?? 'none').'.dump';

        if ($tenant === null || ! is_file($file)) {
            return ['no dump was available to attempt the restore with'];
        }

        $existing = self::PROBE.'_taken';
        $platform = DB::connection('migrator');
        $platform->statement('CREATE DATABASE '.$platform->getQueryGrammar()->wrap($existing));
        $this->probes[] = $existing;

        try {
            app(TenantDump::class)->restore($file, $existing);
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'already exists')) {
                return [sprintf('the restore refused an existing database for the wrong reason: %s', $e->getMessage())];
            }

            return [];
        }

        return ['the restore wrote into a database that already existed, so a second restore into the '.
            'same name would merge two dumps and the result would look like one tenant'];
    }

    /**
     * The preflight, which is the whole reason the dump is trustworthy.
     *
     * `pg_dump` has to run with row security ON - with it off PostgreSQL
     * errors on every table a policy touches - and with it on, a table
     * whose bypass policy is missing dumps as EMPTY and reports success.
     * This drops the bypass on one table and asserts the dump stops.
     *
     * Dropping it makes the table MORE restrictive, so an abort between the
     * two statements leaves the tenant no less protected than before.
     *
     * @return list<string>
     */
    private function refusesAnInvisibleTable(): array
    {
        $tenant = $this->aProvisionedTenant();

        if ($tenant === null) {
            return ['no provisioned tenant was available, so this check asserted nothing'];
        }

        $this->pointAt('dump_src', (string) $tenant->connection?->database);

        $table = DB::connection('dump_src')->selectOne(<<<'SQL'
            SELECT c.relname AS name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_policy p ON p.polrelid = c.oid AND p.polname = 'peakflow_migrator_bypass'
            WHERE n.nspname = 'public' AND c.relkind = 'r'
            ORDER BY 1
            LIMIT 1
        SQL);

        if ($table === null) {
            return ['no table carries a migrator bypass policy, which means the preflight has nothing '.
                'to find and the dump is reading every table through an entity policy'];
        }

        $name = (string) $table->name;
        $connection = DB::connection('dump_src');

        $connection->statement(sprintf('DROP POLICY peakflow_migrator_bypass ON %s', $connection->getQueryGrammar()->wrap($name)));

        try {
            app(TenantDump::class)->dump($tenant, $this->directory.'/invisible');

            return [sprintf(
                'the dump ran with no bypass policy on %s. With row security on that table comes out '.
                'EMPTY and the manifest still looks healthy, which is found when the restore is the '.
                'only copy left',
                $name,
            )];
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'dump as EMPTY')) {
                return [sprintf('the dump failed, but not because of the missing policy: %s', $e->getMessage())];
            }

            return [];
        } finally {
            $connection->statement(sprintf(
                'CREATE POLICY peakflow_migrator_bypass ON %s TO %s USING (true) WITH CHECK (true)',
                $connection->getQueryGrammar()->wrap($name),
                $connection->getQueryGrammar()->wrap((string) config('database.connections.migrator.username')),
            ));
        }
    }

    private function countIn(string $connection, string $table): int
    {
        $grammar = DB::connection($connection)->getQueryGrammar();

        return (int) DB::connection($connection)
            ->selectOne(sprintf('select count(*) as c from %s', $grammar->wrap($table)))->c;
    }

    /**
     * Point a named connection at `$database` as the migrator.
     */
    private function pointAt(string $name, string $database): void
    {
        Config::set('database.connections.'.$name, array_merge(
            (array) config('database.connections.migrator'),
            ['database' => $database],
        ));

        DB::purge($name);
    }

    private function aProvisionedTenant(): ?Tenant
    {
        return Tenant::query()
            ->with('connection')
            ->whereHas('connection')
            ->orderBy('slug')
            ->first();
    }

    private function cleanUp(): void
    {
        foreach (['dump_src', 'dump_probe'] as $connection) {
            DB::purge($connection);
        }

        $platform = DB::connection('migrator');

        foreach (array_unique($this->probes) as $probe) {
            try {
                $platform->statement('DROP DATABASE IF EXISTS '.$platform->getQueryGrammar()->wrap($probe).' WITH (FORCE)');
            } catch (\Throwable) {
                // A probe database left behind is untidy, not wrong. It must
                // not turn a passing check into a failing one.
            }
        }

        $this->remove($this->directory);
    }

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->remove($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
