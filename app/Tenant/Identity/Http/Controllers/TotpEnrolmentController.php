<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Http\Controllers;

use App\Tenant\Identity\Exceptions\CannotRemoveLastFactor;
use App\Tenant\Identity\Models\Authenticator;
use App\Tenant\Identity\Services\MfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Start, confirm and remove an authenticator app - PF-246.
 *
 * Three actions on one controller rather than three controllers, because
 * they are three steps of one thing and each is meaningless without the
 * others: a started enrolment that is never confirmed is an unusable row,
 * and a confirmed factor that cannot be removed is a lost phone that locks
 * somebody out for good.
 *
 * ## Every action resolves the authenticator against the CURRENT user
 *
 * By query, not by route-model binding. Binding would resolve any id in the
 * table and leave the ownership check to whoever wrote the handler - and the
 * thing being confirmed here is a second factor, so an id from another user
 * is somebody enrolling their own phone against a colleague's account.
 *
 * The failure is a 404 rather than a 403: a 403 would confirm that the id
 * exists.
 */
final class TotpEnrolmentController
{
    /**
     * @no-permission-required: a user enrols their own factor. See
     * {@see SecurityController} - gating this would put the key behind the
     * lock it opens.
     */
    public function begin(Request $request, MfaService $mfa): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $name = trim((string) $request->input('name', ''));

        $started = $mfa->beginTotpEnrolment(
            $user,
            $name === '' ? __('تطبيق المصادقة') : mb_substr($name, 0, 60),
        );

        /*
         | The secret travels in a one-request flash and nowhere else.
         |
         | Kept in the session for the duration, it would sit in the session
         | store - redis, for every signed-in user - for as long as the
         | session lives, long after the enrolment finished. Flashed, it
         | exists for the one render that shows it.
         |
         | Which also means a refresh loses it. That is the right trade and
         | the page says so: an enrolment whose secret was lost is started
         | again, which costs a click, while a secret that can be
         | redisplayed turns a stolen session into a stolen factor.
         */
        return redirect()
            ->route('security.index')
            ->with('mfa.enrolment', [
                'id' => (string) $started['authenticator']->getKey(),
                'secret' => $started['secret'],
                'uri' => $started['uri'],
            ]);
    }

    /**
     * @no-permission-required: see begin().
     */
    public function confirm(Request $request, MfaService $mfa, string $authenticator): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $factor = Authenticator::query()
            ->where('user_id', $user->getKey())
            ->whereKey($authenticator)
            ->first();

        abort_if($factor === null, 404);

        /*
         | Passed through as typed. `Totp::verify()` strips non-digits, and
         | it is the right place for that: it is where the comparison
         | happens, so every caller gets the same normalisation.
         |
         | It was written here too at first, which looked like care and was
         | not: two layers stripping means biting either one leaves the
         | behaviour intact, so neither can be proved. One place normalises
         | and one check can fail.
         |
         | What it is for: every authenticator app displays "123 456".
         | Somebody copying it copies the space, and a form that answered
         | "that code is wrong" to a correct code is how a person concludes
         | the feature is broken and gives up - at the exact moment they are
         | being asked to trust it.
         */
        $code = (string) $request->input('code', '');

        if (! $mfa->confirmTotpEnrolment($factor, $code)) {
            return redirect()
                ->route('security.index')
                /*
                 | The enrolment is flashed BACK, so a mistyped digit does
                 | not cost the secret. Without this a wrong code would
                 | strand a half-enrolled authenticator the user can no
                 | longer confirm, because the secret it needs is gone.
                 */
                ->with('mfa.enrolment', [
                    'id' => (string) $factor->getKey(),
                    'secret' => (string) $request->input('secret', ''),
                    'uri' => (string) $request->input('uri', ''),
                ])
                ->withErrors(['code' => __('الرمز غير صحيح. تأكّد من أن ساعة هاتفك مضبوطة تلقائيًا، فالرموز محسوبة على الوقت.')]);
        }

        return redirect()
            ->route('security.index')
            ->with('status', __('تم تأكيد تطبيق المصادقة. احفظ رموز الاسترداد الآن.'));
    }

    /**
     * @no-permission-required: see begin().
     */
    public function destroy(Request $request, MfaService $mfa, string $authenticator): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $factor = Authenticator::query()
            ->where('user_id', $user->getKey())
            ->whereKey($authenticator)
            ->first();

        abort_if($factor === null, 404);

        /*
         | The refusal belongs to the service, and this catches it.
         |
         | `MfaService::removeAuthenticator()` already refuses to remove the
         | last confirmed factor of a user who holds a permission requiring
         | one - and it writes the refusal to the audit trail, which a check
         | in a controller would not.
         |
         | A guard was written here as well at first. It looked like care and
         | was not: two conditions for one rule drift apart, and the one that
         | matters is the one holding the audit record. Worse, the duplicate
         | hid the real problem - that the service's exception reached the
         | user as a 500. What was needed here was never a second check. It
         | was catching the first one.
         |
         | The sentence shown is the service's own, which names the
         | permissions at stake and the two ways out.
         */
        try {
            $mfa->removeAuthenticator($factor);
        } catch (CannotRemoveLastFactor $e) {
            return redirect()
                ->route('security.index')
                ->withErrors(['factor' => $e->getMessage()]);
        }

        return redirect()
            ->route('security.index')
            ->with('status', __('تم حذف العامل.'));
    }
}
