<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * The policy, and the one nonce it is built around - PF-237.
 *
 * ## Why a request-scoped object and not a helper function
 *
 * The nonce in the header and the nonce on the `<script>` tag must be the
 * same string, and the two are produced in different places: the view renders
 * first and the middleware sets the header afterwards. A helper that
 * generated a value would hand out a different one each time it was called,
 * and the symptom - the inline theme resolver silently not running, so the
 * first paint is in the wrong theme - is one nobody would connect to a
 * security header.
 *
 * So the nonce is generated once, lazily, and held. It is bound
 * `scoped`, which in a queue worker or an Octane process means it is
 * discarded between requests: a nonce reused across two responses is a nonce
 * an attacker can read from the first and use in the second, which is the
 * same as having no nonce at all.
 *
 * ## No `unsafe-inline`, and no `unsafe-eval`
 *
 * `script-src` is a nonce and nothing else. There is no `'self'` either:
 * `'self'` would admit any script served from the application's own origin,
 * and this application serves tenant-uploaded files - from a separate origin
 * precisely so that they are not `'self'`, but a policy that does not need
 * `'self'` should not ask for it.
 *
 * `'unsafe-eval'` appears nowhere. Nothing here compiles a string: the only
 * JavaScript is the first-paint theme resolver and whatever Vite builds.
 *
 * ## The one exception, named rather than hidden
 *
 * `style-src-attr 'unsafe-inline'`.
 *
 * Six lines across two templates carry a computed `style` attribute - a grid
 * column's width, a progress bar's share - and an attribute cannot carry a
 * nonce, because a nonce is an attribute of an element and `style=""` is not
 * an element. The alternatives were to emit a nonced `<style>` block with a
 * generated class per value, which puts a stylesheet in the markup of a
 * reusable grid component, or to accept this.
 *
 * It is accepted, and confined: `style-src-attr` governs style attributes
 * ONLY. `script-src-attr` stays `'none'`, so `onclick=` and every other event
 * handler attribute is still refused, and `style-src` itself - which governs
 * `<style>` elements and stylesheets, the things that can actually be made to
 * load something - keeps the nonce. What is given up is protection against an
 * attacker who can inject an attribute but not an element, which is a narrow
 * shape of injection, and `bin/verify hardening` asserts that
 * `style-src-attr` is the only directive in the whole policy carrying the
 * keyword.
 */
final class ContentSecurityPolicy
{
    private ?string $nonce = null;

    /**
     * The per-request nonce, base64 of 16 random bytes.
     *
     * Sixteen bytes rather than eight: the CSP specification asks for at
     * least 128 bits of entropy, and the reason is not theoretical. A nonce
     * an attacker can guess within the lifetime of one page is a nonce that
     * turns a reflected-input bug back into a working script injection.
     */
    public function nonce(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * `nonce="..."` for a Blade template, escaped.
     *
     * Base64 holds `+`, `/` and `=` and nothing that needs escaping in an
     * attribute, so the escaping is belt and braces - the kind that costs
     * nothing and survives somebody changing the encoding to something with
     * a quote in it.
     */
    public function nonceAttribute(): string
    {
        return sprintf('nonce="%s"', htmlspecialchars($this->nonce(), \ENT_QUOTES, 'UTF-8'));
    }

    /** The header name, which is the whole difference between the two phases. */
    public function headerName(): string
    {
        return (bool) config('security.csp.enforce', true)
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';
    }

    /**
     * The policy as a header value.
     *
     * Built from an ordered map so the directives come out in a stable order:
     * a header that reorders between requests is one no diff and no cache key
     * can be compared.
     *
     * `$secure` is whether the response is going out over HTTPS; see
     * `upgrade-insecure-requests` below for the one directive it decides.
     */
    public function header(bool $secure = true): string
    {
        $nonce = sprintf("'nonce-%s'", $this->nonce());

        $directives = [
            /*
             | `'none'` as the floor, so every directive below is an opening
             | rather than a narrowing. A missing directive falls back to
             | `default-src`, and a `default-src` of `'self'` would mean the
             | directives nobody thought of silently permit the origin.
             */
            'default-src' => "'none'",

            'script-src' => $nonce,

            /*
             | `'self'` for the compiled stylesheet Vite emits, and the nonce
             | for the error page's inline `<style>` - which cannot use the
             | stylesheet, because the failures that page explains include the
             | build manifest being unreadable.
             */
            'style-src' => "'self' ".$nonce,

            'style-src-attr' => "'unsafe-inline'",

            'script-src-attr' => "'none'",

            /*
             | `data:` for images, which is what an inlined SVG icon and a
             | generated QR code are. It is safe for images and would not be
             | for scripts: `data:` in `script-src` is `'unsafe-inline'` with
             | extra steps.
             */
            'img-src' => "'self' data:",

            'font-src' => "'self'",

            /*
             | Same-origin XHR only. There is no third-party analytics, no
             | error reporter posting from the browser and no CDN, so a wider
             | `connect-src` would only describe requests an injected script
             | would like to make.
             */
            'connect-src' => "'self'",

            'form-action' => "'self'",

            'base-uri' => "'none'",

            /*
             | `frame-ancestors 'none'` is the modern `X-Frame-Options: DENY`
             | and the one browsers actually consult. The old header is sent
             | alongside it for the proxies and scanners that only know that
             | one, and the two must agree - a `DENY` beside a permissive
             | `frame-ancestors` is a contradiction a reviewer cannot resolve.
             */
            'frame-ancestors' => "'none'",

            'frame-src' => "'none'",

            'object-src' => "'none'",

            'worker-src' => "'self'",

            'manifest-src' => "'self'",
        ];

        /*
         | Upgrade, not block - and only over HTTPS.
         |
         | `block-all-mixed-content` is deprecated and
         | `upgrade-insecure-requests` is what remains: a stylesheet reference
         | somebody wrote as `http://` is fetched over HTTPS instead of failing.
         |
         | It is NOT inert on a page served over plain HTTP. Browsers upgrade
         | every insecure request the page makes, its own same-origin
         | stylesheet, images and form posts included, whatever the page's own
         | scheme. A development machine without working HTTPS then renders
         | the sign-in page unstyled, and signing in posts to an `https://`
         | URL that does not answer. On a plain-HTTP page there is no mixed
         | content to protect, so the directive is sent where HSTS is: only
         | over HTTPS.
         */
        if ($secure) {
            $directives['upgrade-insecure-requests'] = '';
        }

        $reportUri = trim((string) config('security.csp.report_uri', ''));

        if ($reportUri !== '') {
            $directives['report-uri'] = $reportUri;
        }

        $parts = [];

        foreach ($directives as $directive => $value) {
            $parts[] = $value === '' ? $directive : $directive.' '.$value;
        }

        return implode('; ', $parts);
    }
}
