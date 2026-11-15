<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use App\Support\Database\TenantModel;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named bundle of permissions.
 *
 * `system` roles ship with the product and cannot be edited by a customer -
 * not out of paternalism, but because an auditor comparing two tenants needs
 * `controller` to mean the same thing in both. A customer who wants a
 * different bundle copies one and names it themselves.
 *
 * A role is held PER ENTITY, never globally. The join lives on
 * `user_entity_roles`, so a group accountant can be a controller in one
 * company and hold nothing at all in its sister company.
 *
 * @property string $key
 * @property bool $system
 */
final class Role extends TenantModel
{
    protected $table = 'roles';

    protected $fillable = [
        'key', 'name_ar', 'name_en', 'description_ar', 'description_en', 'system',
    ];

    protected $casts = ['system' => 'boolean'];

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function name(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $locale === 'ar' ? $this->name_ar : $this->name_en;
    }
}
