<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Numbering\NumberFormat;
use App\Tenant\Ledger\Services\NumberSeriesReport;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;

/**
 * `numbering:gaps` - the report an auditor asks for - PF-071.
 *
 * ## Why it exits non-zero on a gap
 *
 * A gap means a document number was allocated and the document was not
 * written, and the only honest answer to "where is invoice 2,417" is to
 * explain what happened. Scheduling this and alerting on the exit code is
 * what makes the explanation available while somebody still remembers the
 * incident, rather than eighteen months later in a meeting.
 *
 * ## It also reports headroom
 *
 * A format is frozen once the series has issued, so a `{seq:3}` series that
 * reaches 999 in October cannot be widened - the only remedy is a new series,
 * which is a decision somebody wants weeks of warning about rather than an
 * error at the moment of issuing.
 */
final class NumberingGaps extends Command
{
    protected $signature = 'numbering:gaps
        {tenant? : A tenant slug or id}
        {--all : Every servable tenant}
        {--list : List the missing numbers, not just the count}';

    protected $description = 'Check every numbering series for gaps, and record the result';

    public function handle(TenantConnectionResolver $resolver, NumberSeriesReport $report): int
    {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched.');

            return self::SUCCESS;
        }

        $gapped = 0;
        $tight = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            foreach (Entity::query()->orderBy('code')->get() as $entity) {
                app(EntityContext::class)->use($entity);

                $lines = $report->check((string) $entity->getKey());

                if ($lines === []) {
                    $this->components->twoColumnDetail(
                        sprintf('%s · %s', $tenant->slug, $entity->code),
                        '<fg=gray>no series yet</>',
                    );

                    continue;
                }

                $this->newLine();
                $this->line(sprintf('  <options=bold>%s · %s</>', $tenant->slug, $entity->code));

                foreach ($lines as $line) {
                    $gapped += $line['status'] === 'gapped' ? 1 : 0;

                    $colour = match ($line['status']) {
                        'clean' => 'green',
                        'gapped' => 'red',
                        'untracked' => 'yellow',
                        default => 'gray',
                    };

                    $this->line(sprintf(
                        '    <fg=%s>%-9s</> %-8s %-28s %s',
                        $colour,
                        $line['status'],
                        $line['key'],
                        $line['format'],
                        $line['detail'],
                    ));

                    /*
                     | Ten per cent, or fewer than a hundred left.
                     |
                     | Both, because the two matter at different sizes: ten
                     | per cent of a six-digit series is 100,000 numbers and
                     | not urgent, while 90 left on a three-digit series is.
                     */
                    if ($line['headroom'] !== null
                        && ($line['headroom'] < 100 || $line['headroom'] < ($line['ceiling'] ?? 0) / 10)) {
                        $tight++;

                        $this->line(sprintf(
                            '    <fg=yellow>headroom</>  %-8s %d of %d numbers left, and the format cannot be '.
                            'widened once a series has issued - plan a new series.',
                            $line['key'],
                            $line['headroom'],
                            $line['ceiling'],
                        ));
                    }

                    if ($this->option('list') && $line['status'] === 'gapped') {
                        $missing = $report->missingNumbers(
                            (string) $entity->getKey(),
                            $line['scope'],
                            $line['key'],
                            $line['year'],
                        );

                        $this->line(sprintf(
                            '    <fg=red>missing</>   %s',
                            $missing === [] ? '(the count disagrees but every number has a document)'
                                : implode(', ', array_map(
                                    static fn (int $n): string => NumberFormat::of($line['format'])->padding() > 0
                                        ? str_pad((string) $n, NumberFormat::of($line['format'])->padding(), '0', STR_PAD_LEFT)
                                        : (string) $n,
                                    $missing,
                                )),
                        ));
                    }
                }
            }

            app(EntityContext::class)->forget();
        }

        $this->newLine();

        if ($gapped > 0) {
            $this->components->error(sprintf(
                '%d series have a gap. A missing number is indistinguishable from a deleted document, so each '.
                'one needs an explanation recorded while somebody still remembers the incident.',
                $gapped,
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Every series is consecutive%s.',
            $tight > 0 ? sprintf(' (%d close to the end of their format)', $tight) : '',
        ));

        return self::SUCCESS;
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        if ($this->option('all')) {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->get()->all();
        }

        $given = $this->argument('tenant');

        if ($given === null) {
            $this->components->error('Name a tenant, or pass --all.');

            return [];
        }

        $tenant = Tenant::query()->with('connection')
            ->where('slug', $given)->orWhere('id', $given)->first();

        return $tenant === null ? [] : [$tenant];
    }
}
