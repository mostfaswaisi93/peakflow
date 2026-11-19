<?php

declare(strict_types=1);

namespace App\Support\Ui;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Which density to render for this request - PF-203.
 *
 * In order: the signed-in user's stored choice, then the `density` cookie,
 * then the default. The same shape as {@see ThemePreference}, and for the same
 * reasons - the cookie is what lets a page rendered before anybody is signed
 * in open at the density this browser was last used at, rather than reflowing
 * the moment the session is established.
 *
 * ## Why the server and not a script
 *
 * PF-203 asks for the density to be applied BEFORE the first paint. A script
 * that sets `data-density` on load is a table that resizes while somebody is
 * already reading it, and on a slow page it is a visible jump in the middle
 * of a column of figures. So the attribute is written onto `<html>` by the
 * layout, from this, and no script is involved at all.
 *
 * ## Two entry points, because one page must not touch the user
 *
 * `layouts.plain` renders the pages that explain why a workspace cannot be
 * reached, including the one shown when the TENANT could not be resolved.
 * Asking the guard for the user there queries a tenant database that does not
 * exist, so that layout reads the cookie only (`forGuest`).
 */
final class DensityPreference
{
    /** Long enough that nobody meets the default again on the same browser. */
    public const string COOKIE = 'density';

    public const int COOKIE_MINUTES = 60 * 24 * 365;

    public static function forGuest(Request $request): Density
    {
        return Density::fromPreference($request->cookie(self::COOKIE));
    }

    public static function forUser(Request $request, ?Authenticatable $user): Density
    {
        $stored = $user !== null ? data_get($user, 'density') : null;

        return \is_string($stored) ? Density::fromPreference($stored) : self::forGuest($request);
    }
}
