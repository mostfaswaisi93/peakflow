<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * Who may act for whom, and when - PF-137.
 *
 * @property string $from_user_id
 * @property string $to_user_id
 * @property string|null $duty
 */
final class ApprovalDelegation extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'approval_delegations';

    protected $fillable = [
        'entity_id', 'from_user_id', 'to_user_id', 'starts_on', 'ends_on',
        'duty', 'limit_amount', 'reason', 'created_by', 'revoked_at', 'revoked_by',
    ];

    protected $casts = [
        'starts_on' => 'immutable_date',
        'ends_on' => 'immutable_date',
        'revoked_at' => 'immutable_datetime',
    ];

    public function coversOn(\DateTimeInterface $date): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        $day = \Illuminate\Support\Carbon::parse($date)->startOfDay();

        return $this->starts_on->lte($day) && $this->ends_on->gte($day);
    }
}
