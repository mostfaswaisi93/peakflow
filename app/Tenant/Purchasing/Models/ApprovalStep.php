<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * A step as it stands in a live chain - PF-136.
 *
 * Copied from the rule rather than read through it, so the chain is a
 * record. A rule changed halfway through an approval would otherwise move
 * the goalposts under the people already in it, and nobody could answer
 * "who was supposed to approve this" about a document from last month.
 *
 * @property int $sequence
 * @property int $quorum
 * @property int $approvals
 * @property string $status
 * @property string|null $role
 */
final class ApprovalStep extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'approval_steps';

    protected $fillable = [
        'entity_id', 'approval_request_id', 'sequence', 'quorum', 'approvals', 'status',
        'approval_rule_id', 'rule_code', 'role', 'user_id', 'limit_amount',
        'escalate_after_hours', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'quorum' => 'integer',
        'approvals' => 'integer',
        'escalate_after_hours' => 'integer',
        'opened_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
    ];

    public function isSatisfied(): bool
    {
        return $this->status === 'approved';
    }

    public function needsMore(): int
    {
        return max(0, $this->quorum - $this->approvals);
    }
}
