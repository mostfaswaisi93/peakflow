<?php

declare(strict_types=1);

return [

    'enabled' => (bool) env('TELEMETRY_ENABLED', false),

    'otlp_endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),

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
    ],

];
