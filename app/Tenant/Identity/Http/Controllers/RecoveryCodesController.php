<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Http\Controllers;

use App\Tenant\Identity\Services\MfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Issue a fresh set of recovery codes - PF-246.
 *
 * ## Why they are shown once and flashed
 *
 * They are stored hashed - `MfaService::consumeRecoveryCode()` hashes
 * against every unused one rather than looking any up, because a recovery
 * code is a password - so there is nothing to redisplay. A page offering to
 * show them again would have to keep them in the clear, which would make
 * them a second password sitting in a database beside the first.
 *
 * The page says this before issuing them, not after.
 *
 * ## And issuing invalidates the previous set
 *
 * `regenerateRecoveryCodes()` deletes the old rows. That is the point - a
 * set somebody may have photographed stops working - but it also means a
 * user who clicks this while holding a printed set loses the printed set.
 * The button says so.
 */
final class RecoveryCodesController
{
    /**
     * @no-permission-required: a user manages their own recovery codes. See
     * {@see SecurityController}.
     */
    public function __invoke(Request $request, MfaService $mfa): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $codes = $mfa->regenerateRecoveryCodes($user);

        return redirect()
            ->route('security.index')
            ->with('mfa.recovery_codes', $codes->all())
            ->with('status', __('أُصدرت رموز استرداد جديدة، وتوقّف العمل بأي رموز سابقة.'));
    }
}
