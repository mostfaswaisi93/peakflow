<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Contracts;

use App\Support\Telemetry\Span;

/**
 * Where a finished span goes - PF-240, PF-229.
 *
 * ## Why a driver and not an OTLP client
 *
 * The same decision as `MessageChannel` and `EXCHANGE_DRIVER=log`, for the
 * same reason and with one extra. A product wired to one collector's protocol
 * at every call site cannot change collector; and a product whose tracing
 * only works against a reachable collector is a product whose tracing is
 * never exercised, because the environment it is written in does not have
 * one.
 *
 * So the committed default writes spans into the log, which needs no
 * credentials, no endpoint and no network - and every check in this phase
 * runs against a driver rather than against somebody's uptime. An OTLP
 * exporter is a second implementation of this interface and a configuration
 * change, not a different code path.
 *
 * ## It must not throw
 *
 * A span is diagnostic. An exporter that threw would convert a collector
 * outage into a failed invoice, which is precisely backwards: the whole
 * reason the Exchange plane exists is that a transport failure is a queue
 * depth and an alert rather than a failed user request, and telemetry has
 * even less claim on the request than a transport does.
 */
interface SpanExporter
{
    /** The driver's name, as `telemetry.exporter` spells it. */
    public function key(): string;

    public function export(Span $span): void;
}
