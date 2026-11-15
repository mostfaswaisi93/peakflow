<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sequence of steps for chasing an overdue invoice - PF-108.
 *
 * @property string $code
 */
final class DunningPolicy extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'dunning_policies';

    protected $fillable = [
        'entity_id', 'code', 'name_ar', 'name_en', 'party_group',
        'is_default', 'active', 'note',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'boolean',
    ];

    /** @return HasMany<DunningPolicyStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(DunningPolicyStep::class)->orderBy('sequence');
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar'
            ? (string) $this->name_ar
            : (string) $this->name_en;
    }
}
