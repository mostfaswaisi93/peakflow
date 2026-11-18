<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | When a stock count needs a second person
    |---------------------------------------------------------------------------
    |
    | PF-253. A variance whose absolute value exceeds this needs somebody
    | other than the counter to approve it before it posts.
    |
    | Not every count. Requiring two signatures on a cycle count of one shelf
    | is how the second signature becomes a rubber stamp - and then it is
    | worth nothing on the count that matters, which is the one where a
    | five-figure difference is being written off.
    |
    | The figure is in the entity's functional currency and is COPIED onto the
    | count when it opens, so a count approved last year stays explainable
    | under the threshold that applied to it rather than under today's.
    |
    | Per jurisdiction rather than one number, because materiality is a
    | judgement about the business and the currencies are not comparable: 500
    | is a shelf in SAR and a rounding error in some others.
    |
    */

    'count_materiality' => [
        'SAR' => '500.0000',
        'JOD' => '150.0000',
        'USD' => '150.0000',
        'EUR' => '150.0000',
    ],

    /*
    | What an entity whose currency is not listed gets.
    |
    | Zero, deliberately: every variance then needs a second name. A default
    | that guessed a number would silently set the bar for a currency nobody
    | thought about, and the symptom is a write-off nobody approved.
    */
    'count_materiality_default' => '0.0000',

    /*
    |---------------------------------------------------------------------------
    | How many items a variance report names
    |---------------------------------------------------------------------------
    |
    | The report is by value and by quantity and names the items that account
    | for most of the difference - a list of two hundred lines is read by
    | nobody, and the four that matter are in it somewhere.
    |
    */

    'variance_report_names' => 10,

];
