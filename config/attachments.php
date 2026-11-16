<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Where attachments live - PF-073
    |---------------------------------------------------------------------------
    |
    | A disk of its own, never the public one. The objects are tax documents -
    | bank statements, contracts, suppliers' invoices - and the public disk is
    | served directly by the web server with no signature and no expiry.
    |
    */

    'disk' => env('ATTACHMENTS_DISK', 'attachments'),

    'prefix' => env('ATTACHMENTS_PREFIX', 'attachments'),

    /*
    |---------------------------------------------------------------------------
    | The size cap
    |---------------------------------------------------------------------------
    |
    | An upload path with no cap is a way to fill the disk, and a full disk
    | stops the ledger accepting postings. Twenty-five megabytes takes a
    | scanned contract and refuses a video.
    |
    */

    'max_bytes' => (int) env('ATTACHMENTS_MAX_BYTES', 25 * 1024 * 1024),

    /*
    |---------------------------------------------------------------------------
    | The download origin
    |---------------------------------------------------------------------------
    |
    | A separate host, so a stored file cannot execute against the
    | application's own origin and reach a session cookie. Empty means the
    | application's own host, which is acceptable in development and is
    | reported by `ProductionConfiguration` in production.
    |
    | The signature covers the path and the query rather than the host, so
    | moving the host is safe.
    |
    */

    'origin' => env('ATTACHMENTS_ORIGIN', ''),

    /*
    |---------------------------------------------------------------------------
    | How long a download link lives
    |---------------------------------------------------------------------------
    |
    | Five minutes. A permanent URL is one that ends up in a browser history,
    | a chat message and a support ticket, and the file stays readable from
    | all three for as long as it exists.
    |
    */

    'url_ttl_seconds' => (int) env('ATTACHMENTS_URL_TTL', 300),

    /*
    |---------------------------------------------------------------------------
    | The virus scanner
    |---------------------------------------------------------------------------
    |
    | `clamav` runs `clamdscan`. `unavailable` stores the file and marks it
    | unscanned, which leaves it unreadable - so a development machine can
    | exercise the whole upload path and still cannot serve a file nobody has
    | looked at.
    |
    | There is deliberately no driver that accepts everything. That is the
    | one every "scanning is optional" implementation has, and it turns every
    | environment where the scanner is missing into one where the scan passes.
    |
    */

    'scanner' => env('ATTACHMENTS_SCANNER', 'unavailable'),

    'clamav' => [
        'binary' => env('CLAMDSCAN_BINARY', 'clamdscan'),
        'timeout_seconds' => (int) env('CLAMDSCAN_TIMEOUT', 60),
    ],

];
