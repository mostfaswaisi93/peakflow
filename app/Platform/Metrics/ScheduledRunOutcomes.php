<?php

declare(strict_types=1);

namespace App\Platform\Metrics;

use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the scheduler did in the last day, by task - PF-241.
 *
 * ## Both outcomes, not only the failures
 *
 * PF-241 asks for "failed scheduled runs by task", and reporting only
 * those would answer the wrong question. A task missing from a list of
 * failures looks exactly like a task that succeeded - and it is also what a
 * task that never ran looks like, which is the condition
 * `invariants:watch` exists to make impossible for every OTHER invariant in
 * the product and had no answer for itself.
 *
 * So the sample is labelled by outcome, and `ran: 0` for a task that should
 * have run is a reading somebody can act on.
 *
 * ## A day, because the shortest cadence is hourly
 *
 * A window shorter than the longest gap between runs reports zero for
 * every daily job most of the time, and a metric that is usually zero for
 * a working system is a metric nobody reads. A day covers every schedule
 * in `config/schedule.php` except the two weekly ones, and those are
 * reported as zero until they run - which is correct and visible rather
 * than hidden by a longer window.
 */
final class ScheduledRunOutcomes implements MetricSource
{
    public function key(): string
    {
        return 'scheduled_runs';
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        return [Audience::Operator, Audience::Engineer];
    }

    public function scope(): MetricScope
    {
        return MetricScope::Platform;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return ['job', 'state'];
    }

    public function unit(): string
    {
        return 'count';
    }

    public function describe(string $locale): string
    {
        return $locale === 'ar'
            ? 'تشغيلات الأعمال المجدولة في آخر يوم، حسب المهمة ونتيجتها'
            : 'Scheduled runs in the last day, by task and outcome';
    }

    /** @return list<MetricSample> */
    public function collect(?string $entityId): array
    {
        unset($entityId);

        if (! Schema::connection('platform')->hasTable('scheduled_runs')) {
            return [];
        }

        $since = Carbon::now()->subDay();

        $rows = DB::connection('platform')->table('scheduled_runs')
            ->where('finished_at', '>=', $since)
            ->selectRaw("command, CASE WHEN exit_code = 0 THEN 'succeeded' WHEN exit_code IS NULL THEN 'unknown' ELSE 'failed' END AS state, count(*) AS runs")
            ->groupBy('command', 'state')
            ->orderBy('command')
            ->get();

        $samples = [];

        foreach ($rows as $row) {
            $samples[] = MetricSample::count([
                /*
                 | The command, not the full line.
                 |
                 | The recorder already strips the php binary and the
                 | artisan path. A label carrying either would make the same
                 | job look like two the first time a deployment moves the
                 | binary, and a metric whose cardinality grows with
                 | deployments is one that breaks a collector.
                 */
                'job' => (string) $row->command,
                'state' => (string) $row->state,
            ], (int) $row->runs);
        }

        return $samples;
    }
}
