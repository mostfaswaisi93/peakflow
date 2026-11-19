<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\TraceRequest;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Telemetry\Alerts\Alert;
use App\Support\Telemetry\Alerts\InvariantWatch;
use App\Support\Telemetry\AllowlistProcessor;
use App\Support\Telemetry\Contracts\MetricSink;
use App\Support\Telemetry\Contracts\SpanExporter;
use App\Support\Telemetry\CorrelationId;
use App\Support\Telemetry\Exporters\CollectingSpanExporter;
use App\Support\Telemetry\Exporters\LogSpanExporter;
use App\Support\Telemetry\Exporters\NullSpanExporter;
use App\Support\Telemetry\Metrics\AgeBucket;
use App\Support\Telemetry\Metrics\Audience;
use App\Support\Telemetry\Metrics\MetricSample;
use App\Support\Telemetry\Metrics\Metrics;
use App\Support\Telemetry\Metrics\MetricScope;
use App\Support\Telemetry\Metrics\MetricSource;
use App\Support\Telemetry\Sampler;
use App\Support\Telemetry\Sinks\CollectingAlertSink;
use App\Support\Telemetry\Sinks\CollectingMetricSink;
use App\Support\Telemetry\Sinks\LogMetricSink;
use App\Support\Telemetry\Span;
use App\Support\Telemetry\SpanKind;
use App\Support\Telemetry\StatementShape;
use App\Support\Telemetry\Tracer;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What a trace, a metric and an alert may contain, and whether they fire.
 *
 * PF-240, PF-241, PF-242.
 *
 * ## The failure this suite exists because of
 *
 * `config/telemetry.php` declared a complete observability design - a sample
 * rate, a list of operations to keep whatever the rate, a database span
 * carrying the statement shape, ten named invariant alerts - and **nothing in
 * the application read any of it**. `grep` for `otlp_endpoint`,
 * `always_sample`, `invariant_alerts`, `record_statement_shape` or `sql_shape`
 * across `app/` returned a single hit, and that hit was the production
 * configuration guard refusing a value.
 *
 * So the whole of it was a document in the shape of a config file: nine of
 * the ten alerts named conditions the product can genuinely detect, every one
 * of them had a command that detects it, and nothing joined the two. An alert
 * that cannot fire is the purest form of this failure, because it looks like
 * coverage, it costs nothing to run, and the first time anybody learns it
 * never worked is the incident it was built for.
 *
 * Which is why every check here is behavioural. Each one breaks an invariant
 * - drifts a balance, unbalances an entry, cuts the hash chain, grants a
 * privilege nobody should hold - and asserts that the alert fires and names
 * the entity, the period and the first divergent record. None of them asserts
 * that a watcher is registered, because that was true of the whole design
 * while it did nothing.
 */
final class ObservabilityRules
{
    /*
     | The bank side, which carries the selling and payable sides with it.
     |
     | Five of the seven business metrics are about money that has moved and
     | not settled - unmatched statement lines, suspense, in transit, prepared
     | runs - so the fixture has to be able to produce each of those states
     | through the real services. A narrower fixture would leave those checks
     | asserting that a query runs, which is what they must not do.
     */
    use Concerns\OpensBankAccounts;
    use Concerns\PreparesPaymentRuns;
    use Concerns\ProvesTheLedger;

    /**
     * The tenant the fixture is pointed at.
     *
     * Held because the alerts and the runbook's first responses are both
     * per tenant: an alert that did not name one could not be routed to a
     * customer, and a runbook command without one does nothing.
     */
    private string $tenantSlug = '';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['observability (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->tenantSlug = (string) $tenant->slug;

        /*
         | FIRST, and outside any transaction.
         |
         | The one check that drives a real queued job cannot run inside one.
         | `QueueTenancyServiceProvider` forgets the tenant connection before
         | every job - correctly, because a worker must not inherit the
         | previous job's database - and forgetting it abandons any
         | transaction this suite was holding, taking the fixture with it.
         | The same hazard `bin/verify public` is excluded from `all` for.
         */
        $untransacted = [
            'one trace holds the request, the job, the outbound call and the statement'
                => $this->oneTraceAcrossTheThree(),

            /*
             | And the runbook check, for the same reason.
             |
             | It RUNS each documented first response, and every one of them
             | is a per-tenant command that points the tenant connection -
             | which purges it, and purging it abandons any transaction this
             | suite was holding. Found the hard way: the whole suite came
             | back as "ROLLBACK TO SAVEPOINT can only be used in transaction
             | blocks", which is what a lost transaction looks like from
             | three checks later.
             */
            'every declared alert has a runbook entry whose first response changes nothing'
                => $this->everyAlertHasATestedRunbookEntry(),

            'invariants:game-day runs, injects what it can, and rolls it all back'
                => $this->theGameDayCommandRuns(),
        ];

        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();

            return $untransacted + $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- PF-240 · distributed tracing -------------------------
                'the request span is wired into the global stack, inside the correlation id'
                    => fn (): array => $this->theRequestSpanIsWired(),
                'and the four global middleware run in the order bootstrap declares'
                    => fn (): array => $this->theGlobalOrderIsTheDeclaredOne(),
                'a database span carries the statement shape and never a bound value'
                    => fn (): array => $this->statementShapeNotBindings(),
                'two statements differing only in their values are one shape'
                    => fn (): array => $this->shapesAreLowCardinality(),
                'the sampler keeps every money-moving operation at a rate of zero'
                    => fn (): array => $this->moneyMovingIsAlwaysSampled(),
                'the sampler keeps an error at a rate of zero'
                    => fn (): array => $this->errorsAreAlwaysSampled(),
                'every always-sampled operation is emitted by production code'
                    => fn (): array => $this->alwaysSampledNamesHaveCallers(),
                'a span attribute goes through the same allowlist as a log line'
                    => fn (): array => $this->spanAttributesAreFiltered(),
                'the channel a span is written on filters its own lines'
                    => fn (): array => $this->theSpanChannelIsFiltered(),
                'the exporter is a driver whose committed default needs no credentials'
                    => fn (): array => $this->theExporterIsADriver(),
                'nothing is traced when telemetry is off'
                    => fn (): array => $this->tracingIsOffByDefault(),
                'an outbound call that never answers is in the trace, as an error'
                    => fn (): array => $this->orphanedOutboundSpansAreClosed(),

                // ---- PF-241 · business metrics ----------------------------
                'every audience has a dashboard, and no two of them are the same'
                    => fn (): array => $this->everyAudienceHasADashboard(),
                'a metric may not label by a party, a document or a correlation id'
                    => fn (): array => $this->metricLabelsAreRefused(),
                'each figure moves when the fact it measures changes'
                    => fn (): array => $this->theFiguresFollowTheFacts(),
                'metrics:report publishes through a driver, and a figure nobody could compute is not zero'
                    => fn (): array => $this->theReportPublishesThroughADriver(),

                // ---- PF-242 · invariant alerting --------------------------
                'an alert cannot be built without naming the entity, the period and the record'
                    => fn (): array => $this->anAlertNamesTheThing(),
                'every declared alert is watched, or the runbook says why not'
                    => fn (): array => $this->everyDeclaredAlertIsWatched(),
                'breaking each invariant fires its own alert, and it names the breach'
                    => fn (): array => $this->theGameDay(),
                'a watcher that throws raises an alert rather than reporting health'
                    => fn (): array => $this->aThrowingWatcherAlerts(),
                'an alert still fires when telemetry is switched off'
                    => fn (): array => $this->alertsAreNotGatedOnTracing(),
                'the watch is scheduled, and a failed run is itself an alert'
                    => fn (): array => $this->theWatchIsScheduled(),
                'an alert carries no party name, no IBAN and no bound value'
                    => fn (): array => $this->alertsCarryNoSecrets(),
                'the alert sink is a driver whose committed default needs no paging provider'
                    => fn (): array => $this->theAlertSinkIsADriver(),
            ]), $this->entityId);
        } catch (Throwable $e) {
            return $untransacted + ['observability could not be checked' => [
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]];
        } finally {
            $this->connection->rollBack();
        }
    }

    // =========================================================================
    //  PF-240 · distributed tracing
    // =========================================================================

    /**
     * The whole of PF-240's first two criteria, in one check.
     *
     * "Why did this invoice take four minutes to clear" is one trace, and the
     * only way to prove that is to produce all four kinds of span in one
     * causal chain and read the trace ids back. A check that asserted the
     * middleware is registered and the queue hook exists would be satisfied
     * by two hooks that generate independent ids - which is the ordinary bug,
     * because each one is correct on its own.
     *
     * @return list<string>
     */
    private function oneTraceAcrossTheThree(): array
    {
        $violations = [];
        $exporter = new CollectingSpanExporter();
        $tracer = new Tracer($exporter, new Sampler(['errors'], 1000), true);

        app()->instance(Tracer::class, $tracer);

        CorrelationId::forget();
        $traceId = CorrelationId::adopt('OBSPROBE'.Str::upper(Str::random(8)));

        Http::fake(['*' => Http::response('{}', 200)]);

        try {
            $exporter->forget();

            (new TraceRequest())->handle(
                Request::create('/up', 'GET'),
                static function (Request $request): Response {
                    unset($request);

                    DB::connection('tenant')->select('select 1 as one');
                    Http::get('https://clearance.example.test/submit');

                    return new Response('ok', 200);
                },
            );

            /*
             | A real queued job on the `sync` driver, not a hand-built
             | event.
             |
             | The thing under test is that the id travels on the PAYLOAD and
             | is adopted rather than regenerated, and a hand-built
             | `JobProcessing` would carry whatever payload the check wrote -
             | which is the assertion making itself true.
             */
            $previous = config('queue.default');
            config(['queue.default' => 'sync']);

            try {
                dispatch(static function (): void {
                    // The span is the point; the job has nothing to do.
                });
            } finally {
                config(['queue.default' => $previous]);
            }
        } catch (Throwable $e) {
            return ['the trace probe could not run: '.Str::limit($e->getMessage(), 200)];
        } finally {
            app()->forgetInstance(Tracer::class);
            CorrelationId::forget();
        }

        $byName = [];

        foreach ($exporter->spans() as $span) {
            $byName[$span->name][] = $span;
        }

        $server = $byName['http.server'][0] ?? null;

        foreach (['http.server' => 'the HTTP request', 'queue.job' => 'the queued job', 'http.client' => 'the outbound transport'] as $name => $what) {
            if (! isset($byName[$name])) {
                $violations[] = sprintf(
                    '%s produced no span called "%s". PF-240 asks for spans across the request, the '.
                    'queued job and the outbound transport: two of the three is three log searches '.
                    'again',
                    $what,
                    $name,
                );
            }
        }

        $database = array_values(array_filter(
            $exporter->spans(),
            static fn (Span $span): bool => $span->kind === SpanKind::Database,
        ));

        if ($database === []) {
            $violations[] = 'no database span was produced at all, so a statement is invisible inside '.
                'the request that issued it - which is where the four minutes usually are';
        }

        foreach ($exporter->spans() as $span) {
            if ($span->traceId !== $traceId) {
                $violations[] = sprintf(
                    'the "%s" span is on trace %s and the request was on %s. Two ids for one causal '.
                    'chain cannot be joined, which is the one thing a correlation id exists for - and '.
                    'a job that GENERATES its own id is correct, useless and indistinguishable from '.
                    'working',
                    $span->name,
                    $span->traceId,
                    $traceId,
                );

                break;
            }
        }

        if ($server !== null) {
            foreach (['http.client', 'db.select'] as $child) {
                $candidate = $byName[$child][0] ?? null;

                if ($candidate !== null && $candidate->parentSpanId !== $server->spanId) {
                    $violations[] = sprintf(
                        'the "%s" span is not a child of the request span, so a viewer draws it as a '.
                        'separate fragment and the request\'s own time cannot be attributed to it',
                        $child,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Wired, and INSIDE the correlation id.
     *
     * The ordering is the half that can be silently wrong. `prepend` goes in
     * front of whatever is already there, so the last prepend runs first -
     * which means a line added in the obvious place puts the span OUTSIDE
     * `AssignCorrelationId`, where `CorrelationId::current()` returns
     * whatever the previous request left bound. Two requests under one trace
     * id is worse than neither having one, because it reads as causation.
     *
     * @return list<string>
     */
    private function theRequestSpanIsWired(): array
    {
        $violations = [];
        $stack = $this->globalMiddleware();

        $span = array_search(TraceRequest::class, $stack, true);
        $correlation = array_search(AssignCorrelationId::class, $stack, true);

        if ($span === false) {
            return [sprintf(
                '%s is not in the global middleware stack, so nothing opens a request span and every '.
                'other span in the trace is a root. Stack: %s',
                TraceRequest::class,
                implode(', ', array_slice($stack, 0, 6)),
            )];
        }

        if ($correlation === false) {
            return ['AssignCorrelationId is not in the global stack at all'];
        }

        if ((int) $span < (int) $correlation) {
            $violations[] = 'the request span runs OUTSIDE AssignCorrelationId. The trace id is the '.
                'correlation id, so a span opened before it is bound adopts whatever the previous '.
                'request left - and in a long-running worker that is the previous customer\'s trace';
        }

        return $violations;
    }

    /**
     * The four global middleware, in the order `bootstrap/app.php` declares.
     *
     * ## Why an absolute order and not four relative rules
     *
     * `prepend()` puts a middleware in FRONT of what is there, so the LAST
     * prepend runs FIRST. Three of the four comments in `bootstrap/app.php`
     * each claimed the outermost position for their own middleware - the
     * correlation id said "FIRST, before anything that might log", the
     * security headers said "prepended, so it is the LAST middleware the
     * response passes on the way out" - and the one written last won.
     * Measured before the fix:
     *
     *     SecurityHeaders, SetLocale, AssignCorrelationId, TraceRequest
     *
     * So the correlation id ran third. A failure inside `SecurityHeaders` or
     * `SetLocale` answered with no `X-Correlation-Id`, which
     * `docs/operations/observability.md` promises "including on an error
     * response - the error page is exactly when somebody needs it". And
     * `AssignCorrelationId`'s `finally` never ran for such a request, so
     * under a long-lived worker the id leaked into the next one: two
     * unrelated requests joined in the logs, which is worse than no id
     * because it reads as causation.
     *
     * Each of those four comments was individually correct about its own
     * requirement and collectively wrong about the result. Relative rules
     * have the same failure: four pairwise assertions can all hold while
     * the order still is not the one anybody intended. So this asserts the
     * whole sequence, and the sequence is written down in one place.
     *
     * Only our own middleware is compared. The framework's entries are not
     * this product's decision and asserting their positions would make the
     * check fail on a Laravel upgrade that is none of its business.
     *
     * @return list<string>
     */
    private function theGlobalOrderIsTheDeclaredOne(): array
    {
        $expected = [
            AssignCorrelationId::class,
            \App\Http\Middleware\SecurityHeaders::class,
            TraceRequest::class,
            \App\Http\Middleware\SetLocale::class,
        ];

        $ours = array_values(array_filter(
            $this->globalMiddleware(),
            static fn (string $class): bool => str_starts_with($class, 'App\\Http\\Middleware\\'),
        ));

        if ($ours === $expected) {
            return [];
        }

        return [sprintf(
            'the global middleware run as [%s] and bootstrap/app.php declares [%s]. The order is '.
            'load-bearing in both directions: the correlation id has to be outermost so that every '.
            'response and every log line carries it and its `finally` always runs, and the security '.
            'headers have to be next so an error response rendered inside the unwinding stack still '.
            'passes back through them.',
            implode(', ', array_map(static fn (string $c): string => class_basename($c), $ours)),
            implode(', ', array_map(static fn (string $c): string => class_basename($c), $expected)),
        )];
    }

    /**
     * The shape, and not one byte of what was bound.
     *
     * Driven with an IBAN, a card number and an amount, because those are the
     * three things a `numeric` and a `varchar` binding in this product
     * actually hold - and the check asserts the bytes of the exported span
     * rather than inspecting an attribute by name, so a binding arriving
     * under a name nobody thought of is still caught.
     *
     * @return list<string>
     */
    private function statementShapeNotBindings(): array
    {
        $iban = 'SA0380000000608010167519';
        $card = '4111111111111111';
        $amount = '1234567.8900';

        $violations = [];

        /*
         | And the configuration is told to record bindings.
         |
         | `telemetry.database.record_bindings` is refused by the production
         | guard, which is the right control for a deployment and no control
         | at all over the code: a future span builder could read the flag and
         | honour it. There is no path here that would, and this is what says
         | so - the hole stays closed even when the configuration asks for it
         | to be opened.
         */
        $was = config('telemetry.database.record_bindings');
        config(['telemetry.database.record_bindings' => true]);

        try {
            $exporter = $this->whileTracing(static function () use ($iban, $card, $amount): void {
                DB::connection('tenant')->select(
                    'select ? as a, ? as b, ?::numeric as c',
                    [$iban, $card, $amount],
                );
            });
        } finally {
            config(['telemetry.database.record_bindings' => $was]);
        }

        $spans = array_values(array_filter(
            $exporter->spans(),
            static fn (Span $span): bool => $span->kind === SpanKind::Database,
        ));

        if ($spans === []) {
            return ['no database span was produced, so nothing was checked'];
        }

        foreach ($spans as $span) {
            $bytes = (string) json_encode($span->toArray());

            foreach (['the IBAN' => $iban, 'the card number' => $card, 'the amount' => $amount] as $what => $secret) {
                if (str_contains($bytes, $secret)) {
                    $violations[] = sprintf(
                        '%s reached a database span. A trace is a log with a nicer viewer, it is kept '.
                        'for weeks on somebody else\'s infrastructure and it is read by engineers - so '.
                        'a bound value in one is customer data with none of the controls customer data '.
                        'needs',
                        $what,
                    );
                }
            }
        }

        $shape = $spans[0]->attribute('sql_shape');

        if (! is_string($shape) || $shape === '') {
            $violations[] = 'the database span carries no statement shape, so it says a query happened '.
                'and not which one - which is a span per statement, each one saying nothing';
        }

        /*
         | And turning the shape off turns it off.
         |
         | Otherwise `record_statement_shape` is the third key in this config
         | file that nothing reads, which is the defect this whole suite was
         | written after finding.
         */
        $was = config('telemetry.database.record_statement_shape');
        config(['telemetry.database.record_statement_shape' => false]);

        try {
            $silent = $this->whileTracing(static function (): void {
                DB::connection('tenant')->select('select 2 as two');
            });
        } finally {
            config(['telemetry.database.record_statement_shape' => $was]);
        }

        foreach ($silent->spans() as $span) {
            if ($span->kind === SpanKind::Database && $span->attribute('sql_shape') !== null) {
                $violations[] = 'telemetry.database.record_statement_shape = false still produced a '.
                    'shape, so the setting is decoration';

                break;
            }
        }

        return $violations;
    }

    /**
     * A span name and a statement shape are both LOW CARDINALITY.
     *
     * Which is not tidiness. A viewer groups by them, so a shape holding a
     * literal is a group of one and "which statement is slow" cannot be
     * answered at all - the trace is collected, stored, paid for and
     * useless.
     *
     * @return list<string>
     */
    private function shapesAreLowCardinality(): array
    {
        $violations = [];

        $pairs = [
            ['select * from accounts where code = \'1.1.2.1\'', 'select * from accounts where code = \'4.1\''],
            ['select * from accounts where id in (\'a\', \'b\', \'c\')', 'select * from accounts where id in (\'x\')'],
            ['update invoices set net_amount = 1000.0000 where id = \'01M7\'', 'update invoices set net_amount = 7.5 where id = \'01M8\''],
        ];

        foreach ($pairs as [$first, $second]) {
            $one = StatementShape::of($first);
            $two = StatementShape::of($second);

            if ($one !== $two) {
                $violations[] = sprintf(
                    'two statements differing only in their values produced two shapes - "%s" and "%s". '.
                    'A viewer grouping by shape then has one group per query',
                    $one,
                    $two,
                );
            }
        }

        /*
         | And the partition name SURVIVES, because it is part of the
         | statement rather than a value.
         |
         | The naive "replace every digit" rule collapses
         | `journal_lines_2026_05` and `journal_lines_2026_06` into one shape,
         | and this product's slow query is routinely "the wrong partition was
         | scanned".
         */
        $partitioned = StatementShape::of('select * from journal_lines_2026_05 where amount > 100');

        if (! str_contains($partitioned, 'journal_lines_2026_05')) {
            $violations[] = sprintf(
                'the partition name did not survive shaping: "%s". A rule that eats every digit cannot '.
                'tell journal_lines_2026_05 from journal_lines_2026_06, and "the wrong partition was '.
                'scanned" is this product\'s commonest slow query',
                $partitioned,
            );
        }

        if (str_contains($partitioned, '100')) {
            $violations[] = 'an amount survived shaping while the partition name was being preserved';
        }

        /*
         | And the names the live tracer produces are from a small set.
         |
         | Asserted against spans the real hooks emitted rather than against
         | the source, because the name is assembled at runtime - `db.` plus
         | a verb - and a name holding a document id would read perfectly in
         | the source that built it.
         */
        $exporter = $this->whileTracing(function (): void {
            DB::connection('tenant')->table('accounts')->where('entity_id', $this->entityId)->limit(1)->get();
        });

        foreach ($exporter->spans() as $span) {
            if (preg_match('/[0-9A-HJKMNP-TV-Z]{26}|\d{4,}/', $span->name) === 1) {
                $violations[] = sprintf(
                    'the span name "%s" holds an identifier. A name is what a viewer groups by, so one '.
                    'per document is one group per document',
                    $span->name,
                );
            }
        }

        return $violations;
    }

    /**
     * At a rate of ZERO, every money-moving operation is still kept.
     *
     * Asserted twice: against the sampler, for every name the configuration
     * lists, and behaviourally - a real invoice issued with the rate set to
     * zero must still produce `invoice.issue` and `journal.post` spans while
     * an ordinary internal span produces nothing.
     *
     * The behavioural half is the one that matters. A sampler that gets this
     * right while no production code passes those names is a correct
     * implementation of a policy that is never applied, and that is exactly
     * what `always_sample` was before PF-240 was built: six operation names,
     * no emitter, nothing to sample.
     *
     * @return list<string>
     */
    private function moneyMovingIsAlwaysSampled(): array
    {
        $violations = [];
        $configured = $this->alwaysSampled();
        $sampler = new Sampler($configured, 0);

        foreach ($configured as $operation) {
            if ($operation === 'errors') {
                continue;
            }

            if (! $sampler->shouldSample($operation)) {
                $violations[] = sprintf(
                    '"%s" was sampled away at a rate of zero. A sampled-away payment release is the one '.
                    'trace you needed, and you find that out when somebody asks what happened to the '.
                    'money',
                    $operation,
                );
            }
        }

        if ($sampler->shouldSample('page.render')) {
            $violations[] = 'an operation that is not on the list was kept at a rate of zero, so the '.
                'rate does nothing and the list means nothing';
        }

        $exporter = $this->whileTracing(function (): void {
            $this->issue();

            /*
             | And a period close, because those two names are chosen by a
             | `match` rather than written at the call site.
             |
             | A file-level scan cannot tell a `match` arm that is reachable
             | from one that is not, so the one of the pair that can be
             | driven without a step-up assertion is driven here. The soft
             | close is a close: it consults the same registry and is the
             | transition an accountant makes on the first working day of the
             | month.
             */
            $period = $this->period;

            if ($period !== null) {
                app(\App\Tenant\Ledger\Services\PeriodStateMachine::class)->transition(
                    $period,
                    \App\Tenant\Ledger\Enums\PeriodState::SoftClosed,
                    'the operational close, for the sampling check',
                );
            }
        }, $configured, 0);

        foreach (['invoice.issue', 'journal.post', 'period.close'] as $expected) {
            if ($exporter->named($expected) === []) {
                $violations[] = sprintf(
                    'issuing a real invoice at a rate of zero produced no "%s" span. The name is in '.
                    'config/telemetry.php and nothing passes it, which makes the sampling policy a '.
                    'list of words',
                    $expected,
                );
            }
        }

        return $violations;
    }

    /**
     * An error is kept whatever the rate, and the decision is made at the END.
     *
     * Which is the awkward half of head sampling: a span starts before
     * anything has gone wrong, so "always keep errors" cannot be a start-time
     * decision. A sampler that only decided at the start would drop the trace
     * of every failure at a rate below one - silently, and in exactly the
     * proportion the rate names.
     *
     * @return list<string>
     */
    private function errorsAreAlwaysSampled(): array
    {
        $violations = [];
        $sampler = new Sampler(['errors'], 0);

        if (! $sampler->keepAfterTheFact('page.render', false, true)) {
            $violations[] = 'an unsampled span that ended in an error was dropped. The sampling '.
                'decision is made when a span starts and nothing has gone wrong yet, so a sampler '.
                'that does not ask again at the end cannot keep errors at all';
        }

        if ($sampler->keepAfterTheFact('page.render', false, false)) {
            $violations[] = 'a successful unsampled span was kept at a rate of zero, so nothing is '.
                'ever sampled away and the rate is decoration';
        }

        $exporter = $this->whileTracing(static function (): void {
            $tracer = app(Tracer::class);

            try {
                $tracer->measure('page.render', SpanKind::Internal, [], static function (): void {
                    throw new \RuntimeException('a deliberate failure, for the sampler');
                });
            } catch (Throwable) {
                // The throw is the point: `measure()` must re-raise it.
            }

            $tracer->measure('page.render', SpanKind::Internal, [], static fn (): bool => true);
        }, ['errors'], 0);

        $kept = $exporter->named('page.render');

        if (count($kept) !== 1) {
            $violations[] = sprintf(
                'a failing and a succeeding unsampled span produced %d kept span(s) and not exactly '.
                'the failing one',
                count($kept),
            );
        } elseif (! $kept[0]->isError()) {
            $violations[] = 'the span that was kept at a rate of zero is the one that succeeded';
        }

        return $violations;
    }

    /**
     * Every name in `always_sample` is passed by something in `app/`.
     *
     * A source scan, and deliberately so: the behavioural check above proves
     * two of the five, and proving the other three would mean releasing a
     * payment run and filing a tax return inside this suite - which other
     * suites already do at length. What this adds is the thing a behavioural
     * check cannot: that no name on the list is a name nothing emits.
     *
     * @return list<string>
     */
    private function alwaysSampledNamesHaveCallers(): array
    {
        $violations = [];
        $sources = [];

        foreach ($this->phpFiles(base_path('app')) as $file) {
            $sources[$file] = (string) file_get_contents($file);
        }

        foreach ($this->alwaysSampled() as $operation) {
            if ($operation === 'errors') {
                continue;
            }

            $emitted = false;

            foreach ($sources as $source) {
                /*
                 | The name AND a `measure()` call, in the SAME FILE.
                 |
                 | The first version of this looked for the quoted name
                 | anywhere in `app/` and did not bite: four of the five
                 | names on the list - `journal.post`, `invoice.issue`,
                 | `payment.release`, `period.reopen` - are also the names of
                 | Gate abilities, so deleting the only span that emits one
                 | left the string in `app/Support/Authorization` and the
                 | check went on passing. A bare substring search is a check
                 | that cannot fail for exactly the operations that matter
                 | most.
                 |
                 | Not "the first argument to measure()" either, which was
                 | the second attempt: `period.close` and `period.reopen` are
                 | chosen by a `match` on the target state a few lines above
                 | the call, because which of the two a transition is is not
                 | something the caller gets to say. Requiring the literal in
                 | the argument position would have forced that decision into
                 | five call sites.
                 */
                if (str_contains($source, "'".$operation."'") && str_contains($source, '->measure(')) {
                    $emitted = true;

                    break;
                }
            }

            if (! $emitted) {
                $violations[] = sprintf(
                    '"%s" is kept at any sample rate and no file in app/ both names it and opens a '.
                    'span. A list of always-sampled operation names with no emitter is a policy '.
                    'applied to nothing, and it reads in a config file exactly like one that works',
                    $operation,
                );
            }
        }

        return $violations;
    }

    /**
     * A span attribute is a log line for the purposes of the allowlist.
     *
     * Driven with the adversarial case as well as the obvious one: an IBAN
     * under the ALLOWLISTED name `reference`, which is the case a field
     * allowlist cannot catch and the value-shape layer exists for. A span
     * that filtered field names only would pass every obvious check and leak
     * on the one that happens in practice, because `reference` is what a
     * caller reaches for.
     *
     * @return list<string>
     */
    private function spanAttributesAreFiltered(): array
    {
        $violations = [];
        $iban = 'SA0380000000608010167519';

        $exporter = $this->whileTracing(static function () use ($iban): void {
            app(Tracer::class)->measure('page.render', SpanKind::Internal, [
                'iban' => $iban,
                'counterparty_name' => 'Acme Industrial LLC',
                'email' => 'someone@example.test',
                'password' => 'correct-horse-battery-staple',
                // The adversarial half: a secret under a name that IS on the
                // list.
                'reference' => $iban,
                // And a diagnostic, which has to survive.
                'document_number' => 'INV-2026-000471',
            ], static fn (): bool => true);
        });

        $spans = $exporter->named('page.render');

        if ($spans === []) {
            return ['no span was exported, so nothing was checked'];
        }

        $bytes = (string) json_encode($spans[0]->toArray());

        foreach ([
            'an IBAN' => $iban,
            'a counterparty name' => 'Acme Industrial LLC',
            'an email address' => 'someone@example.test',
            'a password' => 'correct-horse-battery-staple',
        ] as $what => $secret) {
            if (str_contains($bytes, $secret)) {
                $violations[] = sprintf(
                    '%s survived into a span attribute. A trace is a log with a nicer viewer and it '.
                    'leaks the same way, so the allowlist has to govern both - and the only version of '.
                    'that which survives the next caller is one where a span cannot be built without '.
                    'it',
                    $what,
                );
            }
        }

        if (! str_contains($bytes, 'INV-2026-000471')) {
            $violations[] = 'a document number did not survive the filter. A scrubber that eats the '.
                'diagnostics is a scrubber somebody turns off';
        }

        return $violations;
    }

    /**
     * The channel a span is written on must carry the allowlist processor.
     *
     * ## The gap this closes
     *
     * `LoggingRules::tapsAreApplied()` resolves every channel that DECLARES
     * the tap and asserts the processor is attached - which is the right
     * check for the question it asks and silently skips every channel that
     * declares no tap at all. `stderr`, `syslog`, `slack`, `monthly` and
     * `errorlog` in `config/logging.php` declare none, and nothing in the
     * repository forbids `LOG_STACK=stderr` or `TELEMETRY_LOG_CHANNEL=stderr`
     * - which in a container is the ordinary choice.
     *
     * So a span exported through one of those would be unfiltered while
     * looking configured, which is the exact failure mode the allowlist was
     * written against. This asserts the channel the exporter actually
     * resolves, by name.
     *
     * @return list<string>
     */
    private function theSpanChannelIsFiltered(): array
    {
        $violations = [];
        $channel = (new LogSpanExporter())->channel();

        $resolved = [];

        /*
         | A stack is its own channels, and every one of them counts.
         |
         | The default is `stack` -> `single`, so asking about `stack` itself
         | finds no processor and no handler: the filtering lives on the
         | channels inside it. A check that looked only at the named channel
         | would pass on a stack holding one tapped channel and one untapped
         | one, which is the worst available outcome because it looks
         | redacted.
         */
        $definition = (array) config('logging.channels.'.$channel, []);

        if (($definition['driver'] ?? null) === 'stack') {
            foreach ((array) ($definition['channels'] ?? []) as $inner) {
                $resolved[] = (string) $inner;
            }
        } else {
            $resolved[] = $channel;
        }

        if ($resolved === []) {
            return [sprintf('telemetry.channel resolves to "%s", which names no channel at all', $channel)];
        }

        foreach ($resolved as $name) {
            try {
                $monolog = app('log')->channel($name)->getLogger();
            } catch (Throwable $e) {
                $violations[] = sprintf('channel "%s" could not be resolved: %s', $name, $e->getMessage());

                continue;
            }

            $processors = array_map(
                static fn (object $processor): string => $processor::class,
                $monolog->getProcessors(),
            );

            if (! in_array(AllowlistProcessor::class, $processors, true)) {
                $violations[] = sprintf(
                    'spans and alerts are written on channel "%s", which has no allowlist processor '.
                    'attached. Every span attribute and every alert field would reach the log '.
                    'unfiltered, while config/telemetry.php still says there is an allowlist',
                    $name,
                );
            }

            foreach ($monolog->getHandlers() as $handler) {
                if (! method_exists($handler, 'getFormatter')) {
                    continue;
                }

                if (! $handler->getFormatter() instanceof JsonFormatter) {
                    $violations[] = sprintf(
                        'channel "%s" writes spans through %s rather than a JSON formatter, so a span '.
                        'is a line a collector parses with a regular expression',
                        $name,
                        $handler->getFormatter()::class,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * PF-229's rule, applied to telemetry.
     *
     * A fresh clone has to trace end to end with no collector, no endpoint
     * and no credentials - otherwise tracing is code that has never run, and
     * the first time it runs is the first time it matters.
     *
     * @return list<string>
     */
    private function theExporterIsADriver(): array
    {
        $violations = [];

        if ((string) config('telemetry.exporter') !== 'log') {
            $violations[] = sprintf(
                'telemetry.exporter is "%s". The committed default has to need no third party, the '.
                'same rule EXCHANGE_DRIVER=log and PDF_DRIVER=stub answer to',
                (string) config('telemetry.exporter'),
            );
        }

        if (config('telemetry.enabled') !== false) {
            $violations[] = 'telemetry.enabled is not false by default. A span per database statement '.
                'in a product whose reports read ten million journal lines is not a setting to arrive '.
                'at by accident';
        }

        /*
         | Disabled means NULL, whatever the driver says.
         |
         | Checked because the two settings are independent and the dangerous
         | combination is the quiet one: `enabled=false` with `exporter=log`
         | must not write, or every deployment that has not thought about
         | telemetry starts paying for it.
         */
        $exporter = app(SpanExporter::class);

        if (! $exporter instanceof NullSpanExporter) {
            $violations[] = sprintf(
                'with telemetry disabled the bound exporter is %s rather than the null one',
                $exporter::class,
            );
        }

        /*
         | And no driver reads a credential or an endpoint.
         |
         | The committed set has to work with nothing configured, so if one of
         | them resolves a URL or a key at construction time then a fresh
         | clone has a tracing path that fails on the first span.
         */
        foreach ($this->phpFiles(base_path('app/Support/Telemetry/Exporters')) as $file) {
            $source = (string) file_get_contents($file);

            foreach (['otlp_endpoint', 'OTEL_EXPORTER', 'api_key', 'getenv('] as $needle) {
                if (str_contains($source, $needle)) {
                    $violations[] = sprintf(
                        '%s reads "%s". A committed default that needs an endpoint or a credential is '.
                        'a tracing path a fresh clone cannot exercise',
                        basename($file),
                        $needle,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Off means off, and it costs nothing.
     *
     * The argument for the default being off is only honest if the off state
     * is actually free. A tracer that built and filtered spans and then
     * dropped them would be a per-statement cost on every deployment that
     * never asked for tracing - and nobody would find it, because the
     * symptom is "the product is a bit slow".
     *
     * @return list<string>
     */
    private function tracingIsOffByDefault(): array
    {
        $violations = [];
        $exporter = new CollectingSpanExporter();

        $tracer = new Tracer($exporter, new Sampler($this->alwaysSampled(), 1000), false);

        app()->instance(Tracer::class, $tracer);

        try {
            if ($tracer->isRecording()) {
                $violations[] = 'a tracer constructed as not recording says it is recording';
            }

            $span = $tracer->start('journal.post', SpanKind::Internal, []);

            if ($span !== null) {
                $violations[] = 'a span was opened while not recording, so the off state still builds '.
                    'and filters every span before dropping it';
            }

            DB::connection('tenant')->select('select 3 as three');

            $this->issue();

            if ($exporter->spans() !== []) {
                $violations[] = sprintf(
                    'issuing an invoice with telemetry off still exported %d span(s)',
                    count($exporter->spans()),
                );
            }
        } finally {
            app()->forgetInstance(Tracer::class);
        }

        return $violations;
    }

    /**
     * A connection failure leaves a span open, and it is still reported.
     *
     * Guzzle raises a connection failure before any response exists, so the
     * response hook never runs and the outbound span is still on the stack
     * when the request's span closes. Dropping it would hide exactly the
     * calls a trace is opened for: the ones to an authority that is down.
     *
     * @return list<string>
     */
    private function orphanedOutboundSpansAreClosed(): array
    {
        $violations = [];

        Http::fake(static function (): void {
            throw new \Illuminate\Http\Client\ConnectionException(
                'the authority did not answer',
            );
        });

        $exporter = $this->whileTracing(static function (): void {
            $tracer = app(Tracer::class);

            $tracer->measure('http.server', SpanKind::Server, [], static function (): void {
                try {
                    Http::get('https://clearance.example.test/submit');
                } catch (Throwable) {
                    // The point is the span that was left open, not the
                    // exception: the caller handles a transport failure.
                }
            });
        });

        $client = $exporter->named('http.client');

        if ($client === []) {
            $violations[] = 'an outbound call that threw produced no span. A connection failure is the '.
                'event a trace is opened for, and the hook that closes the span only runs when there '.
                'is a response to close it with';

            return $violations;
        }

        if (! $client[0]->isError()) {
            $violations[] = sprintf(
                'the orphaned outbound span was closed as "%s" rather than an error, so a failed call '.
                'is indistinguishable from one that worked',
                $client[0]->status(),
            );
        }

        $server = $exporter->named('http.server');

        if ($server !== [] && $client[0]->durationMicroseconds() > $server[0]->durationMicroseconds()) {
            $violations[] = 'the orphaned outbound span is longer than the request that contained it, '.
                'so it was closed by a later clock reading than its parent';
        }

        return $violations;
    }

    // =========================================================================
    //  PF-241 · business metrics
    // =========================================================================

    /**
     * Three dashboards, derived, and none of them a copy of another.
     *
     * The criterion is that the dashboards are per audience "because they ask
     * different questions". Two assertions make that true rather than
     * decorative: every audience has something on it, and no two audiences
     * see the same set. Equal sets would mean the audience field exists and
     * decides nothing, which is the state a single dashboard with three names
     * on it is in.
     *
     * @return list<string>
     */
    private function everyAudienceHasADashboard(): array
    {
        $violations = [];
        $metrics = app(Metrics::class);
        $sets = [];

        foreach (Audience::all() as $audience) {
            $keys = array_map(
                static fn (MetricSource $source): string => $source->key(),
                $metrics->forAudience($audience),
            );

            sort($keys);
            $sets[$audience->value] = $keys;

            if ($keys === []) {
                $violations[] = sprintf(
                    'the %s dashboard has no metric on it. A dashboard with nothing on it is read '.
                    'once, and an audience nobody built for is an audience whose questions are '.
                    'answered by somebody else\'s screen',
                    $audience->value,
                );
            }
        }

        foreach ($sets as $first => $one) {
            foreach ($sets as $second => $two) {
                if ($first < $second && $one === $two && $one !== []) {
                    $violations[] = sprintf(
                        'the %s and %s dashboards hold exactly the same metrics, so the audience '.
                        'decides nothing. A single dashboard with three names on it serves none of '.
                        'the three: queue depth is not a figure a controller can act on, and '.
                        'unmatched bank lines by age is not one an operator can',
                        $first,
                        $second,
                    );
                }
            }
        }

        /*
         | And every registered metric is on at least one of them.
         |
         | Which the registry refuses at registration - but the refusal is
         | only worth anything if it is the registry the application actually
         | uses, so the live one is asked.
         */
        foreach ($metrics->names() as $key) {
            $shown = false;

            foreach ($sets as $keys) {
                if (in_array($key, $keys, true)) {
                    $shown = true;

                    break;
                }
            }

            if (! $shown) {
                $violations[] = sprintf(
                    'the "%s" metric is registered and on no dashboard, so it is a query that runs '.
                    'for nothing',
                    $key,
                );
            }
        }

        return $violations;
    }

    /**
     * A label that identifies one record is refused at registration.
     *
     * ## Why this is the check and not a convention
     *
     * A metric labelled by customer id is a customer list on whoever's
     * dashboard. It is retained for weeks, it is read by engineers, and it is
     * exported to whatever the monitoring stack is - which is the set of
     * properties the field allowlist exists to keep customer data out of.
     *
     * It is also the label somebody reaches for at 2am, because it is the one
     * that makes a graph answer "which customer". So the refusal has to
     * happen at boot, loudly, rather than being a sentence in a doc.
     *
     * The adversarial case is the one that matters: `party_id` and
     * `document_number` ARE on the field allowlist, legitimately, because a
     * log line about one document should name the document. A registry that
     * only checked the allowlist would admit both.
     *
     * @return list<string>
     */
    private function metricLabelsAreRefused(): array
    {
        $violations = [];

        $cases = [
            'a party id' => ['party_id'],
            'a document number' => ['document_number'],
            'a correlation id' => ['correlation_id'],
            'a name that is not on the field allowlist at all' => ['counterparty_name'],
        ];

        foreach ($cases as $what => $labels) {
            $refusal = $this->refusalFromRegistering($labels, [Audience::Engineer]);

            if ($refusal === null) {
                $violations[] = sprintf(
                    'a metric labelled by %s was accepted. One series per record is a list of records '.
                    'on a dashboard and a chart no browser can draw - and %s',
                    $what,
                    $labels === ['counterparty_name']
                        ? 'a label the allowlist drops arrives as no label at all, so the metric is '.
                          'silently unlabelled and still looks right'
                        : 'the name is on the field allowlist because a LOG LINE about one document '.
                          'should name it, which is the opposite case from a time series',
                );
            }
        }

        // And a legitimate set is accepted, or the refusal is just a wall.
        if ($this->refusalFromRegistering(['entity_id', 'age_bucket', 'currency'], [Audience::Controller]) !== null) {
            $violations[] = 'a metric labelled by entity, age bucket and currency was refused. Those '.
                'are the labels PF-241 asks for by name - suspense balance BY ENTITY, unmatched lines '.
                'BY AGE - so a rule that refuses them has refused the requirement';
        }

        // A metric for nobody is refused too.
        if ($this->refusalFromRegistering(['entity_id'], []) === null) {
            $violations[] = 'a metric naming no audience was accepted, so it appears on no dashboard '.
                'and its query runs for nothing. The dashboards are derived from the audience, so a '.
                'metric without one cannot be shown anywhere';
        }

        return $violations;
    }

    /**
     * The check that makes the whole of PF-241 more than a set of queries.
     *
     * Each figure is read, the fact underneath it is changed through the real
     * service, and the figure is read again. A metric that returned a
     * plausible constant - because the query names a column that is always
     * null, or filters on a status nothing ever has - passes every structural
     * check ever written and is a flat line on a dashboard that somebody
     * trusts.
     *
     * Five of the seven are driven here. `documents_issued` and
     * `queue_health` are driven in the checks either side of this one.
     *
     * @return list<string>
     */
    private function theFiguresFollowTheFacts(): array
    {
        $violations = [];
        $metrics = app(Metrics::class);

        // ---- documents issued, per jurisdiction profile --------------------
        $before = $this->total($metrics, 'documents_issued');
        $invoice = $this->issue();
        $after = $this->total($metrics, 'documents_issued');

        if ($after !== $before + 1) {
            $violations[] = sprintf(
                'issuing one document moved documents_issued from %d to %d. A count that does not '.
                'follow the documents is a flat line somebody trusts',
                $before,
                $after,
            );
        }

        // ---- unmatched bank lines, by age ----------------------------------
        $before = $this->total($metrics, 'unmatched_bank_lines');

        $this->importLines([
            $this->line('900.0000', ['bank_reference' => 'OBS-UNMATCHED-1']),
            $this->line('-400.0000', ['bank_reference' => 'OBS-UNMATCHED-2']),
        ]);

        $after = $this->total($metrics, 'unmatched_bank_lines');

        if ($after !== $before + 2) {
            $violations[] = sprintf(
                'importing two unmatched statement lines moved unmatched_bank_lines from %d to %d. It '.
                'is the leading indicator of a reconciliation that is drifting, and a count that does '.
                'not move is the one number that will read as healthy while it does',
                $before,
                $after,
            );
        }

        $buckets = [];

        foreach ($this->samples($metrics, 'unmatched_bank_lines') as $sample) {
            $buckets[] = (string) ($sample->labels['age_bucket'] ?? '(none)');
        }

        /*
         | And the bucket follows the clock the metric is READ on.
         |
         | Read twice - once now, once with the clock on the fixture's own
         | month - and the two must differ, because the lines are six months
         | old today and days old then. A metric bucketing against the
         | DATABASE's `current_date` gives the same answer both times, which
         | is how a dashboard and a report dated the same day can disagree
         | by one bucket whenever the two clocks fall either side of
         | midnight. An entity in this product has its own timezone, so they
         | routinely do.
         */
        $was = \Illuminate\Support\Carbon::getTestNow();
        \Illuminate\Support\Carbon::setTestNow($this->date->copy());

        try {
            $thenBuckets = array_map(
                static fn (MetricSample $sample): string => (string) ($sample->labels['age_bucket'] ?? '?'),
                $this->samples($metrics, 'unmatched_bank_lines'),
            );
        } finally {
            \Illuminate\Support\Carbon::setTestNow($was);
        }

        sort($buckets);
        sort($thenBuckets);

        if ($buckets === $thenBuckets && $buckets !== []) {
            $violations[] = sprintf(
                'unmatched_bank_lines reported the same age buckets (%s) read six months apart, so it '.
                'is measured against the database clock rather than the one the figure is read on',
                implode(', ', $buckets),
            );
        }

        foreach ($buckets as $bucket) {
            if (! in_array($bucket, AgeBucket::ALL, true)) {
                $violations[] = sprintf(
                    'unmatched_bank_lines reported the age bucket "%s", which is not one of the four. '.
                    'Buckets that differ between metrics cannot be read side by side, which is the '.
                    'whole reason there is one set',
                    $bucket,
                );
            }
        }

        /*
         | And matching one takes it back out.
         |
         | The direction that is easy to get wrong: a query that counted
         | `matches` rows rather than UNREVERSED ones would move up on an
         | import and never move down, which reads as a backlog nobody is
         | clearing while somebody is clearing it.
         */
        $matched = $this->total($metrics, 'unmatched_bank_lines');

        /*
         | The match is written at the table against the line already
         | imported above.
         |
         | `applyAMatchDirectly()` imports a line of its OWN and then matches
         | it, which leaves the unmatched count exactly where it was - so
         | reaching for the shared helper here would have made this half of
         | the check unable to fail. It cost one confusing green.
         */
        $transaction = $this->transactionBy('OBS-UNMATCHED-1');

        $this->connection->table('matches')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'bank_transaction_id' => (string) $transaction->getKey(),
            'booking_date' => $transaction->booking_date->toDateString(),
            'matched_type' => 'invoice',
            'matched_id' => (string) $invoice->getKey(),
            'amount' => '900.0000',
            'currency' => (string) $transaction->currency,
            'pass' => 'exact_reference',
            'confidence' => '0.9000',
            'reason' => 'matched so the unmatched metric can be seen to fall',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($this->total($metrics, 'unmatched_bank_lines') !== $matched - 1) {
            $violations[] = 'matching a line did not take it out of unmatched_bank_lines, so the figure '.
                'only ever rises - which reads as a backlog nobody is clearing while somebody is';
        }

        /*
         | And REVERSING it puts the line back.
         |
         | This is the half the first version of this check was missing, and
         | the bite sweep found it: removing `reversed_at IS NULL` from the
         | query changed nothing, because no check ever reversed a match. A
         | reversal is how a wrong match is undone, so the lines that were
         | matched incorrectly and sent back are exactly the ones at risk of
         | being forgotten - and a metric that cannot see them is blind to
         | the set most likely to age.
         |
         | Written at the table rather than through the matching service: the
         | column is the thing under test, and a reversal performed by the
         | service is already proved by `bin/verify banking`.
         */
        $this->connection->table('matches')
            ->where('bank_transaction_id', (string) $transaction->getKey())
            ->update([
                'reversed_at' => now(),
                'reversed_by' => $this->secondActorId(),
                'reversal_reason' => 'matched to the wrong document, so the line is unexplained again',
            ]);

        if ($this->total($metrics, 'unmatched_bank_lines') !== $matched) {
            $violations[] = 'reversing a match did not put the bank line back among the unmatched. A '.
                'reversal is how a WRONG match is undone, so those lines are the ones most likely to '.
                'be forgotten - and a count that reads the existence of a match row rather than an '.
                'unreversed one cannot see any of them';
        }

        // ---- suspense, read from the ledger --------------------------------
        $before = $this->amountOf($metrics, 'suspense_balance');

        app(\App\Tenant\Banking\Services\Suspense::class)->park(
            $this->transactionBy('OBS-UNMATCHED-2'),
            $this->actorId(),
            'parked so the suspense metric has something to report',
        );

        $after = $this->amountOf($metrics, 'suspense_balance');

        if ($after->isEqualTo($before)) {
            $violations[] = sprintf(
                'parking a bank line left the suspense_balance metric at %s. Rising suspense means '.
                'matching is failing before anybody complains, and it is also what blocks the period '.
                'close - so a controller watching a figure that does not move meets it for the first '.
                'time on the day they are trying to close',
                (string) $before,
            );
        }

        // ---- payment runs prepared and not released ------------------------
        $before = $this->total($metrics, 'payment_runs_awaiting_release');

        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4000.0000', $this->date->toDateString());

        $run = app(\App\Tenant\Settlement\Services\PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: $this->date->copy(),
            userId: $this->actorId(),
        );

        if ($this->total($metrics, 'payment_runs_awaiting_release') !== $before + 1) {
            $violations[] = 'preparing a payment run did not appear in payment_runs_awaiting_release. '.
                'A prepared run is a set of beneficiaries and amounts that one person assembled and '.
                'nobody approved: forgotten, it is how the same payments get re-keyed by hand and '.
                'made twice';
        }

        // ---- in transit, after the release ---------------------------------
        $before = $this->amountOf($metrics, 'in_transit_ageing');

        app(\App\Tenant\Settlement\Services\PaymentRuns::class)->release(
            (string) $run['id'],
            $this->secondActorId(),
        );

        if ($this->total($metrics, 'payment_runs_awaiting_release') !== 0) {
            $violations[] = 'a RELEASED run is still counted as awaiting release, so the figure counts '.
                'every run ever prepared and never falls';
        }

        $after = $this->amountOf($metrics, 'in_transit_ageing');

        /*
         | And read again with the clock set to the day the run was paid.
         |
         | This is the half a bite found missing. `TransitClearing::aged()`
         | defaults to an age floor of five days, and the fixture lives six
         | months in the past - so every instrument it releases is already
         | 180 days old and clears the floor whatever the floor is. Dropping
         | the explicit "every open item" argument changed nothing and the
         | check stayed green, which is the same shape as a report whose
         | digest hashed `now()` and whose own fixture passed a fixed
         | timestamp.
         |
         | With the clock on the fixture's own day the instruction is zero
         | days old, so the default floor WOULD exclude it and the argument
         | that keeps it is load-bearing again. An ageing whose first bucket
         | is missing shows a position whose newest items are absent, and
         | "nothing is aged" then reads identically to "nothing is in
         | transit".
         */
        $was = \Illuminate\Support\Carbon::getTestNow();
        \Illuminate\Support\Carbon::setTestNow($this->date->copy());

        try {
            $young = $this->amountOf($metrics, 'in_transit_ageing');
            $youngBuckets = array_map(
                static fn (MetricSample $sample): string => (string) ($sample->labels['age_bucket'] ?? '?'),
                $this->samples($metrics, 'in_transit_ageing'),
            );
        } finally {
            \Illuminate\Support\Carbon::setTestNow($was);
        }

        if ($young->isZero()) {
            $violations[] = 'in_transit_ageing reported nothing for an instruction released on the day '.
                'it was read. The ageing defaults to an age FLOOR, so a metric that takes the default '.
                'shows a position whose newest items are missing - and "nothing is aged" then reads '.
                'identically to "nothing is in transit"';
        }

        if ($youngBuckets !== [] && ! in_array('0-1', $youngBuckets, true)) {
            $violations[] = sprintf(
                'an instruction released today landed in the %s bucket rather than 0-1, so the ageing '.
                'is measured against a different clock from the one it is read on',
                implode(', ', $youngBuckets),
            );
        }

        if (! $after->isGreaterThan($before)) {
            $violations[] = sprintf(
                'releasing a payment run left in_transit_ageing at %s. In transit is money that left '.
                'and has not appeared on a statement: at two weeks it is a file never handed to the '.
                'bank, a rejection nobody told us about, or a statement line nobody matched - and all '.
                'three end with a supplier who has not been paid and a ledger that says they have',
                (string) $before,
            );
        }

        /*
         | And no sample anywhere names the beneficiary.
         |
         | `TransitClearing::aged()` returns the legal name, because the
         | screen that lists aged items has to say who is waiting. The metric
         | must not: one series per beneficiary is a supplier list on
         | whoever's dashboard. This is the check that notices if a label is
         | added from the row that already carries it.
         */
        foreach ($metrics->names() as $key) {
            foreach ($this->samples($metrics, $key) as $sample) {
                $bytes = (string) json_encode($sample->toArray());

                foreach ([(string) $supplier->legal_name, 'Acme Industrial LLC'] as $name) {
                    if ($name !== '' && str_contains($bytes, $name)) {
                        $violations[] = sprintf(
                            'the "%s" metric carries a party name in a sample. A metric labelled by '.
                            'who is a list of who on whoever\'s dashboard',
                            $key,
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * The command runs, publishes through the sink, and fails loudly.
     *
     * ## Why the command and not the collectors
     *
     * Because the command is what is scheduled. A check that called every
     * collector directly would prove the queries work and nothing about
     * whether anything ever runs them - which is the defect this whole suite
     * was written after finding, in the task next door.
     *
     * The platform audience is used because the entity-scoped half of the
     * command points the tenant connection per tenant, and pointing it
     * abandons the transaction this suite is holding.
     *
     * @return list<string>
     */
    private function theReportPublishesThroughADriver(): array
    {
        $violations = [];
        $sink = new CollectingMetricSink();

        app()->instance(MetricSink::class, $sink);

        try {
            $exit = \Illuminate\Support\Facades\Artisan::call('metrics:report', ['--audience' => 'engineer']);
        } finally {
            app()->forgetInstance(MetricSink::class);
        }

        if ($exit !== 0) {
            $violations[] = sprintf('metrics:report --audience=engineer exited %d', $exit);
        }

        if ($sink->published() === []) {
            $violations[] = 'metrics:report collected its figures and published none of them. A metric '.
                'that is only printed on a console is not a metric: nobody is watching the console at '.
                '03:00, and the sink is the only part of this that reaches anything that is';
        }

        foreach ($sink->published() as $row) {
            if ($row['value'] === '') {
                $violations[] = sprintf('the "%s" metric published an empty value', $row['metric']);
            }

            if ($row['labels'] === []) {
                $violations[] = sprintf(
                    'the "%s" metric published a sample with no labels at all, so every series is the '.
                    'same series',
                    $row['metric'],
                );
            }
        }

        /*
         | The committed default is the log sink, and it writes on a tapped
         | channel.
         |
         | Same argument as the span exporter: a metric sink that needed a
         | push gateway would be a metrics path a fresh clone cannot
         | exercise, and one writing on an untapped channel would publish
         | labels past the allowlist.
         */
        if ((string) config('telemetry.metric_sink') !== 'log') {
            $violations[] = sprintf(
                'telemetry.metric_sink is "%s". The committed default has to need no third party',
                (string) config('telemetry.metric_sink'),
            );
        }

        if (! app(MetricSink::class) instanceof LogMetricSink) {
            $violations[] = sprintf(
                'the bound metric sink is %s rather than the log one',
                app(MetricSink::class)::class,
            );
        }

        /*
         | And a source that throws is REPORTED, never zero.
         |
         | "Unmatched bank lines: 0" because the query failed is the most
         | dangerous figure on a dashboard: it is the one somebody acts on by
         | doing nothing.
         */
        $broken = $this->aMetricSource(['entity_id'], [Audience::Engineer], static function (): array {
            throw new \RuntimeException('the table this metric reads was renamed');
        });

        $result = app(Metrics::class)->collect($broken, null);

        if ($result['error'] === null) {
            $violations[] = 'a metric source that threw was collected without an error, so the figure '.
                'reads as zero. A dashboard that cannot tell "none" from "the query failed" is one '.
                'where the failure is the reassuring reading';
        }

        if ($result['samples'] !== []) {
            $violations[] = 'a metric source that threw still produced samples';
        }

        return $violations;
    }

    // ---- metric helpers ----------------------------------------------------

    /** @return list<MetricSample> */
    private function samples(Metrics $metrics, string $key): array
    {
        $source = $metrics->source($key);

        if ($source === null) {
            return [];
        }

        return $metrics->collect($source, $this->entityId)['samples'];
    }

    private function total(Metrics $metrics, string $key): int
    {
        $total = 0;

        foreach ($this->samples($metrics, $key) as $sample) {
            $total += (int) $sample->value;
        }

        return $total;
    }

    /**
     * A money metric's total, summed exactly.
     *
     * `BigDecimal`, never a float: half of these figures are money and a
     * dashboard that disagrees with the general ledger at the fourth decimal
     * is worse than no dashboard, because somebody reconciles to it.
     */
    private function amountOf(Metrics $metrics, string $key): \Brick\Math\BigDecimal
    {
        $total = \Brick\Math\BigDecimal::of('0.0000');

        foreach ($this->samples($metrics, $key) as $sample) {
            $total = $total->plus(\Brick\Math\BigDecimal::of($sample->value));
        }

        return $total->toScale(4, \Brick\Math\RoundingMode::HalfUp);
    }

    /**
     * Register a throwaway source and report the refusal, if any.
     *
     * @param  list<string>  $labels
     * @param  list<Audience>  $audiences
     */
    private function refusalFromRegistering(array $labels, array $audiences): ?string
    {
        /*
         | A SEPARATE registry, not the application's.
         |
         | The real one is a singleton and has no unregister, and the
         | container is not rolled back with a savepoint - so a probe source
         | registered into it would be collected by every later check and by
         | the scheduled command. The refusal is a property of the class, so
         | a fresh instance proves it.
         */
        $registry = new Metrics();

        try {
            $registry->register($this->aMetricSource($labels, $audiences, static fn (): array => []));
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param  list<string>  $labels
     * @param  list<Audience>  $audiences
     * @param  callable(): list<MetricSample>  $collect
     */
    private function aMetricSource(array $labels, array $audiences, callable $collect): MetricSource
    {
        return new class($labels, $audiences, $collect) implements MetricSource
        {
            /**
             * @param  list<string>  $labels
             * @param  list<Audience>  $audiences
             * @param  callable(): list<MetricSample>  $collect
             */
            public function __construct(
                private readonly array $labels,
                private readonly array $audiences,
                private readonly mixed $collect,
            ) {}

            public function key(): string
            {
                return 'a_probe_metric';
            }

            /** @return list<Audience> */
            public function audiences(): array
            {
                return $this->audiences;
            }

            public function scope(): MetricScope
            {
                return MetricScope::Platform;
            }

            /** @return list<string> */
            public function labels(): array
            {
                return $this->labels;
            }

            public function unit(): string
            {
                return 'count';
            }

            public function describe(string $locale): string
            {
                return $locale === 'ar' ? 'مقياس للاختبار' : 'A probe metric';
            }

            /** @return list<MetricSample> */
            public function collect(?string $entityId): array
            {
                unset($entityId);

                return ($this->collect)();
            }
        };
    }

    // =========================================================================
    //  PF-242 · invariant alerting
    // =========================================================================

    /**
     * The acceptance criterion, enforced by the constructor.
     *
     * "Each alert names the entity, the period and the first divergent
     * record, so it is actionable at 3am." A convention would hold until the
     * first watcher somebody writes in a hurry - which is the watcher for
     * whichever invariant broke most recently, written by whoever was woken
     * up by the last one.
     *
     * @return list<string>
     */
    private function anAlertNamesTheThing(): array
    {
        $violations = [];

        $cases = [
            'no entity' => ['entityId' => ''],
            'no period' => ['period' => ''],
            'no first divergent record' => ['firstDivergence' => ''],
            'no tenant' => ['tenant' => ''],
            'no Arabic sentence' => ['messageAr' => ''],
            'no English sentence' => ['messageEn' => ''],
            'the same sentence in both languages' => ['messageAr' => 'Suspense is above its threshold'],
        ];

        foreach ($cases as $what => $missing) {
            if ($this->refusalFromBuilding($missing) === null) {
                $violations[] = sprintf(
                    'an alert with %s was accepted. %s',
                    $what,
                    str_contains($what, 'sentence')
                        ? 'This is an Arabic-first product and the alert is read under pressure by '.
                          'whoever is on call: one language is a sentence they have to translate '.
                          'before they can act, and an Arabic field holding English reports as covered'
                        : 'An alert that does not name it sends whoever is woken up to find it, at '.
                          '03:00, by reading a ledger',
                );
            }
        }

        // An undeclared key is refused, because it would have no runbook.
        if ($this->refusalFromBuilding(['key' => 'a_key_nobody_declared']) === null) {
            $violations[] = 'an alert was raised under a key that config/telemetry.php does not '.
                'declare. Every declared key has a runbook entry with a tested first response, so an '.
                'undeclared one is an alert whose reader has nothing to do next - and it looks exactly '.
                'like one that has';
        }

        // And the complete one is accepted, or the refusal is just a wall.
        if ($this->refusalFromBuilding([]) !== null) {
            $violations[] = 'a complete alert was refused: '.(string) $this->refusalFromBuilding([]);
        }

        /*
         | The two sentinels are ACCEPTED, and that is the point of them.
         |
         | A privilege grant is not dated into a period and does not belong to
         | one company in the group. The difference between "this is not
         | period-bound" and "we did not record which period" is the
         | difference between a first response and an investigation, so the
         | first has to be sayable.
         */
        foreach (['entityId' => Alert::NO_ENTITY, 'period' => Alert::NO_PERIOD] as $field => $sentinel) {
            if ($this->refusalFromBuilding([$field => $sentinel]) !== null) {
                $violations[] = sprintf(
                    'the %s sentinel was refused, so an alert that genuinely has none cannot be raised '.
                    'at all and the watcher has to invent a scope',
                    $field,
                );
            }
        }

        return $violations;
    }

    /**
     * A declared alert nobody raises is the defect this task exists to fix.
     *
     * `config/telemetry.php` declared ten invariant alerts and nothing in
     * `app/` raised any of them. That is the state this check makes
     * impossible to return to quietly: a key is either watched or the runbook
     * says, in writing, why not and which task brings it.
     *
     * @return list<string>
     */
    private function everyDeclaredAlertIsWatched(): array
    {
        $violations = [];
        $watched = app(InvariantWatch::class)->keys();
        $runbook = $this->runbookText();

        foreach (Alert::declaredKeys() as $key) {
            if (in_array($key, $watched, true)) {
                continue;
            }

            /*
             | `watch_failed` is raised by the registry itself and by the
             | schedule's onFailure, not by a registered watcher - so it is
             | legitimately absent from the watched list and is proved by its
             | own check below.
             */
            if ($key === 'watch_failed') {
                continue;
            }

            $section = $this->runbookSection($runbook, $key);

            if ($section === null) {
                $violations[] = sprintf(
                    '"%s" is declared and nothing watches it, and the runbook has no section for it. '.
                    'This is the exact state the whole configuration was in before PF-242: ten '.
                    'declared alerts, no emitter, and a config file that read like one that worked',
                    $key,
                );

                continue;
            }

            if (! str_contains($section, '**Not yet watched:**')) {
                $violations[] = sprintf(
                    '"%s" is declared and nothing watches it, and its runbook section does not say so. '.
                    'A declared alert with no watcher reads as coverage; saying "not yet watched" and '.
                    'naming the task that brings it is the difference between a gap and a lie',
                    $key,
                );
            }
        }

        if ($watched === []) {
            $violations[] = 'no alert key is watched at all';
        }

        return $violations;
    }

    /**
     * Every runbook entry exists, and its first response changes NOTHING.
     *
     * ## Why the first response is RUN rather than read
     *
     * "A tested, non-destructive first response" is the criterion, and the
     * destructive half is the one that matters: the commands that would fix
     * these conditions all exist beside the ones that diagnose them -
     * `ledger:rebuild-balances` without `--check`, `privileges:verify
     * --repair` - and each of them erases the evidence of how long the figure
     * was wrong. A runbook that sent whoever is woken up to the repair would
     * be worse than no runbook.
     *
     * So each first response is executed and the database is fingerprinted
     * either side of it. A command that writes anything fails this, by name.
     *
     * @return list<string>
     */
    private function everyAlertHasATestedRunbookEntry(): array
    {
        $violations = [];
        $runbook = $this->runbookText();

        if ($runbook === '') {
            return ['docs/operations/runbooks.md does not exist, so no alert has a documented first '.
                'response and every page sends its reader to work out what to do'];
        }

        $commands = [];

        foreach (Alert::declaredKeys() as $key) {
            $section = $this->runbookSection($runbook, $key);

            if ($section === null) {
                $violations[] = sprintf(
                    '"%s" is declared in config/telemetry.php and has no section in '.
                    'docs/operations/runbooks.md. An alert with no runbook entry is a page that says '.
                    'what is wrong and nothing about what to do, at 03:00',
                    $key,
                );

                continue;
            }

            if (str_contains($section, '**Not yet watched:**')) {
                continue;
            }

            if (preg_match('/\*\*First response \(non-destructive\):\*\*\s*`([^`]+)`/', $section, $m) !== 1) {
                $violations[] = sprintf(
                    'the runbook section for "%s" names no first response. The criterion is a TESTED, '.
                    'non-destructive first response, and a section that only explains the condition '.
                    'leaves the reader to choose between the diagnostic command and the repair',
                    $key,
                );

                continue;
            }

            $commands[$key] = trim($m[1]);
        }

        if ($commands === []) {
            return $violations;
        }

        /*
         | Each distinct command, once, with the fixture's tenant substituted.
         |
         | The same command appears under more than one alert - `ledger:verify`
         | answers two of them - and running it per alert would only make the
         | check slower.
         */
        $seen = [];

        foreach ($commands as $key => $command) {
            $invocation = str_replace('{tenant}', (string) $this->tenantSlug, $command);

            if (isset($seen[$invocation])) {
                continue;
            }

            $seen[$invocation] = true;

            $violations = [...$violations, ...$this->proveItChangesNothing($key, $invocation)];
        }

        return $violations;
    }

    /**
     * Run one first response and prove it wrote nothing.
     *
     * @return list<string>
     */
    private function proveItChangesNothing(string $key, string $invocation): array
    {
        if (! str_starts_with($invocation, 'php artisan ')) {
            return [sprintf(
                'the first response for "%s" is "%s", which is not an artisan command this check can '.
                'run - so "tested" would mean "somebody read it"',
                $key,
                $invocation,
            )];
        }

        $parts = preg_split('/\s+/', trim(substr($invocation, strlen('php artisan ')))) ?: [];
        $name = array_shift($parts) ?? '';

        if (! array_key_exists($name, \Illuminate\Support\Facades\Artisan::all())) {
            return [sprintf(
                'the first response for "%s" is `%s`, and this application registers no such command. '.
                'A runbook naming a command that does not exist is read once, at 03:00, by somebody '.
                'who then has nothing',
                $key,
                $name,
            )];
        }

        /*
         | A forbidden flag is refused WITHOUT running it.
         |
         | `--repair` and a bare `ledger:rebuild-balances` are the right fixes
         | and the wrong first response, and the point is to catch them in the
         | document rather than to discover they wrote something.
         */
        foreach (['--repair', '--force', '--fix'] as $destructive) {
            if (in_array($destructive, $parts, true)) {
                return [sprintf(
                    'the first response for "%s" passes %s. The repair commands exist and each is a '.
                    'deliberate act with its own audit entry, taken AFTER the cause is known: a '.
                    'silent repair hides the bug that caused the drift and erases the only evidence '.
                    'of how long the figure was wrong',
                    $key,
                    $destructive,
                )];
            }
        }

        if ($name === 'ledger:rebuild-balances' && ! in_array('--check', $parts, true)) {
            return [sprintf(
                'the first response for "%s" is `ledger:rebuild-balances` without --check, which '.
                'rebuilds the read model. That is the fix and not the first response',
                $key,
            )];
        }

        $before = $this->databaseFingerprint();

        try {
            /*
             | Without the `php artisan` prefix.
             |
             | The runbook writes the command the way a person types it,
             | which is right - and `Artisan::call('php artisan …')` looks for
             | a command called `php` and fails with "The command php does not
             | exist", which the first run of this check duly reported for
             | every single entry.
             */
            \Illuminate\Support\Facades\Artisan::call(implode(' ', [$name, ...$parts]));
        } catch (Throwable $e) {
            return [sprintf(
                'the first response for "%s" (`%s`) could not run: %s',
                $key,
                $invocation,
                Str::limit($e->getMessage(), 160),
            )];
        }

        $after = $this->databaseFingerprint();

        if ($before !== $after) {
            $changed = [];

            foreach ($after as $table => $count) {
                if (($before[$table] ?? null) !== $count) {
                    $changed[] = sprintf('%s %s -> %s', $table, (string) ($before[$table] ?? '?'), (string) $count);
                }
            }

            return [sprintf(
                'the first response for "%s" (`%s`) changed the database: %s. A runbook that sends '.
                'whoever is woken up to a command that writes is a runbook that destroys the evidence '.
                'they were called to look at',
                $key,
                $invocation,
                implode(', ', $changed),
            )];
        }

        return [];
    }

    /**
     * The command, not only the injectors.
     *
     * ## Why this exists beside the game-day check below
     *
     * The check below drives the injectors and the watchers directly, which
     * is the only way to exercise them against a fixture. It does not run the
     * COMMAND - and the command is what somebody types once per release.
     *
     * Both of its first two runs crashed. The first built its tenant query
     * inside a `static fn` that referenced `$this->argument()`, which fails
     * with "Using $this when not in object context" the moment the query is
     * built. The second opened a savepoint per condition with no enclosing
     * transaction, which PostgreSQL refuses outright. Neither is a subtle
     * bug and neither was reachable from any check, because nothing ran the
     * command.
     *
     * @return list<string>
     */
    private function theGameDayCommandRuns(): array
    {
        /*
         | A tenant that HAS an entity, and it has to be looked for.
         |
         | Found by a bite: pointed at the first provisioned tenant - whose
         | books are empty, because every suite rolls back - the command
         | returns "no books to break" before it opens a transaction at all.
         | Two deliberate breaks in its transaction handling were therefore
         | invisible, which is a check that cannot fail dressed as one that
         | passes.
         */
        $tenant = null;

        foreach (Tenant::query()->whereNotNull('provisioned_at')->with('connection')->whereHas('connection')->orderBy('created_at')->get() as $candidate) {
            try {
                app(TenantConnectionResolver::class)->point($candidate);

                if (DB::connection('tenant')->table('entities')->exists()) {
                    $tenant = $candidate;

                    break;
                }
            } catch (Throwable) {
                // An unreachable tenant is not the one to exercise this on.
            }
        }

        $violations = [];

        /*
         | The source-level half, which holds whatever data exists.
         |
         | The behavioural check below can only prove what this environment's
         | tenants let it prove, and a game day that COMMITTED its damage
         | against an empty tenant commits nothing - so the one assertion that
         | cannot be made behaviourally here is made on the source instead.
         | An unbalanced entry and a corrupted fingerprint left behind would
         | be found by the next reader of those books with nothing to say they
         | were deliberate.
         */
        $source = (string) file_get_contents(base_path('app/Console/Commands/InvariantsGameDay.php'));

        if (! str_contains($source, '$connection->beginTransaction()')) {
            $violations[] = 'invariants:game-day opens no transaction, so its per-condition savepoints '.
                'are refused outright - SAVEPOINT outside a transaction block is an error - and its '.
                'damage would have nothing to roll back to';
        }

        if (! str_contains($source, '$connection->rollBack()')) {
            $violations[] = 'invariants:game-day never rolls back. Its injections include an unbalanced '.
                'entry and a corrupted fingerprint: left behind, they are damage the next reader of '.
                'those books has nothing to tell them was deliberate';
        }

        if (str_contains($source, '$connection->commit()')) {
            $violations[] = 'invariants:game-day commits. There is no version of this exercise that '.
                'should: the whole point is to break an invariant, watch the alert fire, and put it '.
                'back';
        }

        if ($tenant === null) {
            return [...$violations, 'no provisioned tenant has an entity, so the game-day command could '.
                'not be exercised at all. That is a fact about this environment rather than a pass, '.
                'and it is reported rather than skipped'];
        }

        /*
         | The ledger's state BEFORE, so only what appeared counts.
         |
         | Found by a bite: this tenant already reports one critical finding -
         | its suspense check cannot be computed, because it has an entity and
         | no banking - and comparing against zero made the check fail for a
         | condition the game day did not cause. A check that cannot
         | distinguish the damage it is looking for from the state it started
         | in is a check that fails for the wrong reason, which is how a check
         | comes to be deleted.
         */
        $before = $this->criticalFindings();

        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();

        try {
            $exit = \Illuminate\Support\Facades\Artisan::call(
                'invariants:game-day',
                ['tenant' => (string) $tenant->slug],
                $buffer,
            );
        } catch (Throwable $e) {
            return [sprintf(
                'invariants:game-day threw: %s. It is the exercise PF-242 asks for once per release, '.
                'so a crash in it is a release where no alert was proved to fire',
                Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 220),
            )];
        }

        $output = $buffer->fetch();

        /*
         | Either it exercised something, or it said why it could not.
         |
         | A tenant whose books are empty - which every provisioned tenant
         | here is, because the suites roll back - genuinely cannot have a
         | drifted balance injected into it. Saying so is the right answer and
         | counting it as a pass is the wrong one.
         */
        $exercised = str_contains($output, 'fired') || str_contains($output, 'not injectable');
        $refused = str_contains($output, 'no books to break');

        if (! $exercised && ! $refused) {
            $violations[] = sprintf(
                'invariants:game-day exited %d and neither exercised a condition nor said why it '.
                'could not: %s',
                $exit,
                Str::limit(preg_replace('/\s+/', ' ', $output) ?? '', 300),
            );
        }

        if (str_contains($output, 'SILENT')) {
            $violations[] = 'invariants:game-day broke an invariant and no alert fired: '.
                Str::limit(preg_replace('/\s+/', ' ', $output) ?? '', 400);
        }

        /*
         | And it left nothing behind.
         |
         | The injections include an unbalanced entry and a corrupted
         | fingerprint. A game day that committed either would leave the next
         | reader of those books looking at damage with nothing to say it was
         | deliberate - so the whole exercise is one transaction and it is
         | rolled back whether every alert fired or none did.
         */
        $appeared = array_values(array_diff($this->criticalFindings(), $before));

        if ($appeared !== []) {
            $violations[] = sprintf(
                'the game day left %d critical finding(s) behind that were not there before, starting '.
                'with "%s". Its injections include an unbalanced entry and a corrupted fingerprint: '.
                'committing either would leave the next reader of these books looking at damage with '.
                'nothing to say it was deliberate',
                count($appeared),
                $appeared[0],
            );
        }

        return $violations;
    }

    /**
     * What `ledger:verify` currently finds, as comparable strings.
     *
     * @return list<string>
     */
    private function criticalFindings(): array
    {
        try {
            return app(\App\Tenant\Ledger\Services\LedgerVerifier::class)
                ->verify(null)
                ->filter(static fn (\App\Tenant\Ledger\Services\LedgerFinding $f): bool => $f->isCritical())
                ->map(static fn (\App\Tenant\Ledger\Services\LedgerFinding $f): string => $f->kind.': '.$f->detail)
                ->values()
                ->all();
        } catch (Throwable $e) {
            return ['the ledger could not be verified: '.$e->getMessage()];
        }
    }

    /**
     * The game day, as a check rather than as a ceremony.
     *
     * ## This is the check the whole task turns on
     *
     * An alert that cannot fire is the purest form of a check that cannot
     * fail: it looks like coverage, it costs nothing to run, and the first
     * time anybody learns it never worked is the incident it was built for.
     *
     * So each condition is injected by breaking the INVARIANT - a stored
     * balance moved by a unit, a line added to a posted entry, a fingerprint
     * that no longer matches its content, a line belonging to no entry, a
     * sealed artefact's hash changed, a grant the application role must not
     * hold, an unexplained receipt parked in suspense, a released
     * instruction aged past the floor - and the alert has to fire AND name
     * the entity, the period and the first divergent record.
     *
     * Not by handing a watcher a finding. That would prove the plumbing and
     * nothing about whether the watcher can see a real breach, which is the
     * half that is wrong in practice.
     *
     * The injectors are the production ones `invariants:game-day` uses, so
     * the release exercise and the build cannot disagree about what was
     * tested.
     *
     * @return list<string>
     */
    private function theGameDay(): array
    {
        $violations = [];
        $watch = app(InvariantWatch::class);
        $injection = app(\App\Platform\Services\InvariantInjection::class);

        // The data every injector needs, through the real services.
        $invoice = $this->issue();

        /*
         | The entry fingerprints are written by a DEFERRED trigger.
         |
         | It fires at COMMIT, and this suite never commits - so without
         | this `journal_entry_fingerprints` is empty and the hash-chain
         | condition reports "this entity has no fingerprinted entry, so
         | nothing is inside the proof". Forcing the deferred constraints to
         | check now is the same technique `LedgerEnforcementRules` uses to
         | exercise the balanced-entry trigger without committing.
         */
        $this->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');

        /*
         | And back to DEFERRED immediately.
         |
         | Leaving them immediate breaks the next injection: the
         | balanced-entry rule is a deferred constraint trigger, and the only
         | way to leave an entry unbalanced without committing is for it to
         | stay deferred. The first version of this left them immediate and
         | the unbalanced-entry condition came back as "does not balance: its
         | lines sum to 3.0000", which is the trigger working and the
         | injection failing.
         */
        $this->connection->statement('SET CONSTRAINTS ALL DEFERRED');

        $this->importLines([$this->line('750.0000', ['bank_reference' => 'OBS-GAMEDAY-1'])]);
        $this->sealAnArchivePeriod($invoice);
        $this->releaseARun();

        foreach (array_keys(\App\Platform\Services\InvariantInjection::conditions()) as $condition) {
            $savepoint = 'gd_'.bin2hex(random_bytes(4));
            $this->connection->statement('SAVEPOINT '.$savepoint);
            $rollback = null;

            try {
                /*
                 | What this condition was ALREADY reporting, before anything
                 | was injected.
                 |
                 | The property being protected is that the injection is what
                 | caused the alert. Without it the sweep proves nothing for
                 | any condition the fixture already breaches: the alert
                 | fires, the check is green, and the injection could have
                 | been a no-op - the same hollowness as a bite that was
                 | never applied.
                 |
                 | This used to refuse outright when the condition was
                 | already firing, and that lost the condition entirely
                 | rather than testing it. `subledgers` arrived on
                 | 2026-11-19 and is breached by this very fixture: the
                 | shared payment-run trait writes an APPROVED, never-posted
                 | bill straight onto the table and then releases a payment
                 | against it, so the payables ageing counts 2,500 nobody
                 | posted and the control account carries a 2,500 debit
                 | nothing credited. Both are unreachable in production -
                 | nothing in the application sets `approved` - but the
                 | fixture stands on them, deliberately, because what a
                 | payment run needs is narrower than a posted bill.
                 |
                 | So the requirement is stated the stronger way: the
                 | injection must ADD a divergence this condition was not
                 | already reporting. A pre-existing breach can no longer
                 | mask the injection, and it can no longer skip it either.
                 */
                $already = $this->firedFor($watch, $condition, null);
                $alreadyDiverged = array_map(
                    static fn (object $alert): string => (string) $alert->firstDivergence,
                    $already,
                );


                $result = $injection->inject($condition, $this->entityId);
                $rollback = $result['rollback'];

                if (! $result['injected']) {
                    $violations[] = sprintf(
                        'the "%s" condition could not be injected against the fixture: %s. A condition '.
                        'that is never injected is an alert nobody has seen fire',
                        $condition,
                        $result['why'],
                    );

                    continue;
                }

                $fired = $this->firedFor(
                    $watch,
                    $condition,
                    $result['watcher'] instanceof \App\Support\Telemetry\Alerts\InvariantWatcher
                        ? $result['watcher']
                        : null,
                );

                if ($fired === []) {
                    $violations[] = sprintf(
                        'the invariant was broken (%s) and no "%s" alert fired. An alert that cannot '.
                        'fire looks exactly like coverage, costs nothing to run, and is discovered '.
                        'during the incident it was built for',
                        $result['why'],
                        $condition,
                    );

                    continue;
                }

                /*
                 | The alert the INJECTION caused, not whichever fired first.
                 |
                 | With a pre-existing breach of the same condition, `$fired[0]`
                 | could be the one that was already there - and every
                 | assertion below would then be made about a divergence
                 | nothing in this sweep produced.
                 */
                $caused = array_values(array_filter(
                    $fired,
                    static fn (object $a): bool => ! \in_array(
                        (string) $a->firstDivergence,
                        $alreadyDiverged,
                        true,
                    ),
                ));

                if ($alreadyDiverged !== [] && $caused === []) {
                    $violations[] = sprintf(
                        'the "%s" condition was already being reported (%s) and the injection added no '.
                        'divergence of its own, so it could have been a no-op. An injection that '.
                        'changes nothing and an alert that cannot fire are indistinguishable from '.
                        'here, which is the whole point of this sweep',
                        $condition,
                        $alreadyDiverged[0],
                    );

                    continue;
                }

                $alert = $caused[0] ?? $fired[0];

                if ($alert->entityId !== $this->entityId && $alert->entityId !== Alert::NO_ENTITY) {
                    $violations[] = sprintf(
                        'the "%s" alert names entity "%s" and the breach is in "%s"',
                        $condition,
                        $alert->entityId,
                        $this->entityId,
                    );
                }

                if (trim($alert->firstDivergence) === '' || $alert->firstDivergence === $alert->key) {
                    $violations[] = sprintf(
                        'the "%s" alert names no first divergent record, so it says what is wrong and '.
                        'nothing about where',
                        $condition,
                    );
                }

                /*
                 | And it names THE THING THAT WAS INJECTED, not just a thing.
                 |
                 | This is the assertion a bite found missing. Neutering the
                 | privilege watcher so it asked about `current_user` instead
                 | of the application's role left the check green: an alert
                 | still fired, with a plausible sentence, about a role that
                 | owns every table in the database. Which is the exact trap
                 | `PrivilegeMatrix` documents in its own docblock and the
                 | reason the first version of the equivalent hardening check
                 | passed.
                 |
                 | So each condition declares what its own first divergence
                 | has to say, and the privilege one additionally has to NOT
                 | name the role that owns the tables.
                 */
                $expectation = $this->whatTheDivergenceMustSay()[$condition] ?? null;

                if ($expectation !== null && ! str_contains($alert->firstDivergence, $expectation)) {
                    $violations[] = sprintf(
                        'the "%s" alert fired and its first divergence does not mention "%s": %s. An '.
                        'alert that fires with a plausible sentence about the wrong thing is worse '.
                        'than one that does not fire, because it reads as working',
                        $condition,
                        $expectation,
                        Str::limit($alert->firstDivergence, 160),
                    );
                }

                if ($condition === 'privilege_drift') {
                    $owner = (string) config('database.connections.migrator.username');

                    if ($owner !== '' && str_contains($alert->firstDivergence, $owner)) {
                        $violations[] = sprintf(
                            'the privilege alert reports drift for "%s", which OWNS every table in the '.
                            'tenant database. Asked about current_user on a privileged connection '.
                            'every answer is yes, so a planted grant is duly reported and the report '.
                            'is about the wrong role',
                            $owner,
                        );
                    }
                }

                /*
                 | And the record a collector receives carries all four facts.
                 |
                 | `toArray()` is what the sink writes, so this is the shape
                 | the thing being woken up actually sees - entity, period,
                 | first divergence and the runbook that says what to do
                 | next. An alert naming three of the four sends its reader
                 | to work out the fourth under pressure.
                 */
                $written = $alert->toArray();

                foreach (['entity_id', 'period_id', 'first_divergence', 'runbook'] as $field) {
                    if (trim((string) ($written[$field] ?? '')) === '') {
                        $violations[] = sprintf(
                            'the "%s" alert reaches the sink with no %s, so what a collector receives '.
                            'names less than the criterion requires',
                            $condition,
                            $field,
                        );
                    }
                }

                /*
                 | And no alert raised by a real watcher names a party.
                 |
                 | The adversarial half of this: `TransitClearing::aged()`
                 | hands back the beneficiary's legal name, because the
                 | register that lists aged items has to say who is waiting -
                 | and the in-transit alert is built from exactly those rows.
                 | A watcher that passed the row through would put a supplier
                 | list into a log kept for weeks, and neither the field
                 | allowlist nor the value-shape scrubber would stop it: a
                 | name has no check digit, and the message is one string.
                 */
                $bytes = (string) json_encode($written + ['ar' => $alert->messageAr, 'en' => $alert->messageEn]);

                foreach ($this->fixtureNames() as $name) {
                    if ($name !== '' && str_contains($bytes, $name)) {
                        $violations[] = sprintf(
                            'the "%s" alert carries the party name "%s". An alert goes to a log and a '.
                            'pager and is kept for weeks; a name has no check digit, so nothing '.
                            'between the watcher and the collector will take it out',
                            $condition,
                            $name,
                        );
                    }
                }
            } catch (Throwable $e) {
                $violations[] = sprintf(
                    'injecting "%s" threw: %s',
                    $condition,
                    Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200),
                );
            } finally {
                if ($rollback !== null) {
                    try {
                        $rollback();
                    } catch (Throwable) {
                        // The second connection's transaction is already closed.
                    }
                }

                $this->connection->statement('ROLLBACK TO SAVEPOINT '.$savepoint);
            }
        }

        /*
         | Every watched condition is exercised, not just the ones with an
         | injector.
         |
         | Otherwise the sweep shrinks silently: somebody registers a watcher,
         | writes no injector, and the game day reports eight of eight while
         | exercising eight of nine.
         */
        $injectable = array_keys(\App\Platform\Services\InvariantInjection::conditions());

        foreach ($watch->keys() as $key) {
            if (! in_array($key, $injectable, true)) {
                $violations[] = sprintf(
                    'the "%s" alert is watched and the game day has no injector for it, so nobody has '.
                    'ever seen it fire. An alert that has never fired is a configuration somebody '.
                    'hopes is right',
                    $key,
                );
            }
        }

        return $violations;
    }

    /**
     * A watcher that cannot run must say so, loudly.
     *
     * The most dangerous failure in the whole design. A watch that threw and
     * was swallowed reports exactly what a healthy watch reports, and the
     * reassuring reading is the wrong one - so the hour a query was refused
     * is the hour every invariant reported as fine.
     *
     * @return list<string>
     */
    private function aThrowingWatcherAlerts(): array
    {
        $watch = new InvariantWatch();

        $broken = new class implements \App\Support\Telemetry\Alerts\InvariantWatcher
        {
            /** @return list<string> */
            public function keys(): array
            {
                return ['read_model_drift'];
            }

            public function scope(): \App\Support\Telemetry\Alerts\WatchScope
            {
                return \App\Support\Telemetry\Alerts\WatchScope::Entity;
            }

            /** @return list<Alert> */
            public function watch(string $tenant, ?string $entityId): array
            {
                throw new \RuntimeException('the table this watcher reads was renamed');
            }
        };

        $watch->register($broken);

        $alerts = $watch->run($broken, (string) $this->tenantSlug, $this->entityId);

        if ($alerts === []) {
            return ['a watcher that threw produced no alert, so a watch that cannot run is '.
                'indistinguishable from one that found nothing - and the second is the reassuring '.
                'reading. The hour this is broken is the hour every invariant reports as healthy'];
        }

        $violations = [];

        if ($alerts[0]->key !== 'watch_failed') {
            $violations[] = sprintf(
                'a watcher that threw raised "%s" rather than watch_failed, so the failure is reported '.
                'as the condition it was looking for',
                $alerts[0]->key,
            );
        }

        if (! str_contains($alerts[0]->firstDivergence, 'class@anonymous')
            && ! str_contains($alerts[0]->messageEn, 'could not run')) {
            $violations[] = 'the watch_failed alert does not name the watcher that failed, so the '.
                'reader knows the watch broke and not which part of it';
        }

        // And an undeclared key is refused at registration.
        $undeclared = new class implements \App\Support\Telemetry\Alerts\InvariantWatcher
        {
            /** @return list<string> */
            public function keys(): array
            {
                return ['a_key_nobody_declared'];
            }

            public function scope(): \App\Support\Telemetry\Alerts\WatchScope
            {
                return \App\Support\Telemetry\Alerts\WatchScope::Entity;
            }

            /** @return list<Alert> */
            public function watch(string $tenant, ?string $entityId): array
            {
                return [];
            }
        };

        try {
            (new InvariantWatch())->register($undeclared);

            $violations[] = 'a watcher raising an undeclared alert key was registered. Every declared '.
                'key has a runbook entry with a tested first response, so an undeclared one is an '.
                'alert whose reader has nothing to do next';
        } catch (Throwable) {
            // Refused, which is the point.
        }

        return $violations;
    }

    /**
     * Turning tracing off must not turn the alerting off.
     *
     * ## Why this is a check and not a comment
     *
     * `TELEMETRY_ENABLED=false` is the committed default, because a span per
     * database statement costs something in a product whose reports read ten
     * million journal lines. Every other part of this phase is correctly
     * gated on it.
     *
     * An alert gated on the same switch would be the purest form of the
     * failure this task exists to prevent: the alerting would be off in every
     * deployment that had not thought about tracing, the configuration would
     * list ten invariant alerts, and nobody would find out until the incident
     * one of them was built for.
     *
     * @return list<string>
     */
    private function alertsAreNotGatedOnTracing(): array
    {
        $violations = [];

        if (config('telemetry.enabled') !== false) {
            $violations[] = 'telemetry.enabled is not false by default, so this check is not testing '.
                'the state deployments are actually in';
        }

        if (app(Tracer::class)->isRecording()) {
            $violations[] = 'the tracer is recording, so this check is not testing the state it is '.
                'meant to test';
        }

        /*
         | A real breach, read by the real watcher, with tracing off.
         |
         | Not through `invariants:watch`: the command points the tenant
         | connection, which purges it and abandons the transaction this
         | suite is holding. The watcher is the thing under test here - the
         | command is scheduled and asserted separately - and what matters is
         | that neither the watcher nor the sink consults the tracing switch.
         */
        $this->issue();

        $this->connection->update(
            'UPDATE account_balances SET net = net + 1 WHERE id = ('.
            'SELECT id FROM account_balances WHERE entity_id = ? ORDER BY id LIMIT 1)',
            [$this->entityId],
        );

        $alerts = app(\App\Tenant\Ledger\Alerts\ReadModelDriftWatcher::class)
            ->watch((string) $this->tenantSlug, $this->entityId);

        if ($alerts === []) {
            $violations[] = 'a drifted balance raised no alert with telemetry disabled. Tracing is off '.
                'in every deployment that has not thought about it, and an alerting path that goes '.
                'quiet with it is one nobody will ever see fire';

            return $violations;
        }

        /*
         | And the sink writes it, with tracing still off.
         |
         | Through a probe channel so the bytes can be read back: the question
         | is whether anything reaches a collector, and an assertion that the
         | sink was CALLED would be satisfied by a sink that returned early.
         */
        $handler = new \Monolog\Handler\TestHandler();
        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true, true));

        $monolog = new \Monolog\Logger('alerts');
        $monolog->pushProcessor(new AllowlistProcessor());
        $monolog->pushHandler($handler);

        \Illuminate\Support\Facades\Log::extend('observability_gate', static fn (): \Illuminate\Log\Logger
            => new \Illuminate\Log\Logger($monolog));

        config(['logging.channels.observability_gate' => ['driver' => 'observability_gate']]);

        $was = config('telemetry.channel');
        config(['telemetry.channel' => 'observability_gate']);

        try {
            app(\App\Support\Telemetry\Sinks\LogAlertSink::class)->raise($alerts[0]);
        } finally {
            config(['telemetry.channel' => $was]);
        }

        if ($handler->getRecords() === []) {
            $violations[] = 'the alert sink wrote nothing with telemetry disabled, so every deployment '.
                'that has not switched tracing on is alerting into silence while config/telemetry.php '.
                'lists ten invariant alerts';
        }

        return $violations;
    }

    /**
     * Scheduled, with a cron expression and a failure path.
     *
     * Before PF-242 `routes/console.php` held one command and it printed an
     * inspiring quote. Every invariant in this product was provable and none
     * was watched, which is the gap this asserts is closed - against the real
     * schedule, by reading the events the application registers.
     *
     * @return list<string>
     */
    private function theWatchIsScheduled(): array
    {
        $violations = [];
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $events = [];
        $watch = null;
        $metrics = null;

        foreach ($schedule->events() as $event) {
            $events[] = (string) $event->command.' @ '.(string) $event->expression;

            if (str_contains((string) $event->command, 'invariants:watch')) {
                $watch = $event;
            }

            if (str_contains((string) $event->command, 'metrics:report')) {
                $metrics = $event;
            }
        }

        if ($watch === null) {
            return [sprintf(
                'nothing in the schedule runs `invariants:watch`. Every invariant in this product is '.
                'provable - ledger:verify, audit:verify, privileges:verify, archive:verify, '.
                'ledger:rebuild-balances --check - and before this task nothing invoked any of them, '.
                'so the first time a breach would have been noticed is the first time somebody '.
                'thought to ask. Scheduled: %s',
                $events === [] ? '(nothing at all)' : implode(' | ', $events),
            )];
        }

        if ((string) $watch->expression !== (string) config('telemetry.watch_schedule')) {
            $violations[] = sprintf(
                'the watch is scheduled at "%s" and telemetry.watch_schedule says "%s", so the '.
                'configured expression does nothing',
                (string) $watch->expression,
                (string) config('telemetry.watch_schedule'),
            );
        }

        if (! str_contains((string) $watch->command, '--all')) {
            $violations[] = 'the scheduled watch does not pass --all, so it watches one tenant and the '.
                'rest of the fleet is unobserved';
        }

        if ($this->propertyOf($watch, 'withoutOverlapping') !== true) {
            $violations[] = 'the watch may overlap itself. It runs ledger:verify\'s full pass per '.
                'entity, so two of them competing for one connection is how a scheduled check quietly '.
                'becomes a load problem';
        }

        /*
         | And a failed run is itself an alert.
         |
         | `onFailure` registers an after-callback, so the presence of one is
         | what says the failure path exists. Without it a run that exited
         | non-zero is recorded by the scheduler and said out loud by nobody -
         | and a watch that could not run looks exactly like one that found
         | nothing.
         */
        $after = $this->propertyOf($watch, 'afterCallbacks');

        if (! is_array($after) || $after === []) {
            $violations[] = 'the scheduled watch registers no failure callback, so a run that exited '.
                'non-zero is recorded by the scheduler and said out loud by nobody';
        }

        /*
         | The callback raises an alert through the sink, not a log line of
         | its own.
         |
         | Asserted by running it against a collecting sink: a failure hook
         | that only wrote to the console would be a failure hook nobody
         | reads, because nobody is attached to the console of a scheduled
         | run.
         */
        if (is_array($after) && $after !== []) {
            $sink = new CollectingAlertSink();
            app()->instance(\App\Support\Telemetry\Contracts\AlertSink::class, $sink);

            try {
                foreach ($after as $callback) {
                    if (is_callable($callback)) {
                        app()->call($callback);
                    }
                }
            } catch (Throwable $e) {
                $violations[] = 'the scheduled watch\'s failure callback threw: '.Str::limit($e->getMessage(), 160);
            } finally {
                app()->forgetInstance(\App\Support\Telemetry\Contracts\AlertSink::class);
            }

            if ($sink->of('watch_failed') === []) {
                $violations[] = sprintf(
                    'the scheduled watch\'s failure callback raised no watch_failed alert through the '.
                    'sink. Raised: %s. A failed scheduled run that only prints to a console nobody is '.
                    'attached to is a failure nobody learns about',
                    $sink->keys() === [] ? '(nothing)' : implode(', ', $sink->keys()),
                );
            }
        }

        if ($metrics === null) {
            $violations[] = 'nothing in the schedule runs `metrics:report`, so the business metrics are '.
                'collected whenever somebody happens to type the command';
        }

        return $violations;
    }

    /** Read a scheduled event's property, public or not. */
    private function propertyOf(object $event, string $name): mixed
    {
        $reflection = new ReflectionClass($event);

        if (! $reflection->hasProperty($name)) {
            return null;
        }

        $property = $reflection->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($event);
    }

    /**
     * An alert goes to a log and is kept for weeks, so it leaks the same way.
     *
     * Driven with the payload PF-234 asks for - an IBAN, a token, a password
     * and a party name - under the alert's own fields, through the real log
     * sink, reading the bytes a collector would read.
     *
     * The adversarial case is the party name: `TransitClearing::aged()`
     * returns the beneficiary's legal name because the register has to say
     * who is waiting, and the in-transit alert is built from exactly those
     * rows. A watcher that passed the row through would leak a supplier list
     * into a log retained for weeks.
     *
     * @return list<string>
     */
    private function alertsCarryNoSecrets(): array
    {
        $iban = 'SA0380000000608010167519';

        /*
         | Only the shapes with a CHECK DIGIT are expected to be scrubbed.
         |
         | A party name is NOT, and that is a real limitation rather than an
         | oversight: the field allowlist governs field NAMES, the value-shape
         | scrubber recognises IBANs by mod-97 and cards by Luhn, and a legal
         | name has neither a name on the list nor a checksum. A name placed
         | in an alert's sentence by a future watcher would reach the log and
         | nothing between here and the collector would take it out.
         |
         | So that half is enforced where it can be: the game-day check
         | asserts that no alert raised by a REAL watcher carries any of the
         | fixture's party names, which bites the moment a watcher starts
         | passing a row through. This check covers the half the scrubber
         | owns.
         */
        $secrets = [
            'an IBAN' => $iban,
            'a card number' => '4111111111111111',
        ];

        $handler = new \Monolog\Handler\TestHandler();
        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true, true));

        $monolog = new \Monolog\Logger('alerts');
        $monolog->pushProcessor(new AllowlistProcessor());
        $monolog->pushHandler($handler);

        \Illuminate\Support\Facades\Log::extend('observability_probe', static fn (): \Illuminate\Log\Logger
            => new \Illuminate\Log\Logger($monolog));

        config(['logging.channels.observability_probe' => ['driver' => 'observability_probe']]);

        $was = config('telemetry.channel');
        config(['telemetry.channel' => 'observability_probe']);

        try {
            app(\App\Support\Telemetry\Sinks\LogAlertSink::class)->raise(Alert::critical(
                key: 'suspense_above_threshold',
                tenant: (string) $this->tenantSlug,
                entityId: $this->entityId,
                period: '2026-05',
                // The three places a watcher would plausibly put one.
                firstDivergence: sprintf('the oldest parked item from %s', $iban),
                messageAr: sprintf('رصيد الأمانات يتضمن تحويلاً من %s', $iban),
                messageEn: sprintf('Suspense holds a transfer from %s', $iban),
                observed: '4111111111111111',
                threshold: '5000.0000',
            ));
        } finally {
            config(['telemetry.channel' => $was]);
        }

        $records = $handler->getRecords();
        $record = $records[0] ?? null;

        if (! $record instanceof \Monolog\LogRecord) {
            return ['the alert was not written through the handler, so nothing was checked'];
        }

        $bytes = (string) $handler->getFormatter()->format($record);
        $violations = [];

        foreach ($secrets as $what => $secret) {
            if (str_contains($bytes, $secret)) {
                $violations[] = sprintf(
                    '%s survived into an alert line. An alert goes to a log and a pager and is kept '.
                    'for weeks on infrastructure with none of the controls customer data needs - and '.
                    'the log is the one place nobody can delete from',
                    $what,
                );
            }
        }

        /*
         | And the three facts that make it actionable are still there.
         |
         | A scrubber that ate them would be a scrubber somebody turns off -
         | which is how the whole allowlist stops being trusted.
         */
        foreach (['the entity' => $this->entityId, 'the period' => '2026-05', 'the runbook' => 'runbooks.md'] as $what => $needle) {
            if (! str_contains($bytes, $needle)) {
                $violations[] = sprintf(
                    '%s did not survive into the alert line, so the alert names less than the criterion '.
                    'requires by the time anybody reads it',
                    $what,
                );
            }
        }

        return $violations;
    }

    /**
     * PF-229's rule, applied to the thing that wakes somebody up.
     *
     * @return list<string>
     */
    private function theAlertSinkIsADriver(): array
    {
        $violations = [];

        if ((string) config('telemetry.alert_sink') !== 'log') {
            $violations[] = sprintf(
                'telemetry.alert_sink is "%s". The committed default has to need no paging provider, '.
                'or the alerting path is one a fresh clone cannot exercise - and an alerting path '.
                'nobody has exercised is the one that is discovered during the incident',
                (string) config('telemetry.alert_sink'),
            );
        }

        $sink = app(\App\Support\Telemetry\Contracts\AlertSink::class);

        if (! $sink instanceof \App\Support\Telemetry\Sinks\LogAlertSink) {
            $violations[] = sprintf('the bound alert sink is %s rather than the log one', $sink::class);
        }

        /*
         | And it writes at `critical`, on a channel that filters.
         |
         | `LOG_LEVEL` is routinely `info` or `warning` in production. A
         | breached ledger invariant written at `error` would be an error
         | among errors; one written at `debug` would be built, filtered,
         | formatted and discarded.
         */
        $source = (string) file_get_contents(base_path('app/Support/Telemetry/Sinks/LogAlertSink.php'));

        if (! str_contains($source, '->critical(')) {
            $violations[] = 'the log alert sink does not write at `critical`. The level is what a '.
                'collector routes on, and a breached ledger invariant is not an error among errors';
        }

        /*
         | And the sink does not read `telemetry.enabled`.
         |
         | Asserted on the source as well as behaviourally, because the
         | behavioural check above can only prove the current default and
         | this proves there is no path at all.
         */
        foreach ([
            base_path('app/Support/Telemetry/Sinks/LogAlertSink.php'),
            base_path('app/Support/Telemetry/Alerts/InvariantWatch.php'),
            base_path('app/Support/Telemetry/Alerts/Alert.php'),
        ] as $file) {
            if (str_contains((string) file_get_contents($file), "telemetry.enabled")) {
                $violations[] = sprintf(
                    '%s reads telemetry.enabled. Tracing is off by default; an alert that went quiet '.
                    'with it would be off in every deployment that had not thought about tracing',
                    basename($file),
                );
            }
        }

        return $violations;
    }

    // ---- alerting helpers --------------------------------------------------

    /**
     * Run every watcher that can raise this key, and return what fired.
     *
     * @param  object|null  $only  a watcher bound to a second connection, when the
     *                             injection needs one
     * @return list<Alert>
     */
    private function firedFor(InvariantWatch $watch, string $condition, ?object $only): array
    {
        $sink = new CollectingAlertSink();

        $watchers = $only instanceof \App\Support\Telemetry\Alerts\InvariantWatcher
            ? [$only]
            : $watch->all();

        foreach ($watchers as $watcher) {
            if (! in_array($condition, $watcher->keys(), true)) {
                continue;
            }

            foreach ($watch->run($watcher, (string) $this->tenantSlug, $this->entityId) as $alert) {
                $sink->raise($alert);
            }
        }

        return $sink->of($condition);
    }

    /**
     * What each condition's first divergent record has to name.
     *
     * Not "an alert fired": a watcher pointed at the wrong question fires an
     * alert with a plausible sentence, and that is the failure mode a bite
     * found in the privilege watcher. Each entry is the smallest phrase that
     * can only be produced by the breach that was actually injected.
     *
     * @return array<string, string>
     */
    private function whatTheDivergenceMustSay(): array
    {
        return [
            // The finding key begins with the entity the drift is in.
            'read_model_drift' => $this->entityId,
            'unbalanced_entry_in_database' => 'out by',
            'hash_chain_divergence' => 'fingerprint',
            'subledger_mismatch' => 'control account',
            'archive_checksum_mismatch' => 'diverged from its hash',
            // The application's own role, which is the only one that matters.
            'privilege_drift' => (string) config('database.connections.tenant.username'),
            'suspense_above_threshold' => 'parked item',
            'in_transit_aged' => 'instruction',
        ];
    }

    /**
     * EVERY party name in the fixture's database.
     *
     * ## Why this reads the table rather than the fixture's own properties
     *
     * The first version listed `$this->party` and `$this->supplier`, and a
     * bite walked straight past it: the in-transit alert is built from a
     * payment run, whose beneficiary is a party created by
     * `aPayableSupplier()` and held by nobody. Adding
     * `.' for '.$oldest['party']` to that watcher's first-divergence field
     * leaked a supplier name into the alert and the check stayed green.
     *
     * So the names come from the table. A watcher cannot leak a name this
     * does not know about, because there is no name in the fixture it does
     * not know about.
     *
     * Bounded to eight characters and longer, because a two-letter party
     * code would match the prose of every alert and the check would fail on
     * its own sentences.
     *
     * @return list<string>
     */
    private function fixtureNames(): array
    {
        $names = $this->connection->table('parties')->pluck('legal_name');

        return array_values(array_filter(
            array_map(static fn (mixed $name): string => (string) $name, $names->all()),
            static fn (string $name): bool => mb_strlen($name) >= 8,
        ));
    }

    /**
     * Build a complete alert with one field replaced, and report the refusal.
     *
     * @param  array<string, string>  $overrides
     */
    private function refusalFromBuilding(array $overrides): ?string
    {
        $fields = array_merge([
            'key' => 'suspense_above_threshold',
            'tenant' => 'acme',
            'entityId' => '01M7FWABCDEFGHJKMNPQRSTVWX',
            'period' => '2026-05',
            'firstDivergence' => 'the oldest parked item, 61 days old',
            'messageAr' => 'رصيد الأمانات تجاوز الحد',
            'messageEn' => 'Suspense is above its threshold',
        ], $overrides);

        try {
            Alert::critical(
                key: $fields['key'],
                tenant: $fields['tenant'],
                entityId: $fields['entityId'],
                period: $fields['period'],
                firstDivergence: $fields['firstDivergence'],
                messageAr: $fields['messageAr'],
                messageEn: $fields['messageEn'],
            );
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function runbookText(): string
    {
        $path = base_path('docs/operations/runbooks.md');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * The runbook section for one key.
     *
     * Found by the backticked key rather than by the heading, because the
     * heading is prose for a reader and the key is the identifier - and a
     * section whose heading drifted from its key would still be the right
     * section.
     */
    private function runbookSection(string $runbook, string $key): ?string
    {
        $sections = preg_split('/^## /m', $runbook) ?: [];

        foreach ($sections as $section) {
            if (str_contains($section, '`'.$key.'`')) {
                return $section;
            }
        }

        return null;
    }

    /**
     * A cheap proof that a command wrote nothing.
     *
     * Row counts over the tables a first response could plausibly touch, plus
     * the audit chain's head - because a command that recorded an audit entry
     * has written, and the audit log is append-only so the count is the
     * cheapest possible witness.
     *
     * @return array<string, int>
     */
    private function databaseFingerprint(): array
    {
        $counts = [];

        foreach ([
            'journal_entries',
            'journal_lines',
            'account_balances',
            'balance_drift_findings',
            'audit_log',
            'invoices',
            'matches',
            'archived_artefacts',
            'archive_manifests',
        ] as $table) {
            /*
             | Read from the connection as it is NOW, not from a handle held
             | since before the command ran.
             |
             | Every one of these commands points the tenant connection,
             | which purges and reopens it - so a cached handle would be
             | reading a closed connection by the time the second
             | fingerprint is taken.
             */
            $counts[$table] = (int) \Illuminate\Support\Facades\DB::connection('tenant')->table($table)->count();
        }

        return $counts;
    }

    /** Store one artefact and seal the period, so the archive has something to check. */
    private function sealAnArchivePeriod(\App\Tenant\Sales\Models\Invoice $invoice): void
    {
        $archive = app(\App\Exchange\Archive\Archive::class);
        $profile = app(\App\Exchange\Profiles\GenericProfile::class);

        $archive->store(
            $this->entityId,
            'invoice',
            (string) $invoice->getKey(),
            'sent_xml',
            '<Invoice><ID>'.(string) $invoice->number.'</ID></Invoice>',
            $profile,
            'application/xml',
            null,
            '2026-05',
        );

        $archive->seal($this->entityId, '2026-05', $this->actorId());
    }

    /**
     * A released payment run that is NOT yet aged.
     *
     * Payable today, with today's accounting period opened, because the
     * game day has to be able to see the in-transit alert go from silent to
     * firing. The fixture's own month is six months in the past, so a run
     * payable there is already 185 days old and the alert fires before
     * anything has been injected - which the game-day check reports as "this
     * condition was not exercised", correctly, and which is how a bite that
     * was never applied looks.
     */
    private function releaseARun(): void
    {
        $supplier = $this->aPayableSupplier();
        $today = \Illuminate\Support\Carbon::today();

        $this->anApprovedBill($supplier, '2500.0000', $today->toDateString());

        /*
         | Today's period, opened.
         |
         | A generated calendar's periods start `future` and `future` does
         | not accept postings - which is correct, and would make the release
         | fail for a reason that has nothing to do with what is being
         | checked.
         */
        $period = app(\App\Tenant\Ledger\Services\FiscalCalendarGenerator::class)
            ->periodFor($this->entityId, $today);

        if ($period !== null && $period->state !== \App\Tenant\Ledger\Enums\PeriodState::Open) {
            app(\App\Tenant\Ledger\Services\PeriodStateMachine::class)->transition(
                $period,
                \App\Tenant\Ledger\Enums\PeriodState::Open,
                'opened so the game day can release a payment run payable today',
            );
        }

        $run = app(\App\Tenant\Settlement\Services\PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: $today->copy(),
            userId: $this->actorId(),
        );

        app(\App\Tenant\Settlement\Services\PaymentRuns::class)->release(
            (string) $run['id'],
            $this->secondActorId(),
        );
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Run a block with a real tracer writing into memory.
     *
     * @param  callable(): void  $work
     * @param  list<string>|null  $alwaysSample
     */
    private function whileTracing(
        callable $work,
        ?array $alwaysSample = null,
        int $ratePerMille = 1000,
    ): CollectingSpanExporter {
        $exporter = new CollectingSpanExporter();

        app()->instance(Tracer::class, new Tracer(
            $exporter,
            new Sampler($alwaysSample ?? ['errors'], $ratePerMille),
            true,
        ));

        try {
            $work();
        } finally {
            /*
             | Forgotten, not replaced with the old instance.
             |
             | `Tracer` is bound as a singleton, so forgetting the instance
             | makes the next resolution rebuild it from configuration - which
             | is the null exporter. Leaving a recording tracer bound would
             | make every later check in this file export spans into an
             | exporter nobody reads, and one of them asserts that nothing is
             | exported at all.
             */
            app()->forgetInstance(Tracer::class);
        }

        return $exporter;
    }

    /** @return list<string> */
    private function alwaysSampled(): array
    {
        $configured = config('telemetry.sampling.always_sample');

        return is_array($configured)
            ? array_values(array_map(static fn (mixed $v): string => (string) $v, $configured))
            : [];
    }

    /** @return list<string> */
    private function globalMiddleware(): array
    {
        $kernel = app(HttpKernel::class);

        $property = (new ReflectionClass($kernel))->getProperty('middleware');
        $property->setAccessible(true);

        /** @var list<string> $stack */
        $stack = $property->getValue($kernel);

        return $stack;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
