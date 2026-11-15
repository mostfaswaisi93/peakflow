<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Identity\Enums\DutyScope;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One incompatible pair of duties - PF-033.
 *
 * ## This is not a permission
 *
 * A permission answers *may this user do X*. An SoD rule answers *may the same
 * user do X and then Y to the same thing*. The second question cannot be
 * answered from roles at all, because both duties are legitimately within the
 * user's role - that is precisely why the control is needed. It is answered
 * from the AUDIT TRAIL: what this person has already done.
 *
 * ## Two rules are not overridable, ever
 *
 * `beneficiary_then_release` and `prepare_then_release` have
 * `overridable = false`, and nothing in the application can grant an exception
 * to them. Changing where a supplier's money goes and then sending money there
 * is the single most common occupational fraud there is, and an override
 * mechanism for it would be the first thing an insider reached for.
 *
 * A two-person company genuinely cannot satisfy them. That is the correct
 * answer: the control says a second person must exist, and no amount of
 * configuration makes one appear.
 *
 * @property string $key
 * @property string $duty_a
 * @property string $duty_b
 * @property DutyScope $scope
 * @property bool $overridable
 * @property bool $active
 */
final class SodRule extends TenantModel
{
    protected $table = 'sod_rules';

    protected $fillable = [
        'key', 'duty_a', 'duty_b', 'scope',
        'rationale_ar', 'rationale_en', 'overridable', 'active',
    ];

    protected $casts = [
        'scope' => DutyScope::class,
        'overridable' => 'boolean',
        'active' => 'boolean',
    ];

    /** @return HasMany<SodOverride, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(SodOverride::class, 'sod_rule_id');
    }

    /** The duty that conflicts with the one given, or null if this rule is not about it. */
    public function conflictingDuty(string $duty): ?string
    {
        return match ($duty) {
            $this->duty_b => $this->duty_a,
            $this->duty_a => $this->duty_b,
            default => null,
        };
    }

    public function rationale(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($locale === 'ar' ? $this->rationale_ar : $this->rationale_en);
    }
}
