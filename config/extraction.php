<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Driver - PF-142
    |---------------------------------------------------------------------------
    |
    | `none` is the committed default and is a declared absence rather than a
    | stub: a reading that looks real and is invented would make the suite
    | assert the behaviour of something that does not exist, and the first
    | real engine would have to match a fiction.
    |
    | With `none`, a document carrying embedded XML is still read exactly -
    | which is most of what matters, because a Factur-X PDF is a structured
    | document and the figures are in it. What is unavailable is reading a
    | scan, and a proposal records that as `unavailable` with the reason:
    | "no engine is installed" sends somebody to the deployment, where
    | "nothing could be read" would send them to re-scan a legible invoice.
    |
    */

    'driver' => env('EXTRACTION_DRIVER', 'none'),

    /*
    |---------------------------------------------------------------------------
    | What a person has to look at
    |---------------------------------------------------------------------------
    |
    | A field read with less confidence than this is flagged for review. The
    | flag is STORED on the field rather than computed from the threshold,
    | because a threshold that changed would move it - and a reviewer who
    | confirmed a field that is now flagged would look careless rather than
    | consistent.
    |
    */

    'review_below' => env('EXTRACTION_REVIEW_BELOW', '0.90'),

    /*
    |---------------------------------------------------------------------------
    | The embedded-file limit
    |---------------------------------------------------------------------------
    |
    | An embedded stream of a few hundred bytes can claim to inflate to a
    | gigabyte, and this reads a file a stranger sent.
    |
    */

    'max_embedded_bytes' => 16 * 1024 * 1024,

];
