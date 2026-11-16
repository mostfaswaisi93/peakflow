<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * A step of a rule: who, how many of them, and up to how much - PF-136.
 *
 * Steps sharing a `sequence` are parallel. `quorum` is how many of that
 * step's approvers have to agree: two of three is a real policy, and a
 * chain that could only express "all" or "any" would make the business pick
 * the wrong one.
 *
 * @property int $sequence
 * @property int $quorum
 * @property string|null $role
 */
final class ApprovalRuleStep extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'approval_rule_steps';

    protected $fillable = [
        'entity_id', 'approval_rule_id', 'sequence', 'quorum',
        'role', 'user_id', 'limit_amount', 'escalate_after_hours',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'quorum' => 'integer',
        'escalate_after_hours' => 'integer',
    ];
}
