<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Support\Audit\AuditTrail;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is acting, and whether their token belongs on this plane - PF-218.
 *
 * Runs immediately after `auth:sanctum`, and does two things that belong
 * together because both answer "is this token allowed to be here at all".
 *
 * ## The plane is checked, and it is checked against the ROW
 *
 * An operator of the platform and a user of a tenant both authenticate with a
 * bearer token, and both tokens are rows in a table called `api_tokens`. They
 * must never be interchangeable: an operator acting on a tenant route would
 * read a customer's figures with nothing in that tenant's own audit log
 * saying they had, which is the single thing the operator console exists to
 * prevent - reaching customer data requires impersonation, which requires the
 * tenant's prior consent.
 *
 * "They are in different databases" is true until the morning somebody
 * restores a dump into the wrong one, or a provisioning bug points the tenant
 * connection at the platform. So the plane is a column, this middleware takes
 * the plane the route serves as a parameter, and a mismatch is refused.
 *
 * The tokenable class is checked too, and the two checks are not redundant:
 * the plane says who issued the token and the class says what the guard just
 * loaded. A row whose plane is right and whose `tokenable_type` is an
 * `Operator` would otherwise authenticate an operator into a tenant request,
 * where `$user->holds()` does not even exist.
 *
 * ## The refusal is a 401 and says nothing about why
 *
 * Not a 403. Telling the holder of a platform token that it is "the wrong
 * plane" confirms that the token is real and that it authenticated - which is
 * the one fact worth having. The same answer as a token that does not exist,
 * for the same reason a hidden record is reported as missing.
 *
 * The attempt IS recorded, as a refusal on its own connection, because a
 * cross-plane token presentation is either a serious bug or somebody probing
 * and both are worth a line somebody can find.
 *
 * ## And the application is told who is acting
 *
 * The whole tenant plane names the acting user through the `web` guard:
 * `AuditLog` reads it to attribute a record, `Poster` to stamp
 * `posted_by_user_id`, `SodEngine` to ask whether this person already
 * performed the conflicting duty. Twenty call sites, written long before
 * there was an API.
 *
 * A token-authenticated request has no session, so every one of those reads
 * answers null - and a posting with no author, or a duty recorded against
 * nobody, is worse than a refusal: `AuditLog`'s own note says a duty with no
 * actor "conflicts with nothing and silently weakens every rule that would
 * have matched it". So the guard is told who the token belongs to.
 *
 * `setUser()` and not `login()`: there is no session to write to, and
 * creating one for a machine would hand back a cookie nobody asked for. The
 * user is forgotten again in a `finally`, because a pooled worker must not
 * carry this request's actor into the next request, which may be somebody
 * else's.
 */
final class EstablishApiActor
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Request $request, Closure $next, string $plane = ApiToken::PLANE_TENANT): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $user instanceof User || ! $token instanceof ApiToken) {
            /*
             | Either nothing authenticated, or something authenticated that
             | is not a tenant user holding one of our token rows - a session
             | cookie accepted by a misconfigured guard, say, whose
             | `currentAccessToken()` is Sanctum's TransientToken and answers
             | `can()` with true for every ability.
             |
             | That is exactly the second, weaker authorization model PF-218
             | forbids, so it is refused here as well as being switched off in
             | config/sanctum.php.
             */
            return $this->refuse($request, $plane, 'no API token authenticated this request');
        }

        /*
         | Revoked or expired is an AUTHENTICATION failure, answered here.
         |
         | Sanctum's guard checks `expires_at` and knows nothing about
         | revocation, so a revoked token authenticates and is then refused
         | by the scope check - as a 403 saying the token lacks the scope.
         | That is true and misleading: the holder re-issues a token, which
         | works, and never learns that the old one was revoked after a leak.
         |
         | 401 is the honest answer, and it is the same answer as a token
         | that never existed.
         */
        if (! $token->isLive()) {
            return $this->refuse(
                $request,
                $plane,
                'the token is revoked or expired',
                (string) $token->getKey(),
            );
        }

        if (! $token->belongsToPlane($plane) || ! $this->tokenableFits($token, $plane)) {
            return $this->refuse(
                $request,
                $plane,
                sprintf(
                    'the token is on the %s plane and names %s',
                    (string) $token->plane,
                    (string) $token->tokenable_type,
                ),
                (string) $token->getKey(),
            );
        }

        auth()->guard('web')->setUser($user);

        try {
            return $next($request);
        } finally {
            auth()->guard('web')->forgetUser();
        }
    }

    /**
     * Does the token's tokenable class belong to the plane it claims?
     *
     * The tenant plane has exactly one population of people who hold roles in
     * entities, and that is {@see User}. The platform plane has operators,
     * and no route serves it yet - so a platform token reaching any route
     * today is a mistake whatever it names.
     */
    private function tokenableFits(ApiToken $token, string $plane): bool
    {
        return $plane === ApiToken::PLANE_TENANT
            && $token->tokenable_type === (new User())->getMorphClass();
    }

    private function refuse(Request $request, string $plane, string $why, ?string $tokenId = null): Response
    {
        $this->audit->refusal(
            category: 'identity',
            action: 'api_token.refused',
            subjectType: 'api_tokens',
            /*
             | WHICH token, where there was one.
             |
             | Without it the only way to find this record is by time, and
             | "a refusal in the last five minutes" is a question whose
             | answer changes depending on what else happened in the last
             | five minutes - which is exactly how a check passes on an
             | earlier run's data.
             */
            subjectId: $tokenId,
            subjectLabel: sprintf('a request to the %s plane was refused', $plane),
            after: ['path' => $request->getPathInfo(), 'reason' => $why],
            reason: 'A token from another plane, a dead one, or no token at all, reached a plane it may not act on.',
        );

        return Problem::of(ProblemType::Unauthenticated)->toResponse($request);
    }
}
