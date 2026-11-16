<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exchange\Archive\Archive;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Console\Command;

/**
 * Restore a sample out of the archive and check it - PF-129.
 *
 * ## A backup nobody has restored is a hope
 *
 * The hashes in the table prove the rows agree with themselves. This proves
 * the bytes come back out of storage and still hash to what the row says,
 * which is what a tax inspector's request actually exercises - five years
 * later, after a bucket migration nobody remembers.
 *
 * Every run is recorded, passing or failing, so "when did we last prove the
 * archive restores" has an answer that is not somebody's recollection.
 */
final class ArchiveDrill extends Command
{
    protected $signature = 'archive:drill {tenant? : the tenant slug} {--all} {--sample=}';

    protected $description = 'Restore a random sample from the archive, verify it, and record the outcome';

    public function handle(): int
    {
        $tenants = $this->option('all')
            ? Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->get()
            : Tenant::query()->where('slug', (string) $this->argument('tenant'))->get();

        if ($tenants->isEmpty()) {
            $this->components->error('No tenant matched. Pass a slug, or --all.');

            return self::FAILURE;
        }

        $sample = (int) ($this->option('sample') ?? config('archive.drill_sample', 10));
        $failed = 0;

        foreach ($tenants as $tenant) {
            app(TenantConnectionResolver::class)->point($tenant);

            foreach (Entity::query()->orderBy('code')->get() as $entity) {
                $drill = app(Archive::class)->drill((string) $entity->getKey(), $sample);

                $this->components->twoColumnDetail(
                    sprintf('%s / %s', (string) $tenant->slug, (string) $entity->code),
                    sprintf(
                        '%s - %d of %d restored',
                        $drill['outcome'],
                        $drill['restored'],
                        $drill['sampled'],
                    ),
                );

                if ($drill['outcome'] === 'failed') {
                    $failed++;
                    $this->components->error((string) $drill['detail']);
                }

                if ($drill['outcome'] === 'empty') {
                    $this->components->warn('nothing is archived, so the drill proved nothing');
                }
            }
        }

        if ($failed > 0) {
            $this->components->error(sprintf(
                '%d drill%s failed. A backup nobody has restored is a hope, and this is the run that '.
                'was supposed to find out before an inspector did.',
                $failed,
                $failed === 1 ? '' : 's',
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
