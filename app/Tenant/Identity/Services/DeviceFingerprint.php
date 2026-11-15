<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use Illuminate\Http\Request;

/**
 * A stable-ish identifier for the device a session was established on -
 * PF-029.
 *
 * ## What this is, and what it is emphatically not
 *
 * It is NOT device identification and must never be described as such. It is a
 * hash of a few request properties that a legitimate user's browser keeps
 * constant and a stolen cookie replayed from elsewhere usually does not. It
 * raises the cost of using a stolen session; it does not make it impossible.
 *
 * Saying that plainly matters, because the failure mode of believing otherwise
 * is building a second control on top of a first one that does not hold.
 *
 * ## What goes into it, and what deliberately does not
 *
 * IN: the User-Agent, the accepted languages, and the application key as a
 * salt so the value is useless in another deployment.
 *
 * OUT: the IP address. This is the decision that matters. Binding a session to
 * an IP breaks every user on mobile data the moment they move between cells,
 * every user behind a corporate NAT pool, and every user whose ISP rotates a
 * CGNAT address - which is most of them, in most of the world this product
 * sells into. The security gained is small, because an attacker in the same
 * coffee shop shares the address anyway; the breakage is constant, and the
 * first support ticket gets the whole control switched off.
 *
 * OUT: anything requiring JavaScript. A fingerprint the server cannot compute
 * on the first request is a fingerprint that cannot protect the first request.
 */
final class DeviceFingerprint
{
    public static function for(Request $request): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                (string) $request->userAgent(),
                (string) $request->header('Accept-Language'),
            ]),
            (string) config('app.key'),
        );
    }

    /**
     * Has the device changed enough to re-authenticate?
     *
     * `hash_equals` rather than `===`: the stored value is attacker-visible
     * only in theory, but a constant-time comparison costs nothing here.
     */
    public static function changed(?string $stored, Request $request): bool
    {
        if ($stored === null || $stored === '') {
            // No binding recorded - an older session, or one established
            // before this was introduced. Treated as unchanged rather than as
            // an attack, because logging out every existing session on deploy
            // is its own kind of incident.
            return false;
        }

        return ! hash_equals($stored, self::for($request));
    }
}
