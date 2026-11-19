<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Http\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response carries - PF-237.
 *
 * ## Why this is global and prepended, not in the web group
 *
 * The responses that most need these headers are the ones that come back from
 * a failure: a 404 from an unmatched route, a 500 rendered by the exception
 * handler, the workspace-unavailable page that exists precisely because the
 * tenant could not be resolved. None of those passes through the `web` group,
 * because none of them gets as far as a route.
 *
 * `prepend` puts this first on the way in, which makes it last on the way out
 * - so the headers are applied to whatever response was finally produced,
 * including one an exception handler built after this middleware had already
 * returned its closure.
 *
 * ## The headers are not set if the response already has them
 *
 * {@see \App\Http\Controllers\AttachmentDownloadController} serves a tenant's
 * uploaded bytes and sets a deliberately different and much tighter policy:
 * `default-src 'none'; sandbox`, with `Content-Disposition: attachment`. The
 * application's own policy would be a loosening there, and overwriting a
 * header a controller set on purpose is how a control becomes decorative. So
 * every header below is written only when the response has not already stated
 * its own answer.
 *
 * ## What each one is for
 *
 * - **CSP** stops an injected script running at all. Everything else on this
 *   list makes a successful attack less useful; this is the one that prevents
 *   it, and it is the one with a report-only phase because it is also the one
 *   that can break a page.
 * - **HSTS** removes the plain-HTTP request that a session cookie could be
 *   stripped from. Sent only over HTTPS, because a browser ignores it
 *   otherwise and sending it anyway would make a development machine look
 *   protected.
 * - **`X-Content-Type-Options: nosniff`** stops a browser deciding that a
 *   `text/plain` response is HTML. This application stores files a stranger
 *   uploaded, so content-type sniffing is not a hypothetical.
 * - **`Referrer-Policy: no-referrer`** keeps document ids and tenant slugs
 *   out of the `Referer` of every outbound link. A URL here names a company
 *   and a document number.
 * - **COOP/COEP** sever the window relationship and refuse un-opted-in
 *   cross-origin subresources.
 * - **`Permissions-Policy`** turns off the device APIs. The application uses
 *   none of them except WebAuthn, and a policy that lists what is denied
 *   rather than what is allowed is one that stops denying things as the
 *   platform grows.
 */
final class SecurityHeaders
{
    /**
     * Features denied outright, with the one exception named.
     *
     * `publickey-credentials-get=(self)` is the exception: passkeys are an
     * authenticator type here, and `()` would turn off the second factor for
     * everybody who registered one. That failure is worth spelling out,
     * because it would present as "my passkey stopped working" on one
     * browser release and be attributed to anything but a header.
     *
     * Everything else is `()` - allowed in no origin at all, this one
     * included. A camera this application never asks for is a camera a
     * cross-site script cannot ask for either.
     *
     * @var list<string>
     */
    private const PERMISSIONS = [
        'accelerometer=()',
        'ambient-light-sensor=()',
        'autoplay=()',
        'battery=()',
        'bluetooth=()',
        'camera=()',
        'display-capture=()',
        'encrypted-media=()',
        'fullscreen=()',
        'geolocation=()',
        'gyroscope=()',
        'hid=()',
        'idle-detection=()',
        'local-fonts=()',
        'magnetometer=()',
        'microphone=()',
        'midi=()',
        'payment=()',
        'picture-in-picture=()',
        'publickey-credentials-get=(self)',
        'screen-wake-lock=()',
        'serial=()',
        'usb=()',
        'xr-spatial-tracking=()',
    ];

    public function __construct(private readonly ContentSecurityPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = [
            $this->policy->headerName() => $this->policy->header($request->isSecure()),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            /*
             | Kept alongside `frame-ancestors 'none'`.
             |
             | Browsers consult `frame-ancestors` and ignore this one when
             | both are present. It is sent for the proxies, gateways and
             | compliance scanners that only know the old header, and the two
             | agree by construction: a `DENY` here beside a permissive
             | `frame-ancestors` would be a contradiction nobody could
             | resolve from the outside.
             */
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Embedder-Policy' => (string) config('security.coep', 'require-corp'),
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Permissions-Policy' => implode(', ', self::PERMISSIONS),
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = $this->hsts();
        }

        foreach ($headers as $name => $value) {
            if ($response->headers->has($name)) {
                continue;
            }

            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * `Strict-Transport-Security`, with preload only where it is honoured.
     *
     * The preload list refuses a max-age under a year, and a header asking
     * for preload with a shorter one is rejected by the list while working
     * perfectly in every browser - so nobody finds out. `preload` is
     * therefore dropped rather than sent wrong, and it also implies
     * `includeSubDomains`, which the list requires too.
     */
    private function hsts(): string
    {
        $maxAge = max(0, (int) config('security.hsts.max_age', 63072000));
        $subdomains = (bool) config('security.hsts.include_subdomains', true);
        $preload = (bool) config('security.hsts.preload', true) && $subdomains && $maxAge >= 31536000;

        return 'max-age='.$maxAge
            .($subdomains ? '; includeSubDomains' : '')
            .($preload ? '; preload' : '');
    }
}
