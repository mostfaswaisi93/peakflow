<?php

declare(strict_types=1);

namespace App\Support\Ui;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Which theme to render for this request - UX-004.
 *
 * In order: the signed-in user's stored choice, then the `theme` cookie, then
 * Automatic. The cookie is what lets the SIGN-IN page - where nobody is signed
 * in yet - open in the theme the person chose last time, instead of flashing
 * light at someone who asked for high contrast.
 *
 * ## Two entry points, because one page must not touch the user
 *
 * `layouts.plain` renders the pages that explain why a workspace cannot be
 * reached, including the one shown when the TENANT could not be resolved.
 * Asking the guard for the user there queries a tenant database that does not
 * exist, so that layout reads the cookie only (`forGuest`). Everything behind
 * `auth` reads the user first (`forUser`).
 */
final class ThemePreference
{
    /** Long enough that nobody meets the default again on the same browser. */
    public const string COOKIE = 'theme';

    public const int COOKIE_MINUTES = 60 * 24 * 365;

    public static function forGuest(Request $request): Theme
    {
        return Theme::fromPreference($request->cookie(self::COOKIE));
    }

    public static function forUser(Request $request, ?Authenticatable $user): Theme
    {
        $stored = $user !== null ? data_get($user, 'theme') : null;

        return \is_string($stored) ? Theme::fromPreference($stored) : self::forGuest($request);
    }
}
