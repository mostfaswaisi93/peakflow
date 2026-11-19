<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Contracts;

use App\Support\Telemetry\Alerts\Alert;

/**
 * Where a breached invariant goes - PF-242, PF-229.
 *
 * ## A driver, and the default needs no paging provider
 *
 * Because an alerting path that only works against a reachable pager is an
 * alerting path nobody has ever seen fire. The committed default writes the
 * alert as one `critical` JSON line on the channel the allowlist taps, so a
 * fresh clone raises real alerts that a collector reading the log can act on,
 * and every check in this phase drives the real firing path rather than a
 * double.
 *
 * ## It must not throw, and it must not be silenced by the tracing switch
 *
 * A sink that threw would make a breached invariant into an exception inside
 * the watch, which would stop the remaining watchers - so one unreachable
 * pager would hide six other invariants.
 *
 * And it is deliberately NOT gated on `telemetry.enabled`. Tracing is off by
 * default because a span per statement costs something. An alert that stopped
 * firing because somebody turned tracing off would be the exact failure this
 * whole section exists to prevent, and nobody would find out until the
 * incident it was built for.
 */
interface AlertSink
{
    public function key(): string;

    public function raise(Alert $alert): void;
}
