<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Support\Http\Api\IdempotencyStore;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\User;
use App\Tenant\Tenancy\Services\EntityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every POST that posts to the ledger carries a key - PF-220.
 *
 * ## Required, not honoured-if-present
 *
 * The optional version is the one that gets built, and it protects exactly
 * the clients that did not need protecting. A client that retries without a
 * key posts the document twice, and the second one is indistinguishable from
 * a real transaction: same amounts, same date, same party, a different
 * number. Nobody finds it until a reconciliation, and by then the cause is
 * unknowable.
 *
 * So a request with no key is REFUSED. The cost is a 422 the first time an
 * integrator calls the endpoint, with a sentence saying what to send. The
 * alternative cost is a duplicate payment.
 *
 * ## Which routes carry it is a routing decision, stated on the route
 *
 * Not inferred from the method. Plenty of POSTs do not post to the ledger -
 * a token issue, a preview, a search - and requiring a key on those trains
 * clients to generate one per call, which is the same as not having one.
 *
 * `ApiContractRules` asserts the other direction: every route that reaches
 * the posting path has this middleware, so forgetting it on the next ledger
 * endpoint is a failed build rather than a duplicate somebody finds in March.
 *
 * ## It runs after the entity is resolved, and that is load-bearing
 *
 * The record is keyed on the token and the key, and the ENTITY is part of the
 * request fingerprint - so the entity has to be known before the fingerprint
 * can be computed. A key reused against a different company is then the 409
 * it should be, rather than a replay telling a client its invoice exists
 * somewhere it does not.
 */
final class RequireIdempotency
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly EntityContext $entities,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $token instanceof ApiToken) {
            // Unauthenticated: EstablishApiActor answers it. Demanding a key
            // from a request that has no identity would report the second
            // problem and hide the first.
            return $next($request);
        }

        $key = IdempotencyStore::keyFrom($request);
        $entityId = (string) $this->entities->current();

        return $this->store->run(
            tokenId: (string) $token->getKey(),
            key: $key,
            entityId: $entityId,
            fingerprint: IdempotencyStore::fingerprint($request, $entityId),
            work: static fn (): Response => $next($request),
        );
    }
}
