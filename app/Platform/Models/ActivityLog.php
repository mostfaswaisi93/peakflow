<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The operator plane's audit trail. Append-only.
 *
 * `UPDATE` and `DELETE` are revoked for the application role rather than
 * merely unused, because a log that can be edited is not evidence. The tenant
 * plane has its own, hash-chained, log (PF-035); this one records what
 * OPERATORS did.
 *
 * Indexed on `created_at` and on composites LEADING with the filtered column:
 * PostgreSQL uses one index per table reference, so a lone `operator_id` index
 * would let it filter or sort but never both.
 */
final class ActivityLog extends PlatformModel
{
    protected $table = 'activity_logs';

    public $timestamps = false;

    protected $fillable = [
        'operator_id', 'tenant_id', 'category', 'action',
        'context', 'ip_address', 'correlation_id', 'created_at',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * An append-only row is never updated.
     *
     * Guarded in the model as well as at the database role, so the failure
     * surfaces as a clear exception during development rather than as a
     * permission error from PostgreSQL in production.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException(
            'activity_logs is append-only. An audit record that can be edited is not evidence; '.
            'record a new entry describing the correction instead.',
        );
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
