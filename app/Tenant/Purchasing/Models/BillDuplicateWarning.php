<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A suspected duplicate, and what somebody decided about it - PF-139.
 *
 * @property string $reason
 * @property string $detail
 * @property string $raised_at_stage
 */
final class BillDuplicateWarning extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'bill_duplicate_warnings';

    protected $fillable = [
        'entity_id', 'bill_id', 'suspected_bill_id', 'reason', 'detail',
        'amount_difference', 'days_apart',
        'acknowledged_at', 'acknowledged_by', 'acknowledgement', 'raised_at_stage',
    ];

    protected $casts = [
        'acknowledged_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return BelongsTo<Bill, $this> */
    public function suspected(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'suspected_bill_id');
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
