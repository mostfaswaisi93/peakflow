<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Http\Controllers;

use App\Support\Ui\Density;
use App\Support\Ui\DensityPreference;
use App\Tenant\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Stores the density a user chose - PF-203.
 *
 * Written to the user, so it follows them to every device, AND to a cookie,
 * so a page rendered before the session is established opens at the same
 * density instead of reflowing once it is. Exactly the shape of
 * {@see ThemePreferenceController}, which is the point: a display preference
 * is one kind of thing, and two kinds of plumbing for it is how the second
 * one ends up not persisting.
 *
 * ## Not audited
 *
 * Like the theme, a density changes nothing anyone else sees and nothing
 * about what the user may do. Recording it would put noise in the trail an
 * auditor actually reads.
 */
final class DensityPreferenceController
{
    /**
     * @no-permission-required: a person's own display preference. It reads and
     * writes nothing but their own user row, so there is no grant to hold -
     * and refusing it would only lock somebody out of the row height they can
     * work at.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $density = Density::tryFrom($request->string('density')->value());

        if ($density === null) {
            throw ValidationException::withMessages([
                'density' => __('هذه الكثافة غير متاحة.'),
            ]);
        }

        $user->forceFill(['density' => $density->value])->saveQuietly();

        return redirect()
            ->back()
            ->with('density_switched', __($density->label()))
            ->withCookie(cookie(DensityPreference::COOKIE, $density->value, DensityPreference::COOKIE_MINUTES));
    }
}
