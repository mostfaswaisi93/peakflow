<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Throwable;

/**
 * What can safely be said about an exception in a log line - PF-234.
 *
 * ## The report was safe and useless
 *
 * Laravel's handler reports an exception as its message plus
 * `['exception' => $e]`. `exception` is not on the context allowlist, so the
 * whole thing is dropped and the message is scrubbed - which is correct, and
 * leaves a production 500 logged as one sentence with **no class, no file,
 * no line and no frames**. Measured: the only place `exception_class` is
 * written at all is a span, and a span is sampled.
 *
 * An error report nobody can act on is not a smaller problem than one that
 * leaks. It is the reason somebody turns the allowlist off.
 *
 * ## Frames carry no secrets; arguments do
 *
 * That is the whole design. `getTraceAsString()` renders argument VALUES -
 * truncated, which makes them unreadable rather than absent, and an IBAN
 * truncated at eight characters is still an IBAN. `getTrace()` carries them
 * in full under `args`.
 *
 * So the frame is rebuilt from `class`, `type`, `function`, `file` and
 * `line` and nothing else. Those five are names of code. A password has
 * never been one.
 *
 * ## Paths are relative
 *
 * An absolute path tells a reader of the log where the deployment lives and
 * which user it runs as. It also makes two environments' reports of the same
 * fault look different, which is the first thing somebody notices and the
 * last thing they need to think about.
 */
final class ExceptionShape
{
    /** How many frames are kept. Enough to see the path in; not the whole call stack. */
    private const FRAMES = 12;

    /**
     * @return array<string, mixed>
     */
    public static function of(Throwable $e): array
    {
        $shape = [
            'exception_class' => $e::class,
            'exception_file' => self::relative($e->getFile()),
            'exception_line' => $e->getLine(),
            'exception_frames' => self::frames($e),
        ];

        $previous = $e->getPrevious();

        if ($previous !== null) {
            /*
             | The class alone, not the whole shape.
             |
             | A chained exception is usually the real cause - a
             | `QueryException` under a domain refusal - and its CLASS is the
             | diagnosis. Its message is not: a database driver puts the
             | failing statement and its bindings in there, and the bindings
             | are the amounts and the account numbers.
             */
            $shape['exception_previous_class'] = $previous::class;
        }

        return $shape;
    }

    /**
     * @return list<string>
     */
    private static function frames(Throwable $e): array
    {
        $frames = [];

        foreach (\array_slice($e->getTrace(), 0, self::FRAMES) as $frame) {
            $call = (string) ($frame['class'] ?? '').(string) ($frame['type'] ?? '').(string) ($frame['function'] ?? '');

            $where = isset($frame['file'])
                ? sprintf(' %s:%d', self::relative((string) $frame['file']), (int) ($frame['line'] ?? 0))
                : '';

            if ($call === '' && $where === '') {
                continue;
            }

            $frames[] = $call.$where;
        }

        return $frames;
    }

    private static function relative(string $path): string
    {
        $base = \function_exists('base_path') ? base_path() : '';

        return $base !== '' && str_starts_with($path, $base.'/')
            ? substr($path, \strlen($base) + 1)
            : basename($path);
    }
}
