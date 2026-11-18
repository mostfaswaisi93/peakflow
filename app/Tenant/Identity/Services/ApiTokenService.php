<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Support\Audit\AuditTrail;
use App\Tenant\Identity\Exceptions\TokenRefused;
use App\Tenant\Identity\Models\ApiToken;
use App\Tenant\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issuing, rotating and revoking a bearer token - PF-218.
 *
 * ## A token delegates; it never grants
 *
 * Every scope is checked against what the OWNER holds, in every entity the
 * token names. Without that check, issuing a token is a way to grant yourself
 * a permission: a bookkeeper with `invoice.draft` asks for a token carrying
 * `payment.release`, and the authorization model has been bypassed by the
 * thing that was meant to enforce it.
 *
 * It is checked per entity and not once, because that is how permissions
 * work here: a user who may post in the parent and only read in a subsidiary
 * is the ordinary case, and a token naming both entities with one scope list
 * would quietly take the union.
 *
 * ## Rotation is two rows and an arrow
 *
 * Not an update. A token whose hash changed in place leaves no record that
 * the old credential ever existed, so "which token posted this entry on the
 * 14th" has no answer - the only row says it was created later. So rotation
 * mints a successor, revokes the predecessor with a reason naming it, and the
 * database refuses the alternative: a trigger rejects any UPDATE that
 * rewrites a token's hash, scopes or entities.
 *
 * The cost is that a client has a window where both work, which is exactly
 * what PF-226 asks for when rotating a webhook secret and for the same
 * reason: a rotation that breaks deliveries is a rotation nobody performs.
 *
 * ## Every one of the four acts is audited
 *
 * Issue, rotate, revoke and use. The first three are written here; the fourth
 * is `last_used_at`, maintained by the guard. PF-218 asks for all four to be
 * visible to the tenant, because the question a customer asks about a token
 * found in a configuration file is "is anything still using this", and a
 * creation date cannot answer it.
 */
final class ApiTokenService
{
    public function __construct(
        private readonly ApiScopes $scopes,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Issue a token, and return it with its one and only plaintext.
     *
     * The plaintext is returned and never stored. A token management screen
     * that could show it again would be a second place it can leak from, and
     * the whole value of storing a hash is that reading the table lets nobody
     * act as anybody.
     *
     * @param  list<string>  $scopes  permission keys, verbatim
     * @param  list<string>  $entityIds
     * @param  ?ApiToken  $issuedUnder  the token this request authenticated with, if any
     * @return array{token: ApiToken, plaintext: string}
     *
     * @throws TokenRefused
     */
    public function issue(
        User $owner,
        string $name,
        array $scopes,
        array $entityIds,
        ?Carbon $expiresAt = null,
        string $plane = ApiToken::PLANE_TENANT,
        ?ApiToken $issuedUnder = null,
    ): array {
        $scopes = $this->assertScopes($owner, $scopes, $entityIds);
        $entityIds = $this->assertEntities($owner, $entityIds);
        $this->assertWithinTheIssuingToken($issuedUnder, $scopes, $entityIds);

        $plaintext = $owner->generateTokenString();

        $token = new ApiToken();
        $token->forceFill([
            'plane' => $plane,
            'tokenable_type' => $owner->getMorphClass(),
            'tokenable_id' => (string) $owner->getKey(),
            'name' => $name,
            'token' => hash('sha256', $plaintext),
            'abilities' => $scopes,
            'entity_ids' => $entityIds,
            'expires_at' => $expiresAt,
            'created_by_user_id' => (string) $owner->getKey(),
        ]);

        $token->save();

        $this->audit->record(
            category: 'identity',
            action: 'api_token.issued',
            subjectType: 'api_tokens',
            subjectId: (string) $token->getKey(),
            subjectLabel: $name,
            after: [
                'scopes' => $scopes,
                'entity_ids' => $entityIds,
                'expires_at' => $expiresAt?->toIso8601ZuluString(),
                'plane' => $plane,
            ],
            reason: sprintf('An API token was issued to %s.', (string) $owner->name),
        );

        return ['token' => $token, 'plaintext' => (string) $token->getKey().'|'.$plaintext];
    }

    /**
     * Mint a successor and revoke the predecessor - in one transaction.
     *
     * One transaction, because the two halves are a single decision. A
     * rotation that minted the successor and then failed to revoke the old
     * token has doubled the number of live credentials, which is the opposite
     * of what somebody rotating after a leak was trying to do.
     *
     * @return array{token: ApiToken, plaintext: string}
     *
     * @throws TokenRefused
     */
    public function rotate(ApiToken $token, User $owner, string $reason): array
    {
        if ($token->revoked_at !== null) {
            throw TokenRefused::alreadyRevoked((string) $token->name);
        }

        $this->assertReason($reason);

        return DB::connection('tenant')->transaction(function () use ($token, $owner, $reason): array {
            $issued = $this->issue(
                owner: $owner,
                name: (string) $token->name,
                scopes: $token->scopes(),
                entityIds: $token->entityIds(),
                expiresAt: $token->expires_at,
                plane: (string) $token->plane,
            );

            $token->forceFill([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
                'rotated_to_token_id' => (string) $issued['token']->getKey(),
            ])->save();

            $this->audit->record(
                category: 'identity',
                action: 'api_token.rotated',
                subjectType: 'api_tokens',
                subjectId: (string) $token->getKey(),
                subjectLabel: (string) $token->name,
                before: ['token_id' => (string) $token->getKey()],
                after: ['token_id' => (string) $issued['token']->getKey()],
                reason: $reason,
            );

            return $issued;
        });
    }

    /** @throws TokenRefused */
    public function revoke(ApiToken $token, string $reason): void
    {
        if ($token->revoked_at !== null) {
            throw TokenRefused::alreadyRevoked((string) $token->name);
        }

        $this->assertReason($reason);

        $token->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();

        $this->audit->record(
            category: 'identity',
            action: 'api_token.revoked',
            subjectType: 'api_tokens',
            subjectId: (string) $token->getKey(),
            subjectLabel: (string) $token->name,
            after: ['revoked_at' => now()->toIso8601ZuluString()],
            reason: $reason,
        );
    }

    /**
     * The tokens a user may see: their own, revoked ones included.
     *
     * Revoked ones included deliberately. A list of live tokens cannot answer
     * "did we already rotate this", and the rotation chain is only legible
     * when both ends of it are in the list.
     *
     * @return Collection<int, ApiToken>
     */
    public function visibleTo(User $owner): Collection
    {
        return ApiToken::query()
            ->where('tokenable_type', $owner->getMorphClass())
            ->where('tokenable_id', (string) $owner->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * A token may never mint a wider token than itself - PF-218.
     *
     * Without this, the scope model has a hole shaped like its own
     * management endpoint: a token issued to read invoices, carrying
     * `user.manage` because somebody wanted it to rotate its own credential,
     * could ask for a token with `payment.release`. The owner holds that
     * permission, so the per-entity check above passes - and the narrow token
     * has widened itself in one call.
     *
     * So delegation only ever narrows. The entity list is held to the same
     * rule and for the same reason: a token scoped to one subsidiary must not
     * be able to mint one for the parent.
     *
     * A null issuer means the request came from a browser session or the
     * console, where there is no token to be bounded by - the owner's own
     * grants are the ceiling, which is what the checks above enforce.
     *
     * @param  list<string>  $scopes
     * @param  list<string>  $entityIds
     *
     * @throws TokenRefused
     */
    private function assertWithinTheIssuingToken(?ApiToken $issuedUnder, array $scopes, array $entityIds): void
    {
        if ($issuedUnder === null) {
            return;
        }

        $wider = array_values(array_diff($scopes, $issuedUnder->scopes()));

        if ($wider !== []) {
            throw TokenRefused::widerThanTheIssuer($wider, $issuedUnder->scopes());
        }

        $elsewhere = array_values(array_diff($entityIds, $issuedUnder->entityIds()));

        if ($elsewhere !== []) {
            throw TokenRefused::entitiesOutsideTheIssuer($elsewhere, $issuedUnder->entityIds());
        }
    }

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $entityIds
     * @return list<string>
     *
     * @throws TokenRefused
     */
    private function assertScopes(User $owner, array $scopes, array $entityIds): array
    {
        $scopes = array_values(array_unique(array_filter(
            $scopes,
            static fn (mixed $scope): bool => \is_string($scope) && $scope !== '',
        )));

        if ($scopes === []) {
            throw TokenRefused::noScopes();
        }

        $unknown = $this->scopes->unknown($scopes);

        if ($unknown !== []) {
            throw TokenRefused::unknownScopes($unknown);
        }

        $stepUp = array_values(array_filter(
            $scopes,
            fn (string $scope): bool => $this->scopes->requiresStepUp($scope),
        ));

        if ($stepUp !== []) {
            throw TokenRefused::scopesThatNeedSomebodyPresent($stepUp);
        }

        /*
         | Per entity, and the first entity that refuses one stops the issue.
         |
         | Checking the union would hand a token every scope its owner holds
         | ANYWHERE, which for a user who posts in the parent and reads in a
         | subsidiary is a token that can post in the subsidiary. That is the
         | failure this whole per-entity model exists to prevent, arriving
         | through the credential rather than through a role.
         */
        foreach ($entityIds as $entityId) {
            $missing = array_values(array_filter(
                $scopes,
                static fn (string $scope): bool => ! $owner->holds($scope, $entityId),
            ));

            if ($missing !== []) {
                throw TokenRefused::scopesTheUserDoesNotHold($missing, $entityId);
            }
        }

        sort($scopes, SORT_STRING);

        return array_values($scopes);
    }

    /**
     * @param  list<string>  $entityIds
     * @return list<string>
     *
     * @throws TokenRefused
     */
    private function assertEntities(User $owner, array $entityIds): array
    {
        $entityIds = array_values(array_unique(array_filter(
            $entityIds,
            static fn (mixed $id): bool => \is_string($id) && $id !== '',
        )));

        if ($entityIds === []) {
            throw TokenRefused::noEntities();
        }

        $held = $owner->entityIds()->map(static fn (mixed $id): string => (string) $id)->all();

        $unreachable = array_values(array_diff($entityIds, $held));

        if ($unreachable !== []) {
            throw TokenRefused::entitiesTheUserCannotReach($unreachable);
        }

        sort($entityIds, SORT_STRING);

        return array_values($entityIds);
    }

    /** @throws TokenRefused */
    private function assertReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw TokenRefused::needsAReason();
        }
    }
}
