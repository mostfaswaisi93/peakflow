<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Services;

use App\Tenant\Identity\Exceptions\FactorRequiredBeforeGrant;
use App\Tenant\Identity\Models\Role;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Models\UserEntityRole;

/**
 * Grants a role to a user in an entity - PF-031.
 *
 * ## The factor is checked HERE, not at the point of use
 *
 * A role that includes `payment.release` cannot be granted to a user who has
 * no second factor. Refusing at the moment of granting means an administrator
 * finds out while they are doing administration, with the user in front of
 * them and nothing urgent happening.
 *
 * Refusing at the point of USE instead - which is where it is easier to
 * implement - means the user discovers it on a Friday afternoon with a payment
 * run to release and the person who could help them gone for the weekend. The
 * control is identical; the cost of enforcing it in the wrong place is a
 * support call and a strong incentive to switch it off.
 *
 * The guard at the point of use still exists, because a factor can be removed
 * after a grant and because defence in depth is the whole architecture here.
 * This one exists so it almost never fires.
 *
 * ## It takes an entity ID, not an Entity
 *
 * Identity sits below Tenancy in the module graph, so it must not know what an
 * Entity is - only that a role is held per entity id. The optional label is
 * for the audit message, passed in by the caller that does know. An
 * architecture rule enforces the direction.
 */
final class RoleGrantService
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws FactorRequiredBeforeGrant
     */
    public function grant(
        User $user,
        Role $role,
        string $entityId,
        ?User $grantedBy = null,
        ?string $entityLabel = null,
    ): UserEntityRole {
        $requiring = $role->permissions()
            ->where('requires_mfa', true)
            ->orderBy('key')
            ->pluck('key');

        if ($requiring->isNotEmpty() && ! $this->mfa->hasConfirmedFactor($user)) {
            $this->audit->refusal(
                category: 'identity',
                action: 'role.grant.refused',
                subjectType: User::class,
                subjectId: (string) $user->getKey(),
                subjectLabel: $user->name,
                after: [
                    'role' => $role->key,
                    'entity_id' => $entityId,
                    'permissions_requiring_a_factor' => $requiring->all(),
                ],
                reason: 'the user has no confirmed second factor',
                actor: $grantedBy,
            );

            throw FactorRequiredBeforeGrant::forRole($role, $user, $requiring->all());
        }

        $grant = UserEntityRole::firstOrCreate(
            [
                'user_id' => $user->getKey(),
                'entity_id' => $entityId,
                'role_id' => $role->getKey(),
            ],
            ['granted_by_user_id' => $grantedBy?->getKey()],
        );

        $user->forgetResolvedPermissions();

        $this->audit->record(
            category: 'identity',
            action: 'role.granted',
            subjectType: User::class,
            subjectId: (string) $user->getKey(),
            subjectLabel: $user->name,
            after: [
                'role' => $role->key,
                'entity_id' => $entityId,
                'granted_by' => $grantedBy?->getKey(),
            ],
            reason: sprintf('%s in %s', $role->name(), $entityLabel ?? $entityId),
            entityId: $entityId,
            actor: $grantedBy,
        );

        return $grant;
    }

    public function revoke(
        User $user,
        Role $role,
        string $entityId,
        ?User $revokedBy = null,
        ?string $entityLabel = null,
    ): void {
        UserEntityRole::query()
            ->where('user_id', $user->getKey())
            ->where('entity_id', $entityId)
            ->where('role_id', $role->getKey())
            ->delete();

        $user->forgetResolvedPermissions();

        $this->audit->record(
            category: 'identity',
            action: 'role.revoked',
            subjectType: User::class,
            subjectId: (string) $user->getKey(),
            subjectLabel: $user->name,
            before: ['role' => $role->key, 'entity_id' => $entityId],
            reason: sprintf('%s in %s', $role->name(), $entityLabel ?? $entityId),
            entityId: $entityId,
            actor: $revokedBy,
        );
    }
}
