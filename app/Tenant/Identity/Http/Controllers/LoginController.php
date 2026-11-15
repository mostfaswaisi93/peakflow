<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Http\Controllers;

use App\Platform\Exceptions\TenantNotResolvable;
use App\Platform\Models\ActivityLog;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\AuditLog;
use App\Tenant\Identity\Services\DeviceFingerprint;
use App\Tenant\Identity\Services\LoginThrottle;
use App\Tenant\Identity\Services\MfaService;
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
 */
final class LoginController
{
    public function __construct(
        private readonly LoginThrottle $throttle,
        private readonly MfaService $mfa,
        private readonly AuditLog $audit,
        private readonly TenantConnectionResolver $resolver,
    ) {}

    public function show(Request $request): View
    {
        return view('auth.login', [
            // Only ask when we do not already know. A user returning to a
            // workspace they have used should not have to remember its slug.
            'needsWorkspace' => ! is_string($request->session()->get('tenant')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
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

        $this->throttle->recordSuccess($email);

        /*
         | Session fixation: a NEW session id before anything is written to it.
         | Without this, an attacker who can set a victim's cookie before they
         | sign in keeps a valid, now-authenticated session id.
         */
        $request->session()->regenerate();

        auth()->guard('web')->login($user, remember: false);

        $request->session()->put('authenticated_at', now()->toIso8601String());
        $request->session()->put('device', DeviceFingerprint::for($request));

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();

        $this->audit->record(
            category: 'identity',
            action: 'session.started',
            subjectType: User::class,
            subjectId: (string) $user->getKey(),
            subjectLabel: $user->name,
            reason: $this->mfa->hasConfirmedFactor($user)
                ? 'password accepted; a second factor is enrolled and will be required for guarded actions'
                : 'password accepted; this user has no second factor',
            actor: $user,
        );

        return redirect()->intended(route('home'));
    }

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
