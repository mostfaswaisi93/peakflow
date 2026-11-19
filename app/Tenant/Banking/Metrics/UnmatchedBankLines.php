<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Metrics;

use App\Support\Telemetry\Metrics\AgeBucket;
use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bank lines nothing accounts for, by age - PF-241.
 *
 * ## The leading indicator
 *
 * A reconciliation that is drifting does not announce itself: the statement
 * imports, the engine matches most of it, and the remainder grows. By the
 * time somebody notices, the oldest unmatched line is in a period that has
 * closed, and what it was is no longer recoverable from anybody's memory.
 *
 * The AGE is the signal, not the count. Forty lines unmatched today is a
 * morning's work; four lines unmatched for sixty days is a process that has
 * stopped, and the count alone cannot tell them apart.
 *
 * ## A line matched and then REVERSED is unmatched again
 *
 * Which is the half that gets left out, and the half that matters: a reversal
 * is how a wrong match is undone, so the lines that have been matched
 * incorrectly and sent back are exactly the ones at risk of being forgotten.
 * `reversed_at IS NULL` on the match is what counts, not the existence of a
 * row.
 */
final class UnmatchedBankLines implements MetricSource
{
    public function key(): string
    {
        return 'unmatched_bank_lines';
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
            ? 'حركات بنكية غير مطابَقة، بحسب عمرها'
            : 'Unmatched bank lines, by age';
    }

    /** @return list<MetricSample> */
    public function collect(?string $entityId): array
    {
        /*
         | The as-of date is BOUND from PHP, never `current_date`.
         |
         | Found by a bite: three of these metrics bucketed by age against the
         | database's `current_date` and a fourth measured the same thing
         | against `Carbon::now()`, so two figures on one dashboard could
         | disagree by a day whenever the two clocks fell either side of
         | midnight - and in this product an entity has its own timezone, so
         | they routinely do.
         |
         | Binding it also makes the bucketing testable at all: with one
         | clock a check can ask what the figures say on a given date, which
         | is the only way to prove that an item NEWER than a bound is
         | included rather than silently filtered out.
         */
        $asOf = Carbon::now()->toDateString();

        $rows = DB::connection('tenant')->select(<<<'SQL'
            SELECT
                t.entity_id,
                (cast(? AS date) - t.booking_date) AS age_days,
                count(*) AS lines
            FROM bank_transactions t
            WHERE (cast(? AS char(26)) IS NULL OR t.entity_id = cast(? AS char(26)))
              AND t.superseded_by_id IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM matches m
                  WHERE m.bank_transaction_id = t.id
                    AND m.booking_date = t.booking_date
                    AND m.reversed_at IS NULL
              )
            GROUP BY 1, 2
        SQL, [$asOf, $entityId, $entityId]);

        $buckets = [];

        foreach ($rows as $row) {
            $key = (string) $row->entity_id.'|'.AgeBucket::of((int) $row->age_days);
            $buckets[$key] = ($buckets[$key] ?? 0) + (int) $row->lines;
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
