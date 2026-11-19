<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenant\Identity\Models\User;
use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Services\EntityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes which entity this request acts in - PF-026, PF-027.
 *
 * Runs AFTER authentication, unlike the tenant resolvers: the entity depends
 * on who the user is, because the answer to "may you act in this company" is
 * a property of their roles.
 *
 * ## The claim is re-checked every request
 *
 * The active entity is remembered in the session, and the session is a thing
 * the user holds. So the role is looked up again on every request rather than
 * trusted from the cookie - a user whose access to a subsidiary was revoked
 * five minutes ago must stop being able to post into it now, not at their next
 * login.
 *
 * The cost is one indexed query per request, which is the correct price.
 */
final class ResolveEntity
{
    public function __construct(private readonly EntityContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         | This resolver needs a SESSION, and says so rather than finding out.
         |
         | It reads `entity_id` from the session and writes it back, twice,
         | unconditionally. On a request with no session store - any route
         | outside the web group - that is `Session store not set on
         | request`, a framework exception with no hint about what was
         | misconfigured or what to use instead.
         |
         | Only web routes mount `entity.scope` today, so this is a trap
         | rather than a live fault. The trap is worth closing because the
         | API deliberately has its own resolver: `api.entity` reads
         | `X-Entity`, checks it against the TOKEN's entity list and then
         | against the owner's grants, and a machine must not fall back to
         | "the entity this user last looked at in a browser". Somebody
         | reaching for `entity.scope` on an API route is reaching for the
         | wrong one, and this is the sentence that tells them.
         |
         | A LogicException and not a refusal: a refusal is an answer to the
         | caller, and there is no caller at fault here - the route is
         | misconfigured, which is a programming mistake and belongs in the
         | log with a stack trace.
         */
        if (! $request->hasSession()) {
            throw new \LogicException(
                'ResolveEntity needs a session and this request has none, so `entity.scope` is on a '.
                'route outside the web group. The API has its own resolver: use `api.entity`, which '.
                'reads X-Entity against the token\'s own entity list rather than falling back to '.
                'whichever company this user last opened in a browser.',
            );
        }

        $user = $request->user();

        if (! $user instanceof User) {
            // Unauthenticated. The auth middleware answers this, not us -
            // reporting "no entity" for a request with no user would send a
            // confusing problem document for an ordinary sign-in redirect.
            return $next($request);
        }

        $requested = $this->requestedEntityId($request, $user);

        if ($requested === null) {
            /*
             | The user holds roles in several entities and has not chosen
             | one - a first sign-in, or a session that was torn down.
             |
             | Refusing here is what the first version did, and it answered a
             | first-ever login with a 400. Picking one for them is worse:
             | silently landing somebody in whichever company sorted first is
             | precisely the "posting into the wrong company" failure this
             | whole design exists to prevent.
             |
             | So they are asked. One entity still resolves without a
             | question, because there is no choice to make.
             */
            if ($request->expectsJson()) {
                throw EntityNotInScope::forQueryOn(Entity::class);
            }

            return redirect()->route('entity.choose');
        }

        // Authorization. Asked of the grants, not of the session.
        if (! $user->entityIds()->contains($requested)) {
            throw EntityNotInScope::notPermitted($requested);
        }

        $this->context->use($requested);

        $entity = $this->context->entity();

        if ($entity === null || ! $entity->active) {
            throw EntityNotInScope::notPermitted($requested);
        }

        // Remember it for the next request, and across devices.
        if ($user->last_entity_id !== $requested) {
            $user->forceFill(['last_entity_id' => $requested])->saveQuietly();
        }

        $request->session()->put('entity_id', $requested);

        try {
            return $next($request);
        } finally {
            // A pooled connection must not carry this entity into the next
            // request, which may belong to a different user entirely.
            $this->context->forget();
        }
    }

    /**
     * Which entity the request is asking for, in order of explicitness.
     *
     * An explicit header wins over the session so that an API client can act
     * in a named entity without first performing a switch, and so that a
     * server-rendered page and an API call from it cannot disagree.
     */
    private function requestedEntityId(Request $request, User $user): ?string
    {
        $header = $request->header('X-Entity');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $session = $request->session()->get('entity_id');

        if (is_string($session) && $session !== '') {
            return $session;
        }

        if (is_string($user->last_entity_id) && $user->last_entity_id !== '') {
            return $user->last_entity_id;
        }

        // A user with exactly one entity never sees a switcher, so falling
        // back to it is not a guess.
        $only = $user->entityIds();

        return $only->count() === 1 ? (string) $only->first() : null;
    }
}
