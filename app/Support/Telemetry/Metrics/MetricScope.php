<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Metrics;

/**
 * What a metric has to be collected per - PF-241.
 *
 * `Entity` metrics are read from a tenant database with an entity
 * established, so the collector points the connection and iterates. `Platform`
 * metrics are read once, from the platform database, and a collector that
 * iterated tenants for one of them would report the same figure once per
 * tenant - which on a queue-depth gauge reads as a backlog multiplied by the
 * number of customers.
 */
enum MetricScope: string
{
    case Entity = 'entity';
    case Platform = 'platform';
}
