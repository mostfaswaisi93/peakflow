<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenant\Identity\Services\StepUpGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a fresh factor assertion for one route - PF-032.
 *
 * Usage: `->middleware('step-up:payment.release,run')` where `run` is the
 * route parameter naming the subject the assertion must cover.
 *
 * ## Independent of session age
 *
 * A session that was legitimately MFA-authenticated an hour ago says nothing
 * about who is at the keyboard now. The assertion is scoped to the specific
 * act and spent when the act happens, so approving one payment does not
 * silently approve the next - which a "re-authenticated recently" flag would.
 *
 * The middleware is the outer layer, not the only one. `StepUpGuard::assert()`
 * is called again on the write path next to the SoD check, because a route is
 * one way to reach a write and queued jobs, console commands and future
 * internal callers are others.
 */
final class RequireStepUp
{
    public function __construct(private readonly StepUpGuard $guard) {}

    public function handle(
        Request $request,
        Closure $next,
        string $permission,
        ?string $subjectParameter = null,
    ): Response {
        $subjectId = $subjectParameter === null
            ? null
            : $this->subjectFrom($request, $subjectParameter);

        // Throws StepUpRequired, which renders as a problem document telling
        // the user to confirm rather than telling them they are forbidden.
        $this->guard->assert($permission, $subjectId);

        return $next($request);
    }

    private function subjectFrom(Request $request, string $parameter): ?string
    {
        $value = $request->route($parameter);

        if (is_string($value)) {
            return $value;
        }

        // A route-bound model: take its key, so the assertion is scoped to the
        // same identifier the write path will use.
        if (is_object($value) && method_exists($value, 'getKey')) {
            $key = $value->getKey();

            return $key === null ? null : (string) $key;
        }

        return null;
    }
}
