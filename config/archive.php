<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Where the archive lives - PF-129
    |---------------------------------------------------------------------------
    |
    | Its own disk, separate from attachments. The two have different
    | retention rules and different deletion rules: an attachment goes when
    | the document does, and an archived artefact cannot go for six years
    | whatever anybody asks.
    |
    | In production this is a bucket with object-lock enabled. The database
    | enforces the half it can - a trigger refuses a delete before the
    | retention date - and object-lock is the half that survives somebody
    | with credentials to the bucket.
    |
    */

    'disk' => env('ARCHIVE_DISK', 'archive'),

    'prefix' => env('ARCHIVE_PREFIX', 'archive'),

    /*
    |---------------------------------------------------------------------------
    | Object lock
    |---------------------------------------------------------------------------
    |
    | Whether the configured bucket has object-lock in compliance mode. It is
    | a declaration rather than a setting PeakFlow can apply: the lock is
    | configured on the bucket at creation and cannot be turned on afterwards.
    |
    | Declared so `ProductionConfiguration` can refuse a production deployment
    | that has not done it - an archive an operator can delete is not a WORM
    | archive, whatever the application refuses.
    |
    */

    'object_lock' => (bool) env('ARCHIVE_OBJECT_LOCK', false),

    /*
    |---------------------------------------------------------------------------
    | The restore drill
    |---------------------------------------------------------------------------
    |
    | How many artefacts a drill restores. Random each time, so a drill that
    | passed last month does not mean it checked the same ones.
    |
    */

    'drill_sample' => (int) env('ARCHIVE_DRILL_SAMPLE', 10),

];
