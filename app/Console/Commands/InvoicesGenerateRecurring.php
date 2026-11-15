<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Services\RecurringGenerator;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Draft the invoices a recurring schedule owes - PF-106.
 *
 * ## Safe to run twice, on purpose
 *
 * The occurrence table's unique index is what makes it safe, not this
 * command's care: cron fires it hourly, a second node fires it in the same
 * minute, somebody runs it by hand while investigating, and the result is one
 * invoice per schedule per occurrence date.
 *
 * ## It reports per occurrence
 *
 * Generated, skipped and failed, each named. A billing job that prints
 * "41 invoices generated" is a job nobody can check, and the occurrence that
 * failed is a customer who is not being invoiced.
 */
final class InvoicesGenerateRecurring extends Command
{
    protected $signature = 'invoices:generate-recurring
        {tenant? : the tenant id or slug}
        {--all : every provisioned tenant}
        {--as-of= : pretend it is this date, for a catch-up run}';

    protected $description = 'Draft the invoices due from recurring schedules';

    public function handle(TenantConnectionResolver $resolver, EntityContext $entities): int
    {
        $tenants = $this->tenants();

        if ($tenants === []) {
            $this->error('Name a tenant, or pass --all.');

            return self::FAILURE;
        }

        $asOf = $this->option('as-of') === null
            ? null
            : CarbonImmutable::parse((string) $this->option('as-of'))->startOfDay();

        $generated = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            foreach (Entity::query()->where('active', true)->orderBy('code')->get() as $entity) {
                $entities->use($entity);

                try {
                    $report = app(RecurringGenerator::class)->run((string) $entity->getKey(), $asOf);
                } catch (Throwable $e) {
                    $this->error(sprintf('  %s %s: %s', (string) $tenant->slug, (string) $entity->code, $e->getMessage()));

                    return self::FAILURE;
                }

                foreach ($report['generated'] as $line) {
                    $generated++;

                    $this->line(sprintf(
                        '  <fg=green>drafted</> %s %s %s — %s',
                        (string) $tenant->slug,
                        $line['schedule'],
                        $line['date'],
                        $line['gross'] ?? '',
                    ));
                }

                foreach ($report['skipped'] as $line) {
                    $this->line(sprintf(
                        '  <fg=gray>skipped</> %s %s %s — %s',
                        (string) $tenant->slug,
                        $line['schedule'],
                        $line['date'],
                        $line['error'] ?? '',
                    ));
                }

                foreach ($report['failed'] as $line) {
                    $failed++;

                    $this->line(sprintf(
                        '  <fg=red>failed</>  %s %s %s — %s%s',
                        (string) $tenant->slug,
                        $line['schedule'],
                        $line['date'],
                        $line['error'] ?? '',
                        ($line['permanent'] ?? 'no') === 'yes' ? ' (will not be retried)' : '',
                    ));
                }
            }
        }

        $this->info(sprintf('%d invoice(s) drafted, %d occurrence(s) failed.', $generated, $failed));

        /*
         | A failed occurrence is a non-zero exit.
         |
         | Because a customer who is not being invoiced is a problem whether
         | or not the other two hundred went out, and a job that exits zero
         | with failures in its output is a job whose output nobody reads.
         */
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<Tenant> */
    private function tenants(): array
    {
        if ((bool) $this->option('all')) {
            return Tenant::query()
                ->with('connection')
                ->whereNotNull('provisioned_at')
                ->orderBy('created_at')
                ->get()
                ->all();
        }

        $named = (string) ($this->argument('tenant') ?? '');

        if ($named === '') {
            return [];
        }

        $tenant = Tenant::query()
            ->with('connection')
            ->where('id', $named)
            ->orWhere('slug', $named)
            ->first();

        return $tenant === null ? [] : [$tenant];
    }
}
