<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Content Security Policy - PF-237
    |---------------------------------------------------------------------------
    |
    | A CSP is the only control in this file that stops an injected script
    | rather than merely making one less useful, and it is also the only one
    | that can break a working page. So it has two modes and the switch is
    | deliberate.
    |
    | `report_only` sends `Content-Security-Policy-Report-Only`: the browser
    | evaluates the policy, reports what it would have blocked, and blocks
    | nothing. That is the first deploy, and it is how the one inline script
    | somebody forgot is found by a report rather than by a customer looking
    | at a blank screen.
    |
    | `enforce` sends `Content-Security-Policy`. A policy that is permanently
    | report-only is a policy that has never stopped anything, so the report-
    | only phase is a phase and not a setting - PF-237 asks for both in that
    | order, and the release notes record when a tenant moved.
    |
    */

    'csp' => [

        'enforce' => (bool) env('CSP_ENFORCE', true),

        /*
        |-----------------------------------------------------------------------
        | Where violation reports go
        |-----------------------------------------------------------------------
        |
        | Empty means no `report-to`, which is the right default: a report
        | endpoint that does not exist produces a browser console full of
        | failed POSTs and no reports. A report-only phase with nowhere to
        | report to is a phase that learns nothing, so this is the value an
        | operator sets FIRST and `CSP_ENFORCE=false` second.
        |
        */
        'report_uri' => env('CSP_REPORT_URI', ''),
    ],

    /*
    |---------------------------------------------------------------------------
    | Strict-Transport-Security
    |---------------------------------------------------------------------------
    |
    | Two years, subdomains included, preload requested. The max-age is not a
    | taste: `preload` is only honoured at 31,536,000 seconds or more, so a
    | shorter one silently produces a header that the preload list refuses and
    | that nobody notices, because the rest of the header works.
    |
    | `includeSubDomains` is the part with a real cost. It commits every
    | subdomain of the application's host to HTTPS, including ones that do not
    | exist yet and including a tenant's own vanity host if one is ever served
    | from a subdomain. That is the correct commitment for a product whose
    | every page is behind a session cookie, and it is irreversible for the
    | duration of the max-age, so it is stated here rather than assumed.
    |
    | Sent only over HTTPS. A browser ignores HSTS on a plain HTTP response,
    | and sending it anyway would make a development machine look protected.
    |
    */

    'hsts' => [
        'max_age' => (int) env('HSTS_MAX_AGE', 63072000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('HSTS_PRELOAD', true),
    ],

    /*
    |---------------------------------------------------------------------------
    | Cross-origin isolation
    |---------------------------------------------------------------------------
    |
    | `same-origin` for COOP severs the `window.opener` relationship, so a page
    | this application opens cannot reach back into it and a page that opened
    | this one cannot either.
    |
    | COEP `require-corp` is the one that can break something: it refuses any
    | cross-origin subresource that has not opted in. This application embeds
    | none - the only external origin is the attachment download host, and an
    | attachment is a navigation with `Content-Disposition: attachment` rather
    | than an embed - so the default holds. It is configurable because the
    | first third-party script anybody adds will need `credentialless`, and
    | discovering that by editing a middleware under incident pressure is
    | worse than discovering it here.
    |
    */

    'coep' => env('COEP_POLICY', 'require-corp'),

];
