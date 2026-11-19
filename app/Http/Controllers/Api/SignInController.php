<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Middleware\ResolveTenantFromHeader;
use App\Platform\Exceptions\TenantNotResolvable;
use App\Platform\Models\ActivityLog;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Http\Api\Wire;
use App\Tenant\Identity\Exceptions\DeviceSessionRefused;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\ApiSignInChallenge;
use App\Tenant\Identity\Services\AuditLog;
use App\Tenant\Identity\Services\DeviceSessions;
use App\Tenant\Identity\Services\IssuedSession;
use App\Tenant\Identity\Services\LoginThrottle;
use App\Tenant\Identity\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Signing a person in on a device - PF-260.
 *
 * The browser's {@see \App\Http\Controllers\LoginController}, for a client that
 * has no session and no cookie jar: the same refusals, the same throttle, the
 * same audit trail, and the same two stages for a person with a second
 * factor. What it hands back is a pair of bearer credentials rather than a
 * session cookie - see {@see DeviceSessions}.
 *
 * ## One refusal, always
 *
 * An unknown workspace, an unknown address, a wrong password, a deactivated
 * account and a wrong code are one problem type with one sentence. The audit
 * log records which it was. The browser's form made this decision first and
 * explains it; an API that answered differently would be the oracle the form
 * refuses to be, reachable by script.
 *
 * ## Why this is in the HTTP layer
 *
 * For the reason the browser's is: the workspace is resolved from the
 * PLATFORM database and the person from the TENANT's, and only the
 * application layer may reach both.
 *
 * ## Why the workspace is in the body here
 *
 * Everywhere else it is `X-Tenant`, resolved by middleware before
 * authentication. Here the person TYPES it, and an unknown one must be
 * refused exactly like a wrong password - which a middleware that answers
 * `400 tenant-required` cannot do. So it is a field, resolved in this
 * controller, and the response hands back the tenant id the app sends as
 * `X-Tenant` from then on.
 */
final class SignInController
{
    public function __construct(
        private readonly TenantConnectionResolver $resolver,
        private readonly LoginThrottle $throttle,
        private readonly MfaService $mfa,
        private readonly AuditLog $audit,
        private readonly ApiSignInChallenge $challenge,
        private readonly DeviceSessions $sessions,
    ) {}

    /**
     * The password stage.
     *
     * @no-permission-required: this IS authentication. Requiring a permission
     * to sign in would require being signed in to sign in.
     */
    public function signIn(Request $request): JsonResponse
    {
        $input = $request->validate([
            'workspace' => ['required', 'string', 'max:64'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['required', 'string', 'max:64'],
            'entity_id' => ['nullable', 'string', 'size:26'],
        ]);

        $email = (string) $input['email'];
        $ip = (string) $request->ip();
        $device = trim((string) $input['device_name']);

        $tenant = $this->workspace((string) $input['workspace']);

        if (! $tenant instanceof Tenant) {
            $this->throttle->recordFailure($email, $ip);
            $this->refuseWithoutTenant($request, $email, 'unknown or unusable workspace');

            throw DeviceSessionRefused::signIn('unknown or unusable workspace');
        }

        $retryAfter = $this->throttle->retryAfter($email, $ip);

        if ($retryAfter !== null) {
            $this->refuse($request, $email, 'throttled');

            throw DeviceSessionRefused::throttled($retryAfter);
        }

        $user = User::query()->where('email', $email)->first();

        // Computed whether or not the user exists - see LoginController: a
        // missing user must take as long as a real one, or the timing answers
        // the question the message refuses to.
        $passwordMatches = $user === null
            ? Hash::check((string) $input['password'], $this->dummyHash())
            : Hash::check((string) $input['password'], (string) $user->getAuthPassword());

        if (! $user instanceof User || ! $passwordMatches || ! $user->active) {
            $this->throttle->recordFailure($email, $ip);

            $because = match (true) {
                ! $user instanceof User => 'no such user',
                ! $passwordMatches => 'wrong password',
                default => 'the account is deactivated',
            };

            $this->refuse($request, $email, $because);

            throw DeviceSessionRefused::signIn($because);
        }

        /*
         | A person with a confirmed factor is not signed in by a password.
         |
         | The same rule the browser keeps, and for the same reason: the
         | password stage opens a challenge and issues nothing. The throttle
         | budget is NOT cleared here - see LoginController::establishSession:
         | clearing it at the password stage hands somebody holding a phished
         | password fresh guesses at the code for every re-submission.
         */
        if ($this->mfa->hasConfirmedFactor($user)) {
            $pending = $this->challenge->begin(
                $user,
                (string) $tenant->getKey(),
                $device,
                isset($input['entity_id']) ? (string) $input['entity_id'] : null,
            );

            return new JsonResponse([
                'resource' => 'second_factor_challenge',
                'challenge' => $pending['challenge'],
                'expires_at' => Wire::timestamp($pending['expires_at']),
                'accepts' => ['totp', 'recovery_code'],
            ], JsonResponse::HTTP_ACCEPTED, ['Cache-Control' => 'no-store']);
        }

        return $this->establish(
            $request,
            $tenant,
            $user,
            $device,
            null,
            isset($input['entity_id']) ? (string) $input['entity_id'] : null,
        );
    }

    /**
     * The code stage: the only path that opens a session for a person with a factor.
     *
     * @no-permission-required: this IS authentication - the second half of the
     * sign-in the password stage refused to complete.
     */
    public function secondFactor(Request $request): JsonResponse
    {
        $input = $request->validate([
            'workspace' => ['required', 'string', 'max:64'],
            'challenge' => ['required', 'string', 'max:4096'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $ip = (string) $request->ip();

        // Bounded before anything is done with it - a recovery code is
        // checked with bcrypt against every unused one the person has.
        $code = mb_substr(trim((string) $input['code']), 0, 64);

        $tenant = $this->workspace((string) $input['workspace']);

        if (! $tenant instanceof Tenant) {
            $this->throttle->recordFailure('', $ip);
            $this->refuseWithoutTenant($request, '', 'a code for an unknown or unusable workspace');

            throw DeviceSessionRefused::signIn('unknown or unusable workspace at the code stage');
        }

        $tenantId = (string) $tenant->getKey();
        $pending = $this->challenge->pendingUser((string) $input['challenge'], $tenantId);

        /*
         | The throttle at THIS stage too, against the account the challenge
         | names: a six-digit code has a million values, and whoever is
         | guessing already has the password.
         */
        if ($pending instanceof User) {
            $retryAfter = $this->throttle->retryAfter($pending->email, $ip);

            if ($retryAfter !== null) {
                $this->refuse($request, $pending->email, 'throttled at the second-factor stage');

                throw DeviceSessionRefused::throttled($retryAfter);
            }
        }

        $completed = $this->challenge->complete((string) $input['challenge'], $tenantId, $code);

        if ($completed === null) {
            $this->throttle->recordFailure($pending instanceof User ? $pending->email : '', $ip);

            throw DeviceSessionRefused::signIn('the code or the challenge was not accepted');
        }

        return $this->establish(
            $request,
            $tenant,
            $completed['user'],
            $completed['device_name'],
            $completed['factor'],
            $completed['entity_id'],
        );
    }

    /**
     * Spend a refresh token for the next pair of credentials.
     *
     * @no-permission-required: the refresh token IS the credential, and what
     * the person may do is re-read from their grants on every exchange.
     */
    public function refresh(Request $request): JsonResponse
    {
        /*
         | Its own bucket, per address, before anything else is looked at.
         |
         | The one unauthenticated call an app makes routinely, so the one a
         | script trying stolen or guessed tokens would aim at. A refresh
         | token is 64 random characters, so guessing is hopeless - the
         | bucket is what keeps hopeless from also being expensive.
         */
        $bucket = 'api-refresh:'.hash('sha256', (string) $request->ip());
        $limit = (int) config('api.rate_limits.refreshes_per_minute', 30);

        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            throw DeviceSessionRefused::throttled(RateLimiter::availableIn($bucket));
        }

        RateLimiter::hit($bucket, 60);

        $input = $request->validate([
            'refresh_token' => ['required', 'string', 'max:200'],
            'entity_id' => ['nullable', 'string', 'size:26'],
        ]);

        $key = $request->header(ResolveTenantFromHeader::HEADER);

        if (! \is_string($key) || trim($key) === '' || ! $this->workspace(trim($key)) instanceof Tenant) {
            throw DeviceSessionRefused::refresh('the X-Tenant header named no usable workspace');
        }

        $issued = $this->sessions->refresh(
            (string) $input['refresh_token'],
            isset($input['entity_id']) ? (string) $input['entity_id'] : null,
            $request,
        );

        /** @var Tenant $tenant */
        $tenant = app('currentTenant');

        return $this->sessionResponse($tenant, $issued, JsonResponse::HTTP_OK);
    }

    /**
     * Turn an accepted sign-in into a session - one place, for both stages.
     *
     * @throws DeviceSessionRefused
     */
    private function establish(
        Request $request,
        Tenant $tenant,
        User $user,
        string $device,
        ?string $factor,
        ?string $entityId,
    ): JsonResponse {
        $entity = $this->sessions->chooseEntity($user, $entityId);
        $issued = $this->sessions->open($user, $device, $factor, $entity, $request);

        // Cleared HERE, once a session exists - see LoginController.
        $this->throttle->recordSuccess($user->email);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => (string) $request->ip(),
        ])->saveQuietly();

        return $this->sessionResponse($tenant, $issued, JsonResponse::HTTP_CREATED);
    }

    private function sessionResponse(Tenant $tenant, IssuedSession $issued, int $status): JsonResponse
    {
        return new JsonResponse(
            DeviceSessionPresenter::session($tenant, $issued, $this->sessions),
            $status,
            /*
             | `no-store`, because the body IS the credential. A shared cache,
             | a service worker or a debugging proxy that kept this response
             | would be keeping a password.
             */
            ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        );
    }

    /**
     * Point the tenant connection at the workspace, or say there is none.
     *
     * Null rather than throwing, so the caller refuses an unknown workspace
     * exactly as it refuses a wrong password.
     */
    private function workspace(string $key): ?Tenant
    {
        if (trim($key) === '') {
            return null;
        }

        try {
            return $this->resolver->resolve(trim($key));
        } catch (TenantNotResolvable) {
            return null;
        }
    }

    /** What actually happened, where only staff can read it. */
    private function refuse(Request $request, string $email, string $because): void
    {
        $this->audit->refusal(
            category: 'identity',
            action: 'device_session.refused',
            subjectLabel: $email,
            after: ['ip' => (string) $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 300, '')],
            reason: $because,
        );
    }

    /**
     * A refusal with no tenant to record it in - the platform's log, with the
     * address hashed. See LoginController::refuse() for why.
     */
    private function refuseWithoutTenant(Request $request, string $email, string $because): void
    {
        ActivityLog::create([
            'tenant_id' => null,
            'category' => 'identity',
            'action' => 'device_session.refused',
            'context' => [
                'workspace_attempted' => $request->string('workspace')->limit(64)->value(),
                'email_sha256' => $email === '' ? null : hash('sha256', Str::lower(trim($email))),
                'user_agent' => Str::limit((string) $request->userAgent(), 300, ''),
                'because' => $because,
            ],
            'ip_address' => (string) $request->ip(),
        ]);
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('peakflow-timing-equalizer-'.config('app.key'));
    }
}
