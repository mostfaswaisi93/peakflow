<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Sinks;

use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Contracts\AlertSink;

/**
 * Keeps raised alerts in memory - PF-242.
 *
 * The driver the game day and the checks run against. It is production code
 * for the same reason `FakeChannel` is: `invariants:game-day` injects each
 * condition and has to read back what fired, and reading it out of a log file
 * would make the exercise an assertion about Monolog's formatting.
 */
final class CollectingAlertSink implements AlertSink
{
    /** @var list<Alert> */
    private array $raised = [];

    public function key(): string
    {
        return 'memory';
    }

    public function raise(Alert $alert): void
    {
        $this->raised[] = $alert;
    }

    /** @return list<Alert> */
    public function raised(): array
    {
        return $this->raised;
    }

    /** @return list<Alert> */
    public function of(string $key): array
    {
        return array_values(array_filter(
            $this->raised,
            static fn (Alert $alert): bool => $alert->key === $key,
        ));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_unique(array_map(
            static fn (Alert $alert): string => $alert->key,
            $this->raised,
        )));
    }

    public function forget(): void
    {
        $this->raised = [];
    }
}
