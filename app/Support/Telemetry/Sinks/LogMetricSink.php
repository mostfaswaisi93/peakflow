<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Sinks;

use App\Support\Telemetry\Contracts\MetricSink;
use App\Support\Telemetry\Metrics\MetricSample;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One sample, one JSON log line - PF-241, PF-229.
 *
 * The committed default, and it needs nothing stood up. The line goes on the
 * channel named by `telemetry.channel`, which carries the allowlist
 * processor - so a metric's labels are filtered by exactly the same mechanism
 * a log line's context is, rather than by a second one somebody has to
 * remember.
 *
 * `info`, not `debug`, for the same reason the span exporter uses it: a level
 * below the channel's threshold is a metric that is collected, labelled,
 * formatted and discarded, and `LOG_LEVEL=info` is the ordinary production
 * setting.
 */
final class LogMetricSink implements MetricSink
{
    public function key(): string
    {
        return 'log';
    }

    /** @param array<string, string|null> $scope */
    public function publish(string $metric, string $unit, MetricSample $sample, array $scope = []): void
    {
        try {
            Log::channel($this->channel())->info('metric', array_filter([
                'metric' => $metric,
                'unit' => $unit,
                'observed' => $sample->value,
                'labels' => $sample->labels,
                'tenant_id' => $scope['tenant_id'] ?? null,
                'entity_id' => $scope['entity_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));
        } catch (Throwable) {
            // A metric is diagnostic. An unwritable log file must not fail
            // the run that was collecting it.
        }
    }

    public function channel(): string
    {
        $configured = config('telemetry.channel');

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) config('logging.default', 'stack');
    }
}
