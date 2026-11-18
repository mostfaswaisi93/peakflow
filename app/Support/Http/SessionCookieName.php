<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Support\Str;

/**
 * The session cookie's name, and why it carries a prefix - PF-237.
 *
 * ## What `__Host-` actually buys
 *
 * It is not decoration and it is not a convention. A browser refuses to
 * accept a cookie whose name begins `__Host-` unless all three of these hold:
 * it is `Secure`, its path is exactly `/`, and it carries **no `Domain`
 * attribute**. The third is the one no other setting can express, and it is
 * the one worth having.
 *
 * Without it, a cookie set by any sibling host can be scoped to the parent
 * domain and will be sent here. `status.peakflow.example`, a marketing site on
 * the same domain, a subdomain somebody parked years ago - any of them can set
 * `peakflow-session=<value>; Domain=.peakflow.example`, and the `Cookie`
 * header the application receives carries no indication of which host set
 * which value. The application cannot tell the attacker's cookie from the real
 * one. That is session fixation through a host nobody considered part of the
 * product, and it is unaffected by `Secure`, `HttpOnly` and `SameSite`, all of
 * which this application already sets.
 *
 * ## The prefix follows `Secure`, because a browser requires it to
 *
 * A `__Host-` cookie that is not `Secure` is simply dropped - silently, with
 * nothing in any log, and the symptom is a login form that reloads for ever.
 * A development machine serves plain HTTP, where a `Secure` cookie cannot be
 * sent at all, so the name is derived from the same setting the browser
 * consults rather than hard-coded: insecure cookie, no prefix, development
 * works; secure cookie, prefix, and the sibling-host hole is closed.
 *
 * Deriving it is what makes the two impossible to get out of step. A name
 * written out in `.env` is one somebody copies from the development file into
 * production, where it is the unprefixed one.
 *
 * ## Why a class and not an expression in config/session.php
 *
 * So that both branches can be proved. A ternary inside a config file is
 * evaluated once, at boot, from whatever that machine's environment holds -
 * there is no way to ask it what it would have produced for the other
 * setting, so there is no way to check that production gets the prefix
 * without deploying to production.
 */
final class SessionCookieName
{
    public const HOST_PREFIX = '__Host-';

    /**
     * The cookie name for a deployment whose session cookie is or is not
     * `Secure`.
     *
     * @param  bool  $secure  `config('session.secure')`, which is `null` when
     *     unset - and `null` means "not secure", which is why the caller casts
     *     rather than passing the raw value
     */
    public static function for(bool $secure, string $applicationName): string
    {
        $base = Str::slug($applicationName === '' ? 'laravel' : $applicationName).'-session';

        return $secure ? self::HOST_PREFIX.$base : $base;
    }
}
