<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Telemetry\Alerts\InvariantWatch;
use App\Support\Telemetry\Contracts\AlertSink;
use App\Support\Telemetry\Contracts\MetricSink;
use App\Support\Telemetry\Contracts\SpanExporter;
use App\Support\Telemetry\Exporters\CollectingSpanExporter;
use App\Support\Telemetry\Exporters\LogSpanExporter;
use App\Support\Telemetry\Exporters\NullSpanExporter;
use App\Support\Telemetry\Metrics\Metrics;
use App\Support\Telemetry\Sampler;
use App\Support\Telemetry\Sinks\CollectingAlertSink;
use App\Support\Telemetry\Sinks\CollectingMetricSink;
use App\Support\Telemetry\Sinks\LogAlertSink;
use App\Support\Telemetry\Sinks\LogMetricSink;
use App\Support\Telemetry\Span;
use App\Support\Telemetry\SpanKind;
use App\Support\Telemetry\Tracer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The three spans, and the statement spans under them - PF-240.
 *
 * ## Why all three hooks are here and not in each caller
 *
 * The same argument as the correlation id and the tenant on the queue
 * payload. "Every job opens a span" and "every outbound call opens a span"
 * are conventions, and the call that matters is the one somebody adds in a
 * hurry - which in this product is a clearance submission at 2am during an
 * authority outage. A hook per boundary cannot be forgotten.
 *
 * ## Registered AFTER QueueTenancyServiceProvider, deliberately
 *
 * `Queue::before` callbacks run in registration order, and that provider's
 * callback is what adopts the correlation id from the payload. A job span
 * opened before it would take its trace id from whatever the worker last
 * had - so the job would be traced under the previous job's invoice. The
 * ordering is asserted rather than commented, because the list in
 * `bootstrap/providers.php` is exactly the kind of thing a merge reorders.
 *
 * ## The exporter is a driver, and `log` is the committed default
 *
 * So a fresh clone traces end to end with no collector, no endpoint and no
 * credentials - PF-229's rule applied to telemetry. `memory` is the driver
 * the checks and a `--trace` run read spans back from; `null` is what
 * `TELEMETRY_ENABLED=false` gets.
 */
final class TelemetryServiceProvider extends ServiceProvider
{
    /**
     * Job spans in flight, innermost last.
     *
     * A stack and not a field, for the same reason the tenant is a stack:
     * the `sync` driver runs a job inside its caller, and a job may dispatch
     * another synchronously from inside itself.
     *
     * @var list<Span|null>
     */
    private array $jobSpans = [];

    /** @var list<Span|null> */
    private array $outboundSpans = [];

    public function register(): void
    {
        $this->app->singleton(Sampler::class, static fn (): Sampler => Sampler::fromConfig());

        $this->app->singleton(SpanExporter::class, function (): SpanExporter {
            /*
             | Disabled means the NULL exporter, whatever the driver says.
             |
             | Checked here rather than in the tracer alone because an
             | exporter resolved by something else - a console command
             | wanting to dump spans - must not start writing because the
             | driver name happens to be `log`.
             */
            if (config('telemetry.enabled') !== true) {
                return new NullSpanExporter();
            }

            return match ((string) config('telemetry.exporter', 'log')) {
                'null' => new NullSpanExporter(),
                'memory' => new CollectingSpanExporter(),
                default => new LogSpanExporter(),
            };
        });

        $this->app->singleton(Tracer::class, fn (): Tracer => new Tracer(
            $this->app->make(SpanExporter::class),
            $this->app->make(Sampler::class),
            config('telemetry.enabled') === true,
        ));

        /*
         | The business-metric registry - PF-241.
         |
         | A singleton registered here and filled by each module's own
         | provider, the same shape as the posting rules and the closing
         | checks. The dashboards are derived from it, so a metric appears on
         | the right one by naming its audience.
         */
        $this->app->singleton(Metrics::class);

        /*
         | Where a collected figure goes - another driver, same reason.
         |
         | `log` is the committed default and needs nothing stood up, so a
         | fresh clone publishes metrics that a collector reading the log can
         | see. `memory` is what the checks drive the real command against.
         */
        $this->app->singleton(MetricSink::class, static fn (): MetricSink => match (
            (string) config('telemetry.metric_sink', 'log')
        ) {
            'memory' => new CollectingMetricSink(),
            default => new LogMetricSink(),
        });

        /*
         | The invariant watch and where its alerts go - PF-242.
         |
         | The sink is deliberately NOT gated on `telemetry.enabled`. Tracing
         | is off by default because a span per statement costs something; an
         | alert that stopped firing because somebody turned tracing off
         | would be the exact failure this whole section exists to prevent,
         | and nobody would find out until the incident it was built for.
         */
        $this->app->singleton(InvariantWatch::class);

        $this->app->singleton(AlertSink::class, static fn (): AlertSink => match (
            (string) config('telemetry.alert_sink', 'log')
        ) {
            'memory' => new CollectingAlertSink(),
            default => new LogAlertSink(),
        });
    }

    public function boot(): void
    {
        $this->traceDatabaseStatements();
        $this->traceQueuedJobs();
        $this->traceOutboundTransport();

        /*
         | The one metric that is not a module's: the queue itself.
         |
         | It lives here because `Platform` owns the queue tables and because
         | the engineer's dashboard needs a question of its own - a controller
         | cannot act on a queue depth, and it is the only figure that
         | explains why their invoices are not being delivered.
         */
        $this->app->make(Metrics::class)->register(new \App\Platform\Metrics\QueueHealth());
        $this->app->make(Metrics::class)->register(new \App\Platform\Metrics\ScheduledRunOutcomes());

        /*
         | The one watcher that is not a module's: the database's own
         | privileges - PF-242, PF-233.
         |
         | It lives here because `Platform` owns the privilege matrix, and
         | because PF-233's open criterion asks for a drift check that
         | actually runs: the check was built, and nothing invoked it.
         */
        $watch = $this->app->make(InvariantWatch::class);

        $watch->register($this->app->make(\App\Platform\Alerts\PrivilegeDriftWatcher::class));

        /*
         | And the archive, which has no provider of its own.
         |
         | `app/Exchange` is a module with no service provider - nothing in it
         | needed boot-time registration until now - and adding one for a
         | single line would put the Exchange plane's wiring in two places.
         | It is registered here, beside the platform's, and the comment is
         | the record of why.
         */
        $watch->register($this->app->make(\App\Exchange\Alerts\ArchiveChecksumWatcher::class));
    }

    /**
     * A span per statement, carrying the shape and never a binding.
     *
     * `QueryExecuted::$time` is milliseconds and arrives as the framework's
     * float. It is converted once, here, into whole microseconds: a
     * sub-millisecond statement - which most of them are - reads as zero in
     * milliseconds, and a span that records every query as taking no time is
     * the most expensive possible way to learn nothing.
     */
    private function traceDatabaseStatements(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            $tracer = $this->app->make(Tracer::class);

            if (! $tracer->isRecording()) {
                return;
            }

            $tracer->database(
                $query->sql,
                (int) round($query->time * 1000),
                $query->connectionName,
            );
        });
    }

    /**
     * The second span: the queued job, under the trace that queued it.
     */
    private function traceQueuedJobs(): void
    {
        Queue::before(function (JobProcessing $event): void {
            $tracer = $this->app->make(Tracer::class);

            $this->jobSpans[] = $tracer->start('queue.job', SpanKind::Consumer, [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempt' => $event->job->attempts(),
            ]);
        });

        Queue::after(function (JobProcessed $event): void {
            unset($event);

            $this->app->make(Tracer::class)->finish(array_pop($this->jobSpans) ?? null);
        });

        /*
         | Both failure events, and `exceptionOccurred` is the one that
         | matters.
         |
         | `JobFailed` fires only when the job has exhausted its attempts.
         | A job that threw and will be retried fires `JobExceptionOccurred`
         | alone, so a handler on `JobFailed` only would leave the span of
         | every retried job open - and the next job's span would then be
         | drawn as its child.
         */
        $failed = function (JobFailed|JobExceptionOccurred $event): void {
            $this->app->make(Tracer::class)->finish(
                array_pop($this->jobSpans) ?? null,
                'error',
                ['exception_class' => $event->exception::class],
            );
        };

        Queue::failing($failed);
        Queue::exceptionOccurred($failed);
    }

    /**
     * The third span: the outbound call, which is where the four minutes go.
     *
     * ## Why the host and not the URL
     *
     * A clearance URL carries the document's identifier in its path on some
     * authorities' platforms, and a span attribute is subject to the same
     * allowlist a log line is. The host answers the question a trace is for -
     * which dependency was slow - without carrying the document.
     *
     * ## A call that throws never reaches the response hook
     *
     * Guzzle raises a connection failure before any response exists, so
     * there is no symmetric place to close the span. It is left open and the
     * enclosing span closes it as an error, which is what a connection
     * failure is. That is the behaviour `Tracer::finish()`'s orphan handling
     * exists for, and the alternative - no span at all for the call that
     * failed - would hide exactly the calls a trace is opened for.
     */
    private function traceOutboundTransport(): void
    {
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            $tracer = $this->app->make(Tracer::class);

            $this->outboundSpans[] = $tracer->start('http.client', SpanKind::Client, [
                'method' => $request->getMethod(),
                'transport' => $request->getUri()->getHost(),
            ]);

            return $request;
        });

        Http::globalResponseMiddleware(function (ResponseInterface $response): ResponseInterface {
            $status = $response->getStatusCode();

            $this->app->make(Tracer::class)->finish(
                array_pop($this->outboundSpans) ?? null,
                $status >= 500 ? 'error' : 'ok',
                ['status' => $status],
            );

            return $response;
        });
    }
}
