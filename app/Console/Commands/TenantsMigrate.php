<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Enums\TenantStatus;
use App\Platform\Models\ActivityLog;
use App\Platform\Models\Tenant;
use App\Platform\Services\MigrationSafetyInspector;
use App\Platform\Services\TenantConnectionResolver;
use App\Platform\Services\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Migrate one tenant, or all of them - PF-023.
 *
 * The honest operational cost of database-per-tenant: migration time grows
 * linearly with tenant count. Bounded concurrency helps and does not change
 * the order of growth.
 *
 * The part that matters most is FAILURE ISOLATION: one tenant's broken
 * migration must not stop the other nine hundred. So each tenant is migrated
 * in its own try, the failures are collected, and the command reports them all
 * at the end with a non-zero exit - rather than stopping at the first and
 * leaving the rest at an unknown version.
 */
final class TenantsMigrate extends Command
{
    protected $signature = 'tenants:migrate
        {tenant? : A tenant slug or id}
        {--all : Migrate every servable tenant}
        {--pretend : Show what would run, change nothing}
        {--concurrency=1 : How many tenants to migrate at once, with --all}
        {--skip-preflight : Run migrations the pre-flight check refused}
        {--unstick= : Return tenants stuck in `migrating` for more than this many minutes to active}';

    protected $description = 'Run the tenant migrations against one tenant or every one';

    public function handle(
        TenantConnectionResolver $resolver,
        TenantProvisioner $provisioner,
        MigrationSafetyInspector $inspector,
    ): int {
        if ($this->option('unstick') !== null) {
            return $this->unstick((int) $this->option('unstick'));
        }

        $this->reportStuck();

        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched.');

            return self::SUCCESS;
        }

        if (! $this->preflight($inspector)) {
            return self::FAILURE;
        }

        $concurrency = max(1, (int) $this->option('concurrency'));

        if ($concurrency > 1 && $this->option('all') && ! $this->option('pretend')) {
            return $this->migrateConcurrently($tenants, $concurrency);
        }

        $expected = $provisioner->currentSchemaVersion();
        $failures = [];
        $migrated = 0;

        foreach ($tenants as $tenant) {
            if ($this->option('pretend')) {
                $this->line(sprintf(
                    '  %s  at %s, expected %s',
                    $tenant->slug,
                    $tenant->schema_version ?? 'none',
                    $expected,
                ));

                continue;
            }

            try {
                // Mark the tenant as migrating so a concurrent request answers
                // 503 with Retry-After rather than failing on a column that is
                // half-added. A visible maintenance window is tolerable; one
                // that presents as random errors is not.
                $tenant->update([
                    'status' => TenantStatus::Migrating,
                    'migrating_since' => now(),
                ]);

                $resolver->point($tenant);
                $provisioner->migrate();

                $tenant->update([
                    'status' => TenantStatus::Active,
                    'migrating_since' => null,
                    'schema_version' => $expected,
                ]);

                $migrated++;
                $this->components->twoColumnDetail($tenant->slug, '<fg=green>migrated</>');
            } catch (Throwable $e) {
                // Put the tenant back so it is servable at its OLD schema
                // rather than stuck in `migrating` forever.
                $tenant->update([
                    'status' => TenantStatus::Active,
                    'migrating_since' => null,
                ]);

                $failures[$tenant->slug] = $e->getMessage();
                $this->components->twoColumnDetail($tenant->slug, '<fg=red>failed</>');
            } finally {
                $resolver->forget();
            }
        }

        if ($failures !== []) {
            $this->newLine();
            $this->components->error(sprintf('%d tenant(s) failed to migrate:', \count($failures)));

            foreach ($failures as $slug => $message) {
                $this->line(sprintf('  <fg=red>%s</>  %s', $slug, $message));
            }

            return self::FAILURE;
        }

        if (! $this->option('pretend')) {
            $this->newLine();
            $this->components->info(sprintf('%d tenant(s) migrated to %s.', $migrated, $expected));
        }

        return self::SUCCESS;
    }

    /**
     * Warn about tenants stuck in `migrating`.
     *
     * The command restores a tenant's status in a `finally`, so an ordinary
     * failure leaves nothing stuck. A killed process does: SIGKILL, an OOM, a
     * container replaced mid-deploy. The tenant then answers 503 to every
     * request forever, which is why `migrating_since` is recorded rather than
     * just a boolean - the column exists so that "stuck" is distinguishable
     * from "started a moment ago".
     *
     * Reported rather than fixed automatically. A tenant that has been
     * migrating for six hours might be stuck, or might be a genuinely large
     * migration someone is watching; guessing wrong in the second case
     * un-sticks a tenant mid-DDL and serves traffic against a half-migrated
     * schema. So `--unstick` is explicit, and takes the threshold from
     * whoever knows.
     */
    private function reportStuck(): void
    {
        $stuck = Tenant::query()
            ->where('status', TenantStatus::Migrating->value)
            ->whereNotNull('migrating_since')
            ->where('migrating_since', '<', now()->subMinutes(30))
            ->orderBy('migrating_since')
            ->get();

        if ($stuck->isEmpty()) {
            return;
        }

        $this->components->warn(sprintf(
            '%d tenant(s) have been in `migrating` for over 30 minutes and are answering 503 to every request:',
            $stuck->count(),
        ));

        foreach ($stuck as $tenant) {
            $this->line(sprintf(
                '  %-28s since %s (%s)',
                (string) $tenant->slug,
                (string) $tenant->migrating_since?->toDateTimeString(),
                (string) $tenant->migrating_since?->diffForHumans(),
            ));
        }

        $this->line('  <comment>If no migration is actually running, --unstick=30 returns them to active at');
        $this->line('  their old schema version. Check first: un-sticking a tenant mid-DDL serves traffic');
        $this->line('  against a half-migrated schema.</comment>');
        $this->newLine();
    }

    private function unstick(int $minutes): int
    {
        $stuck = Tenant::query()
            ->where('status', TenantStatus::Migrating->value)
            ->whereNotNull('migrating_since')
            ->where('migrating_since', '<', now()->subMinutes($minutes))
            ->get();

        if ($stuck->isEmpty()) {
            $this->components->info(sprintf('No tenant has been migrating for more than %d minute(s).', $minutes));

            return self::SUCCESS;
        }

        foreach ($stuck as $tenant) {
            $since = $tenant->migrating_since;

            // Back to active at its OLD schema version, which is the honest
            // state: the migration did not finish, so the tenant is not at the
            // new version and must not claim to be.
            $tenant->update(['status' => TenantStatus::Active, 'migrating_since' => null]);

            ActivityLog::create([
                'tenant_id' => $tenant->getKey(),
                'category' => 'tenant',
                'action' => 'tenant.unstuck',
                'context' => [
                    'migrating_since' => $since?->toIso8601String(),
                    'minutes' => (int) ($since?->diffInMinutes() ?? 0),
                    'schema_version' => $tenant->schema_version,
                ],
            ]);

            $this->components->twoColumnDetail((string) $tenant->slug, '<fg=yellow>returned to active</>');
        }

        $this->components->info(sprintf(
            '%d tenant(s) returned to active at their previous schema version. Re-run the migration for each.',
            $stuck->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Refuse a migration that is not safe to run while the application serves
     * traffic - PF-023.
     *
     * Runs ONCE, before any tenant is touched. Checking per tenant would
     * report the same hazard nine hundred times, and - much worse - would
     * discover it after the first six hundred tenants had already been
     * migrated by it.
     */
    private function preflight(MigrationSafetyInspector $inspector): bool
    {
        $files = glob(database_path('migrations/tenant/*.php')) ?: [];
        $hazards = $inspector->inspect($files);
        $reviewed = array_filter($hazards, static fn ($h): bool => $h->isReviewed());
        $blocking = $inspector->blocking($hazards);

        if ($reviewed !== [] && $this->output->isVerbose()) {
            $this->components->info(sprintf('%d reviewed hazard(s), accepted:', count($reviewed)));

            foreach ($reviewed as $hazard) {
                $this->line(sprintf('  %s  %s', $hazard->location(), $hazard->name));
                $this->line(sprintf('    <comment>%s</comment>', $hazard->reviewedBecause));
            }
        }

        if ($blocking === []) {
            return true;
        }

        $this->newLine();
        $this->components->error(sprintf(
            '%d migration hazard(s) are not safe to run while the application serves traffic:',
            count($blocking),
        ));

        foreach ($blocking as $hazard) {
            $this->newLine();
            $this->line(sprintf('  <fg=red;options=bold>%s</>  %s', $hazard->location(), $hazard->name));
            $this->line(sprintf('  <options=bold>%s</>', $hazard->statement));
            $this->line(sprintf('    it %s', $hazard->does));
            $this->line(sprintf('    <fg=green>instead:</> %s', $hazard->instead));
        }

        $this->newLine();
        $this->line('  If one of these is genuinely safe here, annotate the line or the file with');
        $this->line('  <options=bold>@unsafe-migration-reviewed: why</> so the reason is recorded where the');
        $this->line('  statement is, rather than in a shell history nobody can read.');
        $this->line('  <comment>--skip-preflight</comment> runs them anyway and records nothing.');

        if (! $this->option('skip-preflight')) {
            return false;
        }

        $this->newLine();
        $this->components->warn('--skip-preflight given; running them anyway.');

        return true;
    }

    /**
     * Migrate several tenants at once, in child processes.
     *
     * Child processes rather than threads because each tenant migration
     * rewrites `database.connections.tenant` in the container - two of those
     * in one process would race over the same config key and migrate one
     * tenant's schema into another's database. A separate process has its own
     * container and cannot.
     *
     * The concurrency is BOUNDED. Unbounded would open one connection per
     * tenant, and a thousand tenants would exhaust `max_connections` long
     * before finishing - turning a slow migration into an outage for every
     * tenant, including the ones not being migrated.
     *
     * @param  list<Tenant>  $tenants
     */
    private function migrateConcurrently(array $tenants, int $concurrency): int
    {
        $this->components->info(sprintf(
            'Migrating %d tenant(s), %d at a time.',
            count($tenants),
            $concurrency,
        ));

        $queue = array_values($tenants);
        $running = [];
        $failures = [];
        $migrated = 0;

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $concurrency) {
                $tenant = array_shift($queue);
                $process = $this->spawn((string) $tenant->slug);

                if ($process === null) {
                    $failures[(string) $tenant->slug] = 'could not start a child process';

                    continue;
                }

                $running[(string) $tenant->slug] = $process;
            }

            foreach ($running as $slug => $process) {
                $status = proc_get_status($process['handle']);

                if ($status['running']) {
                    continue;
                }

                $output = trim((string) stream_get_contents($process['stdout']))
                    ."\n".trim((string) stream_get_contents($process['stderr']));

                fclose($process['stdout']);
                fclose($process['stderr']);
                proc_close($process['handle']);

                unset($running[$slug]);

                if ($status['exitcode'] === 0) {
                    $migrated++;
                    $this->components->twoColumnDetail((string) $slug, '<fg=green>migrated</>');

                    continue;
                }

                // Failure isolation: record it and keep the other children
                // going. One tenant's broken migration must not stop the
                // other nine hundred.
                $failures[(string) $slug] = trim(preg_replace('/\s+/', ' ', $output) ?? '');
                $this->components->twoColumnDetail((string) $slug, '<fg=red>failed</>');
            }

            if ($running !== []) {
                // 50ms. Long enough not to spin a core, short enough that a
                // fast tenant does not hold a slot it has finished with.
                usleep(50_000);
            }
        }

        if ($failures !== []) {
            $this->newLine();
            $this->components->error(sprintf('%d tenant(s) failed to migrate:', count($failures)));

            foreach ($failures as $slug => $message) {
                $this->line(sprintf('  <fg=red>%s</>  %s', $slug, Str::limit($message, 300)));
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info(sprintf('%d tenant(s) migrated.', $migrated));

        return self::SUCCESS;
    }

    /**
     * @return ?array{handle: resource, stdout: resource, stderr: resource}
     */
    private function spawn(string $slug): ?array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // The child runs the single-tenant path, and skips the pre-flight
        // because the parent already ran it once for all of them.
        $handle = proc_open(
            [PHP_BINARY, base_path('artisan'), 'tenants:migrate', $slug, '--skip-preflight', '--no-interaction'],
            $descriptors,
            $pipes,
        );

        if (! is_resource($handle)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['handle' => $handle, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        if ($this->option('all')) {
            return Tenant::query()
                ->with('connection')
                ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Suspended->value])
                ->whereHas('connection')
                ->orderBy('created_at')
                ->get()
                ->all();
        }

        $key = $this->argument('tenant');

        if ($key === null) {
            $this->components->error('Name a tenant, or pass --all.');

            return [];
        }

        $tenant = Tenant::query()
            ->with('connection')
            ->where('slug', $key)
            ->orWhere('id', $key)
            ->first();

        return $tenant === null ? [] : [$tenant];
    }
}
