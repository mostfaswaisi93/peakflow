<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Contracts;

use App\Support\Telemetry\Metrics\MetricSample;

/**
 * Where a collected figure goes - PF-241, PF-229.
 *
 * ## A driver, for the same reason the span exporter is one
 *
 * A metric that is only printed on a console is not a metric: nobody is
 * watching the console at 03:00. A metric wired to one vendor's push gateway
 * is a product that cannot change vendor, and - worse in this environment - a
 * product whose metrics are never exercised, because there is no gateway to
 * push to.
 *
 * So the committed default writes each sample as one JSON log line on the
 * channel the allowlist taps. A collector that reads the log reads the
 * metrics; a Prometheus or OTLP sink is a second implementation named in
 * configuration.
 */
interface MetricSink
{
    public function key(): string;

    /**
     * Publish one sample.
     *
     * `$scope` is the tenant and entity the figure belongs to, when it
     * belongs to one: a figure with no tenant on it cannot be routed to a
     * customer, and a platform metric legitimately has none.
     *
     * @param  array<string, string|null>  $scope
     */
    public function publish(string $metric, string $unit, MetricSample $sample, array $scope = []): void;
}
