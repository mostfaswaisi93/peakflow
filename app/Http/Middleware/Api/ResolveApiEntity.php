<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\User;
use App\Tenant\Tenancy\Services\EntityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which entity an API request acts in - PF-218, PF-026.
 *
 * ## Why the API does not share `ResolveEntity`
 *
 * That middleware resolves an entity for a BROWSER: it falls back to the
 * session, then to the user's last entity, then to their only one, and it
 * redirects to a chooser when it cannot decide. Every one of those is right
 * for a person and wrong for a machine.
 *
 * It also reads and writes `$request->session()`, and an API request has no
 * session at all - so sharing it would mean a middleware that throws on the
 * first request, or one with an `if (api)` branch through the middle of it,
 * which is two middlewares in one file pretending to be shared code.
 *
 * ## `X-Entity` is required, and never guessed
 *
 * Even for a token that names exactly one entity. The browser can guess
 * because a person is watching and will notice the wrong company's figures;
 * an integration will not, and the failure is silent and cumulative - a
 * month of postings into the parent instead of the subsidiary, each of them
 * plausible.
 *
 * It is also the header the request will be read back with in a log, which
 * makes "which company did this go into" answerable without re-deriving a
 * fallback chain.
 *
 * ## Three checks, and the order is the order of the information leaked
 *
 * The token's own entity list first, then the user's roles, then the entity's
 * own state. Asking the token first means an entity outside its list is
 * refused without a single query against entity data - so a token cannot be
 * used to probe which entity ids exist by watching which refusals take
 * longer.
 *
 * The user's roles are re-read on every request rather than trusted from the
 * token, because a role revoked five minutes ago must stop working now: the
 * token is a delegation of what its owner can do, and its owner's grants
 * change without the token changing.
 */
final class ResolveApiEntity
{
    public const HEADER = 'X-Entity';

    public function __construct(private readonly EntityContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $user instanceof User || ! $token instanceof ApiToken) {
            // EstablishApiActor answers this, and runs first. Reporting "no
            // entity" for a request with no token would send a problem
            // document naming the wrong thing entirely.
            return $next($request);
        }

        $requested = $request->header(self::HEADER);

        if (! \is_string($requested) || trim($requested) === '') {
            return Problem::of(ProblemType::EntityRequired)->toResponse($request);
        }

        $requested = trim($requested);

        if (! $token->mayActIn($requested)) {
            return Problem::of(ProblemType::EntityNotPermitted)
                ->withLocalizedDetail(
                    sprintf(
                        'هذا الرمز يعمل في %s، و%s ليس أحدها. الرمز مقيّد بكيانات بعينها لا بالمستأجر، '.
                        'فإضافة شركة إلى المجموعة لا تُوسّع رمزًا صُدر قبلها.',
                        implode('، ', $token->entityIds()),
                        $requested,
                    ),
                    sprintf(
                        'This token may act in %s, and %s is not among them. A token is scoped to specific '.
                        'entities and never to the tenant, so adding a company to the group does not widen '.
                        'a token that was issued before it.',
                        implode(', ', $token->entityIds()),
                        $requested,
                    ),
                )
                ->withExtensions(['token_entity_ids' => $token->entityIds()])
                ->toResponse($request);
        }

        if (! $user->entityIds()->contains($requested)) {
            return Problem::of(ProblemType::EntityNotPermitted)
                ->withLocalizedDetail(
                    sprintf(
                        'المستخدم صاحب هذا الرمز لا يحمل أي دور في %s. الوصول يُمنح لكل كيان على حدة: '.
                        'الدور في إحدى شركات المجموعة لا يمنح شيئًا في أخرى، والرمز لا يعيش أطول من '.
                        'التفويض الذي ينقله.',
                        $requested,
                    ),
                    sprintf(
                        'The user behind this token holds no role in %s. Access is granted per entity: a '.
                        'role in one company of a group grants nothing in another, and a token cannot '.
                        'outlive the grant it delegates.',
                        $requested,
                    ),
                )
                ->toResponse($request);
        }

        $this->context->use($requested);

        $entity = $this->context->entity();

        if ($entity === null || ! $entity->active) {
            /*
             | Reported as not permitted rather than as not found.
             |
             | The token named an entity it is scoped to, so its existence is
             | not a secret from this caller - and "not found" would send an
             | integrator looking for a typo in an id that is correct.
             */
            return Problem::of(ProblemType::EntityNotPermitted)
                ->withLocalizedDetail(
                    sprintf(
                        'الكيان %s مُغلق للنشاط. الكيان المُغلق ليس مكانًا يُرحَّل إليه شيء.',
                        $requested,
                    ),
                    sprintf(
                        'Entity %s is closed to activity. An entity that has been closed is not somewhere '.
                        'anything should be posting.',
                        $requested,
                    ),
                )
                ->toResponse($request);
        }

        try {
            return $next($request);
        } finally {
            /*
             | A pooled connection must not carry this entity into the next
             | request, which may belong to a different token and a different
             | company. The row-level security policies read the session
             | variable this clears.
             */
            $this->context->forget();
        }
    }
}
