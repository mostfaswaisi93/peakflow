<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A bearer token, and what it may do - PF-218.
 *
 * Sanctum's own model, narrowed in three ways that each close a hole.
 *
 * ## It lives in the TENANT database
 *
 * Which is why the connection is named rather than inherited. `tenant` is
 * rewritten per request by the connection resolver, and a model that did not
 * name it would authenticate against whichever connection happened to be
 * default - the platform database in development, and the PREVIOUS request's
 * tenant in production. That is the same failure the middleware ordering
 * exists to prevent, arriving one layer lower down.
 *
 * ## There is no wildcard
 *
 * Sanctum's `can()` treats `*` as every ability. PF-218 asks that token
 * scopes mirror permissions exactly, so that there is one authorization model
 * and not a second, weaker one for machines - and `*` is the single value
 * that cannot mirror a permission, because it names none. A token holding it
 * would grant abilities nobody granted and that no permission catalogue
 * describes, including abilities added by a later deployment.
 *
 * So the wildcard is refused here as well as by a database constraint, and
 * `can()` is overridden rather than the constraint being trusted alone: the
 * constraint stops the row being written, and this stops a row that somehow
 * exists - restored from an older dump, say - from being believed.
 *
 * ## A revoked or expired token can do nothing
 *
 * Sanctum's guard checks `expires_at` and leaves revocation to the
 * application. Checking it in `can()` as well means a token revoked halfway
 * through a long-running request stops being able to act within it, rather
 * than at the next request.
 *
 * @property array<int, string> $abilities
 * @property array<int, string> $entity_ids
 * @property string $plane
 * @property ?\Illuminate\Support\Carbon $revoked_at
 * @property ?\Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $last_used_at
 */
final class ApiToken extends PersonalAccessToken
{
    use HasUlids;

    /** The plane a tenant API route serves. A platform token is not one of these. */
    public const PLANE_TENANT = 'tenant';

    /** The plane the operator console would serve. No route uses it yet - PF-036. */
    public const PLANE_PLATFORM = 'platform';

    protected $table = 'api_tokens';

    protected $fillable = [
        'plane', 'name', 'token', 'abilities', 'entity_ids', 'expires_at', 'created_by_user_id',
    ];

    /*
     | The hash is hidden, and so is nothing else.
     |
     | `token` holds the SHA-256 of the credential. It is never the credential
     | itself, so leaking it does not let anybody act as anybody - but it is
     | enough to recognise a token in a log, and a token management screen
     | that echoed it would put it in a browser's history.
     */
    protected $hidden = ['token'];

    public function getConnectionName(): ?string
    {
        return 'tenant';
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'entity_ids' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Does this token carry a scope?
     *
     * @param  string  $ability  a permission key, verbatim
     */
    public function can($ability): bool
    {
        if (! $this->isLive()) {
            return false;
        }

        // Never `*`. See the class note: a wildcard is the one scope that
        // cannot mirror a permission, and it widens itself with every
        // deployment that adds one.
        return \in_array($ability, $this->scopes(), true);
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return array_values(array_filter(
            (array) $this->abilities,
            static fn (mixed $scope): bool => \is_string($scope) && $scope !== '*',
        ));
    }

    /** @return list<string> */
    public function entityIds(): array
    {
        return array_values(array_filter(
            (array) $this->entity_ids,
            static fn (mixed $id): bool => \is_string($id) && $id !== '',
        ));
    }

    /**
     * May this token act in this entity? - PF-218.
     *
     * A token is scoped to specific entities and never to the tenant. A
     * bookkeeping integration set up for one subsidiary must not acquire the
     * parent because somebody added a company six months later, and a
     * tenant-wide token widens itself every time the group grows.
     */
    public function mayActIn(string $entityId): bool
    {
        return $this->isLive() && \in_array($entityId, $this->entityIds(), true);
    }

    public function isLive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || ! $this->expires_at->isPast();
    }

    /**
     * Which plane issued it - and therefore which routes it may reach.
     *
     * Read from the row rather than inferred from `tokenable_type`. A model
     * class is exactly the thing that stays plausible when a dump is restored
     * into the wrong database.
     */
    public function belongsToPlane(string $plane): bool
    {
        return $this->plane === $plane;
    }

    /**
     * What a tenant may see about its own tokens - PF-218.
     *
     * Deliberately includes `last_used_at` and the revocation, because the
     * question a customer asks about a token they have found in a
     * configuration file is "is anything still using this" - and a list of
     * names and creation dates cannot answer it.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => (string) $this->name,
            'plane' => (string) $this->plane,
            'scopes' => $this->scopes(),
            'entity_ids' => $this->entityIds(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'revoked_reason' => $this->revoked_reason === null ? null : (string) $this->revoked_reason,
            'rotated_to_token_id' => $this->rotated_to_token_id === null
                ? null
                : (string) $this->rotated_to_token_id,
        ];
    }
}
