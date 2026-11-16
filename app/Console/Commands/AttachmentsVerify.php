<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Attachments\AttachmentStore;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Console\Command;

/**
 * Re-hash every stored attachment against its row - PF-073.
 *
 * ## Why this is a command and not a trust in storage
 *
 * The row says the object is there and hashes to a value. Storage loses
 * objects, a restore puts back an older version, and a migration between
 * buckets copies 99.99% of them. None of those announce themselves, and the
 * first symptom is a tax inspector asking for a contract.
 *
 * It exits non-zero on any divergence, so a scheduled run is an alert rather
 * than a log line nobody reads.
 */
final class AttachmentsVerify extends Command
{
    protected $signature = 'attachments:verify {tenant? : the tenant slug} {--all}';

    protected $description = 'Re-hash every stored attachment against its row and report divergence';

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
                $report = app(AttachmentStore::class)->verify((string) $entity->getKey());

                $this->components->twoColumnDetail(
                    sprintf('%s / %s', (string) $tenant->slug, (string) $entity->code),
                    sprintf('%d checked', $report['checked']),
                );

                foreach (['missing' => 'is not in storage', 'diverged' => 'does not hash to its row'] as $kind => $what) {
                    foreach ($report[$kind] as $hash) {
                        $problems++;
                        $this->components->error(sprintf('%s %s', $hash, $what));
                    }
                }

                foreach ($report['unscanned'] as $hash) {
                    $this->components->warn(sprintf(
                        '%s has not been found clean, so it is stored and unreadable',
                        $hash,
                    ));
                }
            }
        }

        if ($problems > 0) {
            $this->components->error(sprintf(
                '%d object%s diverged. A stored attachment is a tax document, and the first symptom of '.
                'a lost one is an inspector asking for it.',
                $problems,
                $problems === 1 ? '' : 's',
            ));

            return self::FAILURE;
        }

        $this->components->info('Every stored attachment hashes to its row.');

        return self::SUCCESS;
    }
}
