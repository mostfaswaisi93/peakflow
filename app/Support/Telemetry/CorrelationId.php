<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One id that follows a request through everything it causes - PF-014.
 *
 * ## What it is for, concretely
 *
 * An invoice is issued. That posts a journal entry, queues a PDF render,
 * pushes a webhook and calls a tax authority. Five log streams, four
 * processes, and the question afterwards is "what happened to invoice 4471" -
 * which is unanswerable unless every line of it carries the same id.
 *
 * ## It is accepted from the client, and bounded
 *
 * A caller that sends `X-Correlation-Id` gets it honoured, because the
 * caller's own logs already use it and the point is to join the two. It is
 * truncated and stripped of anything but the characters an id needs: the
 * header is attacker-controlled and ends up in log lines, so a newline in it
 * is a forged log entry.
 *
 * ## And it is generated when absent, never left null
 *
 * A null correlation id means the lines cannot be joined, which is the one
 * thing this exists to prevent. A console command and a queued job get one
 * too - the job's comes from the payload, so it is the SAME id as the request
 * that queued it.
 */
final class CorrelationId
{
    /** The container key, so every reader agrees where it lives. */
    public const KEY = 'peakflow.correlation_id';

    public const HEADER = 'X-Correlation-Id';

    /**
     * The id for the current request, job or command.
     *
     * Generated on first ask and remembered, so two readers in one request
     * cannot disagree - which is the failure that makes a correlation id
     * useless while looking like it works.
     */
    public static function current(): string
    {
        if (app()->bound(self::KEY)) {
            return (string) app(self::KEY);
        }

        $id = self::fromRequest() ?? self::generate();

        app()->instance(self::KEY, $id);

        return $id;
    }

    /** Adopt an id that arrived from somewhere else - a queue payload. */
    public static function adopt(?string $id): string
    {
        $clean = $id === null ? null : self::sanitise($id);

        $adopted = $clean ?? self::generate();

        app()->instance(self::KEY, $adopted);

        return $adopted;
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::KEY);
    }

    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    private static function fromRequest(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        if (! $request instanceof Request) {
            return null;
        }

        $header = $request->header(self::HEADER);

        return is_string($header) ? self::sanitise($header) : null;
    }

    /**
     * Strip everything an id does not need.
     *
     * The header is attacker-controlled and lands in log lines, so a newline
     * in it forges a log entry - the oldest log-injection trick there is, and
     * the one a JSON formatter does NOT protect against on its own, because
     * the line is valid JSON containing a string that a line-based collector
     * then splits.
     *
     * Alphanumerics, hyphens and underscores, 64 characters. An id that
     * arrives longer is truncated rather than refused: refusing a request
     * over the shape of a trace header would be a worse failure than
     * shortening it.
     */
    private static function sanitise(string $id): ?string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, 64);
    }
}
