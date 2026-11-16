<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * A threshold, in the entity's own currency - PF-138.
 *
 * @property string|null $role
 * @property string|null $user_id
 * @property string $limit_amount
 */
final class ApprovalLimit extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'approval_limits';

    protected $fillable = [
        'entity_id', 'role', 'user_id', 'limit_amount', 'currency',
        'effective_from', 'effective_to', 'created_by', 'reason',
    ];

    protected $casts = [
        'effective_from' => 'immutable_date',
        'effective_to' => 'immutable_date',
    ];

    public function appliesOn(\DateTimeInterface $date): bool
    {
        $day = \Illuminate\Support\Carbon::parse($date)->startOfDay();

        return $this->effective_from->lte($day)
            && ($this->effective_to === null || $this->effective_to->gte($day));
    }
}
