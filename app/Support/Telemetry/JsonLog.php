<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

/**
 * A log channel's tap: JSON lines, with the allowlist applied - PF-014.
 *
 * ## Why JSON
 *
 * A line-based format is parsed by a regular expression that works until a
 * message contains the delimiter. For a financial system the messages contain
 * amounts, account codes and quoted refusals from PostgreSQL, so they contain
 * every delimiter.
 *
 * `JsonFormatter::BATCH_MODE_NEWLINES` so a collector can read it a line at
 * a time, and `includeStacktraces` on: a stack trace that was dropped is a
 * bug that has to be reproduced.
 *
 * ## The processor is attached HERE, not registered globally
 *
 * A global processor is one call to `Log::pushProcessor()` away from being
 * replaced, and the replacement looks like a reasonable refactor. Attaching
 * it per channel in the channel's own definition means every handler has it,
 * including the ones a package adds later.
 */
final class JsonLog
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        /*
         | Pushed onto the LOGGER, so it runs before any handler.
         |
         | A processor on one handler filters that handler's copy and leaves
         | the others - and a stack with a file channel and a stderr channel
         | would then redact one and not the other, which is the worst of the
         | available outcomes because it looks redacted.
         */
        if (method_exists($monolog, 'pushProcessor')) {
            $monolog->pushProcessor(new AllowlistProcessor());
        }

        foreach ($monolog->getHandlers() as $handler) {
            if (! method_exists($handler, 'setFormatter')) {
                continue;
            }

            $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true, true);

            $handler->setFormatter($formatter);
        }
    }
}
