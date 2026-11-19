<?php

declare(strict_types=1);

namespace App\Platform\Metrics;

use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Queue depth and dead letters, per queue - PF-241.
 *
 * ## The engineer's dashboard needs a question of its own
 *
 * The other metrics are a controller's: is this company's money accounted
 * for. This one is nobody else's - a queue that is 40,000 deep is not a
 * figure a controller can act on and is the only figure that explains why
 * their invoices are not being delivered.
 *
 * ## `failed_jobs` IS the dead letter, for the one transport that exists
 *
 * PF-241 asks for dead-letter depth per TRANSPORT, meaning the Exchange
 * plane's: a clearance submission that exhausted its retries sits in a dead
 * letter a human re-drives, because the document is already legally issued.
 * That table does not exist yet - the transport is Phase 12 - so what is
 * reported here is the queue's own failed table, which is the dead letter of
 * the only transport the product currently has. The criterion stays open and
 * says so rather than being ticked against a different table with a similar
 * shape.
 *
 * ## Platform-scoped, and that is load-bearing
 *
 * Both tables are on the default connection, not a tenant's. A collector
 * that iterated tenants for this would report the same depth once per
 * customer, which on a gauge reads as a backlog multiplied by the number of
 * tenants.
 */
final class QueueHealth implements MetricSource
{
    public function key(): string
    {
        return 'queue_health';
    }

    /** @return list<Audience> */
    public function audiences(): array
    {
        return [Audience::Engineer, Audience::Operator];
    }

    public function scope(): MetricScope
    {
        return MetricScope::Platform;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return ['queue', 'state'];
    }

    public function unit(): string
    {
        return 'count';
    }

    public function describe(string $locale): string
    {
        return $locale === 'ar'
            ? 'عمق الطابور والمهام الفاشلة، لكل طابور'
            : 'Queue depth and dead letters, per queue';
    }

    /** @return list<MetricSample> */
    public function collect(?string $entityId): array
    {
        unset($entityId);

        $samples = [];

        if (Schema::hasTable('jobs')) {
            foreach (DB::table('jobs')->selectRaw('queue, count(*) as depth')->groupBy('queue')->get() as $row) {
                $samples[] = MetricSample::count([
                    'queue' => (string) $row->queue,
                    'state' => 'waiting',
                ], (int) $row->depth);
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            foreach (DB::table('failed_jobs')->selectRaw('queue, count(*) as depth')->groupBy('queue')->get() as $row) {
                $samples[] = MetricSample::count([
                    'queue' => (string) $row->queue,
                    'state' => 'dead',
                ], (int) $row->depth);
            }
        }

        return $samples;
    }
}
