<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rule that may contribute steps to an approval chain - PF-136.
 *
 * Every condition is nullable and an absent one matches everything, so a
 * rule with no conditions is "every document" - which is what a small
 * tenant's single rule looks like, and it should not need four fields set to
 * express it.
 *
 * @property string $code
 * @property int $priority
 * @property bool $stops_here
 * @property bool $active
 */
final class ApprovalRule extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'approval_rules';

    protected $fillable = [
        'entity_id', 'code', 'name_ar', 'name_en', 'document_type',
        'amount_from', 'amount_to', 'party_id', 'account_id',
        'dimension_axis', 'dimension_value', 'priority', 'stops_here', 'active',
    ];

    protected $casts = [
        'stops_here' => 'boolean',
        'active' => 'boolean',
        'priority' => 'integer',
    ];

    /** @return HasMany<ApprovalRuleStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalRuleStep::class)->orderBy('sequence');
    }
}
