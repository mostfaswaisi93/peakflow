<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Services\RevenueRecognition;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Release deferred revenue that has been earned - PF-107.
 *
 * ## Re-runnable, and it says what it skipped
 *
 * The plan rows already recognised are not posted again - the unique index
 * and the row's own status are what make that true - and the run reports the
 * count rather than staying silent, because a reporting command that
 * double-posts is worse than one that fails: the figures look plausible.
 *
 * ## It reports what it deferred as well as what it recognised
 *
 * The closing deferral is the number somebody has to reconcile to the control
 * account, and a run that printed only what it released would leave them to
 * work it out from a report they have not run yet.
 */
final class RevenueRecognize extends Command
{
    protected $signature = 'revenue:recognize
        {period : the date to recognise up to, inclusive - 2026-05-31}
        {tenant? : the tenant id or slug}
        {--all : every provisioned tenant}
        {--preview : show what would be recognised and post nothing}';

    protected $description = 'Release deferred revenue earned on or before a date';

    public function handle(TenantConnectionResolver $resolver, EntityContext $entities): int
    {
        $upTo = CarbonImmutable::parse((string) $this->argument('period'))->startOfDay();
        $tenants = $this->tenants();

        if ($tenants === []) {
            $this->error('Name a tenant, or pass --all.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $resolver->point($tenant);

            foreach (Entity::query()->where('active', true)->orderBy('code')->get() as $entity) {
                $entities->use($entity);

                try {
                    if ((bool) $this->option('preview')) {
                        $preview = app(RevenueRecognition::class)->preview(
                            (string) $entity->getKey(),
                            $upTo,
                            (string) $entity->functional_currency,
                        );

                        $this->line(sprintf(
                            '  %s %s — %d row(s) due, %s, leaving %s deferred',
                            (string) $tenant->slug,
                            (string) $entity->code,
                            \count($preview['due']),
                            $preview['total'],
                            $preview['deferred_after'],
                        ));

                        continue;
                    }

                    $report = app(RevenueRecognition::class)->recognise($entity, $upTo);

                    $this->line(sprintf(
                        '  %s %s %s — recognised %s across %d row(s)%s, %s still deferred%s',
                        (string) $tenant->slug,
                        (string) $entity->code,
                        $report['period'],
                        $report['recognised'],
                        $report['rows'],
                        $report['skipped'] === 0
                            ? ''
                            : sprintf(' (%d already recognised, skipped)', $report['skipped']),
                        $report['deferred_after'],
                        $report['entry'] === null ? '' : sprintf(' — entry %s', $report['entry']),
                    ));
                } catch (Throwable $e) {
                    $this->error(sprintf(
                        '  %s %s: %s',
                        (string) $tenant->slug,
                        (string) $entity->code,
                        $e->getMessage(),
                    ));

                    return self::FAILURE;
                }
            }
        }

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
