<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Driver
    |---------------------------------------------------------------------------
    |
    | 'stub' renders an HTML placeholder, so a fresh clone works with no PDF
    | service. 'gotenberg' renders real PDF/A-3.
    |
    */

    'driver' => env('PDF_DRIVER', 'stub'),

    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://127.0.0.1:3000'),
        'timeout_seconds' => 60,
    ],

    /*
    |---------------------------------------------------------------------------
    | PDF/A conformance - VERIFIED, not claimed
    |---------------------------------------------------------------------------
    |
    | Gotenberg renders, Ghostscript converts, and then veraPDF VERIFIES. A
    | non-conformant output FAILS THE JOB rather than being delivered, because
    | a PDF that merely claims PDF/A conformance is not an archive copy - and
    | the archive is the legal record.
    |
    */

    'archive_profile' => 'PDF/A-3b',

    'verapdf' => [
        'binary' => env('VERAPDF_BINARY', 'verapdf'),
        'enforce' => true,
        'fail_job_on_nonconformance' => true,
    ],

    /*
    |---------------------------------------------------------------------------
    | Fonts
    |---------------------------------------------------------------------------
    |
    | FULLY EMBEDDED, including the Arabic face, with correct shaping and text
    | extraction. A PDF whose Arabic cannot be selected or searched is a scan
    | with extra steps.
    |
    */

    'fonts' => [
        'embed' => 'full',
        'subset' => false,
        'arabic_shaping' => true,
    ],

    /*
    |---------------------------------------------------------------------------
    | Determinism
    |---------------------------------------------------------------------------
    |
    | The same invoice must render BYTE-IDENTICALLY, so the archive hash is
    | stable. That means no embedded timestamp, no random document id, and a
    | fixed producer string.
    |
    */

    'deterministic' => [
        'enabled' => true,
        'fixed_creation_date' => true,
        'producer' => 'PeakFlow',
    ],

    /*
    |---------------------------------------------------------------------------
    | Print geometry
    |---------------------------------------------------------------------------
    */

    'page' => [
        'sizes' => ['A4', 'Letter'],
        'default' => 'A4',
        'margin_mm' => ['top' => 16, 'bottom' => 16, 'inline_start' => 14, 'inline_end' => 14],
        'repeat_table_headers' => true,
        'page_x_of_y' => true,
    ],

];
