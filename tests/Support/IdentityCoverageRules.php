<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Controllers\LoginController;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Mfa\Totp;
use App\Tenant\Identity\Exceptions\StepUpRequired;
use App\Tenant\Identity\Models\Authenticator;
use App\Tenant\Identity\Models\Permission;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\LoginThrottle;
use App\Tenant\Identity\Services\MfaService;
use App\Tenant\Identity\Services\StepUpGuard;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What a sign-in has to prove before it is a session - PF-029, PF-030.
 *
 * ## The hole this suite was written for
 *
 * `StepUpGuard` refuses any permission marked `requires_mfa` unless the
 * session carries `factor_asserted_at`. Nothing in `app/` ever wrote that
 * key - a search for it found one migration column, one read in the guard,
 * and four check suites setting it by hand to reach the code they were
 * testing.
 *
 * So the second factor was enrolled, confirmed, audited, refused on removal,
 * demanded at the point of granting - and could never be *used*. Every
 * `requires_mfa` permission was refused for every real user with the message
 * "this session was not established with a second factor", which was true of
 * every session that could exist.
 *
 * It failed closed, which is why it survived: the symptom is a payment run
 * nobody can release, not a payment run anybody can. The lesson is the one
 * `SodCoverageRules` records about duties and audit actions - a control
 * whose own tests supply the state it reads is a control that has never run.
 *
 * ## Every check here attempts the thing and asserts the refusal
 *
 * Not that a flag is set, and not that a method returns true. The suite
 * drives `LoginController` the way a browser does - a real `Request`, a real
 * session store, the real guard - and asks afterwards whether anybody is
 * signed in. A check that asserted `isPending()` would have passed against a
 * version of this that signed the user in first and then opened a challenge.
 *
 * ## Why the session driver is swapped
 *
 * The guard, the `session()` helper and the controller must all be looking at
 * ONE store, which is what `StartSession` arranges in a real request. Here
 * the default driver is swapped to `array` and the guards are forgotten so
 * they rebind to it; both are restored afterwards, because the suites that
 * run after this one in the same process expect the configured driver.
 */
final class IdentityCoverageRules
{
    /** A browser that keeps its headers constant, which is what the binding assumes. */
    private const AGENT = 'PeakFlow-IdentityCoverage/1.0';

    private ?Connection $connection = null;

    private ?Session $session = null;

    private string $slug = '';

    private string $ip = '';

    private string $originalDriver = '';

    /** @var list<string> */
    private array $spentLimiterKeys = [];

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereHas('connection')->orderBy('slug')->first();

        if ($tenant === null) {
            return ['the sign-in challenge (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);

        $this->slug = (string) $tenant->slug;
        $this->connection = DB::connection('tenant');

        /*
         | A fresh address from TEST-NET-1 (RFC 5737, never routed) per run.
         |
         | The same lesson `WiringRules` records: this suite deliberately
         | spends login attempts, and a fixed address would throttle the
         | suite itself after a couple of runs - producing a false PASS
         | first, because a throttled refusal is still a refusal.
         */
        $this->ip = sprintf('192.0.2.%d', random_int(1, 254));

        $this->useArraySession();
        $this->connection->beginTransaction();

        try {
            return [
                'a password alone does not sign in a user who has a second factor' => $this->passwordAloneIsNotASession(),
                'the pending challenge satisfies no MFA requirement, and an accepted code does' => $this->challengeGatesMfaPermissions(),
                'a wrong code signs nobody in, and the refusal is recorded' => $this->wrongCodeIsRefusedAndRecorded(),
                'a code that established one session cannot establish another' => $this->codeCannotBeReplayedIntoASecondSession(),
                'a code with no challenge in progress signs nobody in' => $this->codeWithoutAChallengeIsRefused(),
                'a challenge opened on one device cannot be finished on another' => $this->challengeIsBoundToItsDevice(),
                'an expired challenge cannot be finished' => $this->expiredChallengeIsRefused(),
                'the session id changes at every stage of a sign-in' => $this->everyStageRotatesTheSessionId(),
                'a correct password does not refill the second-factor guess budget' => $this->passwordDoesNotRefillTheCodeBudget(),
                'a password-only session satisfies no MFA requirement' => $this->passwordOnlySessionAssertsNoFactor(),
                'a tenant sign-in authenticates nobody on the operator plane' => $this->planesStaySeparate(),
                'a recovery code finishes a challenge, and does so exactly once' => $this->recoveryCodeWorksOnce(),
                'the challenge form asks for a code, and does not ask for the account again' => $this->challengeFormAsksForACode(),
            ];
        } finally {
            $this->connection->rollBack();
            $this->restoreSession();

            // Give back what the probes spent, so a run leaves the limiter as
            // it found it and a real user sharing the address is unaffected.
            foreach (array_unique($this->spentLimiterKeys) as $key) {
                RateLimiter::clear($key);
            }

            RateLimiter::clear('login:ip:'.hash('sha256', $this->ip));
        }
    }

    /**
     * The password stage must leave the visitor unauthenticated.
     *
     * This is the check that fails if somebody "simplifies" the two stages
     * into one by signing the user in and setting a flag for the middleware
     * to honour. The flag is honoured by the paths that remember it; the
     * session is authenticated for all of them.
     *
     * @return list<string>
     */
    public function passwordAloneIsNotASession(): array
    {
        [$user, $password] = $this->probeUserWithFactor();
        $violations = [];

        $this->freshBrowser();
        $refusal = $this->submit(['email' => $user->email, 'password' => $password]);

        if ($refusal !== null) {
            return [sprintf('a correct password was refused outright: %s', $refusal)];
        }

        if (auth()->guard('web')->check()) {
            $violations[] = 'the password alone signed the user in, so the second factor is something the '.
                'session has already got past rather than something it needed';
        }

        if ($this->session?->has('factor_asserted_at') === true) {
            $violations[] = 'the password stage wrote factor_asserted_at, which is the whole of what '.
                'requires_mfa is checked against - every MFA requirement in the system is now satisfied '.
                'by a password';
        }

        return $violations;
    }

    /**
     * A permission that demands a factor is refused while the challenge is
     * open and allowed once a code has been accepted.
     *
     * The user is passed to the guard explicitly, deliberately: with nobody
     * signed in, `assert()` finds no current user and returns without
     * refusing - that is the console path, and a check that relied on it
     * would pass while proving nothing at all.
     *
     * @return list<string>
     */
    public function challengeGatesMfaPermissions(): array
    {
        $permission = $this->mfaOnlyPermission();

        if ($permission === null) {
            return ['no permission in the catalogue requires a factor without also requiring step-up, so '.
                'the session half of the demand could not be probed in isolation'];
        }

        [$user, $password, $secret] = $this->probeUserWithFactor();
        $guard = app(StepUpGuard::class);
        $violations = [];

        $this->freshBrowser();
        $this->submit(['email' => $user->email, 'password' => $password]);

        try {
            $guard->assert($permission, user: $user);

            $violations[] = sprintf(
                '"%s" was allowed while the second-factor challenge was still open, so passing the '.
                'password stage is all the permission demands',
                $permission,
            );
        } catch (StepUpRequired) {
            // Correct: the password stage establishes nothing.
        }

        $refusal = $this->submit(['code' => Totp::at($secret)]);

        if ($refusal !== null) {
            return [...$violations, sprintf('a correct code was refused: %s', $refusal)];
        }

        if (! auth()->guard('web')->check()) {
            return [...$violations, 'an accepted code did not sign the user in, so there is no path to an '.
                'MFA-backed session at all'];
        }

        try {
            $guard->assert($permission, user: $user);
        } catch (StepUpRequired $e) {
            $violations[] = sprintf(
                '"%s" is still refused after a code was accepted, so no session can ever satisfy it: %s',
                $permission,
                $e->getMessage(),
            );
        }

        return $violations;
    }

    /**
     * A wrong code signs nobody in, and says so where staff can read it.
     *
     * The audit half matters as much as the refusal: a refused second factor
     * is the signal that somebody has the password, and a refusal nobody can
     * see is indistinguishable from an attempt nobody made.
     *
     * @return list<string>
     */
    public function wrongCodeIsRefusedAndRecorded(): array
    {
        [$user, $password] = $this->probeUserWithFactor();
        $violations = [];

        $this->freshBrowser();
        $this->submit(['email' => $user->email, 'password' => $password]);

        $before = $this->refusalsRecorded($user);
        $refusal = $this->submit(['code' => '000000']);

        if ($refusal === null) {
            $violations[] = 'a wrong code was accepted';
        }

        if (auth()->guard('web')->check()) {
            $violations[] = 'the wrong code signed the user in';
        }

        if ($this->refusalsRecorded($user) <= $before) {
            $violations[] = 'the refused code wrote no audit refusal, so an attacker working through codes '.
                'with a stolen password leaves no trace anybody can read';
        }

        return $violations;
    }

    /**
     * One accepted code is one session.
     *
     * A code stays correct for its whole 30-second step, so without the
     * authenticator's counter the same code opens a second session seconds
     * later - which is exactly what a phishing proxy relays.
     *
     * @return list<string>
     */
    public function codeCannotBeReplayedIntoASecondSession(): array
    {
        [$user, $password, $secret] = $this->probeUserWithFactor();
        $code = Totp::at($secret);
        $violations = [];

        $this->freshBrowser();
        $this->submit(['email' => $user->email, 'password' => $password]);

        if ($this->submit(['code' => $code]) !== null) {
            return ['a correct code was refused on first use, so the replay half could not be probed'];
        }

        $this->freshBrowser();
        $this->submit(['email' => $user->email, 'password' => $password]);

        if ($this->submit(['code' => $code]) === null) {
            $violations[] = 'the same code established a second session, so a code read over a shoulder or '.
                'relayed by a proxy is still usable after the user has signed in with it';
        }

        if (auth()->guard('web')->check()) {
            $violations[] = 'the replayed code left somebody signed in';
        }

        return $violations;
    }

    /**
     * A code is worth nothing without the challenge that asked for it.
     *
     * The session names the account; the form does not. Without this, a
     * captured code could be aimed at whichever account the sender chose.
     *
     * @return list<string>
     */
    public function codeWithoutAChallengeIsRefused(): array
    {
        [$user, , $secret] = $this->probeUserWithFactor();

        $this->freshBrowser();

        $violations = [];

        if ($this->submit(['code' => Totp::at($secret)]) === null) {
            $violations[] = 'a code with no challenge in progress was accepted';
        }

        if (auth()->guard('web')->check()) {
            $violations[] = sprintf('a bare code signed %s in with no password stage at all', $user->email);
        }

        return $violations;
    }

    /**
     * The challenge is bound to the device it was opened on.
     *
     * The established session carries the same binding - `EnforceSessionPolicy`
     * enforces it - and a challenge without it is a thirty-second window in
     * which that binding does not apply.
     *
     * @return list<string>
     */
    public function challengeIsBoundToItsDevice(): array
    {
        [$user, $password, $secret] = $this->probeUserWithFactor();
        $violations = [];

        $this->freshBrowser();
        $this->submit(['email' => $user->email, 'password' => $password]);

        $refusal = $this->submit(['code' => Totp::at($secret)], agent: 'Somebody-Elses-Browser/9.0');

        if ($refusal === null) {
            $violations[] = 'a challenge opened in one browser was finished from another, so a stolen '.
                'pre-authentication cookie completes the sign-in the victim started';
        }

        if (auth()->guard('web')->check()) {
            $violations[] = 'the foreign device was left signed in';
        }

        return $violations;
    }

    /**
     * A challenge left open does not stay open.
     *
     * Time is moved rather than the stored state edited, so the check knows
     * nothing about how the challenge is written down - it only knows that
     * five minutes later the same correct code must not work.
     *
     * @return list<string>
     */
    public function expiredChallengeIsRefused(): array
    {
        [$user, $password, $secret] = $this->probeUserWithFactor();
        $violations = [];

        $this->freshBrowser();
        $this->submit(['email' => $user->email, 'password' => $password]);

        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        try {
            $refusal = $this->submit(['code' => Totp::at($secret)]);

            if ($refusal === null) {
                $violations[] = 'a challenge opened half an hour earlier was still completable, which is a '.
                    'password-only sign-in that finishes whenever whoever has the password gets round to it';
            }

            if (auth()->guard('web')->check()) {
                $violations[] = 'the expired challenge left somebody signed in';
            }
        } finally {
            Carbon::setTestNow();
        }

        return $violations;
    }

    /**
     * Session fixation, across both stages.
     *
     * The id is rotated when the challenge is opened and again when the
     * session is authenticated. The first rotation is the one that matters
     * and the one that is easy to miss: the challenge is written INTO the
     * session, so an id an attacker planted becomes the id that finishes
     * authenticating.
     *
     * @return list<string>
     */
    public function everyStageRotatesTheSessionId(): array
    {
        [$user, $password, $secret] = $this->probeUserWithFactor();

        $this->freshBrowser();
        $planted = (string) $this->session?->getId();

        $this->submit(['email' => $user->email, 'password' => $password]);
        $afterPassword = (string) $this->session?->getId();

        $this->submit(['code' => Totp::at($secret)]);
        $afterCode = (string) $this->session?->getId();

        $violations = [];

        if ($afterPassword === $planted) {
            $violations[] = 'the id a visitor arrived with is the id the challenge was written into, so a '.
                'planted cookie is the session that finishes authenticating';
        }

        if ($afterCode === $afterPassword) {
            $violations[] = 'the pre-authentication id became the authenticated one, so anything that saw '.
                'it while the challenge form was on screen names a signed-in session';
        }

        if (! auth()->guard('web')->check()) {
            $violations[] = 'the sign-in did not complete, so the rotation could not be read';
        }

        return $violations;
    }

    /**
     * Passing the password stage must not reset the code stage's budget.
     *
     * A six-digit code has a million values. The account counter is the only
     * thing bounding guesses at it, and clearing that counter the moment a
     * password is accepted hands five fresh guesses to whoever re-submits the
     * password they already have - an unbounded number of attempts, arranged
     * out of the counter that exists to bound them.
     *
     * @return list<string>
     */
    public function passwordDoesNotRefillTheCodeBudget(): array
    {
        [$user, $password, $secret] = $this->probeUserWithFactor();
        $throttle = app(LoginThrottle::class);
        $email = (string) $user->email;

        $this->spentLimiterKeys[] = 'login:account:'.hash('sha256', Str::lower(trim($email)));

        $violations = [];

        $this->freshBrowser();
        $this->submit(['email' => $email, 'password' => $password]);

        // Four, which is one short of the account budget.
        for ($i = 0; $i < 4; $i++) {
            $this->submit(['code' => '00000'.$i]);
        }

        if ($throttle->retryAfter($email, $this->ip) !== null) {
            return ['four wrong codes exhausted the account budget, so the fifth attempt could not be '.
                'distinguished from a budget that had been refilled'];
        }

        // The password again: under the old ordering this cleared the counter.
        $this->submit(['email' => $email, 'password' => $password]);
        $this->submit(['code' => '000009']);

        if ($throttle->retryAfter($email, $this->ip) === null) {
            $violations[] = 'five wrong codes spread across a re-submitted password left the account '.
                'budget unspent, so a correct password refills the guesses at the code';
        }

        /*
         | And while the budget is spent, a CORRECT code must be refused -
         | on the challenge that is STILL OPEN from the submission above.
         |
         | Asserting the counter alone would not show that: a counter that
         | nothing consults is a number in Redis. Nor would starting a fresh
         | sign-in, which is what the first draft did: the password stage
         | refuses a throttled account before it opens a challenge, so the
         | code never reached the code stage and the check passed with the
         | code stage's limit removed entirely. The attacker does not
         | re-submit the password - they keep guessing at the challenge they
         | already have.
         */
        if ($this->submit(['code' => Totp::at($secret)]) === null) {
            $violations[] = 'a correct code was accepted while the account was locked out, so the second '.
                'stage consults no limit at all';
        }

        if (auth()->guard('web')->check()) {
            $violations[] = 'the throttled attempt left somebody signed in';
        }

        return $violations;
    }

    /**
     * A user with no factor signs in as before - and asserts nothing.
     *
     * Both halves matter. Breaking the first locks out every user who has not
     * enrolled, which is most of them; breaking the second satisfies every
     * MFA requirement in the system with a password, which is the control
     * deleting itself.
     *
     * @return list<string>
     */
    public function passwordOnlySessionAssertsNoFactor(): array
    {
        $permission = $this->mfaOnlyPermission();
        [$user, $password] = $this->probeUser();
        $violations = [];

        $this->freshBrowser();
        $refusal = $this->submit(['email' => $user->email, 'password' => $password]);

        if ($refusal !== null) {
            return [sprintf('a user with no second factor could not sign in at all: %s', $refusal)];
        }

        if (! auth()->guard('web')->check()) {
            $violations[] = 'a user with no second factor was left unauthenticated by a correct password, '.
                'so they are being asked for a code they cannot produce';
        }

        if ($this->session?->has('factor_asserted_at') === true) {
            $violations[] = 'a password-only sign-in wrote factor_asserted_at, so every permission that '.
                'demands a factor is satisfied by a password';
        }

        if ($permission === null) {
            return $violations;
        }

        try {
            app(StepUpGuard::class)->assert($permission, user: $user);

            $violations[] = sprintf(
                '"%s" was allowed to a session established without a factor',
                $permission,
            );
        } catch (StepUpRequired) {
            // Correct: enrolled or not, this session asserted nothing.
        }

        return $violations;
    }

    /**
     * The two planes are two guards, and a sign-in on one is not a sign-in on
     * the other.
     *
     * An operator who could be authenticated by a tenant sign-in would reach
     * the console with a customer's credentials; a tenant user authenticated
     * on the `ops` guard would read customer data unattributably, because
     * nothing in that tenant's own audit log would say they had.
     *
     * @return list<string>
     */
    public function planesStaySeparate(): array
    {
        [$user, $password, $secret] = $this->probeUserWithFactor();
        $violations = [];

        $this->freshBrowser();
        $this->submit(['email' => $user->email, 'password' => $password]);
        $this->submit(['code' => Totp::at($secret)]);

        if (! auth()->guard('web')->check()) {
            return ['the tenant sign-in did not complete, so the plane separation could not be read'];
        }

        if (auth()->guard('ops')->check()) {
            $violations[] = 'a tenant sign-in also authenticated the operator guard, so a customer\'s '.
                'credentials reach the operator console';
        }

        /*
         | And the tenant user's own id must not authenticate on `ops`.
         |
         | The guards resolve different providers against different
         | databases, and this is the probe that says so out loud: the id
         | exists, and on the operator plane it is nobody.
         */
        try {
            if (auth()->guard('ops')->loginUsingId($user->getKey()) !== false) {
                $violations[] = 'a tenant user id authenticated on the operator guard, so the two planes '.
                    'share a user provider';
            }
        } catch (Throwable $e) {
            // A failure to even look the id up is the separation working; a
            // refusal is what this asserts, not the shape of it.
            if (! str_contains(mb_strtolower($e->getMessage()), 'operator')) {
                $violations[] = sprintf(
                    'the operator guard failed in an unexpected way rather than refusing: %s',
                    Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 120),
                );
            }
        } finally {
            auth()->guard('ops')->logout();
        }

        return $violations;
    }

    /**
     * The printed fallback works, once.
     *
     * A recovery code that can be used twice is a password that never
     * changes, printed on paper, and the user has ten of them.
     *
     * @return list<string>
     */
    public function recoveryCodeWorksOnce(): array
    {
        [$user, $password] = $this->probeUserWithFactor();
        $codes = app(MfaService::class)->regenerateRecoveryCodes($user, 2);
        $code = (string) $codes->first();
        $violations = [];

        $this->freshBrowser();
        $this->submit(['email' => $user->email, 'password' => $password]);

        if ($this->submit(['code' => $code]) !== null) {
            return ['a freshly issued recovery code was refused, so a user whose phone is lost has no way '.
                'in at all'];
        }

        if (! auth()->guard('web')->check()) {
            $violations[] = 'the recovery code was accepted and signed nobody in';
        }

        $this->freshBrowser();
        $this->submit(['email' => $user->email, 'password' => $password]);

        if ($this->submit(['code' => $code]) === null) {
            $violations[] = 'the same recovery code was accepted twice, so a list somebody photographed '.
                'stays usable';
        }

        if (auth()->guard('web')->check()) {
            $violations[] = 'the reused recovery code left somebody signed in';
        }

        return $violations;
    }

    /**
     * The second stage has to be reachable from a browser.
     *
     * A challenge nobody can answer is a lockout, not a control: there is no
     * route of its own for it - Fortify would have brought one - so the
     * sign-in page itself has to turn into the code form, and this is the
     * check that says it does.
     *
     * The password field being ABSENT is the half that matters for safety.
     * A challenge form that asks for the credentials again would let whoever
     * holds the page aim an accepted code at a different account, which is
     * the whole reason the pending challenge names the user and the form
     * does not.
     *
     * @return list<string>
     */
    public function challengeFormAsksForACode(): array
    {
        [$user, $password] = $this->probeUserWithFactor();
        $violations = [];

        $this->freshBrowser();

        if (str_contains($this->renderSignIn(), 'name="code"')) {
            $violations[] = 'the sign-in page offers a code field before any password has been accepted, '.
                'so the two stages are one form and the challenge is optional';
        }

        $this->submit(['email' => $user->email, 'password' => $password]);
        $rendered = $this->renderSignIn();

        if (! str_contains($rendered, 'name="code"')) {
            $violations[] = 'the page a visitor is sent to after the password stage has no code field, so '.
                'every user with a factor is locked out rather than challenged';
        }

        if (str_contains($rendered, 'name="password"')) {
            $violations[] = 'the challenge form asks for the password again, so a code accepted for one '.
                'account can be submitted with another account\'s credentials';
        }

        return $violations;
    }

    /** The sign-in page as a browser would receive it. */
    private function renderSignIn(): string
    {
        $request = Request::create('/login', 'GET', server: [
            'REMOTE_ADDR' => $this->ip,
            'HTTP_USER_AGENT' => self::AGENT,
            'HTTP_ACCEPT_LANGUAGE' => 'ar',
        ]);

        $request->setLaravelSession($this->session);

        /*
         | What `ShareErrorsFromSession` would have shared.
         |
         | The view reads `$errors`, which that middleware puts there on
         | every web request. Rendering outside the middleware stack without
         | it fails on an undefined variable, which would be reported here as
         | "there is no code field" - a true statement about a page that
         | never rendered, and a misleading one about the form.
         */
        app(ViewFactory::class)->share('errors', new ViewErrorBag());

        try {
            return app(LoginController::class)->show($request)->render();
        } catch (Throwable $e) {
            return sprintf('(the sign-in page could not be rendered: %s)', $e->getMessage());
        }
    }

    /**
     * Drive `POST /login` the way a browser does, and say whether it refused.
     *
     * Returns null when the controller accepted the submission, and the
     * refusal message when it did not. Anything other than a validation
     * refusal is returned as its own message: a sign-in that fails
     * differently answers with a different status code, which is the oracle
     * `WiringRules` exists to keep shut.
     *
     * @param  array<string, string>  $input
     */
    private function submit(array $input, ?string $agent = null): ?string
    {
        $request = Request::create('/login', 'POST', $input, server: [
            'REMOTE_ADDR' => $this->ip,
            'HTTP_USER_AGENT' => $agent ?? self::AGENT,
            'HTTP_ACCEPT_LANGUAGE' => 'ar',
        ]);

        $request->setLaravelSession($this->session);

        try {
            app(LoginController::class)->store($request);

            return null;
        } catch (ValidationException $e) {
            return implode(' ', $e->errors()['email'] ?? ['(no message)']);
        } catch (Throwable $e) {
            return sprintf('%s: %s', class_basename($e), Str::limit(
                preg_replace('/\s+/', ' ', $e->getMessage()) ?? '',
                160,
            ));
        }
    }

    /** A new browser: nobody signed in, nothing remembered, the workspace known. */
    private function freshBrowser(): void
    {
        auth()->guard('web')->logout();

        $this->session?->flush();
        $this->session?->migrate(destroy: true);
        $this->session?->put('tenant', $this->slug);
    }

    /**
     * A user who can sign in, with a confirmed TOTP authenticator.
     *
     * The authenticator is written directly rather than enrolled through the
     * ceremony: {@see TenantEnforcementRules::totpCodeWorksOnce()} proves the
     * ceremony, and going through it here would consume the current time step
     * and leave this suite unable to use a code for thirty seconds.
     *
     * @return array{0: User, 1: string, 2: string}
     */
    private function probeUserWithFactor(): array
    {
        [$user, $password] = $this->probeUser();
        $secret = Totp::generateSecret();

        $authenticator = new Authenticator([
            'user_id' => $user->getKey(),
            'type' => Authenticator::TYPE_TOTP,
            'name' => 'identity coverage probe',
            'confirmed_at' => now(),
            'sign_count' => 0,
        ]);

        $authenticator->setSecret($secret);
        $authenticator->save();

        return [$user, $password, $secret];
    }

    /** @return array{0: User, 1: string} */
    private function probeUser(): array
    {
        $password = Str::password(32);

        $user = User::create([
            'name' => 'Identity coverage probe',
            'email' => 'identity-probe-'.bin2hex(random_bytes(5)).'@invalid.test',
            'password' => $password,
            'active' => true,
        ]);

        $this->spentLimiterKeys[] = 'login:account:'.hash('sha256', Str::lower(trim((string) $user->email)));

        return [$user, $password];
    }

    /**
     * A permission that demands a factor in the SESSION and nothing further.
     *
     * One that also requires step-up would be refused after the code too -
     * correctly, for a different reason - and the check would then pass
     * whether or not the session half works.
     */
    private function mfaOnlyPermission(): ?string
    {
        $permission = Permission::query()
            ->where('requires_mfa', true)
            ->where('requires_step_up', false)
            ->orderBy('key')
            ->first();

        return $permission === null ? null : (string) $permission->key;
    }

    /**
     * How many refusals this user has accumulated, read from the trail itself.
     *
     * Read on the ordinary tenant connection although a refusal is written on
     * one of its own: the refusal is committed by the time this runs, and
     * PostgreSQL's default isolation shows it to a later statement in this
     * suite's open transaction. The audit table is tenant-scoped rather than
     * entity-scoped, so a sign-in refusal - which has no entity - is readable
     * with none established.
     */
    private function refusalsRecorded(User $user): int
    {
        return DB::connection('tenant')->table('audit_log')
            ->where('action', 'session.factor_refused')
            ->where('subject_id', (string) $user->getKey())
            ->count();
    }

    /**
     * Point the guard, the helper and the controller at one array store.
     */
    private function useArraySession(): void
    {
        $this->originalDriver = (string) config('session.driver');

        config(['session.driver' => 'array']);

        app()->forgetInstance('session');
        app()->forgetInstance('session.store');
        app(AuthManager::class)->forgetGuards();

        $session = app(SessionManager::class)->driver();
        $session->start();

        $this->session = $session;
    }

    private function restoreSession(): void
    {
        auth()->guard('web')->logout();
        $this->session?->flush();

        config(['session.driver' => $this->originalDriver]);

        app()->forgetInstance('session');
        app()->forgetInstance('session.store');
        app(AuthManager::class)->forgetGuards();

        $this->session = null;
    }
}
