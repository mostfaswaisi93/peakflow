<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Exceptions\TenantNotResolvable;
use App\Platform\Models\ActivityLog;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\AuditLog;
use App\Tenant\Identity\Services\DeviceFingerprint;
use App\Tenant\Identity\Services\LoginThrottle;
use App\Tenant\Identity\Services\MfaService;
use App\Tenant\Identity\Services\SecondFactorChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tenant sign-in - PF-029.
 *
 * ## One failure message, always
 *
 * Wrong password, no such user, deactivated account, throttled: the user sees
 * the same sentence. Distinguishing them turns the login form into an oracle
 * that answers "does this person bank with you" to anyone who asks, which is
 * a disclosure on its own before it is a step towards an attack.
 *
 * The cost is real - a user who mistypes their email gets the same message as
 * one who mistypes their password - and it is the right trade. The audit log
 * records which it actually was, so support can tell them.
 *
 * ## Why this lives in the HTTP layer and not in Identity
 *
 * Signing in crosses both planes by nature: the workspace is resolved from the
 * PLATFORM database, the user is authenticated against the TENANT's. A domain
 * module may not do that - Platform depends on Identity, so Identity reaching
 * back inverts the graph and makes the tenant plane unrunnable without a
 * platform connection.
 *
 * An operation that genuinely spans both planes belongs to the application
 * layer that coordinates them, which is here. The architecture rule that
 * forbids `app/Tenant` from referencing `App\Platform` is what moved it.
 *
 * ## The workspace comes first
 *
 * Users live in their tenant's own database, so there is no database to
 * authenticate against until a workspace is known. In production the
 * subdomain supplies it; where it does not, the form asks, and the slug is
 * stored in the session before any credential is looked at.
 *
 * Deliberately: an unknown workspace produces the SAME refusal as a wrong
 * password. Otherwise the form answers "is acme a customer of yours" to
 * anyone who types it, which is a disclosure about the customer rather than
 * about the person signing in.
 *
 * ## Timing is equalized
 *
 * A missing user would otherwise return in a fraction of the time a real one
 * takes, because no hash is verified. That difference is measurable over a
 * few requests and reintroduces the oracle the message just closed. So a
 * hash is computed either way.
 *
 * ## Signing in is TWO stages for a user with a second factor
 *
 * The password stage establishes no session at all - it opens a challenge
 * ({@see SecondFactorChallenge}) and leaves the visitor unauthenticated. The
 * code stage is the one that signs them in, and it is the only place that
 * writes `factor_asserted_at`, which is what every `requires_mfa` permission
 * is checked against.
 *
 * Both stages are the same route, because Fortify is not installed here and
 * the route table is fixed: `POST /login` with a code and a challenge in
 * progress is the second stage, and anything else is the first. A visitor who
 * abandons the challenge and submits credentials again simply starts over.
 */
final class LoginController
{
    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly MfaService $mfa,
        private readonly AuditLog $audit,
        private readonly TenantConnectionResolver $resolver,
        private readonly SecondFactorChallenge $challenge,
    ) {}

    public function show(Request $request): View
    {
        return view('auth.login', [
            // Only ask when we do not already know. A user returning to a
            // workspace they have used should not have to remember its slug.
            'needsWorkspace' => ! is_string($request->session()->get('tenant')),

            /*
             | Which stage the form is for.
             |
             | Asked of the challenge rather than of a flash message: the
             | pending state is what the POST will actually be judged
             | against, and a form that offers a code field because
             | something was flashed - while the challenge behind it has
             | expired - sends the user to a refusal with no explanation of
             | what they did wrong.
             */
            'awaitingFactor' => $this->challenge->isPending($request),
        ]);
    }

    /**
     * @no-permission-required: this IS authentication. Requiring a permission
     * to sign in would require being signed in to sign in.
     */
    public function store(Request $request): RedirectResponse
    {
        /*
         | The second stage, when there is one in progress.
         |
         | Judged on `code` being present rather than on the challenge alone,
         | so that a user who gave up on the code - their phone is upstairs,
         | or they typed the wrong workspace - can submit credentials again
         | and start a fresh challenge instead of being stuck on a form they
         | cannot satisfy.
         |
         | A code submitted with NO challenge in progress is still handled
         | here, and refused there, rather than falling through to the
         | credential path. Falling through answered "the email field is
         | required", which tells somebody replaying a captured code the one
         | thing they do not already know: that their code was never even
         | looked at.
         */
        if ($request->filled('code')) {
            return $this->completeChallenge($request);
        }

        $credentials = $request->validate([
            'workspace' => ['nullable', 'string', 'max:64'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $email = (string) $credentials['email'];
        $ip = (string) $request->ip();

        if (! $this->establishWorkspace($request, $credentials['workspace'] ?? null)) {
            $this->throttle->recordFailure($email, $ip);
            $this->refuse($email, $ip, 'unknown or unusable workspace', $request);

            throw ValidationException::withMessages([
                'email' => __('بيانات الدخول غير صحيحة.'),
            ]);
        }

        $retryAfter = $this->throttle->retryAfter($email, $ip);

        if ($retryAfter !== null) {
            $this->refuse($email, $ip, 'throttled', $request);

            throw ValidationException::withMessages([
                'email' => __('يرجى الانتظار :seconds ثانية قبل المحاولة مرة أخرى.', ['seconds' => $retryAfter]),
            ]);
        }

        $user = User::query()->where('email', $email)->first();

        // Computed whether or not the user exists, so the two paths take
        // comparable time. Hash::check on a dummy hash is the standard way to
        // do this and costs one bcrypt round either way.
        $passwordMatches = $user === null
            ? Hash::check((string) $credentials['password'], $this->dummyHash())
            : Hash::check((string) $credentials['password'], (string) $user->getAuthPassword());

        if ($user === null || ! $passwordMatches || ! $user->active) {
            $this->throttle->recordFailure($email, $ip);

            $this->refuse($email, $ip, match (true) {
                $user === null => 'no such user',
                ! $passwordMatches => 'wrong password',
                default => 'the account is deactivated',
            }, $request);

            throw ValidationException::withMessages([
                'email' => __('بيانات الدخول غير صحيحة.'),
            ]);
        }

        /*
         | Session fixation: a NEW session id before anything is written to it.
         | Without this, an attacker who can set a victim's cookie before they
         | sign in keeps a valid, now-authenticated session id.
         |
         | It happens here, before the challenge is opened, because the
         | challenge is itself written into the session: opened in an id
         | somebody else chose, the session they can already read is the one
         | the victim finishes authenticating.
         */
        $request->session()->regenerate();

        /*
         | A user with a confirmed factor is NOT signed in by a password.
         |
         | This is the half that was missing. `StepUpGuard` refuses every
         | `requires_mfa` permission unless the session carries
         | `factor_asserted_at`, and until this branch existed nothing in the
         | application wrote it - so a user who had enrolled a factor in
         | order to release payments was refused for not having used it,
         | with no path that could have used it.
         */
        if ($this->mfa->hasConfirmedFactor($user)) {
            $this->challenge->begin($request, $user);

            return redirect()->route('login');
        }

        $this->establishSession($request, $user, factor: null);

        return redirect()->intended(route('home'));
    }

    /**
     * The code stage: the only path that establishes an MFA-backed session.
     *
     * @no-permission-required: this IS authentication, and the second half of
     * the same sign-in the password stage refused to complete.
     */
    private function completeChallenge(Request $request): RedirectResponse
    {
        /*
         | Bounded before anything is done with it, and refused as a wrong
         | code rather than as a validation error.
         |
         | A validation message would be a second shape of refusal on a form
         | whose whole design is that every refusal reads alike - and the
         | bound is not cosmetic: a recovery code is checked with
         | `Hash::check` against every unused code the user has, so an
         | unbounded field is ten bcrypt rounds over a megabyte of input per
         | request.
         */
        $code = mb_substr((string) $request->input('code'), 0, 64);

        $pending = $this->challenge->pendingUser($request);
        $ip = (string) $request->ip();

        /*
         | The throttle is consulted at THIS stage too, against the account
         | the challenge names.
         |
         | A six-digit code has a million values and a thirty-second life, so
         | an unthrottled code field is guessable by anyone willing to send a
         | few thousand requests - and whoever is sending them already has
         | the password, because that is how they got here.
         */
        if ($pending !== null && $this->throttle->retryAfter($pending->email, $ip) !== null) {
            $retryAfter = $this->throttle->retryAfter($pending->email, $ip);

            $this->refuse($pending->email, $ip, 'throttled at the second-factor stage', $request);

            throw ValidationException::withMessages([
                'email' => __('يرجى الانتظار :seconds ثانية قبل المحاولة مرة أخرى.', ['seconds' => $retryAfter]),
            ]);
        }

        $completed = $this->challenge->complete($request, $code);

        if ($completed === null) {
            /*
             | Counted against the account the challenge named, where there
             | was one. A code submitted with no challenge at all is counted
             | against the address only: there is no account to attribute it
             | to, and inventing one from the form would let anybody lock out
             | an address they know by submitting codes for it.
             */
            if ($pending !== null) {
                $this->throttle->recordFailure($pending->email, $ip);
            } else {
                $this->throttle->recordFailure('', $ip);
            }

            throw ValidationException::withMessages([
                'email' => __('بيانات الدخول غير صحيحة.'),
            ]);
        }

        $this->establishSession($request, $completed['user'], factor: $completed['factor']);

        return redirect()->intended(route('home'));
    }

    /**
     * Turn an accepted sign-in into a session.
     *
     * Shared by both stages so that there is exactly one place where a
     * session comes into existence. Two copies of this is how a second
     * factor becomes optional: the copy that runs for the password-only user
     * is the one somebody later edits, and the other one keeps writing
     * `factor_asserted_at` for a sign-in that never asserted anything.
     *
     * @param  ?string  $factor  which factor was asserted just now, or null
     *     when this sign-in was a password alone. A session established
     *     without one must NOT carry `factor_asserted_at`: that key is the
     *     whole of what `requires_mfa` is checked against, and writing it
     *     unconditionally would satisfy every MFA requirement in the system
     *     with a password.
     */
    private function establishSession(Request $request, User $user, ?string $factor): void
    {
        /*
         | The id rotates again here, and this call is what does it.
         |
         | `SessionGuard::login()` migrates the session itself, so an
         | explicit `regenerate()` on this line would be a second rotation
         | that no check can tell apart from the first - and a line that
         | cannot be observed is a line the next person deletes or
         | duplicates with equal confidence. It is written down instead,
         | because the property matters: the pre-authentication id is handed
         | to the browser with the challenge form, and anything that leaked
         | it must not name the session that is now signed in.
         |
         | What holds it is `login()` doing the migration, so the check that
         | guards it ("the session id changes at every stage of a sign-in")
         | is aimed at the guard call rather than at a line here: replacing
         | it with `setUser()`, which authenticates for one request and
         | writes nothing, fails that check.
         */
        auth()->guard('web')->login($user, remember: false);

        $request->session()->put('authenticated_at', now()->toIso8601String());
        $request->session()->put('device', DeviceFingerprint::for($request));

        if ($factor !== null) {
            $request->session()->put('factor_asserted_at', now()->toIso8601String());
            $request->session()->put('factor_type', $factor);
        }

        /*
         | The account's throttle budget is cleared HERE, not when the
         | password was accepted.
         |
         | Clearing it at the password stage gave somebody holding a phished
         | password five fresh guesses at the code for every re-submission of
         | that password, which is an unbounded number of guesses arranged
         | from a counter that exists to bound them.
         */
        $this->throttle->recordSuccess($user->email);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => (string) $request->ip(),
        ])->saveQuietly();

        $this->audit->record(
            category: 'identity',
            action: 'session.started',
            subjectType: User::class,
            subjectId: (string) $user->getKey(),
            subjectLabel: $user->name,
            after: ['factor' => $factor],
            reason: match ($factor) {
                null => 'password accepted; this user has no second factor',
                'recovery_code' => 'password accepted and a recovery code spent, so the user\'s normal factor '.
                    'was unavailable; the session may perform actions that require a factor',
                default => sprintf(
                    'password accepted and a %s code verified, so this session may perform actions that '.
                    'require a factor',
                    $factor,
                ),
            },
            actor: $user,
        );
    }

    /**
     * @no-permission-required: a user may always end their own session, and a
     * sign-out that can be refused is a way to keep someone signed in.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->audit->record(
                category: 'identity',
                action: 'session.signed_out',
                subjectType: User::class,
                subjectId: (string) $user->getKey(),
                subjectLabel: $user->name,
                actor: $user,
            );
        }

        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Point the tenant connection at the workspace this sign-in is for.
     *
     * Returns false rather than throwing, so the caller gives the same
     * refusal it gives a wrong password - an unknown workspace must not be
     * distinguishable from a bad credential.
     */
    private function establishWorkspace(Request $request, ?string $workspace): bool
    {
        $existing = $request->session()->get('tenant');

        if (is_string($existing) && $existing !== '') {
            // Already resolved by the tenant middleware for this request.
            return app()->bound('currentTenant');
        }

        if ($workspace === null || trim($workspace) === '') {
            return false;
        }

        try {
            $tenant = $this->resolver->resolve(trim($workspace));
        } catch (TenantNotResolvable) {
            return false;
        }

        $request->session()->put('tenant', $tenant->slug);

        return true;
    }

    /**
     * Record what actually happened, where only staff can read it.
     *
     * The user is told one thing; the log records the truth. That is the whole
     * point of refusing to distinguish them in the response - the information
     * still exists, it is simply not handed to whoever asked.
     */
    private function refuse(string $email, string $ip, string $because, Request $request): void
    {
        /*
         | Where this goes depends on whether a tenant was established.
         |
         | With one, it belongs in that tenant's audit log, next to the
         | successful sign-ins it should be read against.
         |
         | WITHOUT one - an unknown workspace, or none given - there is no
         | tenant database to write to, and the first version simply called
         | the tenant audit log anyway. That returned 500, recorded nothing,
         | and leaked through the status code exactly what the identical
         | refusal message existed to hide: a wrong password answered 302 and
         | an unknown workspace answered 500, so the form told anyone who
         | asked which workspaces are real.
         |
         | So a tenant-less refusal goes to the platform activity log, which
         | is the only place that can hold it.
         */
        if (app()->bound('currentTenant')) {
            $this->audit->refusal(
                category: 'identity',
                action: 'session.refused',
                subjectLabel: $email,
                after: ['ip' => $ip, 'user_agent' => $request->userAgent()],
                reason: $because,
            );

            return;
        }

        ActivityLog::create([
            'tenant_id' => null,
            'category' => 'identity',
            'action' => 'session.refused',
            'context' => [
                'workspace_attempted' => $request->string('workspace')->limit(64)->value(),
                /*
                 | A HASH of the email, not the address.
                 |
                 | This record is about a workspace that does not exist, so the
                 | address belongs to no customer of ours and the platform
                 | plane has no business accumulating a list of them. The hash
                 | still answers the question an abuse investigation asks -
                 | "is one address being tried across four hundred
                 | workspaces" - because the same address hashes the same way.
                 */
                'email_sha256' => hash('sha256', Str::lower(trim($email))),
                'user_agent' => Str::limit((string) $request->userAgent(), 300, ''),
                'because' => $because,
            ],
            'ip_address' => $ip,
        ]);
    }

    /**
     * A real bcrypt hash of a value no password can be, so the comparison for
     * a non-existent user does the same work as for a real one.
     */
    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('peakflow-timing-equalizer-'.config('app.key'));
    }
}
