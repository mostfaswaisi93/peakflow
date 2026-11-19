<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Platform\Services\TenantExport;
use Illuminate\Console\Command;

/**
 * Hand a tenant everything it owns - PF-239.
 *
 * ## Why a directory and not a zip
 *
 * The thing a tenant asks for is portability, and a directory of
 * line-delimited JSON is more portable than an archive format: it can be
 * read with `head`, streamed into anything, and zipped by whoever wants it
 * zipped. Producing a zip here would mean holding the whole export in memory
 * or shelling out, and neither is better than leaving the choice to the
 * person who knows where it is going.
 *
 * ## It prints the manifest rather than the files
 *
 * An export of a real tenant writes a few hundred files. What an operator
 * needs on the console is the shape - how many tables, how many rows, and
 * whether any attachment's object was missing - because that is what they
 * check before they hand it over.
 */
final class TenantsExport extends Command
{
    protected $signature = 'tenants:export
        {tenant : A tenant slug or id}
        {--out= : The directory to write into (default storage/app/exports/<slug>)}';

    protected $description = 'Write everything a tenant owns as line-delimited JSON, with its attachments';

    public function handle(TenantConnectionResolver $resolver, TenantExport $export): int
    {
        $tenant = Tenant::query()
            ->with('connection')
            ->whereHas('connection')
            ->where(fn ($q) => $q->where('slug', $this->argument('tenant'))->orWhere('id', $this->argument('tenant')))
            ->first();

        if ($tenant === null) {
            $this->components->error('No such tenant.');

            return self::FAILURE;
        }

        $resolver->point($tenant);

        $directory = (string) ($this->option('out') ?? '');

        if ($directory === '') {
            $directory = storage_path('app/exports/'.(string) $tenant->slug);
        }

        $manifest = $export->write($tenant, $directory);

        $rows = array_sum(array_column($manifest['tables'], 'rows'));
        $withRows = array_filter($manifest['tables'], static fn (array $t): bool => $t['rows'] > 0);

        $this->components->info(sprintf(
            '%s: %d tables (%d with rows), %d rows, %d attachment object(s) -> %s',
            (string) $tenant->slug,
            \count($manifest['tables']),
            \count($withRows),
            $rows,
            (int) $manifest['attachments']['written'],
            $directory,
        ));

        $missing = $manifest['attachments']['missing'];

        if ($missing !== []) {
            /*
             | Non-zero, because an incomplete export that reports success is
             | the one failure mode an export must not have. The archive
             | looks complete and a document's evidence is absent, and the
             | person who finds out is whoever needed the file.
             */
            $this->components->error(sprintf(
                '%d attachment row(s) have no object on the disk: %s',
                \count($missing),
                implode(', ', \array_slice($missing, 0, 5)),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
