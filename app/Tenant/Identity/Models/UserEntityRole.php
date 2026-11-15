<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use App\Support\Database\TenantModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grant: this user holds this role in this entity.
 *
 * `granted_by_user_id` is kept because "who gave them that" is the second
 * question of every access review, and reconstructing it from the audit log
 * is slower than storing it.
 *
 * @property string $user_id
 * @property string $entity_id
 * @property string $role_id
 */
final class UserEntityRole extends TenantModel
{
    protected $table = 'user_entity_roles';

    protected $fillable = ['user_id', 'entity_id', 'role_id', 'granted_by_user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
