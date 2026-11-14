<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Statement formats
    |---------------------------------------------------------------------------
    |
    | Ordered by FIDELITY, not by popularity. The order matters because where a
    | bank offers more than one, the import prefers the richer format - and the
    | UI states the difference rather than presenting them as equivalent.
    |
    |   camt053   ISO 20022. The target. Structured remittance, transaction
    |             codes, batch entries, reversals, charges, FX amounts.
    |   camt052   Intraday report. PROVISIONAL: feeds the cash position, does
    |   camt054   not post and does not reconcile. Treating it as final
    |             double-counts when the camt053 arrives.
    |   mt940     SWIFT. Legacy, ubiquitous and LOSSY - `:86:` is
    |   mt942     semi-structured free text whose layout is bank-specific.
    |   ofx       Retail and North American banks, both SGML and XML variants.
    |   csv       Per-bank column mapping, saved as a reusable template.
    |
    */

    'formats' => [
        'camt053' => ['fidelity' => 'high', 'posts' => true, 'reconciles' => true],
        'camt052' => ['fidelity' => 'intraday', 'posts' => false, 'reconciles' => false],
        'camt054' => ['fidelity' => 'intraday', 'posts' => false, 'reconciles' => false],
        'mt940' => ['fidelity' => 'low', 'posts' => true, 'reconciles' => true],
        'mt942' => ['fidelity' => 'intraday', 'posts' => false, 'reconciles' => false],
        'ofx' => ['fidelity' => 'medium', 'posts' => true, 'reconciles' => true],
        'csv' => ['fidelity' => 'variable', 'posts' => true, 'reconciles' => true],
    ],

    /*
    |---------------------------------------------------------------------------
    | Statement continuity
    |---------------------------------------------------------------------------
    |
    | Every import asserts its opening balance equals the previous statement's
    | closing balance for that account. A mismatch STOPS THE IMPORT and reports
    | the gap rather than importing a hole - the alternative is a
    | reconciliation that can never be completed and nobody knows why.
    |
    | The assertion is on the BALANCE, not the statement number, because banks
    | number statements inconsistently: per month, per page, per download.
    |
    */

    'continuity' => [
        'enforce' => true,
        'allow_first_statement' => true,
    ],

    /*
    |---------------------------------------------------------------------------
    | Matching engine
    |---------------------------------------------------------------------------
    |
    | Seven passes, cheapest and most certain first. Each proposes with a
    | confidence score and a HUMAN-READABLE REASON.
    |
    */

    'matching' => [
        'passes' => [
            1 => 'exact_reference',
            2 => 'payment_run',
            3 => 'amount_party_window',
            4 => 'structured_remittance',
            5 => 'subset_sum',
            6 => 'rule_engine',
            7 => 'fuzzy_party',
        ],

        // A tenant sets its own threshold and may LOWER it freely. The ceiling
        // is the operator's, and a tenant cannot raise past it.
        'auto_apply_threshold' => 0.90,
        'auto_apply_ceiling' => (float) env('BANKING_AUTOMATCH_CONFIDENCE_CEILING', 0.95),

        // Pass 7 is proposal-only at ANY confidence. Not configurable: the
        // engine learns by accumulating counterparty identifiers, not by
        // lowering its threshold, because an engine whose thresholds move on
        // their own cannot be audited.
        'fuzzy_is_proposal_only' => true,

        // A three-character match against a six-digit invoice number is noise.
        // Treating it as a signal is how an engine applies a payment to the
        // wrong invoice confidently.
        'min_reference_match_length' => 5,

        // Subset-sum is bounded, because the naive implementation hangs on a
        // customer with 400 open items.
        'subset_sum' => [
            'candidate_cap' => (int) env('BANKING_SUBSET_SUM_CANDIDATE_CAP', 24),
            'time_budget_ms' => (int) env('BANKING_SUBSET_SUM_TIME_BUDGET_MS', 500),
            'tie_break' => 'oldest_first',
            'auto_apply_above_candidates' => false,
        ],

        'date_window_days' => 5,
    ],

    /*
    |---------------------------------------------------------------------------
    | Difference classification
    |---------------------------------------------------------------------------
    |
    | Differences are CLASSIFIED before they are written off. Each type has its
    | own posting rule and its own tolerance, and `withholding` resolves
    | against the withholding-tax path rather than being treated as a short
    | payment - an invoice total that does not equal the cash received is
    | CORRECT when withholding applies.
    |
    | Tolerances are tenant settings under a platform ceiling: "write off
    | anything under 50" is a policy decision, not a developer's constant.
    |
    */

    'differences' => [
        'types' => [
            'bank_charge',
            'fx_difference',
            'short_payment',
            'overpayment',
            'rounding',
            'withholding',
        ],

        'tolerance_ceiling' => env('TAX_WRITEOFF_TOLERANCE_CEILING', '50.0000'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Payment initiation
    |---------------------------------------------------------------------------
    |
    | PeakFlow NEVER MOVES MONEY. It produces an instruction file that a human
    | releases into the bank's own channel. The boundary is stated in the UI,
    | because a product that appears to send money carries a duty of care it
    | cannot discharge.
    |
    */

    'payments' => [
        'formats' => ['pain001' => '001.09', 'pain002' => '001.10', 'pain008' => '001.08'],

        // Validated BEFORE the file is produced, not after the bank rejects
        // it - a rejected file arrives hours later, by which point the window
        // is missed.
        'sepa' => [
            'remittance_max_length' => 140,
            'beneficiary_name_max_length' => 70,
            // SEPA permits a restricted Latin set, so an Arabic beneficiary
            // name must be transliterated - and the user is shown EXACTLY what
            // will be sent rather than having it silently mangled.
            'charset' => 'a-zA-Z0-9/\\-?:().,\'+ ',
            'show_transliteration_to_user' => true,
        ],

        'duplicate_detection' => [
            // Across ALL runs and ALL entities of the tenant, and including
            // already-released and already-reconciled payments - the duplicate
            // frequently arrives after the first was already approved.
            'window_days' => 30,
            'scope' => 'tenant',
            'include_reconciled' => true,
        ],
    ],

];
