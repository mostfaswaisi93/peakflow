<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Exporters;

use App\Support\Telemetry\Contracts\SpanExporter;
use App\Support\Telemetry\Span;

/**
 * Drops every span - PF-240.
 *
 * What a deployment with `TELEMETRY_ENABLED=false` gets, which is the
 * committed default: tracing every database statement in a product whose
 * reports read ten million journal lines is not a setting to arrive at by
 * accident.
 *
 * It is a driver rather than a null check at each call site because the call
 * sites are the whole application, and "tracing is off" has to be one
 * decision made in one place. The tracer still refuses to start spans when it
 * is not recording, so this is the second of two defences and the one that
 * holds if somebody binds an exporter without enabling telemetry.
 */
final class NullSpanExporter implements SpanExporter
{
    public function key(): string
    {
        return 'null';
    }

    public function export(Span $span): void
    {
        unset($span);
    }
}
