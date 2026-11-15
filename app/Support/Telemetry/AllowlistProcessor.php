<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Every log record passes through the allowlist - PF-014.
 *
 * ## Why a processor rather than asking callers to filter
 *
 * Because the callers are everywhere, including inside the framework, inside
 * a package, and inside the exception handler. A convention that each call
 * site filters its own context is a convention that holds until the first
 * `Log::debug('why is this null', compact('request'))` - which is written at
 * 2am, works, and ships.
 *
 * A processor is the one place every record goes through. There is no call
 * site that can forget it.
 *
 * ## `extra` is filtered too, and that is not paranoia
 *
 * Monolog processors write there - the web processor puts the URL, the IP and
 * the user agent in it, and a URL carries query parameters. A filter on
 * `context` alone leaves the half of the record somebody else populated.
 */
final class AllowlistProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            /*
             | The MESSAGE is scrubbed too, and that is not optional.
             |
             | "could not validate SA0380000000608010167519" is an ordinary
             | exception message, and the exception message IS the log line.
             | Filtering the context while passing the message through is
             | redacting the half nobody puts secrets in.
             */
            message: (string) SecretShapes::scrub($record->message),
            context: LogContext::build($record->context),
            extra: LogContext::filter($record->extra, 1),
        );
    }
}
