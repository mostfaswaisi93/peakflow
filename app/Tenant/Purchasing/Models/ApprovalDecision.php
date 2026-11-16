<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;

/**
 * One person's decision, on a document as it was - PF-136.
 *
 * `fingerprint` is what this approver actually saw. If it differs from the
 * request's current one, the approval was given on a document that has since
 * changed - which is the hole PF-136 exists to close and the reason an
 * approval is not a boolean.
 *
 * @property string $decision
 * @property string $fingerprint
 * @property string|null $on_behalf_of
 */
final class ApprovalDecision extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'approval_decisions';

    protected $fillable = [
        'entity_id', 'approval_request_id', 'approval_step_id', 'user_id',
        'decision', 'comment', 'fingerprint', 'on_behalf_of', 'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'immutable_datetime',
    ];
}
