<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Driver
    |---------------------------------------------------------------------------
    |
    | 'log' writes the document that WOULD have been transmitted into the log
    | and marks the transmission succeeded, so the full state machine is
    | exercised without a third party. It is the committed default, which is
    | what lets a fresh clone issue an invoice end to end with no Peppol
    | credentials and no clearance certificate.
    |
    | Switching to a real driver is a CONFIGURATION change and not a different
    | code path - asserted by running the same feature test against both.
    |
    */

    'driver' => env('EXCHANGE_DRIVER', 'log'),

    /*
    |---------------------------------------------------------------------------
    | Reliability
    |---------------------------------------------------------------------------
    |
    | A transport outage is a QUEUE DEPTH AND AN ALERT, never a failed user
    | request. The document is issued; its transmission is pending. Those are
    | different facts and the architecture keeps them different - which is why
    | the Exchange plane exists at all.
    |
    | An outbound transmission is idempotent at the PARTNER'S identifier, so a
    | retry after an ambiguous timeout cannot submit twice. For a clearance
    | platform that would be a duplicate legal document.
    |
    */

    'retry' => [
        'max_attempts' => (int) env('EXCHANGE_RETRY_MAX', 8),
        // Exponential with jitter. The long tail is deliberate: a national
        // portal can be down for hours, and giving up in minutes converts
        // their outage into our data loss.
        'backoff_seconds' => [10, 60, 300, 1800, 7200, 21600, 86400],
        'jitter_ratio' => 0.2,
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('EXCHANGE_CIRCUIT_BREAKER_THRESHOLD', 5),
        'open_seconds' => 120,
        'half_open_probes' => 1,
    ],

    'dead_letter' => [
        'enabled' => true,
        // A human inspects and re-drives. Nothing is discarded, because the
        // document is already legally issued.
        'retain_days' => 90,
    ],

    /*
    |---------------------------------------------------------------------------
    | Transports
    |---------------------------------------------------------------------------
    |
    | Credentials are REFERENCES, never keys. Signing keys live in a KMS and
    | the Exchange plane never reads one from config at runtime.
    |
    */

    'peppol' => [
        'access_point_url' => env('PEPPOL_ACCESS_POINT_URL'),
        'sml_domain' => env('PEPPOL_SML_DOMAIN'),
        'smp_cache_ttl_seconds' => 3600,
        // Tells the user whether a recipient is reachable BEFORE they issue,
        // because "issued but undeliverable" is a worse state than "not yet
        // issued".
        'pre_send_reachability_check' => true,
    ],

    'zatca' => [
        'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),
        'kms_key_reference' => env('ZATCA_KMS_KEY_REFERENCE'),
        // Standard invoices are CLEARED before delivery; simplified invoices
        // are REPORTED within the window. Two different flows, and conflating
        // them is the common failure.
        'reporting_window_hours' => 24,
    ],

    /*
    |---------------------------------------------------------------------------
    | Webhooks
    |---------------------------------------------------------------------------
    |
    | The timestamp is INSIDE the signed material, which is what makes a
    | captured request unusable later. A signature over the body alone can be
    | replayed indefinitely.
    |
    | The outbound allowlist refuses private, link-local and loopback
    | addresses, and redirects are not followed - so a webhook URL cannot be
    | used to reach an internal service.
    |
    */

    'webhooks' => [
        'signature_header' => 'PeakFlow-Signature',
        'timestamp_tolerance_seconds' => 300,
        'secret_rotation_overlap_days' => 14,
        'request_timeout_seconds' => 10,
        'follow_redirects' => false,
        'refuse_private_ranges' => true,
        'retry_schedule_seconds' => [0, 10, 60, 300, 1800, 7200, 21600, 86400],
        'disable_after_consecutive_failures' => 20,
        'delivery_log_retain_days' => 90,
    ],

    /*
    |---------------------------------------------------------------------------
    | Inbound XML hardening
    |---------------------------------------------------------------------------
    |
    | A document from a trading partner is UNTRUSTED INPUT. These are disabled
    | EXPLICITLY on every reader rather than assumed off by default, and a test
    | fires XXE, billion-laughs, a deeply nested document and an oversized one
    | at every parser the application has.
    |
    */

    /*
    |---------------------------------------------------------------------------
    | Email intake - PF-140
    |---------------------------------------------------------------------------
    |
    | A supplier sends to `inbox+<entity code>@<domain>`, and that address is
    | the ONLY thing that decides which entity's queue the document joins.
    | Not the sender, not the subject, not a header: a group with eight
    | entities receives eight suppliers' invoices at eight addresses, and
    | resolving the entity from anything the sender controls means the sender
    | chooses which company's ledger their invoice lands in.
    |
    | Derived rather than stored per entity, because a stored address is a
    | second answer to "which entity is this" - and the two disagree the
    | first time somebody renames an entity.
    |
    */

    'intake' => [
        'domain' => env('EXCHANGE_INTAKE_DOMAIN', 'invoices.peakflow.test'),
        'mailbox' => env('EXCHANGE_INTAKE_MAILBOX', 'inbox'),
    ],

    'xml' => [
        'allow_external_entities' => false,
        'allow_dtd' => false,
        'allow_network' => false,
        'max_entity_expansion' => 0,
        'max_depth' => 100,
        'max_bytes' => 16 * 1024 * 1024,
    ],

];
