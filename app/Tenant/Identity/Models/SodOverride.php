<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded, approved, expiring exception to one SoD rule - PF-034.
 *
 * Every field exists to answer a question an auditor will ask: who, for which
 * rule, in which entity, why, approved by whom, until when, and was it
 * revoked. An override with no expiry is a permanent change to the control
 * environment wearing the costume of an exception, so `expires_at` is NOT
 * NULL.
 *
 * The approver must not be the beneficiary. That is checked in the engine
 * rather than here, because the message a user needs to read when it happens
 * belongs with the refusal.
 *
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $revoked_at
 */
final class SodOverride extends TenantModel
{
    use BelongsToEntity;

    protected $table = 'sod_overrides';

    protected $fillable = [
        'sod_rule_id', 'entity_id', 'user_id', 'reason',
        'approved_by_user_id', 'approved_at', 'expires_at', 'revoked_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<SodRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SodRule::class, 'sod_rule_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Is this override usable right now?
     *
     * Approved, not revoked, and not expired. Valid THROUGH its expiry
     * instant, so the comparison is strictly greater - the same boundary
     * convention as a subscription, and it has its own test for the same
     * reason.
     */
    public function isUsable(): bool
    {
        if ($this->approved_at === null || $this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at->greaterThan(now());
    }
}
