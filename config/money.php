<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Decimal scales
    |---------------------------------------------------------------------------
    |
    | These are listed for documentation, NOT for configuration. Exactly three
    | scales exist, they are declared in App\Support\Money\Scale, and an
    | architecture test fails a migration that introduces a fourth. Changing a
    | scale after there is data means rewriting every money column in every
    | tenant database and re-deriving every stored total.
    |
    |   amount      4   Journal lines, document totals, tax subtotals, payments.
    |                   Four decimals absorb the three-decimal currencies
    |                   (BHD, IQD, JOD, KWD, LYD, OMR, TND) plus a guard digit.
    |   unit_price  8   Per-litre, per-kWh, per-minute and per-thousand pricing
    |                   with no scaling factor.
    |   rate       12   FX rates and tax percentages, so a round trip through
    |                   an inverse rate does not drift.
    |
    | See docs/architecture/money-and-precision.md and ADR-0004.
    |
    */

    'scales' => [
        'amount' => 4,
        'unit_price' => 8,
        'rate' => 12,
    ],

    /*
    |---------------------------------------------------------------------------
    | Default rounding mode
    |---------------------------------------------------------------------------
    |
    | HALF_UP - round half AWAY FROM ZERO - because EN 16931 and most VAT
    | regimes specify it. IEEE 754 and most language defaults use half-even
    | ("banker's"), which differs on roughly one document in a hundred and
    | compounds across a return.
    |
    | This is the FALLBACK. A jurisdiction profile that specifies otherwise
    | overrides it, which is why the rule is data rather than a branch. No
    | arithmetic operation in App\Support\Money accepts an implicit mode: the
    | policy is always passed, and that is the whole reason brick/money was
    | chosen over a hand-rolled helper.
    |
    | Supported: half_up, half_down, half_even, up, down, ceiling, floor.
    |
    */

    'default_rounding' => env('MONEY_DEFAULT_ROUNDING', 'half_up'),

    /*
    |---------------------------------------------------------------------------
    | Tax grouping
    |---------------------------------------------------------------------------
    |
    | 'per_rate_group' accumulates tax UNROUNDED per (category, rate) pair and
    | rounds once per group, which is what EN 16931 BG-23 describes and what
    | BR-CO-14 asserts.
    |
    | 'per_line' rounds each line's tax and sums. It gives a different and
    | usually wrong answer: three lines of 10.03 at 15% give 4.51 per group and
    | 4.50 per line. One cent per document, across 50,000 documents a quarter,
    | is a difference between the ledger and the filed return that nobody can
    | locate - because every individual document looks correct.
    |
    | It exists only because a jurisdiction may mandate it.
    |
    */

    'tax_grouping' => env('MONEY_TAX_GROUPING', 'per_rate_group'),

    /*
    |---------------------------------------------------------------------------
    | Apportionment residual
    |---------------------------------------------------------------------------
    |
    | A document-level discount across three rate groups will not divide
    | evenly. The residual cent is assigned by RULE, always the same way, so
    | that recomputing a document twice gives an identical total - a document
    | that recomputes differently is worse than one that is a cent off.
    |
    | 'largest_group' | 'first_group' | 'last_group'
    |
    */

    'apportionment_residual' => 'largest_group',

    /*
    |---------------------------------------------------------------------------
    | Display
    |---------------------------------------------------------------------------
    |
    | Rounding to the currency's ISO 4217 exponent is a VIEW, computed at
    | render and payment time. It is never stored as a second total: a JPY
    | invoice shows the integer, and the stored amount keeps four decimals.
    | Storing the display value is how a ledger stops balancing.
    |
    | 'digits' is the per-tenant default for digit shaping. Financial documents
    | in the region conventionally use Western digits even in Arabic text, so
    | `latn` is the default; the setting exists because institutional
    | convention differs. Applied through ICU, never by string replacement, and
    | display only - stored values, exports, XML and API responses are always
    | Western digits.
    |
    */

    'display' => [
        'digits' => 'latn',
        'accounting_negative' => true,
    ],

];
