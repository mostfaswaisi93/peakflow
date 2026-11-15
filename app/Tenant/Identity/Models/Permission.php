<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use App\Support\Database\TenantModel;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One thing a user may do - PF-028.
 *
 * A permission is a VERB, not a screen. `invoices.issue` is a permission;
 * `invoices.index` is a route. The catalogue is seeded, not user-editable: a
 * customer may invent a role, never a capability, because a capability that
 * can be invented cannot be reasoned about by an auditor.
 *
 * ## Two flags that are not about authorization
 *
 * `requires_mfa` and `requires_step_up` do not decide WHETHER a user holds the
 * permission. They decide what the user must have proved about themselves at
 * the moment they use it. Releasing a payment is not a permission a password
 * alone can exercise, however many roles grant it (PF-031, PF-032).
 *
 * @property string $key
 * @property string $module
 * @property bool $requires_mfa
 * @property bool $requires_step_up
 */
final class Permission extends TenantModel
{
    protected $table = 'permissions';

    protected $fillable = [
        'key', 'module', 'label_ar', 'label_en',
        'description_ar', 'description_en',
        'requires_mfa', 'requires_step_up',
    ];

    protected $casts = [
        'requires_mfa' => 'boolean',
        'requires_step_up' => 'boolean',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    /** The label in the active locale, falling back to English. */
    public function label(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $locale === 'ar' ? $this->label_ar : $this->label_en;
    }
}
