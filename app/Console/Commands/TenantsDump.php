<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantDump;
use Illuminate\Console\Command;

/**
 * A physical per-tenant dump for a migration - PF-244.
 *
 * `tenants:export` answers a data request in JSON Lines. This answers
 * "move this tenant to another cluster", which needs the sequences, the
 * partitions and the constraints, and a restore somebody has actually run.
 */
final class TenantsDump extends Command
{
    protected $signature = 'tenants:dump
        {tenant? : A tenant slug}
        {--all : Dump every provisioned tenant}
        {--path= : Where to write (default storage/app/dumps)}';

    protected $description = 'Write a per-tenant pg_dump for migration, with a manifest';

    public function handle(TenantDump $dumps): int
    {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->error('Name a tenant, or pass --all.');

            return self::FAILURE;
        }

        $directory = (string) ($this->option('path') ?? storage_path('app/dumps'));
        $failed = [];

        foreach ($tenants as $tenant) {
            try {
                $manifest = $dumps->dump($tenant, $directory);
            } catch (\Throwable $e) {
                $failed[] = $tenant->slug;
                $this->components->error(sprintf('%s: %s', $tenant->slug, $e->getMessage()));

                continue;
            }

            $this->components->info(sprintf(
                '%s: %s (%s, sha256 %s)',
                $tenant->slug,
                $manifest['file'],
                $this->humanBytes((int) $manifest['bytes']),
                substr((string) $manifest['sha256'], 0, 12),
            ));
        }

        if ($failed !== []) {
            $this->components->error(sprintf('%d of %d dump(s) failed: %s', count($failed), count($tenants), implode(', ', $failed)));

            return self::FAILURE;
        }

        $this->components->warn('A dump restores without grants. Attach the tenant role before serving traffic from a restored database.');

        return self::SUCCESS;
    }

    /**
     * @return list<Tenant>
     */
    private function targets(): array
    {
        if ($this->option('all')) {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->get()->all();
        }

        $needle = $this->argument('tenant');

        if ($needle === null) {
            return [];
        }

        $tenant = Tenant::query()
            ->with('connection')
            ->where('slug', (string) $needle)
            ->orWhere('id', (string) $needle)
            ->first();

        return $tenant === null ? [] : [$tenant];
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KiB', 'MiB', 'GiB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GiB') {
                return sprintf('%.1f %s', $bytes, $unit);
            }

            $bytes = intdiv($bytes, 1024);
        }

        return $bytes.' B';
    }
}
