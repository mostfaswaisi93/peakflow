<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Alerts\InvariantWatch;
use App\Support\Telemetry\Alerts\WatchScope;
use App\Support\Telemetry\Contracts\AlertSink;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * `invariants:watch` - the thing that was missing - PF-242.
 *
 * ## What this command is, in one sentence
 *
 * Every invariant it checks was already provable before it existed, and
 * nothing ran any of them. `routes/console.php` held one command and it
 * printed an inspiring quote.
 *
 * ## Why a scheduled command and not a pipeline step
 *
 * There is no deploy pipeline in this repository and the product owner has
 * asked for no GitHub Actions, so an alert cannot be "a workflow runs the
 * check". It also should not be: the failures these watch for do not happen
 * at deploy time. A balance read model drifts when a write path bypasses a
 * trigger, a privilege is granted by hand during an incident, an archive
 * rots in storage - all of them between deploys, and a check that ran only
 * on the next one would find them days later.
 *
 * So the schedule is in `routes/console.php`, which is where this product
 * schedules work, and the cron expression is configuration so a large fleet
 * can be slowed down without a deployment.
 *
 * ## One unreachable tenant must not stop the others
 *
 * The whole point of running this across a fleet is the one that is wrong,
 * and it may well be wrong because it is unreachable. A tenant that cannot
 * be pointed at becomes a `watch_failed` alert of its own and the run
 * continues - the same decision `audit:verify` made for the same reason.
 *
 * ## Non-zero on a critical alert, so the scheduler sees it too
 *
 * The sink is the primary path. The exit code is the second, and it is the
 * one that still works when the sink's destination is down: Laravel's
 * scheduler records a failed task, and `->onFailure()` in the schedule says
 * so out loud.
 */
final class InvariantsWatch extends Command
{
    protected $signature = 'invariants:watch
        {tenant? : A tenant slug or id}
        {--all : Watch every servable tenant}
        {--only= : One alert key, for a single condition}';

    protected $description = 'Watch the ledger, archive and privilege invariants, and alert on a breach';

    public function handle(
        InvariantWatch $watch,
        AlertSink $sink,
        TenantConnectionResolver $resolver,
    ): int {
        $tenants = $this->targets();

        if ($tenants === []) {
            $this->components->warn('No tenants matched. Name a tenant, or pass --all.');

            return self::SUCCESS;
        }

        if ($watch->all() === []) {
            /*
             | A run with no watchers registered is a FAILURE, not a quiet
             | success.
             |
             | It is the exact state this whole task was written after
             | finding: the configuration declared ten invariant alerts and
             | nothing raised any of them, and a green run every hour is what
             | that state looks like from outside.
             */
            $this->components->error(
                'No invariant watcher is registered, so this run proved nothing. A watch with nothing '.
                'in it reports healthy every hour, which is indistinguishable from one that works.',
            );

            return self::FAILURE;
        }

        $critical = 0;
        $raised = 0;

        foreach ($tenants as $tenant) {
            $slug = (string) $tenant->slug;

            try {
                $resolver->point($tenant);
            } catch (Throwable $e) {
                $critical++;
                $this->raise($sink, Alert::critical(
                    key: 'watch_failed',
                    tenant: $slug,
                    entityId: Alert::NO_ENTITY,
                    period: Alert::NO_PERIOD,
                    firstDivergence: sprintf('the tenant connection could not be pointed: %s', $e->getMessage()),
                    messageAr: sprintf(
                        'تعذّر الوصول إلى قاعدة بيانات المستأجر %s، فلم يُفحَص أي ثابت لديه هذه الساعة.',
                        $slug,
                    ),
                    messageEn: sprintf(
                        'The tenant database for %s could not be reached, so none of its invariants was '.
                        'checked this hour. An unreachable tenant is the one most likely to be the one '.
                        'that is wrong.',
                        $slug,
                    ),
                ));

                continue;
            }

            foreach ($watch->inScope(WatchScope::Tenant) as $watcher) {
                if (! $this->wanted($watcher->keys())) {
                    continue;
                }

                foreach ($watch->run($watcher, $slug, null) as $alert) {
                    $raised++;
                    $critical += $alert->isCritical() ? 1 : 0;
                    $this->raise($sink, $alert);
                }
            }

            /*
             | Entities listed OUTSIDE any entity scope, then established one
             | at a time.
             |
             | The same ordering `ledger:verify` uses, and for the same
             | reason: the point is to check all of them, and the per-entity
             | checks then run with row-level security seeing the entity they
             | are about.
             */
            $entities = Entity::query()->orderBy('code')->get();

            foreach ($entities as $entity) {
                app(EntityContext::class)->use($entity);

                foreach ($watch->inScope(WatchScope::Entity) as $watcher) {
                    if (! $this->wanted($watcher->keys())) {
                        continue;
                    }

                    foreach ($watch->run($watcher, $slug, (string) $entity->getKey()) as $alert) {
                        $raised++;
                        $critical += $alert->isCritical() ? 1 : 0;
                        $this->raise($sink, $alert);
                    }
                }
            }

            app(EntityContext::class)->forget();

            if ($raised === 0) {
                $this->components->twoColumnDetail($slug, '<fg=green>every invariant holds</>');
            }
        }

        if ($critical > 0) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d critical invariant alert(s) raised. Each names the entity, the period and the '.
                'first divergent record, and each has a runbook entry in '.
                'docs/operations/runbooks.md.',
                $critical,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function raise(AlertSink $sink, Alert $alert): void
    {
        $sink->raise($alert);

        $this->newLine();
        $this->line(sprintf(
            '  <fg=%s;options=bold>%s</>',
            $alert->isCritical() ? 'red' : 'yellow',
            $alert->key,
        ));
        $this->line(sprintf('    entity %s · period %s', $alert->entityId, $alert->period));
        $this->line(sprintf('    <comment>first divergence:</comment> %s', $alert->firstDivergence));
        $this->line(sprintf('    %s', $alert->messageEn));
        $this->line(sprintf('    <fg=gray>runbook:</> %s', $alert->runbook()));
    }

    /** @param list<string> $keys */
    private function wanted(array $keys): bool
    {
        $only = $this->option('only');

        if ($only === null || $only === '') {
            return true;
        }

        return in_array((string) $only, $keys, true);
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        if ($this->option('all')) {
            return Tenant::query()->with('connection')->whereHas('connection')->orderBy('slug')->get()->all();
        }

        $needle = $this->argument('tenant');

        if ($needle === null) {
            return [];
        }

        $tenant = Tenant::query()
            ->with('connection')
            ->whereHas('connection')
            ->where(static fn ($q) => $q->where('slug', $needle)->orWhere('id', $needle))
            ->first();

        return $tenant === null ? [] : [$tenant];
    }
}
