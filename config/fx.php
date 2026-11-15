<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Exchange rate feeds - PF-069
|--------------------------------------------------------------------------
|
| HOW to talk to each provider. The ORDER they are tried in lives in the
| tenant's own `fx_providers` table, not here.
|
| The split is deliberate. Precedence is an operator decision - "use the
| central bank first this month" - and has to change without a deploy. A URL
| and an API key are a deployment concern. Keeping both here would mean a
| finance manager filing a pull request to change which feed wins.
|
| A provider configured here with no row in `fx_providers` is never tried,
| and a row with no configuration here is skipped. Both are intentional: the
| row is the switch, this file is the wiring.
|
| NOTHING IN HERE MAY INVERT A RATE. A feed that publishes only SAR -> EUR
| returns nothing when asked for EUR -> SAR, and the next provider is tried.
| 1 / x on a rounded rate drifts one way permanently; see
| docs/architecture/money-and-precision.md.
*/

return [

    'providers' => [

        /*
         | The riyal peg, which has not moved since 1986.
         |
         | Configured rather than fetched, because going to a feed every
         | morning for a number that does not move adds a dependency and a
         | failure mode, and the feed's value wobbles in the fourth decimal
         | in ways that then appear in the books.
         |
         | Both directions, because both are used and neither is computed
         | from the other. 1 / 3.75 is 0.266666..., and the recurring 6 is
         | exactly the drift this architecture refuses to accept.
         */
        'peg' => [
            'driver' => 'static',
            'rates' => [
                'USD/SAR' => '3.750000000000',
                'SAR/USD' => '0.266666666667',
            ],
        ],

        /*
         | An HTTP feed, as an example of the shape.
         |
         | Placeholders in the URL: {base}, {date}, {symbols}. One request
         | per base currency, with every quote currency needed from it -
         | asking per pair turns a nine-currency tenant into seventy-two
         | requests and gets the feed to rate-limit us, which then looks
         | like an outage.
         |
         | `rates_path` is where the quote map lives in the response body, in
         | dot notation. It is configuration rather than code because a URL
         | and a path into a JSON body are the two things most likely to be
         | wrong, and they belong where a reader can see them next to each
         | other.
         |
         | Off unless FX_PRIMARY_URL is set. A feed with no URL would fail on
         | every run and fill the audit log with alerts about a provider
         | nobody configured.
         */
        'primary' => env('FX_PRIMARY_URL') === null ? null : [
            'driver' => 'http',
            'url' => (string) env('FX_PRIMARY_URL'),
            'rates_path' => (string) env('FX_PRIMARY_RATES_PATH', 'rates'),
            'timeout' => (int) env('FX_PRIMARY_TIMEOUT', 10),
            'headers' => array_filter([
                // The key goes in a header, never in the URL: a URL ends up
                // in an access log, an error report and a browser history.
                'Authorization' => env('FX_PRIMARY_KEY') === null
                    ? null
                    : 'Bearer '.env('FX_PRIMARY_KEY'),
            ]),
        ],

    ],

    /*
     | How far back the fetch will look for a pair that no feed supplied.
     |
     | Not a staleness threshold - that is per rate set, in
     | `fx_rate_sets.staleness_threshold_days`, and it decides what gets
     | FLAGGED. This decides what gets USED, and the two differ on purpose:
     | a rate may be stale enough to flag on every document and still be the
     | best thing available, and refusing it would stop a business trading
     | because a third party's API is down.
     */
    'fallback_window_days' => (int) env('FX_FALLBACK_WINDOW_DAYS', 30),

];
