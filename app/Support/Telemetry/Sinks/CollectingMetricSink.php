<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Sinks;

use App\Support\Telemetry\Contracts\MetricSink;
use App\Support\Telemetry\Metrics\MetricSample;

/**
 * Keeps published samples in memory - PF-241.
 *
 * The driver the checks run against, for the same reason `FakeChannel` is
 * production code: a check that read the log file back would be asserting
 * Monolog's formatting, and a check that called the collectors directly would
 * not exercise the command that is actually scheduled.
 */
final class CollectingMetricSink implements MetricSink
{
    /** @var list<array{metric: string, unit: string, labels: array<string, string|int>, value: string, scope: array<string, string|null>}> */
    private array $published = [];

    public function key(): string
    {
        return 'memory';
    }

    /** @param array<string, string|null> $scope */
    public function publish(string $metric, string $unit, MetricSample $sample, array $scope = []): void
    {
        $this->published[] = [
            'metric' => $metric,
            'unit' => $unit,
            'labels' => $sample->labels,
            'value' => $sample->value,
            'scope' => $scope,
        ];
    }

    /** @return list<array{metric: string, unit: string, labels: array<string, string|int>, value: string, scope: array<string, string|null>}> */
    public function published(): array
    {
        return $this->published;
    }

    /** @return list<array{metric: string, unit: string, labels: array<string, string|int>, value: string, scope: array<string, string|null>}> */
    public function of(string $metric): array
    {
        return array_values(array_filter(
            $this->published,
            static fn (array $row): bool => $row['metric'] === $metric,
        ));
    }

    public function forget(): void
    {
        $this->published = [];
    }
}
