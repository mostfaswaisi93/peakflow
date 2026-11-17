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
    | MT940 `:86:` layouts
    |---------------------------------------------------------------------------
    |
    | `:86:` is semi-structured free text and the standard does not say what
    | goes in it, so its layout is BANK-SPECIFIC. The numbered sub-fields
    | mean different things at different banks: 20-29 for remittance and
    | 32-33 for the name is the common case and not the only one.
    |
    | A profile that does not fit falls back to keeping the text verbatim,
    | which is always better than dropping it: `:86:` is the only place a
    | reference appears in this format.
    |
    */

    'mt940' => [
        'profiles' => [
            'generic' => [
                'remittance' => ['20', '21', '22', '23', '24', '25', '26', '27', '28', '29'],
                'name' => ['32', '33'],
                'account' => ['31'],
            ],

            // Several German banks put the counterparty's sort code in 30
            // and its account in 31, and start remittance at 21 because 20
            // carries a posting text.
            'de_numbered' => [
                'remittance' => ['21', '22', '23', '24', '25', '26', '27', '28', '29'],
                'name' => ['32', '33'],
                'account' => ['31'],
            ],
        ],
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
    | Transaction codes, and the strategy each one picks
    |---------------------------------------------------------------------------
    |
    | ISO 20022 External Code Sets, as `domain/family/sub-family`. The code
    | the bank sent is the cheapest signal there is about what a line IS, and
    | using it means the engine does not try to match a bank charge against
    | open invoices.
    |
    | This is DATA, not code, for one reason: a bank using an unusual code is
    | configuration. The alternative is a release every time somebody's bank
    | sends `PMNT/MCOP` where its neighbour sends `PMNT/MDOP`.
    |
    | Most specific wins: `PMNT/RCDT/ESCT` beats `PMNT/RCDT`, which beats
    | `PMNT`. An unmapped code falls through to the generic passes rather
    | than failing - a code nobody has seen is a transaction like any other,
    | and refusing it would make an unfamiliar bank unusable.
    |
    */

    'routing' => [
        'strategies' => [
            // Outgoing credit transfers: ours, so they match a payment run.
            'PMNT/ICDT' => 'payment_run',
            'PMNT/ICDT/ESCT' => 'payment_run',
            'PMNT/ICDT/XBCT' => 'payment_run',
            'PMNT/ICDT/DMCT' => 'payment_run',

            // Incoming: a customer paying us, so receivables.
            'PMNT/RCDT' => 'receivables',
            'PMNT/RCDT/ESCT' => 'receivables',
            'PMNT/RCDT/XBCT' => 'receivables',

            // A returned incoming payment is not a new receipt; it reverses
            // one, and matching it against open invoices would apply a
            // bounced payment to a different invoice.
            'PMNT/RCDT/RRTN' => 'reversal',
            'PMNT/ICDT/RRTN' => 'reversal',

            // Direct debits: ours collecting, so receivables; theirs
            // collecting from us, so payables.
            'PMNT/RDDT' => 'receivables',
            'PMNT/IDDT' => 'payables',

            // Fees, charges and interest have no document and never will.
            // Sending them to the document passes wastes the passes and
            // produces the occasional confident wrong match on amount.
            'PMNT/MDOP' => 'rule_engine',
            'PMNT/MDOP/CHRG' => 'rule_engine',
            'PMNT/MDOP/INTR' => 'rule_engine',
            'PMNT/MCOP' => 'rule_engine',
            'ACMT' => 'rule_engine',

            // Cheques and cards.
            'PMNT/ICHQ' => 'payables',
            'PMNT/RCHQ' => 'receivables',
            'PMNT/CCRD' => 'rule_engine',
            'PMNT/DCRD' => 'rule_engine',

            // Securities and FX are not document traffic.
            'SECU' => 'rule_engine',
            'FORX' => 'rule_engine',
        ],

        // What a line with no code, or a code nobody has mapped, gets.
        'fallback' => 'generic',

        /*
         | Which passes each strategy runs, in order.
         |
         | `generic` is every document pass, which is what an unmapped code
         | falls through to: slower and no less correct.
         */
        'passes' => [
            'payment_run' => ['exact_reference', 'payment_run', 'rule_engine'],
            'receivables' => ['exact_reference', 'structured_remittance', 'amount_party_window', 'subset_sum', 'rule_engine', 'fuzzy_party'],
            'payables' => ['exact_reference', 'structured_remittance', 'amount_party_window', 'rule_engine'],
            'reversal' => ['exact_reference', 'reversal', 'rule_engine'],
            'rule_engine' => ['rule_engine'],
            'generic' => ['exact_reference', 'payment_run', 'structured_remittance', 'amount_party_window', 'subset_sum', 'rule_engine', 'fuzzy_party'],
        ],
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

        /*
         | How far a payment-run debit may sit from the run's pay date.
         |
         | Wider than the five days the document passes allow, because a
         | run's pay date is a request rather than an observation: the
         | bank executes on the next business day, a cross-border transfer
         | takes longer, and a file sent on a Thursday before a holiday
         | lands the following week. Narrowing it to five would leave
         | exactly those runs to be matched by hand.
         */
        'payment_run_window_days' => 10,

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

        /*
         | The passes that are not worth running once something certain has
         | been found.
         |
         | Not a shortcut for its own sake: a subset-sum search against four
         | hundred open invoices is bounded precisely because it is
         | expensive, and running it after pass 1 already read the invoice
         | number off the remittance field spends that budget on a worse
         | answer to a question already answered.
         |
         | Skipping is RECORDED on the result, never silent - "no fuzzy
         | proposal" and "fuzzy was not run" are different facts, and only
         | the second has an explanation.
         */
        'expensive_passes' => ['subset_sum', 'fuzzy_party'],

        // Two candidates within this of each other are a choice, not a
        // ranking. Surfaced rather than resolved - PF-157.
        'ambiguity_margin' => 0.05,

        // Subset-sum is bounded, because the naive implementation hangs on a
        // customer with 400 open items.
        'subset_sum' => [
            'candidate_cap' => (int) env('BANKING_SUBSET_SUM_CANDIDATE_CAP', 24),
            'time_budget_ms' => (int) env('BANKING_SUBSET_SUM_TIME_BUDGET_MS', 500),
            'tie_break' => 'oldest_first',

            // A combination of eleven invoices adding to a round figure is
            // arithmetic, not evidence, and searching for one costs more
            // than it is worth: the combinations at size 6 over 24
            // documents are already 134,596.
            'max_combination_size' => (int) env('BANKING_SUBSET_SUM_MAX_SIZE', 6),

            // Above this many documents the combination is proposed and a
            // human confirms, whatever it scores.
            'auto_apply_max_size' => (int) env('BANKING_SUBSET_SUM_AUTO_APPLY_MAX_SIZE', 3),
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
