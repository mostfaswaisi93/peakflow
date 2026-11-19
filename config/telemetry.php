<?php

declare(strict_types=1);

return [

    'enabled' => (bool) env('TELEMETRY_ENABLED', false),

    'otlp_endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),

    /*
    |---------------------------------------------------------------------------
    | Where a span goes - a DRIVER, with a working local default
    |---------------------------------------------------------------------------
    |
    | PF-240, PF-229. `log` writes each finished span as one JSON line on the
    | channel below and needs no endpoint, no credentials and no network, so a
    | fresh clone traces an invoice end to end with nothing stood up. An OTLP
    | exporter is a second implementation of
    | `App\Support\Telemetry\Contracts\SpanExporter` named here - a
    | configuration change, not a different code path.
    |
    | `memory` keeps the last spans in memory, which is what the checks and a
    | one-off diagnostic run read back. `null` drops them, and is what
    | TELEMETRY_ENABLED=false gets whatever this says.
    |
    */

    'exporter' => env('TELEMETRY_EXPORTER', 'log'),

    /*
    |---------------------------------------------------------------------------
    | Which log channel a span and an alert are written on
    |---------------------------------------------------------------------------
    |
    | It has to be a channel that carries the allowlist tap, because a span's
    | attributes and an alert's fields are log context and are filtered by the
    | channel's own processor rather than by a second mechanism. `stderr`,
    | `syslog` and `slack` in `config/logging.php` declare NO tap, so a span
    | exported through one of them would be unfiltered while looking
    | configured. `bin/verify observability` asserts the resolved channel has
    | the processor attached.
    |
    */

    'channel' => env('TELEMETRY_LOG_CHANNEL', 'single'),

    /*
    |---------------------------------------------------------------------------
    | Where a collected metric goes
    |---------------------------------------------------------------------------
    |
    | PF-241, PF-229. The same decision as the span exporter: `log` writes each
    | sample as one JSON line on the channel above, so a collector that already
    | reads the log reads the metrics and a fresh clone needs no push gateway.
    | `memory` keeps them in process, which is what the checks drive the real
    | `metrics:report` command against.
    |
    */

    'metric_sink' => env('TELEMETRY_METRIC_SINK', 'log'),

    /*
    |---------------------------------------------------------------------------
    | Sampling
    |---------------------------------------------------------------------------
    |
    | ALWAYS keep errors, and ALWAYS keep money-moving operations, whatever the
    | rate. A sampled-away payment release is the one trace you needed.
    |
    */

    'sampling' => [
        'ratio' => (float) env('OTEL_TRACES_SAMPLER_ARG', 0.1),
        'always_sample' => [
            'errors',
            'payment.release',
            'invoice.issue',
            'journal.post',
            'period.close',
            'period.reopen',
            'tax_return.file',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Field ALLOWLIST - never a redaction blocklist
    |---------------------------------------------------------------------------
    |
    | A blocklist FAILS OPEN the first time someone adds a field, and the field
    | that gets added is an IBAN.
    |
    | A trace is a log with a nicer viewer and it leaks the same way, so traces
    | and logs share this list. A test asserts that a payload containing an
    | IBAN, a token and a password produces none of them in a log line, an
    | error report or a span attribute.
    |
    */

    'context_allowlist' => [
        'correlation_id',
        'request_id',
        'tenant_id',
        'entity_id',
        'user_id',
        'operator_id',
        'route',
        'method',
        'status',
        'duration_ms',
        'queue',
        'job',
        'attempt',
        'document_type',
        'document_id',
        'document_number',
        'journal_id',
        'period_id',
        'account_code',
        'currency',
        'jurisdiction_profile',
        'transport',
        'sql_shape',
        'exception_class',

        /*
         | The fields the invariant alerts actually use - PF-014, PF-053.
         |
         | Added because the allowlist was declared before anything logged
         | through it, and the first real callers wanted names that were not
         | on it: the drift alert's `findings`, `kinds` and `sample`, and the
         | revaluation's `run_id` and `rate_set`.
         |
         | Every one of them is a count, an id or a classification. The test
         | that an IBAN, a token and a password produce none of themselves in
         | a log line is what keeps that true as the list grows - a name
         | added here still cannot carry a secret past it, because the test
         | passes the secret under an allowlisted name too.
         */
        'findings',
        'kinds',
        'sample',
        'checked',
        'run_id',
        'rate_set',
        'as_of',
        'accounts_restated',
        'provider_failures',
        'from_fallback',
        'unresolved',
        'rows',
        'entries',
        'partition',
        'lines',
        'reference',
        'kind',
        'key',
        'count',

        /*
         | The fields a SPAN, a METRIC and an ALERT are made of - PF-240,
         | PF-241, PF-242.
         |
         | A span's attributes and a metric's labels are log context: they go
         | through this list because they are written as log context, not
         | because somebody remembered to filter them twice. Which means the
         | span's own structural fields have to be on it, or a span would
         | arrive with its trace id stripped - and a metric's label names
         | have to be on it, or every dashboard would be unlabelled.
         |
         | Every name here is an id, a count or a classification, which is
         | the rule the list is kept by. `jurisdiction`, `authority`,
         | `age_bucket`, `task` and `state` are the label names the business
         | metrics use, and each is a code or a bucket: a metric labelled by
         | customer id is a customer list on whoever's dashboard, so no
         | metric may label by one and `Metrics::register()` refuses a label
         | name that is not on this list.
         */
        'trace_id',
        'span_id',
        'parent_span_id',
        'span',
        'operation',
        'sampled',
        'duration_us',
        'alert',
        'severity',
        'runbook',
        'first_divergence',
        'observed',
        'threshold',
        'metric',
        'audience',
        'unit',
        'labels',
        'samples',
        'jurisdiction',
        'authority',
        'age_bucket',
        'task',
        'state',
    ],

    /*
    |---------------------------------------------------------------------------
    | Database spans
    |---------------------------------------------------------------------------
    |
    | Carry the statement SHAPE, never its bound values. A bound value is
    | customer data, and a trace is not an audit log.
    |
    */

    'database' => [
        'record_statement_shape' => true,
        'record_bindings' => false,
    ],

    /*
    |---------------------------------------------------------------------------
    | Invariant alerts
    |---------------------------------------------------------------------------
    |
    | The part that matters most in a financial system, and the part that
    | cannot be retrofitted cheaply. A dashboard of CPU and p99 latency does
    | not tell you that a subledger stopped equalling its control account last
    | Tuesday.
    |
    | Each alert must name the entity, the period and the first divergent
    | record, so it is actionable at 3am - and each has a runbook entry with a
    | TESTED, NON-DESTRUCTIVE first response. Drift is never auto-repaired:
    | silent repair hides the bug that caused it.
    |
    */

    'invariant_alerts' => [
        'read_model_drift',
        'hash_chain_divergence',
        'subledger_mismatch',
        'unbalanced_entry_in_database',
        'suspense_above_threshold',
        'in_transit_aged',
        'privilege_drift',
        'archive_checksum_mismatch',
        'statement_continuity_gap',
        'clearance_rejection_spike',

        /*
         | And the watch itself - PF-242.
         |
         | Added when the watch was built, because a watch that failed
         | silently is indistinguishable from one that found nothing, and
         | the second is the reassuring reading: the hour a query was
         | refused is the hour every invariant above reports as healthy.
         |
         | `InvariantWatch::run()` turns a throwing watcher into this,
         | rather than letting it stop the remaining ones.
         */
        'watch_failed',
    ],

    /*
    |---------------------------------------------------------------------------
    | Where an invariant alert goes
    |---------------------------------------------------------------------------
    |
    | PF-242, PF-229. A driver, with a committed default that needs no paging
    | provider: `log` writes the alert as one JSON line, at `critical`, on the
    | channel above. A paging integration is a second implementation of
    | `App\Support\Telemetry\Contracts\AlertSink` named here.
    |
    | This is deliberately NOT gated on `telemetry.enabled`. Tracing is off by
    | default because a span per statement costs something; an alert that
    | stopped firing because somebody turned tracing off would be the exact
    | failure this whole section exists to prevent, and nobody would find out
    | until the incident it was built for.
    |
    */

    'alert_sink' => env('TELEMETRY_ALERT_SINK', 'log'),

    /*
    |---------------------------------------------------------------------------
    | Thresholds the watch compares against
    |---------------------------------------------------------------------------
    |
    | A decimal STRING, not a float: it is compared against a control account
    | balance read out of `numeric`, and a threshold that had been through a
    | float would make the comparison disagree with the ledger at the fourth
    | decimal - which on a boundary is the difference between an alert firing
    | and not.
    |
    | Not zero, because a few hundred in suspense on the day a statement is
    | imported is ordinary, and an alert that fires every morning is an alert
    | nobody reads.
    |
    */

    'thresholds' => [
        'suspense' => (string) env('ALERT_SUSPENSE_THRESHOLD', '5000.0000'),
    ],

    /*
    |---------------------------------------------------------------------------
    | When the watch runs
    |---------------------------------------------------------------------------
    |
    | Read by `routes/console.php`, which is the only place this product
    | schedules anything. A cron expression rather than a `->hourly()` call so
    | that an operator can slow it down on a large fleet without a deployment -
    | and so the schedule is a value a check can assert about.
    |
    */

    'watch_schedule' => (string) env('ALERT_WATCH_SCHEDULE', '30 * * * *'),

];
