<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Payment schemes
    |---------------------------------------------------------------------------
    |
    | What a bank will accept, declared as data - PF-176.
    |
    | A payment file is rejected as a whole. One beneficiary name with a
    | character the scheme forbids, one remittance line two characters too
    | long, one execution date on a Sunday: the bank returns the batch and
    | nobody is paid. The rejection arrives hours later, by email, naming an
    | XPath.
    |
    | So every constraint a scheme imposes is declared here and checked BEFORE
    | the file is produced, against the run the person is looking at, with the
    | beneficiary named. That is the whole point of the task: the failure that
    | costs a day is the one discovered at the bank.
    |
    | ## Why the countries are a declaration and not vendored truth
    |
    | The SEPA scheme area is a list the EPC maintains and changes. PeakFlow
    | does not pretend to know it: the list below is what an operator has
    | DECLARED their bank reaches, and `bin/verify` cannot check it against a
    | registry because there is no registry file to check it against.
    |
    | That is the same decision `IbanRegistry` records about BBAN structure.
    | A half-remembered list would refuse real payments to real suppliers,
    | which stops them being paid; a list that is too wide costs a rejected
    | file, later, with a reason. The second is recoverable.
    |
    | An account's scheme is on the account (`bank_accounts.payment_scheme`),
    | because it is the bank that accepts the file, not the entity.
    |
    */

    'schemes' => [

        /*
         | SEPA Credit Transfer - the EPC rulebook.
         |
         | EUR only, by the rulebook. An account in another currency cannot
         | send SCT, and offering to try would produce a file the bank
         | rejects for a reason that reads as our bug.
         */
        'sepa_sct' => [
            'label' => 'SEPA Credit Transfer',
            'format' => 'pain.001.001.09',
            'namespace' => 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09',
            'currency' => 'EUR',

            /*
             | The restricted Latin set, from the EPC rulebook.
             |
             | Letters, digits, space, and `/ - ? : ( ) . , ' +`. Nothing
             | else - no diacritics, no ampersand, no quotation marks. A
             | name outside it is transliterated and SHOWN, never silently
             | mangled: a supplier whose name arrives as "Mller" on their
             | statement has been mangled by us, and they will ask.
             */
            'character_set' => 'epc_restricted_latin',

            'limits' => [
                'remittance' => 140,
                'name' => 70,
                'address_line' => 70,
                'message_id' => 35,
                'end_to_end_id' => 35,
                'instruction_id' => 35,
            ],

            /*
             | The per-transaction ceiling, as a decimal string.
             |
             | 999,999,999.99 is the rulebook's. A larger payment is not a
             | SEPA credit transfer and has to go another way, which is a
             | sentence somebody can act on rather than a rejected file.
             */
            'amount_ceiling' => '999999999.99',

            'execution' => [
                'business_days_only' => true,
                'closed_days' => ['saturday', 'sunday'],
                'max_days_ahead' => 90,
            ],

            /*
             | What the operator declares this bank reaches. Not a registry.
             |
             | Provenance: the EPC SEPA scheme country list as understood
             | when this file was written. It is a DECLARATION, which is why
             | it lives in config and not in `app/Support/Iso/Data` - the
             | vendored registries there each carry a published example that
             | checks them arithmetically, and there is no such check for
             | this.
             */
            'countries' => [
                'AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES',
                'FI', 'FR', 'GB', 'GI', 'GR', 'HR', 'HU', 'IE', 'IS', 'IT',
                'LI', 'LT', 'LU', 'LV', 'MC', 'MT', 'NL', 'NO', 'PL', 'PT',
                'RO', 'SE', 'SI', 'SK', 'SM', 'VA',
            ],

            'requires_iban' => true,
            'requires_bic' => false,
        ],

        /*
         | Saudi local transfers - SARIE, through the bank's own channel.
         |
         | A different scheme with different rules, declared rather than
         | assumed: SAR, IBAN-addressed, and a shorter remittance field than
         | SEPA allows. Named here so that a non-SEPA bank is not served a
         | SEPA file, which is what a product with one hard-coded format
         | does.
         */
        'sa_local' => [
            'label' => 'Saudi local transfer',
            'format' => 'pain.001.001.09',
            'namespace' => 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09',
            'currency' => 'SAR',

            /*
             | Arabic is permitted on the wire here, and still goes out as
             | Latin.
             |
             | The local scheme carries UTF-8, so nothing forces a
             | transliteration - but the beneficiary bank's own systems
             | frequently do not, and a name that arrives as question marks
             | is worse than one that arrives transliterated. So the same
             | restricted set applies, and what will be sent is shown.
             */
            'character_set' => 'epc_restricted_latin',

            'limits' => [
                'remittance' => 70,
                'name' => 70,
                'address_line' => 70,
                'message_id' => 35,
                'end_to_end_id' => 35,
                'instruction_id' => 35,
            ],

            'amount_ceiling' => null,

            /*
             | Friday and Saturday, not Saturday and Sunday.
             |
             | Declared rather than inferred from the country, because the
             | first scheme that spans both working weeks would make any
             | inference wrong - and the symptom is a file rejected for a
             | reason nobody reads as a calendar.
             */
            'execution' => [
                'business_days_only' => true,
                'closed_days' => ['friday', 'saturday'],
                'max_days_ahead' => 30,
            ],

            'countries' => ['SA'],

            'requires_iban' => true,
            'requires_bic' => false,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Direct debit schemes
    |---------------------------------------------------------------------------
    |
    | What a collection scheme demands - PF-177.
    |
    | `lead_days` is the pre-notification the scheme requires: the debtor
    | must be told this many days before money is taken. It is not paperwork.
    | A collection without it is one the debtor can reverse without giving a
    | reason, for `refund_months` afterwards - thirteen months under SDD Core,
    | which is a liability nobody budgets for.
    |
    | `presentation_days` is how far ahead the bank wants the file. Miss it
    | and the collection moves to the next cycle, which the customer notices
    | because the money leaves on a different day from the one they were
    | promised.
    |
    */

    'collection_schemes' => [

        'sepa_core' => [
            'label' => 'SEPA Direct Debit Core',
            'format' => 'pain.008.001.08',
            'namespace' => 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08',
            'currency' => 'EUR',
            'local_instrument' => 'CORE',
            'lead_days' => 14,
            'presentation_days' => ['FRST' => 1, 'RCUR' => 1, 'OOFF' => 1, 'FNAL' => 1],
            'refund_months' => 13,
            'character_set' => 'epc_restricted_latin',
            'limits' => ['remittance' => 140, 'name' => 70, 'reference' => 35],
        ],

        /*
         | B2B: no refund right, and the mandate is registered with the
         | debtor's bank in advance.
         |
         | Which is why the two cannot be mixed on one mandate - a Core
         | collection under a B2B mandate is refused by the debtor's bank
         | because they hold a registration that says otherwise.
         */
        'sepa_b2b' => [
            'label' => 'SEPA Direct Debit B2B',
            'format' => 'pain.008.001.08',
            'namespace' => 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.08',
            'currency' => 'EUR',
            'local_instrument' => 'B2B',
            'lead_days' => 14,
            'presentation_days' => ['FRST' => 1, 'RCUR' => 1, 'OOFF' => 1, 'FNAL' => 1],
            'refund_months' => 0,
            'character_set' => 'epc_restricted_latin',
            'limits' => ['remittance' => 140, 'name' => 70, 'reference' => 35],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | How far apart two payments can be and still look like one
    |---------------------------------------------------------------------------
    |
    | PF-180. Same party, same amount, within this many days - in this entity
    | or any other of the tenant - raises a warning somebody has to accept.
    |
    | Ten days, which is the shape of the failure rather than a guess about
    | invoice cycles: the duplicate that gets paid is the one where the same
    | invoice reached two people in the same fortnight. A window of two days
    | misses the one that arrives on a Friday and is paid the following
    | Tuesday; a window of sixty warns about every monthly retainer, and a
    | warning that fires every time is a warning nobody reads.
    |
    | It is configurable because the right number depends on how often a
    | business pays - a weekly run and a daily one are not the same - and
    | because a business that finds the warnings noisy will otherwise turn the
    | check off entirely.
    |
    */

    'duplicate_window_days' => 10,

    /*
    |---------------------------------------------------------------------------
    | The default scheme for an account that declares none
    |---------------------------------------------------------------------------
    |
    | Null, deliberately. An account whose scheme nobody declared cannot
    | produce a file, and the refusal says which schemes exist.
    |
    | A default would pick one, and the first time it picked wrong the file
    | would be built to the wrong rulebook, pass every check in this product
    | and be rejected by the bank - which is exactly the failure mode the
    | task exists to remove.
    |
    */

    'default_scheme' => null,

];
