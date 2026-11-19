<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Exporters;

use App\Support\Telemetry\Contracts\SpanExporter;
use App\Support\Telemetry\Span;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The committed default: one span, one JSON log line - PF-240, PF-229.
 *
 * ## Why this is production code and not a test double
 *
 * It is what a fresh clone gets. A deployment that has not stood up a
 * collector still produces traces - joinable by `trace_id`, which is the
 * correlation id already on every other line - and an operator can see the
 * whole path work before they have an endpoint. The alternative default is
 * "tracing exists in the code and has never run", which is the state this
 * phase was written to end.
 *
 * ## It writes through a CHANNEL, so the allowlist applies
 *
 * Not `error_log`, not a file of its own. The channel named by
 * `telemetry.channel` carries the `AllowlistProcessor` tap, so a span's
 * attributes go through exactly the filter a log line's context does - which
 * is the fifth acceptance criterion of PF-240, implemented by not having a
 * second path rather than by remembering to filter twice.
 *
 * `bin/verify logging` asserts the processor is ATTACHED to the channels that
 * declare the tap; `bin/verify observability` asserts that the channel THIS
 * writes to is one of them, because a span exported through `stderr` - a
 * channel in this repository that declares no tap - would be unfiltered while
 * looking configured.
 *
 * ## `info`, not `debug`
 *
 * A level below the channel's own threshold is a span that is built, filtered,
 * formatted and discarded. `LOG_LEVEL` defaults to `debug` locally and is
 * routinely `info` in production, so `debug` would mean traces that work on a
 * laptop and are absent where they are needed.
 */
final class LogSpanExporter implements SpanExporter
{
    public function key(): string
    {
        return 'log';
    }

    public function export(Span $span): void
    {
        try {
            Log::channel($this->channel())->info('span', $span->toArray());
        } catch (Throwable) {
            /*
             | Swallowed, and this is the one place it is right to.
             |
             | A span is diagnostic. An unwritable log file must not fail the
             | invoice that was being traced, and there is nowhere to report
             | the failure to that is not the thing that just failed.
             */
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
