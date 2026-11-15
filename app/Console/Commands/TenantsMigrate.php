<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Enums\TenantStatus;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Platform\Services\TenantProvisioner;
use Illuminate\Console\Command;
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
        {--pretend : Show what would run, change nothing}';

    protected $description = 'Run the tenant migrations against one tenant or every one';

    public function handle(
        TenantConnectionResolver $resolver,
        TenantProvisioner $provisioner,
    ): int {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched.');

            return self::SUCCESS;
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
