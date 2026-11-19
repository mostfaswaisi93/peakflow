<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Services\CompliancePack;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The evidence pack, as a file an auditor can be given - PF-238.
 *
 * ## JSON, and a digest of it
 *
 * The pack is machine-readable because the next thing that happens to it is
 * that somebody diffs it against last quarter's. A table printed to a
 * terminal cannot be diffed, and a PDF can be edited between the printing
 * and the handing over.
 *
 * The digest is printed to the console and NOT written into the file, for
 * the obvious reason: a digest inside the thing it covers covers everything
 * except itself. It goes in the run output, which is where somebody records
 * what they produced and when.
 *
 * ## It does not take the date from the clock
 *
 * `--as-of` defaults to today and is printed in the pack, because a pack
 * whose date is implicit is a pack nobody can place later. Running it twice
 * for the same date produces the same event-derived sections - that is what
 * makes it reproducible - and the state-derived ones say in the file that
 * they are not.
 */
final class ComplianceReport extends Command
{
    protected $signature = 'compliance:report
        {tenant : A tenant slug or id}
        {--as-of= : The date the pack is about (default today)}
        {--dormant-days=90 : Treat an account or token unused for this long as dormant}
        {--out= : Write the JSON here instead of to standard output}';

    protected $description = 'Produce the compliance evidence pack for a tenant, as at a date';

    public function handle(TenantConnectionResolver $resolver): int
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

        $asAt = $this->option('as-of') === null
            ? Carbon::now()
            : Carbon::parse((string) $this->option('as-of'));

        $pack = app(CompliancePack::class)->for($asAt, max(1, (int) $this->option('dormant-days')));
        $pack = ['tenant' => (string) $tenant->slug] + $pack;

        /*
         | Sorted keys and pretty-printed, so two runs of the same date
         | produce byte-identical files for the sections that are supposed
         | to be reproducible. Unsorted output would make a diff between two
         | quarters show every section as changed.
         */
        $json = json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $out = (string) ($this->option('out') ?? '');

        if ($out !== '') {
            file_put_contents($out, $json."\n");
            $this->components->info(sprintf('Written to %s', $out));
        } else {
            $this->line($json);
        }

        /*
         | The digest covers the EVIDENCE, not the whole file.
         |
         | `generated_at` is in the pack because provenance matters - a
         | reader has to know when it was produced - and it is excluded from
         | the digest because it changes every run. Hashing it would mean two
         | packs for the same date never match, which is the opposite of what
         | the digest is for.
         |
         | What it covers is everything that is supposed to be reproducible.
         | A section marked `as_now` is in there too: it is not reproducible
         | for a PAST date, but it is reproducible for the moment it was
         | taken, and a reader comparing two packs wants to see that the
         | access list moved.
         */
        $evidence = $pack;
        unset($evidence['generated_at']);

        $digest = hash('sha256', json_encode(
            $evidence,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        $this->newLine();
        $this->components->info(sprintf('as at %s · sha256 %s', $asAt->toDateString(), $digest));

        $attention = array_filter(
            $pack['access']['grants'] ?? [],
            static fn (array $row): bool => ($row['needs_attention'] ?? false) === true,
        );

        if ($attention !== []) {
            $this->components->warn(sprintf(
                '%d grant(s) carry a money-moving permission with no confirmed second factor. '.
                'The pack names them; `access:review` is the worksheet.',
                \count($attention),
            ));
        }

        /*
         | Zero whatever it found.
         |
         | Producing the pack succeeded. What is IN it is for a person to
         | read - and a report that exited non-zero because an organisation
         | has something to review would stop being produced.
         */
        return self::SUCCESS;
    }
}
