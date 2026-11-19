<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Http\Controllers;

use App\Tenant\Identity\Models\Authenticator;
use App\Tenant\Identity\Models\RecoveryCode;
use App\Tenant\Identity\Services\MfaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Where a user enrols a second factor - PF-246.
 *
 * ## The deadlock this page ends
 *
 * `RoleGrantService::grant()` refuses any role carrying a permission marked
 * `requires_mfa` unless `MfaService::hasConfirmedFactor()` is true. That is
 * correct and it is the control the product is built around: nobody holds
 * `payment.release` without a second factor.
 *
 * `hasConfirmedFactor()` is true only when a confirmed `Authenticator` row
 * exists. An `Authenticator` is created by `beginTotpEnrolment()` and
 * confirmed by `confirmTotpEnrolment()`. Measured before this page existed:
 *
 *     beginTotpEnrolment       0 callers
 *     confirmTotpEnrolment     0 callers
 *     regenerateRecoveryCodes  0 callers
 *     removeAuthenticator      0 callers
 *
 * So no user could obtain a factor, so no user could be granted a
 * money-moving role, ever. The control was perfect and the function was
 * unreachable - and nothing reported it, because every piece existed, had
 * tests, and worked when called. `mfa:status` even listed who was missing a
 * factor, which read as a gap in enrolment rather than as the absence of any
 * way to enrol.
 *
 * ## No permission gates this page
 *
 * Deliberately. Every user needs a factor before they can be given the role
 * that needs one, so a permission here would be a lock whose key is behind
 * it. The page shows one user their own factors and nobody else's.
 *
 * ## Why the secret is not re-shown
 *
 * `beginTotpEnrolment()` returns the secret once and the model will not give
 * it back without an explicit call. A page that could redisplay it would
 * turn a stolen session into a stolen factor: the attacker reads the secret,
 * enrols their own app, and the real user notices nothing because their app
 * keeps working. An enrolment whose secret was lost is started again.
 */
final class SecurityController
{
    /**
     * @no-permission-required: a user has to be able to enrol a factor
     * BEFORE they can be granted the role that requires one. Gating this
     * page would put the key behind the lock it opens.
     */
    public function __invoke(Request $request, MfaService $mfa): View
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $session = $request->hasSession() ? $request->session() : null;

        $flash = static fn (string $key): mixed => $session?->get($key);

        $error = static function (string $key) use ($session): ?string {
            $bag = $session?->get('errors');

            return $bag instanceof \Illuminate\Support\ViewErrorBag && $bag->has($key)
                ? $bag->first($key)
                : null;
        };

        $factors = Authenticator::query()
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->get();

        return view('app.security', [
            'factors' => $factors,
            'hasConfirmedFactor' => $mfa->hasConfirmedFactor($user),
            'recoveryCodesLeft' => RecoveryCode::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->count(),

            /*
             | Which of this user's permissions are the reason any of this
             | matters, named rather than described.
             |
             | "Two-factor authentication is recommended" is a sentence
             | people close. "You hold payment.release, and it needs a
             | factor" is a sentence that gets acted on - and if the list is
             | empty the page says that too, so somebody enrolling for a
             | role they are about to be given is not told they need nothing.
             */
            'moneyMoving' => $mfa->moneyMovingPermissionsHeldBy($user),

            /*
             | Everything that comes out of the session is read HERE, once,
             | and handed to the template as plain data.
             |
             | Not for tidiness. A template calling `session()` and `@error`
             | cannot be rendered without a session, and the page itself does
             | not need one - there is simply nothing in progress. The shell
             | check renders every page the menu offers from a bare request,
             | and this page was the only one that threw.
             |
             | The flash is read rather than kept: a secret that survives a
             | navigation is a secret sitting in the session store, for every
             | signed-in user, for as long as their session lives.
             */
            'started' => $flash('mfa.enrolment'),
            'status' => $flash('status'),
            'recoveryCodes' => $flash('mfa.recovery_codes'),
            'factorError' => $error('factor'),
            'codeError' => $error('code'),
        ]);
    }
}
