<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Exporters;

use App\Support\Telemetry\Contracts\SpanExporter;
use App\Support\Telemetry\Span;

/**
 * Keeps the last spans in memory - PF-240.
 *
 * ## Why this is a driver rather than a test double
 *
 * The same argument as `FakeChannel`. The checks that prove a trace holds the
 * HTTP request, the queued job, the outbound call and the statement under one
 * trace id have to read the spans back, and they must read the spans the
 * real tracer produced rather than ones a double invented - otherwise they
 * assert the double's behaviour.
 *
 * It is also the driver a `--trace` flag on a console command would use: the
 * spans of one run, in order, with no collector. That is why the buffer is
 * bounded rather than unbounded. A long `ledger:verify --all` with every
 * statement traced produces hundreds of thousands of spans, and an exporter
 * that held all of them would turn a diagnostic into an out-of-memory.
 */
final class CollectingSpanExporter implements SpanExporter
{
    /** @var list<Span> */
    private array $spans = [];

    public function __construct(private readonly int $limit = 2000) {}

    public function key(): string
    {
        return 'memory';
    }

    public function export(Span $span): void
    {
        $this->spans[] = $span;

        if (count($this->spans) > $this->limit) {
            array_shift($this->spans);
        }
    }

    /** @return list<Span> */
    public function spans(): array
    {
        return $this->spans;
    }

    /** @return list<Span> */
    public function named(string $name): array
    {
        return array_values(array_filter(
            $this->spans,
            static fn (Span $span): bool => $span->name === $name,
        ));
    }

    public function forget(): void
    {
        $this->spans = [];
    }
}
