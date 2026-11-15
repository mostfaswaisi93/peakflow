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

    /**
     * `created_at` is stamped here, not left to the caller.
     *
     * The table has no `updated_at` - the log is append-only, so there is
     * nothing to update - which means `$timestamps = false` and Laravel stops
     * filling `created_at` too. Every caller then has to remember a NOT NULL
     * column, and the one that forgets finds out at runtime, in whichever
     * scheduled job happened to be the first to log something.
     */
    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->created_at ??= now();
        });
    }

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
