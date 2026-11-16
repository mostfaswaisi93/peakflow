<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exchange\Archive\Archive;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-checksum the archive against storage - PF-129.
 *
 * Exits non-zero on any divergence, because a scheduled run that only logs
 * is a run nobody reads. The thing being defended is specific: five years
 * from now somebody asks for a document, and the answer has to be the bytes
 * that were sent rather than an apology.
 */
final class ArchiveVerify extends Command
{
    protected $signature = 'archive:verify {tenant? : the tenant slug} {--all} {--period=}';

    protected $description = 'Re-checksum archived artefacts against storage and against their sealed manifest';

    public function handle(): int
    {
        $tenants = $this->option('all')
            ? Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->get()
            : Tenant::query()->where('slug', (string) $this->argument('tenant'))->get();

        if ($tenants->isEmpty()) {
            $this->components->error('No tenant matched. Pass a slug, or --all.');

            return self::FAILURE;
        }

        $problems = 0;

        foreach ($tenants as $tenant) {
            app(TenantConnectionResolver::class)->point($tenant);

            foreach (Entity::query()->orderBy('code')->get() as $entity) {
                $periods = $this->option('period') !== null && $this->option('period') !== ''
                    ? [(string) $this->option('period')]
                    : DB::connection('tenant')->table('archived_artefacts')
                        ->where('entity_id', (string) $entity->getKey())
                        ->distinct()
                        ->orderBy('period_label')
                        ->pluck('period_label')
                        ->map(static fn ($p): string => (string) $p)
                        ->all();

                if ($periods === []) {
                    $this->components->twoColumnDetail(
                        sprintf('%s / %s', (string) $tenant->slug, (string) $entity->code),
                        'nothing archived',
                    );

                    continue;
                }

                foreach ($periods as $period) {
                    $report = app(Archive::class)->verify((string) $entity->getKey(), $period);

                    $this->components->twoColumnDetail(
                        sprintf('%s / %s / %s', (string) $tenant->slug, (string) $entity->code, $period),
                        sprintf(
                            '%d checked, manifest %s',
                            $report['checked'],
                            $report['manifest'] ?? 'not sealed',
                        ),
                    );

                    foreach (['missing' => 'is not in storage', 'diverged' => 'does not hash to its row'] as $kind => $what) {
                        foreach ($report[$kind] as $hash) {
                            $problems++;
                            $this->components->error(sprintf('%s %s', substr((string) $hash, 0, 16), $what));
                        }
                    }

                    foreach ($report['removed'] as $hash) {
                        $problems++;
                        $this->components->error(sprintf(
                            '%s was in the sealed manifest and is no longer in the archive',
                            substr((string) $hash, 0, 16),
                        ));
                    }

                    foreach ($report['added'] as $hash) {
                        $problems++;
                        $this->components->error(sprintf(
                            '%s is in the archive and was not in the sealed manifest, so the period was '.
                            'sealed before it was complete',
                            substr((string) $hash, 0, 16),
                        ));
                    }

                    if ($report['manifest'] === 'diverged') {
                        $problems++;
                        $this->components->error(sprintf('the manifest for %s does not hash to its own list', $period));
                    }
                }
            }
        }

        if ($problems > 0) {
            $this->components->error(sprintf(
                '%d divergence%s. Five years from now somebody asks for a document, and the answer has '.
                'to be the bytes that were sent.',
                $problems,
                $problems === 1 ? '' : 's',
            ));

            return self::FAILURE;
        }

        $this->components->info('The archive agrees with storage and with its sealed manifests.');

        return self::SUCCESS;
    }
}
