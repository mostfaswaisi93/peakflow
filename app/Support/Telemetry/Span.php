<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

/**
 * One span, with its attributes already through the log allowlist - PF-240.
 *
 * ## The allowlist is applied in the CONSTRUCTOR, not by the caller
 *
 * A trace is a log with a nicer viewer and it leaks the same way. The field
 * rule in `config/telemetry.php` therefore has to govern span attributes as
 * well as log context - and the only way that survives the next caller is for
 * there to be no way to build a span whose attributes have not been through
 * it. A `withAttributes()` that filtered and a constructor that did not would
 * be one careless call from a span labelled by customer name, and a metric or
 * a span labelled by customer is a customer list on whoever's dashboard.
 *
 * So `attributes()` returns what survived, there is no accessor for what was
 * passed, and `with()` filters again.
 *
 * ## Durations are integers of MICROSECONDS
 *
 * `hrtime(true)` returns an integer of nanoseconds and is monotonic, so a
 * clock adjustment mid-request cannot produce a negative duration. The float
 * that `microtime(true)` returns would be the only float in the money path's
 * neighbourhood, and `duration_ms` is what somebody reads off a dashboard
 * when deciding whether four minutes is normal.
 *
 * ## The trace id is the correlation id
 *
 * Deliberately the same value, not a second one derived from it. "Why did
 * this invoice take four minutes to clear" has to be answerable from a log
 * line - which carries `correlation_id` and nothing else - so a trace id that
 * differed would mean holding two ids and a join nobody has written.
 */
final class Span
{
    /** @var array<string, mixed> */
    private array $attributes;

    private ?int $endedAt = null;

    private string $status = 'unset';

    private function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $name,
        public readonly SpanKind $kind,
        public readonly int $startedAt,
        public readonly bool $sampled,
        array $attributes,
    ) {
        $this->attributes = LogContext::filter($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function start(
        string $traceId,
        ?string $parentSpanId,
        string $name,
        SpanKind $kind,
        bool $sampled,
        array $attributes = [],
    ): self {
        return new self(
            traceId: $traceId,
            spanId: bin2hex(random_bytes(8)),
            parentSpanId: $parentSpanId,
            name: $name,
            kind: $kind,
            startedAt: hrtime(true),
            sampled: $sampled,
            attributes: $attributes,
        );
    }

    /**
     * A span whose duration was measured by somebody else - PF-240.
     *
     * `DB::listen` fires after the statement has run and hands over the
     * elapsed time, so there is no interval for `hrtime()` to measure here.
     * The first version of this built an ordinary span and closed it
     * immediately, which recorded every query as having taken zero
     * milliseconds - a span per statement, each one saying nothing, which is
     * the most expensive possible way to learn nothing.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function measured(
        string $traceId,
        ?string $parentSpanId,
        string $name,
        SpanKind $kind,
        bool $sampled,
        int $microseconds,
        array $attributes = [],
        string $status = 'ok',
    ): self {
        $now = hrtime(true);

        $span = new self(
            traceId: $traceId,
            spanId: bin2hex(random_bytes(8)),
            parentSpanId: $parentSpanId,
            name: $name,
            kind: $kind,
            startedAt: $now - (max(0, $microseconds) * 1000),
            sampled: $sampled,
            attributes: $attributes,
        );

        $span->endedAt = $now;
        $span->status = $status;

        return $span;
    }

    /**
     * Close the span.
     *
     * Idempotent: the first close wins. A span closed twice - which happens
     * when an outbound call throws and the enclosing span closes the orphan
     * it left - would otherwise report the outer duration as the inner one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function finish(string $status = 'ok', array $attributes = []): self
    {
        if ($this->endedAt !== null) {
            return $this;
        }

        $this->endedAt = hrtime(true);
        $this->status = $status;

        if ($attributes !== []) {
            $this->attributes = LogContext::filter($attributes) + $this->attributes;
        }

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function with(array $attributes): self
    {
        $this->attributes = LogContext::filter($attributes) + $this->attributes;

        return $this;
    }

    public function isFinished(): bool
    {
        return $this->endedAt !== null;
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }

    public function status(): string
    {
        return $this->status;
    }

    /** Whole microseconds, so nothing here is a float. */
    public function durationMicroseconds(): int
    {
        return intdiv(($this->endedAt ?? hrtime(true)) - $this->startedAt, 1000);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function attribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * The span as the exporter writes it.
     *
     * Every key here is on the allowlist in `config/telemetry.php`, and the
     * exporter writes it as log context - so if one is ever removed from the
     * list the field disappears from the span rather than leaking past it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $microseconds = $this->durationMicroseconds();

        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'span' => $this->name,
            'kind' => $this->kind->value,
            'status' => $this->status,
            /*
             | Milliseconds, floored, as an integer - and the microseconds
             | beside it.
             |
             | A span that took 400 microseconds reads as `0` in
             | milliseconds, which is the figure a dashboard would then
             | average. Keeping both means the millisecond field stays the
             | one everybody already understands without it being the only
             | thing recorded.
             */
            'duration_ms' => intdiv($microseconds, 1000),
            'duration_us' => $microseconds,
            'sampled' => $this->sampled,
        ] + $this->attributes;
    }
}
