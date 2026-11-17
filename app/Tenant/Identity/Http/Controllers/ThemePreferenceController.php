<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Http\Controllers;

use App\Support\Ui\Theme;
use App\Support\Ui\ThemePreference;
use App\Tenant\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Stores the colour theme a user chose - UX-004.
 *
 * Written to the user, so it follows them to every device, AND to a cookie,
 * so the sign-in page on this browser opens in it before anyone is signed in.
 *
 * ## Not audited
 *
 * Unlike an entity switch, a theme changes nothing anyone else sees and
 * nothing about what the user may do. Recording it would put noise in the
 * trail an auditor actually reads.
 */
final class ThemePreferenceController
{
    /**
     * @no-permission-required: a person's own display preference. It reads and
     * writes nothing but their own user row, so there is no grant to hold -
     * refusing it would only lock someone out of the high-contrast theme they
     * need to read the rest of the screen.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $theme = Theme::tryFrom($request->string('theme')->value());

        if ($theme === null) {
            throw ValidationException::withMessages([
                'theme' => __('هذا المظهر غير متاح.'),
            ]);
        }

        $user->forceFill(['theme' => $theme->value])->saveQuietly();

        return redirect()
            ->back()
            ->with('theme_switched', __($theme->label()))
            ->withCookie(cookie(ThemePreference::COOKIE, $theme->value, ThemePreference::COOKIE_MINUTES));
    }
}
