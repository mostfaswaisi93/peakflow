<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use App\Support\Telemetry\Contracts\SpanExporter;
use Throwable;

/**
 * One trace per correlation id, across every process it touches - PF-240.
 *
 * ## "Why did this invoice take four minutes to clear" is ONE trace
 *
 * Which is the whole requirement, and it is satisfied by one decision: the
 * trace id IS the correlation id. The correlation id already survives the
 * three hops that matter - it is honoured from an inbound header, it travels
 * on the queue payload and is adopted rather than regenerated, and it is sent
 * on every outbound HTTP request by a global client middleware. So the HTTP
 * span, the queued job's span, the outbound transport's span and every
 * database span under all three already share an id without anything new
 * having to be propagated.
 *
 * The alternative - a trace id generated per entry point and carried in a
 * second header - would have meant two ids, two propagation mechanisms, and a
 * join from a log line to a trace that nobody has written.
 *
 * ## It is a no-op unless it is recording
 *
 * `TELEMETRY_ENABLED=false` is the committed default, and it has to cost
 * nothing: a product whose reports read ten million journal lines cannot
 * afford a span per statement by accident. So `start()` returns null, the
 * call sites hold `?Span`, and `measure()` hides that from the five business
 * operations that use it.
 *
 * ## Exporting SUSPENDS the tracer, and that is not an optimisation
 *
 * The log exporter writes through a Monolog channel. The allowlist processor
 * on that channel reads the current entity from the database session - a
 * query - and a query produces a database span, which is exported, which
 * writes a log line, which runs the processor, which queries. Unguarded this
 * is not slow: it is infinite, and it is the kind of recursion that presents
 * as a worker that stopped responding.
 *
 * So nothing started while a span is being exported becomes a span.
 *
 * ## Orphans are closed by their parent, as errors
 *
 * An outbound call that throws never reaches the response hook, so its span
 * is still open when the request's span closes. Closing it silently would
 * report it as having taken the whole request; dropping it would hide the
 * failed call entirely. It is closed as an error, which is what it was.
 */
final class Tracer
{
    /** @var list<Span> */
    private array $stack = [];

    private bool $suspended = false;

    public function __construct(
        private readonly SpanExporter $exporter,
        private readonly Sampler $sampler,
        private readonly bool $recording,
    ) {}

    public function isRecording(): bool
    {
        return $this->recording && ! $this->suspended;
    }

    public function exporter(): SpanExporter
    {
        return $this->exporter;
    }

    public function sampler(): Sampler
    {
        return $this->sampler;
    }

    public function traceId(): string
    {
        $current = $this->current();

        return $current === null ? CorrelationId::current() : $current->traceId;
    }

    public function current(): ?Span
    {
        $last = end($this->stack);

        return $last === false ? null : $last;
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    /**
     * Open a span, or do nothing at all.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function start(string $name, SpanKind $kind, array $attributes = []): ?Span
    {
        if (! $this->isRecording()) {
            return null;
        }

        $parent = $this->current();

        /*
         | The sampling decision is INHERITED from the parent, unless the
         | operation is one that is always kept.
         |
         | A child sampled independently of its parent produces a span whose
         | parent is absent from the trace, which a viewer draws as a
         | fragment with no context - so the rate would be respected and the
         | traces would be unreadable. The exception is the point: a
         | `payment.release` under an unsampled request is kept, and the
         | request it came from is what gets reconstructed from the log lines
         | that share the trace id.
         */
        $sampled = $parent !== null && ! $this->sampler->isAlwaysSampled($name)
            ? $parent->sampled
            : $this->sampler->shouldSample($name);

        $span = Span::start(
            traceId: $parent?->traceId ?? CorrelationId::current(),
            parentSpanId: $parent?->spanId,
            name: $name,
            kind: $kind,
            sampled: $sampled,
            attributes: $attributes,
        );

        $this->stack[] = $span;

        return $span;
    }

    /**
     * Close a span, and anything that was left open inside it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function finish(?Span $span, string $status = 'ok', array $attributes = []): void
    {
        if ($span === null) {
            return;
        }

        /*
         | Everything above it in the stack first, as an error.
         |
         | Searched by identity rather than popping blindly: a span that is
         | not on the stack at all - closed twice, or belonging to a tracer
         | that has since been replaced - must not take somebody else's
         | spans with it.
         */
        $index = null;

        foreach ($this->stack as $position => $candidate) {
            if ($candidate === $span) {
                $index = $position;

                break;
            }
        }

        if ($index === null) {
            $this->emit($span->finish($status, $attributes));

            return;
        }

        while (count($this->stack) > $index + 1) {
            $orphan = array_pop($this->stack);

            if ($orphan instanceof Span) {
                $this->emit($orphan->finish('error', [
                    'kind' => $orphan->kind->value,
                ]));
            }
        }

        array_pop($this->stack);

        $this->emit($span->finish($status, $attributes));
    }

    /**
     * Measure a block of work, recording a failure as a failure.
     *
     * The five money-moving operations use this, which is what makes
     * `always_sample` a behaviour rather than a list: the names in
     * `config/telemetry.php` are the names passed here.
     *
     * @template T
     *
     * @param  array<string, mixed>  $attributes
     * @param  callable(): T  $work
     * @return T
     */
    public function measure(string $name, SpanKind $kind, array $attributes, callable $work): mixed
    {
        $span = $this->start($name, $kind, $attributes);

        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->finish($span, 'error', ['exception_class' => $e::class]);

            throw $e;
        }

        $this->finish($span);

        return $result;
    }

    /**
     * A database statement, which is already over by the time we hear of it.
     *
     * `DB::listen` fires after the query, so there is no span to open and
     * close - the duration is handed to us. The span is built finished.
     */
    public function database(string $sql, int $microseconds, ?string $connection = null): void
    {
        if (! $this->isRecording()) {
            return;
        }

        $attributes = [];

        /*
         | The SHAPE, and only when the configuration asks for it.
         |
         | `telemetry.database.record_bindings` has no code path here at all,
         | in either direction. It is refused by the production guard so a
         | deployment cannot ask for bindings, and there is nothing here that
         | would honour it if it did - which is the difference between a
         | setting that is off and a feature that does not exist.
         */
        if (config('telemetry.database.record_statement_shape') === true) {
            $attributes['sql_shape'] = StatementShape::of($sql);
        }

        if ($connection !== null) {
            $attributes['transport'] = $connection;
        }

        $parent = $this->current();
        $name = 'db.'.StatementShape::operation($sql);

        $this->emit(Span::measured(
            traceId: $parent?->traceId ?? CorrelationId::current(),
            parentSpanId: $parent?->spanId,
            name: $name,
            kind: SpanKind::Database,
            sampled: $parent !== null ? $parent->sampled : $this->sampler->shouldSample($name),
            microseconds: $microseconds,
            attributes: $attributes,
        ));
    }

    /**
     * Hand a finished span to the exporter, if the sampler keeps it.
     */
    private function emit(Span $span): void
    {
        if (! $this->sampler->keepAfterTheFact($span->name, $span->sampled, $span->isError())) {
            return;
        }

        if ($this->suspended) {
            return;
        }

        $this->suspended = true;

        try {
            $this->exporter->export($span);
        } catch (Throwable) {
            // See the note on SpanExporter: telemetry has no claim on the
            // request. An exporter that threw would convert a collector
            // outage into a failed invoice.
        } finally {
            $this->suspended = false;
        }
    }
}
