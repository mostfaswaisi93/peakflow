<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document's journey through a chain - PF-136.
 *
 * @property string $status
 * @property string $document_fingerprint
 * @property string $amount
 * @property string|null $functional_amount
 */
final class ApprovalRequest extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'approval_requests';

    protected $fillable = [
        'entity_id', 'subject_type', 'subject_id', 'status',
        'currency', 'amount', 'functional_amount', 'fx_rate', 'fx_rate_date',
        'document_fingerprint', 'requested_by', 'requested_at', 'decided_at', 'outcome_detail',
    ];

    protected $casts = [
        'requested_at' => 'immutable_datetime',
        'decided_at' => 'immutable_datetime',
        'fx_rate_date' => 'immutable_date',
    ];

    /** @return HasMany<ApprovalStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('sequence');
    }

    /** @return HasMany<ApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class)->orderBy('decided_at');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
