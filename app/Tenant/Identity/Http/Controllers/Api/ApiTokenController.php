<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Http\Controllers\Api;

use App\Support\Http\Api\Wire;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\ApiScopes;
use App\Tenant\Identity\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * A tenant's own tokens: what exists, what is still in use, and rotation -
 * PF-218.
 *
 * ## Why an integration can rotate its own credential
 *
 * Because the alternative is that nobody rotates. A rotation that needs a
 * human to open a screen, generate a token, paste it into a configuration
 * file and redeploy happens once, during setup, and then never - so the
 * credential in that file is the one that was issued three years ago and has
 * been in two laptop backups since.
 *
 * `POST /api-tokens/{id}/rotate` is what makes a scheduled rotation
 * possible: the integration mints its successor, writes it to its own
 * configuration, and the predecessor is revoked. Both work until the
 * predecessor is revoked, which is the overlap that stops a rotation from
 * dropping a request.
 *
 * ## The first token is not issued here
 *
 * This endpoint needs a token to call it, so it cannot be where an
 * integration begins; the first token is issued in the application, by a
 * person, who is the only one who can decide what it is for. That is not a
 * gap - a bootstrap endpoint that mints a credential from no credential is
 * the hole every API with one eventually finds.
 *
 * ## Delegation only narrows
 *
 * A token may issue a token carrying its own scopes or fewer, in its own
 * entities or fewer, and never more. Without that rule this endpoint IS the
 * privilege escalation: a token issued to read invoices, holding
 * `user.manage` so that it can rotate itself, would otherwise be able to
 * mint one that releases payments - because its OWNER holds that permission.
 *
 * ## The plaintext is returned exactly once
 *
 * On creation and on rotation, and never again. A list endpoint that could
 * show it would be a second place it can leak from, and the whole value of
 * storing a hash is that reading the table lets nobody act as anybody.
 */
final class ApiTokenController
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ApiScopes $scopes,
    ) {}

    /**
     * What tokens exist, including revoked ones.
     *
     * Revoked ones included, because the question a customer asks is "did we
     * already rotate this", and a list of live tokens cannot answer it. The
     * rotation chain is only legible when both ends are in the list.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('user.manage');

        $user = $this->actor($request);

        return new JsonResponse([
            'data' => $this->tokens->visibleTo($user)
                ->map(static fn (ApiToken $token): array => [
                    'resource' => 'api_token',
                    ...$token->toWire(),
                ])
                ->all(),
            /*
             | The scopes that exist, on the listing.
             |
             | Because the next thing a client does with this endpoint is
             | issue a token, and the set of valid scopes is the permission
             | catalogue - which changes with a deployment. Publishing it here
             | means an integration never has to keep its own copy, and a copy
             | is what goes stale.
             */
            'meta' => [
                'available_scopes' => $this->scopes->all(),
                'scopes_requiring_a_person_present' => $this->scopes->neverIssuable(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('user.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'max:64'],
            'entity_ids' => ['required', 'array', 'min:1'],
            'entity_ids.*' => ['required', 'string', 'size:26'],
            /*
             | A DATE, not a timestamp.
             |
             | An expiry a customer asked for as "the end of March" is a day,
             | and accepting a timestamp invites a timezone argument about
             | which hour a credential dies in. It expires at the end of the
             | day named.
             */
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ]);

        $user = $this->actor($request);

        /** @var list<string> $scopes */
        $scopes = array_map(static fn (mixed $scope): string => (string) $scope, (array) $validated['scopes']);
        /** @var list<string> $entityIds */
        $entityIds = array_map(static fn (mixed $id): string => (string) $id, (array) $validated['entity_ids']);

        $issued = $this->tokens->issue(
            owner: $user,
            name: (string) $validated['name'],
            scopes: $scopes,
            entityIds: $entityIds,
            expiresAt: isset($validated['expires_on'])
                ? Carbon::createFromFormat('Y-m-d', (string) $validated['expires_on'])->endOfDay()
                : null,
            issuedUnder: $this->currentToken($request),
        );

        return $this->issuedResponse($issued, JsonResponse::HTTP_CREATED);
    }

    public function rotate(Request $request, string $id): JsonResponse
    {
        Gate::authorize('user.manage');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:200'],
        ]);

        $user = $this->actor($request);
        $token = $this->ownToken($user, $id);

        return $this->issuedResponse(
            $this->tokens->rotate($token, $user, (string) $validated['reason']),
            JsonResponse::HTTP_CREATED,
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        Gate::authorize('user.manage');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:200'],
        ]);

        $user = $this->actor($request);
        $token = $this->ownToken($user, $id);

        $this->tokens->revoke($token, (string) $validated['reason']);

        return new JsonResponse([
            'resource' => 'api_token',
            ...$token->toWire(),
        ]);
    }

    /**
     * @param  array{token: ApiToken, plaintext: string}  $issued
     */
    private function issuedResponse(array $issued, int $status): JsonResponse
    {
        return new JsonResponse([
            'resource' => 'api_token',
            ...$issued['token']->toWire(),
            /*
             | Once. There is no endpoint that returns this again, and that is
             | the point of storing a hash.
             */
            'token' => $issued['plaintext'],
            'issued_at' => Wire::timestamp($issued['token']->created_at),
        ], $status);
    }

    /**
     * The token this request authenticated with, or null off the API.
     *
     * Used as the CEILING on what may be issued. Null when the caller is a
     * browser session or the console, where the owner's own grants are the
     * only ceiling there is.
     */
    private function currentToken(Request $request): ?ApiToken
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof ApiToken ? $token : null;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Unreachable behind the guard; asserted so that a future route
            // registered outside it fails here rather than issuing a token
            // attributed to nobody.
            abort(401);
        }

        return $user;
    }

    /**
     * One of the caller's OWN tokens, or a 404.
     *
     * Scoped to the owner rather than to the tenant. A token belonging to
     * another user is reported as not found and not as forbidden: telling
     * somebody that an id they guessed is real, and simply not theirs, is a
     * fact worth having if what they are doing is enumerating.
     */
    private function ownToken(User $user, string $id): ApiToken
    {
        /** @var ApiToken $token */
        $token = ApiToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', (string) $user->getKey())
            ->findOrFail($id);

        return $token;
    }
}
