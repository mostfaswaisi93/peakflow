<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Http\Controllers\Api;

use App\Support\Http\Api\Wire;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\RefreshToken;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\ApiTokenService;
use App\Tenant\Identity\Services\DeviceSessions;
use App\Tenant\Tenancy\Exceptions\EntityNotInScope;
use App\Tenant\Tenancy\Services\EntityContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A person's own devices, and who they are - PF-260.
 *
 * Everything here is about the person holding the token and nobody else, so
 * nothing here asks the gate for a permission: a person may always see who
 * they are, list where they are signed in, and sign out. A sign-out that can
 * be refused is a way to keep somebody signed in.
 */
final class DeviceSessionController
{
    public function __construct(
        private readonly DeviceSessions $sessions,
        private readonly ApiTokenService $tokens,
        private readonly EntityContext $entities,
    ) {}

    /**
     * Who this token is, where it acts, and what it may do.
     *
     * @no-permission-required: a person may always ask who they are.
     */
    public function me(Request $request): JsonResponse
    {
        [$user, $token] = $this->holder($request);

        $entity = $this->entities->entity();

        if ($entity === null) {
            throw EntityNotInScope::forQueryOn(User::class);
        }

        return new JsonResponse($this->sessions->describe($user, (string) $entity->getKey(), $token->scopes()));
    }

    /**
     * Where this person is signed in.
     *
     * @no-permission-required: a person may always see their own devices.
     */
    public function index(Request $request): JsonResponse
    {
        [$user, $token] = $this->holder($request);

        $current = $this->sessions->sessionOf($token)?->family_id;

        return new JsonResponse([
            'data' => $this->sessions->liveSessionsOf($user)
                ->map(fn (RefreshToken $row): array => $this->summary($row, $current))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Sign one of this person's devices out - the lost phone.
     *
     * @no-permission-required: a person may always end their own sessions.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        [$user, $token] = $this->holder($request);

        $head = $this->sessions->liveSessionsOf($user)->first(
            static fn (RefreshToken $row): bool => $row->family_id === $id,
        );

        if (! $head instanceof RefreshToken) {
            // Another person's session is reported as not existing - the
            // same rule every record in this API follows.
            throw (new ModelNotFoundException())->setModel(RefreshToken::class, [$id]);
        }

        $this->sessions->close($id, sprintf('Signed out from another device by %s.', $user->name));

        $closed = $head->fresh() ?? $head;

        return new JsonResponse($this->summary($closed, $this->sessions->sessionOf($token)?->family_id));
    }

    /**
     * Sign this device out: its session, or - for a token that is not part of
     * one - the token itself.
     *
     * @no-permission-required: a person may always end their own session.
     */
    public function signOut(Request $request): JsonResponse
    {
        [$user, $token] = $this->holder($request);

        $session = $this->sessions->sessionOf($token);

        if ($session instanceof RefreshToken) {
            $this->sessions->close($session->family_id, 'Signed out on the device.');
        } elseif ($token->revoked_at === null) {
            $this->tokens->revoke($token, sprintf('Signed out by %s.', $user->name));
        }

        return new JsonResponse([
            'resource' => 'sign_out',
            'session_id' => $session?->family_id,
            'token_id' => (string) $token->getKey(),
            'signed_out_at' => Wire::timestamp(now()),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(RefreshToken $row, ?string $current): array
    {
        return [
            'resource' => 'device_session_summary',
            'id' => $row->family_id,
            'device_name' => $row->device_name,
            'entity_id' => $row->entity_id,
            'second_factor' => $row->second_factor,
            'started_at' => Wire::timestamp($row->session_started_at),
            'last_refreshed_at' => Wire::timestamp($row->created_at),
            'expires_at' => Wire::timestamp($row->expires_at),
            'revoked_at' => Wire::timestamp($row->revoked_at),
            'current' => $current !== null && $current === $row->family_id,
        ];
    }

    /**
     * The person and the token this request authenticated with.
     *
     * Unreachable without both, behind `api.actor` - and checked anyway, for
     * the reason every controller here re-asserts what its middleware did.
     *
     * @return array{0: User, 1: ApiToken}
     */
    private function holder(Request $request): array
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if (! $user instanceof User || ! $token instanceof ApiToken) {
            throw new AuthenticationException();
        }

        return [$user, $token];
    }
}
