<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Models\Authenticator;
use App\Tenant\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The second half of one sign-in - PF-029, PF-030.
 *
 * ## What was broken, and how quietly
 *
 * `StepUpGuard` refuses a permission marked `requires_mfa` unless the session
 * carries `factor_asserted_at`, which it reads to mean "a second factor was
 * used to establish THIS session". Nothing in the application ever wrote that
 * key. Only check suites did, by hand, to reach the code they were testing.
 *
 * So every `requires_mfa` permission - releasing a payment run, reopening a
 * period, changing a supplier's bank details - was refused for every real
 * user, including the ones who had enrolled a factor exactly so they could
 * perform them. The control failed closed, which is the safe direction and
 * the reason nobody noticed: the refusal reads "this session was not
 * established with a second factor", which is true, and there was no way for
 * any session to be established with one.
 *
 * This is the piece Laravel Fortify would have supplied and which PF-029 says
 * has to be written by hand here. It is deliberately a state machine with
 * three facts in the session and nothing else: who passed the password stage,
 * when, and on which device.
 *
 * ## The password stage does not sign anybody in
 *
 * A user with a confirmed factor leaves {@see begin()} unauthenticated. The
 * tempting shortcut is to sign them in and set a "needs 2FA" flag that the
 * middleware honours, and it is how most hand-rolled second factors end up
 * optional: the session is already authenticated, so every path that forgets
 * to consult the flag - a new route, a queued job acting on the session, an
 * API token minted from it - is a path that skipped the factor. Here there is
 * nothing to forget, because there is no authenticated session to forget it
 * with until a code has been accepted.
 *
 * ## The pending challenge expires, and is bound to the device
 *
 * Five minutes, because the window is the time between reading a code off a
 * phone and typing it. A challenge left open for a day is a password-only
 * sign-in that completes whenever the attacker who has the password gets
 * round to it.
 *
 * The device binding is the same hash `EnforceSessionPolicy` uses on an
 * established session, applied one stage earlier: a challenge opened in the
 * victim's browser must not be completable from somebody else's.
 *
 * ## Both factor types are accepted here, and only here
 *
 * A TOTP code and a recovery code are presented in the same field, because a
 * user whose phone is in the other room does not want to find a different
 * form. Which one was used is recorded - a recovery code is evidence that the
 * normal factor was unavailable, and a run of them is either a lost phone or
 * somebody working through a printed list.
 */
final class SecondFactorChallenge
{
    /**
     * One key, holding the whole pending state.
     *
     * Three separate keys would allow a half-populated challenge to exist -
     * a user id with no device, say - and the code reading it would have to
     * decide what that means. One key is either there and complete, or
     * absent.
     */
    private const SESSION_KEY = 'second_factor_challenge';

    /** See the class note on why this is minutes rather than hours. */
    private const LIFETIME_SECONDS = 300;

    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Record that the password was accepted and a factor is now owed.
     *
     * The caller must have regenerated the session id already: the challenge
     * is written into the session, and writing it into an id an attacker
     * chose means the session they can already read becomes the session the
     * victim finishes authenticating.
     */
    public function begin(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => (string) $user->getKey(),
            'started_at' => Carbon::now()->toIso8601String(),
            'device' => DeviceFingerprint::for($request),
        ]);

        $this->audit->record(
            category: 'identity',
            action: 'session.factor_required',
            subjectType: User::class,
            subjectId: (string) $user->getKey(),
            subjectLabel: $user->name,
            reason: 'the password was accepted and the user has a confirmed second factor, so no session '.
                'exists yet and none will until a code is accepted',
            actor: $user,
        );
    }

    /**
     * Is a usable challenge in progress for this session?
     *
     * Usable means: it exists, it has not expired, it was opened on this
     * device, and the user it names can still sign in at all. A challenge
     * that fails any of those is discarded here rather than carried to the
     * code check, because a challenge that survives the user being
     * deactivated is a deactivated user's sign-in waiting to complete.
     */
    public function isPending(Request $request): bool
    {
        return $this->pendingUser($request) !== null;
    }

    /** The user who passed the password stage, if the challenge still holds. */
    public function pendingUser(Request $request): ?User
    {
        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state)) {
            return null;
        }

        $userId = $state['user_id'] ?? null;
        $startedAt = $state['started_at'] ?? null;
        $device = $state['device'] ?? null;

        if (! is_string($userId) || ! is_string($startedAt) || ! is_string($device)) {
            $this->abandon($request);

            return null;
        }

        if (Carbon::parse($startedAt)->addSeconds(self::LIFETIME_SECONDS)->isPast()) {
            $this->abandon($request);

            return null;
        }

        /*
         | The fingerprint is compared in constant time against the one the
         | challenge was opened with, the same way an established session's
         | binding is. `DeviceFingerprint::changed()` treats an absent stored
         | value as unchanged - correct for a session that predates the
         | binding, wrong here, where an absent value means somebody wrote a
         | challenge without one. The guard above refuses that case already.
         */
        if (DeviceFingerprint::changed($device, $request)) {
            $this->abandon($request);

            return null;
        }

        $user = User::query()->whereKey($userId)->first();

        if (! $user instanceof User || ! $user->active) {
            $this->abandon($request);

            return null;
        }

        /*
         | And the factor itself must still be there.
         |
         | Removing the last factor is refused while the user holds a
         | money-moving permission, but a user who holds none may remove
         | theirs - and if that happens between the two stages, completing
         | the challenge would need a code from an authenticator that no
         | longer exists. Treated as no challenge at all, so the next attempt
         | is an ordinary password sign-in rather than a form nobody can
         | satisfy.
         */
        if (! $this->mfa->hasConfirmedFactor($user)) {
            $this->abandon($request);

            return null;
        }

        return $user;
    }

    /**
     * Accept a TOTP code or a recovery code, and say which it was.
     *
     * Returns null for every failure, and the reason goes to the audit log
     * rather than to the caller: an expired challenge, a wrong code and a
     * replayed code are one sentence to whoever is typing, for the same
     * reason a wrong password and an unknown account are.
     *
     * @return array{user: User, factor: string}|null
     */
    public function complete(Request $request, string $code): ?array
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            $this->audit->refusal(
                category: 'identity',
                action: 'session.factor_refused',
                subjectLabel: 'second factor',
                reason: 'a code was submitted with no usable challenge in progress - expired, never opened, '.
                    'or opened on another device',
            );

            return null;
        }

        $authenticator = $this->mfa->verifyCode($user, $code);

        if ($authenticator instanceof Authenticator) {
            /*
             | The challenge is spent before the caller establishes the
             | session. One accepted code establishes one session: leaving it
             | pending would let a second request replay the same accepted
             | code - which the authenticator's own counter would refuse, but
             | only because of a control that lives somewhere else.
             */
            $this->abandon($request);

            return ['user' => $user, 'factor' => (string) $authenticator->type];
        }

        /*
         | A recovery code, tried second and deliberately.
         |
         | A TOTP code is six digits and a recovery code is eleven characters
         | with a hyphen, so they cannot be confused - but trying the
         | recovery codes first would mean every mistyped TOTP code is hashed
         | against ten bcrypt hashes before being refused, which is a free
         | way to make the login form expensive to serve.
         */
        if ($this->mfa->consumeRecoveryCode($user, $code)) {
            $this->abandon($request);

            return ['user' => $user, 'factor' => 'recovery_code'];
        }

        $this->audit->refusal(
            category: 'identity',
            action: 'session.factor_refused',
            subjectType: User::class,
            subjectId: (string) $user->getKey(),
            subjectLabel: $user->name,
            reason: 'neither a code from a confirmed authenticator nor an unused recovery code matched; '.
                'the password stage stands and the challenge is still open',
            actor: $user,
        );

        return null;
    }

    /** Forget the pending challenge, leaving the session unauthenticated. */
    public function abandon(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
