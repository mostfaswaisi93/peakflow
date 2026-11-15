<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Services\Dunning;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Chase what is overdue - PF-108.
 *
 * ## Safe to run twice
 *
 * One event per invoice per step per day and one notice per customer per
 * day, both by unique index. Cron fires it daily, somebody runs it by hand
 * while looking into a complaint, a retry lands after a timeout - and the
 * customer is not chased twice in a morning.
 *
 * ## It reports per customer, not per invoice
 *
 * Because that is what was sent. A run that printed forty invoice numbers
 * would read as forty emails, and the point of the whole design is that it
 * was six statements.
 */
final class DunningRun extends Command
{
    protected $signature = 'dunning:run
        {tenant? : the tenant id or slug}
        {--all : every provisioned tenant}
        {--as-of= : pretend it is this date}';

    protected $description = 'Send the dunning notices that are due';

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

        $sent = 0;

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            foreach (Entity::query()->where('active', true)->orderBy('code')->get() as $entity) {
                $entities->use($entity);

                try {
                    $report = app(Dunning::class)->run($entity, $asOf);
                } catch (Throwable $e) {
                    $this->error(sprintf('  %s %s: %s', (string) $tenant->slug, (string) $entity->code, $e->getMessage()));

                    return self::FAILURE;
                }

                foreach ($report['notices'] as $notice) {
                    $sent++;

                    $this->line(sprintf(
                        '  <fg=yellow>notice</> %s %s — %d invoice(s), %s, step %d by %s',
                        (string) $tenant->slug,
                        $notice['party'],
                        $notice['invoices'],
                        $notice['total'],
                        $notice['step'],
                        $notice['channel'],
                    ));
                }

                foreach ($report['fees'] as $fee) {
                    $this->line(sprintf(
                        '  <fg=magenta>fee</>    %s %s on %s — %s',
                        (string) $tenant->slug,
                        $fee['document'],
                        $fee['invoice'],
                        $fee['fee'],
                    ));
                }

                foreach ($report['holds'] as $party) {
                    $this->line(sprintf('  <fg=red>hold</>   %s %s', (string) $tenant->slug, $party));
                }

                foreach ($report['skipped'] as $skip) {
                    $this->line(sprintf(
                        '  <fg=gray>skip</>   %s %s — %s',
                        (string) $tenant->slug,
                        $skip['invoice'],
                        $skip['why'],
                    ));
                }
            }
        }

        $this->info($sent === 0 ? 'Nothing was due.' : sprintf('%d notice(s) sent.', $sent));

        return self::SUCCESS;
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
