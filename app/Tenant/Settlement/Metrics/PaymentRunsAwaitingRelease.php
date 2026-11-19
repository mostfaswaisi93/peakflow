<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Metrics;

use App\Support\Telemetry\Metrics\AgeBucket;
use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Work that stopped half way through the highest-risk flow - PF-241.
 *
 * A prepared payment run is a set of beneficiaries, amounts and bank details
 * that one person has assembled and nobody has approved. It is the state the
 * maker-checker control creates on purpose, and it is also the state where a
 * run sits forgotten while suppliers go unpaid and somebody re-keys the same
 * payments by hand - which is how a payment gets made twice.
 *
 * ## Why the age and not just the count
 *
 * Because a run prepared this morning is the control working. A run prepared
 * eleven days ago is a control nobody is operating, and the two are the same
 * number on a count.
 */
final class PaymentRunsAwaitingRelease implements MetricSource
{
    public function key(): string
    {
        return 'payment_runs_awaiting_release';
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        return [Audience::Controller, Audience::Operator];
    }

    public function scope(): MetricScope
    {
        return MetricScope::Entity;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return ['entity_id', 'age_bucket'];
    }

    public function unit(): string
    {
        return 'count';
    }

    public function describe(string $locale): string
    {
        return $locale === 'ar'
            ? 'دفعات مُعدّة ولم تُعتمد بعد، بحسب عمرها'
            : 'Payment runs prepared but not released, by age';
    }

    /** @return list<MetricSample> */
    public function collect(?string $entityId): array
    {
        // One clock for every age bucket in this product - see
        // UnmatchedBankLines for what measuring against `current_date` cost.
        $asOf = Carbon::now()->toDateString();

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                r.entity_id,
                (cast(? AS date) - cast(coalesce(r.prepared_at, r.created_at) AS date)) AS age_days,
                count(*) AS runs
            FROM payment_runs r
            WHERE r.state = 'prepared'
              AND (cast(? AS char(26)) IS NULL OR r.entity_id = cast(? AS char(26)))
            GROUP BY 1, 2
        SQL, [$asOf, $entityId, $entityId]);

        $buckets = [];

        foreach ($rows as $row) {
            $key = (string) $row->entity_id.'|'.AgeBucket::of((int) $row->age_days);
            $buckets[$key] = ($buckets[$key] ?? 0) + (int) $row->runs;
        }

        $samples = [];

        foreach ($buckets as $key => $count) {
            [$entity, $bucket] = explode('|', $key, 2);

            $samples[] = MetricSample::count([
                'entity_id' => $entity,
                'age_bucket' => $bucket,
            ], $count);
        }

        return $samples;
    }
}
