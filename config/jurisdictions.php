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
