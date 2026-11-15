<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Services\SodExceptionsReport;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prints the segregation-of-duties exceptions report - PF-034.
 *
 * Part of the `compliance:report` evidence pack, and runnable on its own so
 * that somebody can read it without assembling a pack.
 */
final class SodExceptions extends Command
{
    protected $signature = 'sod:exceptions
        {tenant : A tenant slug or id}
        {--entity= : Limit to one entity}
        {--from= : Start date (default: the first of this month)}
        {--to= : End date (default: today)}';

    protected $description = 'Report where segregation of duties was exercised, and where it was suspended';

    public function handle(TenantConnectionResolver $resolver, SodExceptionsReport $report): int
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

        $from = $this->option('from') === null ? null : Carbon::parse((string) $this->option('from'));
        $to = $this->option('to') === null ? null : Carbon::parse((string) $this->option('to'));

        /*
         | One report per entity, because both tables are entity-scoped by
         | row-level security. A single query across them would read nothing
         | at all and say so cheerfully, which for a compliance document is
         | the worst thing it could do.
         */
        $entities = Entity::query()
            ->when($this->option('entity') !== null, fn ($q) => $q->whereKey($this->option('entity')))
            ->orderBy('code')
            ->get();

        if ($entities->isEmpty()) {
            $this->components->warn('This tenant has no entities, so there is nothing to report on.');

            return self::SUCCESS;
        }

        foreach ($entities as $entity) {
            $this->reportOn($report, $tenant->name, $entity, $from, $to);
        }

        return self::SUCCESS;
    }

    private function reportOn(
        SodExceptionsReport $report,
        string $tenantName,
        Entity $entity,
        ?Carbon $from,
        ?Carbon $to,
    ): void {
        $data = $report->for((string) $entity->getKey(), $from, $to);

        $this->newLine();
        $this->components->info(sprintf(
            '%s · %s (%s) · %s to %s',
            $tenantName,
            $entity->legal_name,
            $entity->code,
            $data['from'],
            $data['to'],
        ));

        $this->newLine();
        $this->line('<options=bold>Where the control was exercised</>');

        if ($data['refusals']->isEmpty()) {
            $this->line('  No refusals in this period.');
            $this->line('  <comment>An empty list is only good news if conflicting duties were attempted and');
            $this->line('  separated by staffing. In a small team it more often means nobody tried.</comment>');
        } else {
            foreach ($data['refusals_by_rule'] as $rule => $count) {
                $this->line(sprintf('  %-32s %d', $rule, $count));
            }
        }

        $this->newLine();
        $this->line('<options=bold>Where the control was suspended</>');
        $this->line(sprintf(
            '  active %d · expired %d · revoked %d',
            $data['active_overrides'],
            $data['expired_overrides'],
            $data['revoked_overrides'],
        ));

        foreach ($data['overrides']->take(20) as $override) {
            $this->line(sprintf(
                '  %-28s %-18s until %s%s',
                (string) $override->rule?->key,
                (string) $override->user?->name,
                $override->expires_at->toDateString(),
                $override->revoked_at !== null ? ' (revoked)' : '',
            ));
        }

        if ($data['repeatedly_renewed']->isNotEmpty()) {
            $this->newLine();
            $this->components->warn('Overrides granted three or more times for the same user and rule');
            $this->line('  <comment>These are not exceptions any more. They describe how the company actually');
            $this->line('  works, and the control environment should say so rather than be suspended for it.</comment>');

            foreach ($data['repeatedly_renewed'] as $row) {
                $this->line(sprintf(
                    '  %-28s %-18s %d times since %s',
                    (string) $row->rule_key,
                    (string) $row->user_name,
                    (int) $row->times_granted,
                    (string) $row->first_granted_at,
                ));
            }
        }
    }
}
