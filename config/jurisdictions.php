<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Enabled jurisdiction profiles
    |---------------------------------------------------------------------------
    |
    | A profile bundles everything country-specific behind one interface:
    | permitted document types, numbering rules, tax rule set, rounding policy,
    | serializer, ordered validator chain, transport, sealing policy, archive
    | policy and QR policy.
    |
    | A profile that is not enabled here CANNOT be selected by a tenant. That
    | is deliberate: enabling a jurisdiction means accepting responsibility for
    | its clearance credentials and its archive retention, which is an operator
    | decision and not a tenant one.
    |
    | An entity declares the profiles it operates under, and a document's
    | profile is FROZEN ON IT at issue - so a later profile change cannot alter
    | what a past document claims to conform to.
    |
    | Adding a country is a new profile class plus fixtures, and never a change
    | to the Sales module. See ADR-0007.
    |
    */

    'enabled' => [
        'generic' => true,   // EN 16931 + UBL 2.1. The baseline every other
                             // profile specializes, and the one that works end
                             // to end with no third-party credentials.
        'peppol' => false,   // Needs an access point and a participant id.
        'zatca' => false,    // Needs onboarding and a production CSID.
        'facturx' => false,  // Needs the PDF/A-3 pipeline (PF-102).
        'eta' => false,      // Egypt. Needs ETA credentials.
        'jofotara' => false, // Jordan. Three-decimal JOD.
    ],

    /*
    |---------------------------------------------------------------------------
    | Default profile for a new entity
    |---------------------------------------------------------------------------
    */

    'default' => 'generic',

    /*
    |---------------------------------------------------------------------------
    | Rounding, per profile - PF-006
    |---------------------------------------------------------------------------
    |
    | The platform default is half-up, which is what `config('money')` says and
    | what almost every tax authority specifies. A profile declares otherwise
    | only where a jurisdiction actually mandates it: banker's rounding exists
    | in this list because a jurisdiction may require it, not because it is a
    | better default.
    |
    | Declared here rather than in code so that adding a country is a profile
    | plus fixtures, as ADR-0007 says, and never a change to the money layer.
    |
    | A profile not named here gets the platform default - which is the right
    | behaviour and is why the fallback is explicit rather than an exception:
    | refusing to round because a profile forgot to declare a mode would make
    | a new jurisdiction unusable until somebody noticed.
    |
    */

    /*
    |---------------------------------------------------------------------------
    | Tax point basis (PF-077)
    |---------------------------------------------------------------------------
    |
    | Which of a document's dates fixes its TAX POINT - EN 16931 BT-7 - and so
    | which return period the supply belongs to. Not the invoice date by
    | definition: a document issued on the 1st for a supply on the 30th of the
    | previous month belongs to the earlier return in many jurisdictions, and a
    | system that files by invoice date files it a month late.
    |
    | The five shapes the engine implements:
    |
    |   invoice_date                     the document's own date
    |   delivery_date                    the date of the supply
    |   payment_date                     the date the money arrived
    |   earlier_of_invoice_and_delivery  whichever came first
    |   earlier_of_invoice_and_payment   the prepayment rule - money received
    |                                    before invoicing fixes the tax point
    |                                    at receipt
    |
    | A profile naming a shape the engine does not implement THROWS rather than
    | falling back, because guessing would apply a rule nobody wrote. A profile
    | that is absent falls back to the default profile's basis, because the
    | alternative is a document that cannot be issued over a misspelled key.
    |
    | `generic` is the invoice date because EN 16931's own default BT-8 code is
    | `3`. The others are the invoice date too, pending the per-jurisdiction
    | source each one needs - a basis asserted without a citation is the same
    | problem as a rate without one, and PF-085 requires the citation.
    |
    */

    'tax_point' => [
        'generic' => 'invoice_date',
        'peppol' => 'invoice_date',
        'zatca' => 'invoice_date',
        'facturx' => 'invoice_date',
        'eta' => 'invoice_date',
        'jofotara' => 'invoice_date',
    ],

    /*
    |---------------------------------------------------------------------------
    | The reverse-charge note - PF-079
    |---------------------------------------------------------------------------
    |
    | A reverse-charge invoice carries no tax amount, so the note is the only
    | thing on the document that says why - and it is a legal requirement, not
    | a courtesy. Directive 2006/112/EC Art. 226(11a) requires the mention;
    | EN 16931 carries it as BT-120 with the code in BT-121.
    |
    | The wording is per jurisdiction because the wording is prescribed. A
    | French invoice says "Autoliquidation"; a Saudi one has to say it in
    | Arabic. Translating a generic English phrase at render time is how an
    | invoice ends up carrying a note that satisfies nobody's rules.
    |
    | Both languages are stored for every profile. PeakFlow serves Arabic and
    | English, an invoice is read by whoever receives it, and a note present in
    | one language only is a note missing from half the documents.
    |
    | Every enabled profile must declare one: a missing note refuses rather
    | than falling back, because the fallback prints an invoice that is missing
    | a mandatory legal mention, and nothing on it says so.
    */
    'reverse_charge_note' => [
        'generic' => [
            'en' => 'Reverse charge: VAT to be accounted for by the recipient.',
            'ar' => 'التحصيل العكسي: الضريبة مستحقة على المستلم.',
            'source' => 'EN 16931 BT-120 with BT-121; Council Directive 2006/112/EC Art. 226(11a)',
        ],
        'peppol' => [
            'en' => 'Reverse charge: VAT to be accounted for by the recipient.',
            'ar' => 'التحصيل العكسي: الضريبة مستحقة على المستلم.',
            'source' => 'EN 16931 BT-120 with BT-121; Council Directive 2006/112/EC Art. 226(11a)',
        ],
        'zatca' => [
            'en' => 'Reverse charge: the customer accounts for the VAT under Article 47.',
            'ar' => 'التحصيل العكسي: المستلم هو المكلف باحتساب ضريبة القيمة المضافة وفقاً للمادة السابعة والأربعين.',
            'source' => 'KSA VAT Implementing Regulations Art. 47 (reverse charge) with Art. 53 (invoice contents)',
        ],
        'facturx' => [
            'en' => 'Reverse charge - VAT self-assessed by the customer.',
            'ar' => 'التحصيل العكسي: الضريبة يحتسبها المستلم.',
            'source' => 'Art. 283-2 and Art. 242 nonies A, 13° CGI; Council Directive 2006/112/EC Art. 226(11a)',
        ],
        'eta' => [
            'en' => 'Reverse charge: VAT due from the recipient.',
            'ar' => 'التحصيل العكسي: الضريبة مستحقة على المستلم.',
            // The instrument is certain; the article number was not verifiable
            // in this environment, and is marked rather than guessed. An
            // invented article is worse than a general citation: it reads as
            // checked and is the first thing an auditor looks up.
            'source' => 'Egypt VAT Law 67/2016 (services imported from abroad) - article unverified here',
        ],
        'jofotara' => [
            'en' => 'Reverse charge: GST due from the recipient.',
            'ar' => 'التحصيل العكسي: الضريبة العامة على المبيعات مستحقة على المستلم.',
            'source' => 'Jordan General Sales Tax Law 6/1994, as amended - article unverified here',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | The rounding policy - PF-081
    |---------------------------------------------------------------------------
    |
    | Three things, and a document needs all three before two systems agree on
    | its tax: the mode, the scale and the basis.
    |
    | `mode` is half away from zero by default, because EN 16931 and most VAT
    | regimes specify it. IEEE 754 and most language defaults use half-even;
    | the difference shows on roughly one document in a hundred and compounds
    | across a return.
    |
    | `basis` is where tax is rounded - once per rate group, or on each line.
    | EN 16931 `BR-CO-14` settles it for a conformant invoice: the category's
    | tax is its taxable amount times its rate, computed once. Three lines of
    | 10.03 at 15% are 4.51 per group and 4.50 per line, and a system that sums
    | rounded line tax produces a document the recipient's validator rejects.
    |
    | The SCALE is not here, deliberately. It comes from the currency: JOD has
    | three decimals because ISO 4217 says so, not because of Jordan's VAT
    | rules. A regime that mandates rounding a total to something other than
    | its currency's exponent - to the nearest five fils, or Switzerland's
    | 0.05 - is a real thing and is not shipped; it would be a third key here
    | rather than a branch anywhere.
    |
    | A profile may still declare a bare string, which is read as the mode
    | alone. A misspelled key INSIDE the array is not accepted: the basis is
    | read by name and an unknown one throws, because a basis somebody wrote
    | and spelled wrong is a decision that was made and then discarded, and the
    | discarding shows up as a cent on a return that cannot be traced.
    */
    /*
    |---------------------------------------------------------------------------
    | How a filed return is corrected - PF-087
    |---------------------------------------------------------------------------
    |
    |   next_period      the correction is reported in the next open return,
    |                    with a schedule saying what it corrects
    |   amended_return   the original return is re-submitted, amended, with
    |                    its own reference
    |
    | Both exist in the world and the difference is procedural rather than
    | arithmetical: the figures are the same, and what differs is which
    | submission carries them. A system that only implements the first files a
    | correction the authority will not match against anything; one that only
    | implements the second re-opens returns in regimes that do not allow it.
    |
    | So it is declared per profile, and the adjustment flow refuses rather
    | than guessing where a regime wants an amendment. The thresholds some
    | regimes set - correct in the next return below an amount, amend above it
    | - are not modelled: that is a figure per regime per year, and a wrong
    | one picks the wrong procedure silently.
    */
    'return_correction' => [
        'generic' => 'next_period',
        'peppol' => 'next_period',
        'zatca' => 'next_period',
        'facturx' => 'amended_return',
        'eta' => 'amended_return',
        'jofotara' => 'next_period',
    ],

    /*
    |---------------------------------------------------------------------------
    | How an issued document is corrected - PF-098
    |---------------------------------------------------------------------------
    |
    |   credit_note        the invoice stands and a credit note (UNCL 1001
    |                      381) reduces it, followed by a new invoice if one
    |                      is needed
    |   corrected_invoice  a replacement document (384) carries the corrected
    |                      figures and references what it replaces
    |
    | Which one is legal is a jurisdiction's decision and not a user's. Both
    | mechanisms produce the same balances; what differs is which document the
    | authority expects to receive and match. Issuing the wrong one is not a
    | preference somebody can be talked out of afterwards - it is a document
    | already filed under a procedure the regime does not recognise.
    |
    | `both` exists because some regimes genuinely accept either, and a system
    | that forced a choice there would be inventing a rule. Where only one is
    | listed, `Corrections` refuses the other and names the regime.
    |
    | The sales screens read this: a document type the profile does not permit
    | is not offered at all, which is the only way a rule like this survives
    | contact with somebody in a hurry.
    */
    'document_correction' => [
        'generic' => 'both',
        'peppol' => 'both',

        // ZATCA's e-invoicing rules recognise both the credit note and the
        // debit note, and a corrected invoice as a separate document type.
        'zatca' => 'both',

        // A `facture rectificative` replaces: the French and Italian
        // procedures expect the replacement document, referenced to what it
        // replaces.
        'facturx' => 'corrected_invoice',

        // Egypt's ETA accepts a credit or debit note against a submitted
        // document, matched by its UUID.
        'eta' => 'credit_note',

        'jofotara' => 'credit_note',
    ],

    'rounding' => [
        'generic' => [
            'mode' => 'half_up',
            'basis' => 'per_rate_group',
            'source' => 'EN 16931 BR-CO-14 (VAT category tax amount), with half away from zero',
        ],
        'peppol' => [
            'mode' => 'half_up',
            'basis' => 'per_rate_group',
            'source' => 'EN 16931 BR-CO-14, as profiled by Peppol BIS Billing 3.0',
        ],
        'zatca' => [
            'mode' => 'half_up',
            'basis' => 'per_rate_group',
            'source' => 'KSA E-Invoicing Resolution, which adopts the EN 16931 data model and BR-CO-14',
        ],
        'facturx' => [
            'mode' => 'half_up',
            'basis' => 'per_rate_group',
            'source' => 'EN 16931 BR-CO-14, as profiled by Factur-X 1.0 (EN 16931 profile)',
        ],
        'eta' => [
            'mode' => 'half_up',
            'basis' => 'per_rate_group',
            'source' => 'Egypt ETA e-invoicing specification - basis unverified here; set to the EN 16931 baseline it is aligned to',
        ],
        'jofotara' => [
            'mode' => 'half_up',
            'basis' => 'per_rate_group',
            'source' => 'Jordan JoFotara specification - basis unverified here; set to the EN 16931 baseline it is aligned to',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Validation artefacts
    |---------------------------------------------------------------------------
    |
    | Schematron is compiled to XSLT 2.0 and COMMITTED, with the source version
    | recorded. Vendored and version-pinned rather than fetched at runtime: a
    | government updating its Schematron on a Tuesday must not silently change
    | what PeakFlow accepts on Wednesday.
    |
    | An update is a pull request with a corpus diff, so a newly failing rule
    | is visible before it reaches a customer.
    |
    */

    'validation' => [
        'artefact_path' => base_path('schematron'),
        'fetch_at_runtime' => false,

        // The four ordered stages. Each catches a different class of error,
        // and running them out of order produces unhelpful messages.
        'stages' => ['internal', 'xsd', 'en16931', 'profile'],
    ],

];
