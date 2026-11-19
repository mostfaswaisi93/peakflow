<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Telemetry\Contracts\MetricSink;
use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\Metrics;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Console\Command;

/**
 * `metrics:report` - one dashboard per audience - PF-241.
 *
 * ## Why a command and not only a screen
 *
 * Because the figures have to leave the process. Every sample is published
 * through `MetricSink`, whose committed default writes one JSON line on the
 * channel the allowlist taps - so a collector that already reads the log
 * reads the metrics, with no gateway to stand up and no credentials. The
 * console table is for a person reading it now; the sink is for the thing
 * watching at 03:00.
 *
 * Scheduled in `routes/console.php`, which is the only place this product
 * schedules anything.
 *
 * ## The dashboards are DERIVED
 *
 * `--audience=controller` shows the metrics whose sources name that audience.
 * There is no list of dashboards anywhere: a metric appears on the right ones
 * by saying who it is for, and `Metrics::register()` refuses one that names
 * nobody. A written-out dashboard definition would be the thing that drifts.
 *
 * ## A metric that could not be computed is REPORTED, never zero
 *
 * "Unmatched bank lines: 0" because the query failed is the most dangerous
 * figure on the page: it is the one somebody acts on by doing nothing. The
 * registry turns a throwing source into a stated error and the command exits
 * non-zero on any of them.
 */
final class MetricsReport extends Command
{
    protected $signature = 'metrics:report
        {tenant? : A tenant slug or id}
        {--all : Every servable tenant}
        {--audience= : operator, controller or engineer; all three when absent}';

    protected $description = 'Collect and publish the business metrics, per audience';

    public function handle(
        Metrics $metrics,
        MetricSink $sink,
        TenantConnectionResolver $resolver,
    ): int {
        $audiences = $this->audiences();

        if ($audiences === []) {
            $this->components->error(sprintf(
                'Unknown audience "%s". One of: %s.',
                (string) $this->option('audience'),
                implode(', ', array_map(static fn (Audience $a): string => $a->value, Audience::all())),
            ));

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($audiences as $audience) {
            $this->newLine();
            $this->components->info(sprintf('%s · %s', $audience->describe('en'), $audience->describe('ar')));

            $sources = $metrics->forAudience($audience);

            if ($sources === []) {
                $this->components->warn(sprintf(
                    'No metric names the %s audience, so that dashboard is empty. A dashboard with '.
                    'nothing on it is read once.',
                    $audience->value,
                ));

                $failures++;

                continue;
            }

            foreach ($sources as $source) {
                if ($source->scope() === MetricScope::Platform) {
                    $failures += $this->render($metrics, $sink, $source, null, []);
                }
            }

            foreach ($this->targets() as $tenant) {
                $resolver->point($tenant);

                $entities = Entity::query()->orderBy('code')->get();

                foreach ($entities as $entity) {
                    app(EntityContext::class)->use($entity);

                    foreach ($sources as $source) {
                        if ($source->scope() !== MetricScope::Entity) {
                            continue;
                        }

                        $failures += $this->render($metrics, $sink, $source, (string) $entity->getKey(), [
                            'tenant_id' => (string) $tenant->getKey(),
                            'entity_id' => (string) $entity->getKey(),
                        ]);
                    }
                }

                app(EntityContext::class)->forget();
            }
        }

        if ($failures > 0) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d metric(s) could not be computed. A figure nobody could compute must not read as '.
                'zero: that is the one number somebody acts on by doing nothing.',
                $failures,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string|null>  $scope
     * @return int  the number of failures
     */
    private function render(
        Metrics $metrics,
        MetricSink $sink,
        MetricSource $source,
        ?string $entityId,
        array $scope,
    ): int {
        $result = $metrics->collect($source, $entityId);

        if ($result['error'] !== null) {
            $this->components->error(sprintf('%s: %s', $source->key(), $result['error']));

            return 1;
        }

        if ($result['samples'] === []) {
            $this->components->twoColumnDetail(
                sprintf('<fg=gray>%s</>', $source->key()),
                '<fg=gray>nothing to report</>',
            );

            return 0;
        }

        $this->line(sprintf('  <options=bold>%s</> <fg=gray>%s</>', $source->key(), $source->describe('en')));

        foreach ($result['samples'] as $sample) {
            /** @var MetricSample $sample */
            $sink->publish($source->key(), $source->unit(), $sample, $scope);

            $this->components->twoColumnDetail(
                '    '.$sample->labelled(),
                sprintf('%s <fg=gray>%s</>', $sample->value, $source->unit()),
            );
        }

        return 0;
    }

    /** @return list<Audience> */
    private function audiences(): array
    {
        $named = $this->option('audience');

        if ($named === null || $named === '') {
            return Audience::all();
        }

        $audience = Audience::tryFrom((string) $named);

        return $audience === null ? [] : [$audience];
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
