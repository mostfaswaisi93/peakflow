<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A user of one tenant - PF-024.
 *
 * Users live in the TENANT database, which is why the tenant must be resolved
 * before the auth guard runs (PF-021). A guard that ran first would look for
 * this table in the platform database.
 *
 * ## Soft deleted, always
 *
 * An invoice issued three years ago names its author. Hard-deleting the user
 * turns that into a dangling id and an audit finding, so a departing employee
 * is deactivated and soft-deleted, never removed. `active` and `deleted_at`
 * are different states on purpose: deactivated is reversible by an
 * administrator, deleted is a record that the person is gone.
 *
 * @property string $name
 * @property string $email
 * @property bool $active
 * @property ?string $last_entity_id
 */
final class User extends Authenticatable implements AuthenticatableContract
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'name', 'email', 'password', 'locale', 'timezone', 'last_entity_id', 'active',
    ];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Permission keys already resolved this request, keyed by entity id.
     *
     * Authorization is asked dozens of times while rendering one screen. This
     * is a per-request memo, not a cache: it is never written anywhere that
     * outlives the request, because a role revoked mid-session must take
     * effect on the next request and not when a TTL expires.
     *
     * @var array<string, Collection<int, string>>
     */
    private array $resolved = [];

    public function getConnectionName(): ?string
    {
        return 'tenant';
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /** @return HasMany<UserEntityRole, $this> */
    public function entityRoles(): HasMany
    {
        return $this->hasMany(UserEntityRole::class);
    }

    /** @return BelongsToMany<Role, $this> */
    public function rolesIn(string $entityId): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_entity_roles')
            ->wherePivot('entity_id', $entityId);
    }

    /**
     * Every permission key this user holds in one entity.
     *
     * Resolved from roles in that entity only. A user with no role in an
     * entity holds nothing there - not "read-only", not "their global role".
     * The absence of a grant is the absence of access.
     *
     * @return Collection<int, string>
     */
    public function permissionsIn(string $entityId): Collection
    {
        return $this->resolved[$entityId] ??= Permission::query()
            ->whereIn('id', fn ($q) => $q
                ->select('permission_id')
                ->from('role_permissions')
                ->whereIn('role_id', fn ($r) => $r
                    ->select('role_id')
                    ->from('user_entity_roles')
                    ->where('user_id', $this->getKey())
                    ->where('entity_id', $entityId)))
            ->pluck('key');
    }

    /**
     * Does this user hold a permission in an entity?
     *
     * A deactivated user holds nothing, whatever their roles say. That check
     * is here rather than at login because a user deactivated during a session
     * must stop being able to act, not stop being able to sign in again.
     */
    public function holds(string $permission, string $entityId): bool
    {
        if (! $this->active) {
            return false;
        }

        return $this->permissionsIn($entityId)->contains($permission);
    }

    /** @return Collection<int, string> */
    public function entityIds(): Collection
    {
        return $this->entityRoles()->distinct()->pluck('entity_id');
    }

    /**
     * The entities this user may switch between - PF-027.
     *
     * Read from the GRANTS, every time, and never from the session. A user
     * whose access to a subsidiary was revoked five minutes ago must stop
     * seeing it in the switcher now, not at their next sign-in. The list is
     * also what the switch handler validates against, so the control and the
     * check read the same source.
     *
     * Inactive entities are excluded: an entity that has been closed is not
     * somewhere anyone should be posting, and leaving it in the list invites
     * exactly that.
     *
     * @return Collection<int, object>
     */
    public function entitiesForSwitcher(): Collection
    {
        return DB::connection('tenant')->table('entities')
            ->whereIn('id', $this->entityIds())
            ->where('active', true)
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['id', 'code', 'legal_name']);
    }

    /** Drop the per-request memo - after a role change, or an entity switch. */
    public function forgetResolvedPermissions(): void
    {
        $this->resolved = [];
    }
}
