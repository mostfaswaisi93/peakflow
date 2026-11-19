<?php

declare(strict_types=1);

namespace App\Support\Telemetry\Sinks;

use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Contracts\AlertSink;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One breached invariant, one `critical` JSON line - PF-242, PF-229.
 *
 * ## Why the message is the English sentence and the Arabic rides alongside
 *
 * The log line's message has to be one thing, and a collector's search and
 * grouping work on it. Both sentences are present - the English as the
 * message, the Arabic as a field - so whoever is on call reads the one they
 * read and neither is lost.
 *
 * The sentence itself names the entity, the period and the first divergent
 * record, not only the structured fields. That is deliberate: the fields can
 * be stripped. `LogContext::build()` replaces a caller's `entity_id` with the
 * ambient one, and on a run where no entity context is established the
 * ambient one is null and the field is dropped - so an alert whose entity was
 * only in a field would arrive without it. In the sentence it cannot.
 *
 * ## `critical`, so it is above every threshold anybody sets
 *
 * A breached ledger invariant is not an `error` among other errors. The level
 * is what a collector routes on, and `LOG_LEVEL` is routinely `info` or
 * `warning` in production - an alert below the threshold is one that was
 * built, filtered, formatted and discarded.
 */
final class LogAlertSink implements AlertSink
{
    public function key(): string
    {
        return 'log';
    }

    public function raise(Alert $alert): void
    {
        try {
            Log::channel($this->channel())->critical(
                sprintf(
                    '%s: %s [entity %s · period %s · first divergence %s]',
                    $alert->key,
                    $alert->messageEn,
                    $alert->entityId,
                    $alert->period,
                    $alert->firstDivergence,
                ),
                $alert->toArray() + ['kinds' => ['ar' => $alert->messageAr]],
            );
        } catch (Throwable) {
            /*
             | Swallowed, and only here.
             |
             | A sink that threw would stop the watch, and one unreachable
             | destination would hide every invariant checked after it. The
             | breach is still in the command's own non-zero exit, which is
             | what the scheduler sees.
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
