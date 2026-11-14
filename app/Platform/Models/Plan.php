<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Platform\Enums\PlanFeature;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Plan extends PlatformModel
{
    protected $table = 'plans';

    protected $fillable = [
        'key', 'name_ar', 'name_en', 'features', 'max_entities',
        'max_users', 'max_documents_per_month', 'jurisdictions',
        'active', 'sort_order',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function has(PlanFeature $feature): bool
    {
        return \in_array($feature->value, (array) ($this->features ?? []), true);
    }

    public function allowsJurisdiction(string $profile): bool
    {
        $allowed = (array) ($this->jurisdictions ?? []);

        // A plan with no explicit list gets the baseline profile only. Failing
        // closed matters here: enabling a jurisdiction means accepting
        // responsibility for its clearance credentials and archive retention.
        return $allowed === [] ? $profile === 'generic' : \in_array($profile, $allowed, true);
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar'
            ? (string) $this->name_ar
            : (string) $this->name_en;
    }

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'jurisdictions' => 'array',
            'active' => 'boolean',
            'max_entities' => 'integer',
            'max_users' => 'integer',
            'max_documents_per_month' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
