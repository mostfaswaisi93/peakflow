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

    'rounding' => [
        'generic' => 'half_up',
        'peppol' => 'half_up',
        'zatca' => 'half_up',
        'facturx' => 'half_up',
        'eta' => 'half_up',
        'jofotara' => 'half_up',
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
