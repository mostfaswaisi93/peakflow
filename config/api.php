<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The REST API - PF-217 to PF-222
    |--------------------------------------------------------------------------
    |
    | Nothing here reads `env()`, and that is deliberate.
    |
    | Every value below is part of the PUBLISHED contract: the version in the
    | path, the window a retry is replayed in, the page sizes a client may
    | ask for. A deployment that could change one of them could change the
    | contract without changing the document that describes it - and an
    | integrator's code is written against the document.
    |
    | They live in a config file rather than as constants in a class so that a
    | reader can see the whole contract's shape in one place, and so that
    | `docs/api/openapi.yaml` can be checked against it.
    |
    */

    /*
     | The version in the path. One segment, never a header - PF-230.
     |
     | A version an integrator can see in a log line and in a bug report is
     | worth more than one negotiated invisibly.
     */
    'version' => 'v1',

    'prefix' => 'api/v1',

    'idempotency' => [

        /*
         | 24 hours, from PF-220.
         |
         | Long enough that a client retrying after an outage is replayed
         | rather than executing a second time, and short enough that the
         | stored responses - which hold amounts and party names - are not a
         | permanent second copy of the ledger.
         */
        'retention_hours' => 24,

        /*
         | What a client is told to wait when the first attempt is still in
         | flight.
         |
         | Two seconds, because the thing it is waiting for is one database
         | transaction. A longer value trains clients to give up and retry on
         | their own schedule, which is the behaviour that produced the
         | duplicate in the first place.
         */
        'in_flight_retry_after_seconds' => 2,

        /*
         | How long a duplicate request waits for the first one to commit
         | before it is told to come back.
         |
         | This is the line between "wait and be given the first request's
         | own answer" and "409, retry after two seconds". Two requests that
         | arrive together are the normal case for a client retrying on a
         | dropped connection, and answering the second one with the first
         | one's response is strictly better for it than a refusal it has to
         | handle - so the wait is long enough to cover a posting that is
         | proceeding normally.
         |
         | It is not longer than that on purpose. A duplicate holding a worker
         | for ten seconds while the first request waits on something slow is
         | two workers spent on one document, and the honest answer at that
         | point is "come back" rather than "hold on".
         */
        'claim_wait_milliseconds' => 2500,

        /*
         | The shortest key that is worth anything.
         |
         | A client sending `1` has not made a unique key, it has made a
         | collision with every other client that thought of `1` - and
         | because the key is scoped to the token, the collision is with
         | itself on its own next request. 16 characters is a UUID without
         | its dashes, which is what every client library produces.
         */
        'minimum_key_length' => 16,

        'maximum_key_length' => 128,
    ],

    'pagination' => [

        /*
         | The page sizes a client may ask for - PF-221.
         |
         | An allowlist and not a maximum. A maximum invites `limit=99999`
         | and answers it with 100, which is a silent reinterpretation of
         | what was asked; an allowlist answers it with a 400 naming the four
         | values that work.
         */
        'page_sizes' => [10, 25, 50, 100],

        'default_page_size' => 25,

        /*
         | The last page number offset pagination will serve - PF-221.
         |
         | Offset pagination exists for a UI that genuinely needs a page
         | number, and it is the slower path: `OFFSET 50000` reads fifty
         | thousand rows in order to discard them. The cap is where that stops
         | being a page and becomes a scan, and a client that reaches it is
         | told to use the cursor.
         */
        'maximum_offset_page' => 200,
    ],
];
