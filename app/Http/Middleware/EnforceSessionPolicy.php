<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenant\Identity\Models\User;
use App\Support\Limits\Ceilings;
use App\Tenant\Identity\Services\AuditLog;
use App\Tenant\Identity\Services\DeviceFingerprint;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle timeout, absolute timeout, and device binding - PF-029.
 *
 * ## Two timeouts, because they answer different questions
 *
 * The IDLE timeout asks "has this person walked away?" and is refreshed by
 * activity. Laravel's `session.lifetime` already does this.
 *
 * The ABSOLUTE timeout asks "how long may one authentication be trusted,
 * however busy the user is?" and is NOT refreshed. Without it, a session kept
 * warm by a polling dashboard never expires - and a session that never expires
 * is a credential, held in a cookie, with no rotation policy. Eight hours
 * matches a working day: long enough that nobody is interrupted mid-task, and
 * short enough that a laptop left at a client site stops being useful
 * overnight.
 *
 * ## Device binding ends the session rather than challenging it
 *
 * A changed fingerprint is not proof of theft - a browser auto-updated its
 * User-Agent and the hash moved. So the response is to require
 * re-authentication, not to lock the account or alarm anybody. The cost to a
 * legitimate user is one sign-in; the cost to someone replaying a stolen
 * cookie is the whole session.
 */
final class EnforceSessionPolicy
{
    /**
     * Not refreshed by activity. See the class note.
     *
     * Bounded from above by the platform ceiling, so a workspace cannot
     * stretch its own sessions past what the platform considers safe.
     */
    private const ABSOLUTE_LIFETIME_MINUTES = 480;

    public function __construct(
        private readonly AuditLog $audit,
        private readonly Ceilings $ceilings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->user() === null) {
            return $next($request);
        }

        $reason = $this->expiryReason($request);

        if ($reason !== null) {
            return $this->endSession($request, $reason);
        }

        return $next($request);
    }

    private function expiryReason(Request $request): ?string
    {
        $startedAt = $request->session()->get('authenticated_at');

        $absolute = $this->ceilings->clamp(
            'ceiling.session_absolute_minutes',
            self::ABSOLUTE_LIFETIME_MINUTES,
        )['value'];

        if (is_string($startedAt) && Carbon::parse($startedAt)->addMinutes($absolute)->isPast()) {
            return 'the session reached its absolute lifetime';
        }

        if (DeviceFingerprint::changed($request->session()->get('device'), $request)) {
            return 'the device binding changed';
        }

        $user = $request->user();

        // A user deactivated mid-session must stop being able to act now, not
        // at their next sign-in attempt.
        if ($user instanceof User && ! $user->active) {
            return 'the account was deactivated';
        }

        return null;
    }

    private function endSession(Request $request, string $reason): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->audit->record(
                category: 'identity',
                action: 'session.ended',
                subjectType: User::class,
                subjectId: (string) $user->getKey(),
                subjectLabel: $user->name,
                reason: $reason,
                actor: $user,
            );
        }

        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $reason], Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->route('login')->with('session_ended', $reason);
    }
}
