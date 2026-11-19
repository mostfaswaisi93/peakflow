<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Metrics;

use App\Support\Telemetry\Metrics\AgeBucket;
use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An approval chain with nobody in it - PF-241.
 *
 * ## Why this is a metric and not a screen
 *
 * There is already a screen: whoever has to approve a bill sees it in their
 * queue. The failure this watches is the one that screen cannot show -
 * nobody is looking at it. A delegate on leave, a role nobody holds in that
 * entity, an approval limit that routes to a person who left: in every case
 * the request is correctly queued, correctly visible, and ages.
 *
 * The age is the whole signal. Four requests pending today is an afternoon's
 * work; one request pending for forty days is a chain with nobody in it, and
 * a count cannot distinguish them.
 *
 * ## The subject TYPE is a label and the subject id is not
 *
 * "Which kind of document is stuck" is the actionable question - bills route
 * differently from requisitions and from expense claims, so a backlog in one
 * and not the others names the rule that is wrong. One series per document
 * would be a chart with a thousand lines and a list of what every supplier
 * has invoiced on somebody's dashboard.
 */
final class DocumentsAwaitingApproval implements MetricSource
{
    public function key(): string
    {
        return 'documents_awaiting_approval';
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        return [Audience::Controller];
    }

    public function scope(): MetricScope
    {
        return MetricScope::Entity;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return ['entity_id', 'document_type', 'age_bucket'];
    }

    public function unit(): string
    {
        return 'count';
    }

    public function describe(string $locale): string
    {
        return $locale === 'ar'
            ? 'مستندات تنتظر الاعتماد، بحسب عمرها'
            : 'Documents awaiting approval, by age';
    }

    /** @return list<MetricSample> */
    public function collect(?string $entityId): array
    {
        // One clock for every age bucket in this product - see
        // UnmatchedBankLines for what measuring against `current_date` cost.
        $asOf = Carbon::now()->toDateString();

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                a.entity_id,
                a.subject_type,
                (cast(? AS date) - cast(a.requested_at AS date)) AS age_days,
                count(*) AS requests
            FROM approval_requests a
            WHERE a.status = 'pending'
              AND a.decided_at IS NULL
              AND (cast(? AS char(26)) IS NULL OR a.entity_id = cast(? AS char(26)))
            GROUP BY 1, 2, 3
        SQL, [$asOf, $entityId, $entityId]);

        $buckets = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                (string) $row->entity_id,
                (string) $row->subject_type,
                AgeBucket::of((int) $row->age_days),
            ]);

            $buckets[$key] = ($buckets[$key] ?? 0) + (int) $row->requests;
        }

        $samples = [];

        foreach ($buckets as $key => $count) {
            [$entity, $type, $bucket] = explode('|', $key, 3);

            $samples[] = MetricSample::count([
                'entity_id' => $entity,
                'document_type' => $type,
                'age_bucket' => $bucket,
            ], $count);
        }

        return $samples;
    }
}
