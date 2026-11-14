<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Platform\Enums\SubscriptionStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Subscription extends PlatformModel
{
    protected $table = 'subscriptions';

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'starts_on', 'ends_on',
        'trial_ends_on', 'suspended_at', 'suspended_reason',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Has this subscription run out as at the given date?
     *
     * A subscription is valid **THROUGH** its end date. The comparison is
     * therefore strictly `>`: a subscription ending on the 31st is still valid
     * on the 31st and expires on the 1st.
     *
     * Off by one here either cuts a paying customer off a day early or lets an
     * expired one trade for a day, so the boundary has its own test rather
     * than being left to a reader of this comment.
     */
    public function hasExpired(?DateTimeInterface $asOf = null): bool
    {
        if ($this->ends_on === null) {
            return false;
        }

        $asOf = $asOf !== null
            ? \Illuminate\Support\Carbon::instance($asOf)->startOfDay()
            : \Illuminate\Support\Carbon::today();

        return $asOf->greaterThan($this->ends_on->startOfDay());
    }

    public function entitlesAccess(?DateTimeInterface $asOf = null): bool
    {
        return $this->status->entitlesAccess() && ! $this->hasExpired($asOf);
    }

    public function isInTrial(?DateTimeInterface $asOf = null): bool
    {
        if ($this->trial_ends_on === null) {
            return false;
        }

        $asOf = $asOf !== null
            ? \Illuminate\Support\Carbon::instance($asOf)->startOfDay()
            : \Illuminate\Support\Carbon::today();

        return ! $asOf->greaterThan($this->trial_ends_on->startOfDay());
    }

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'trial_ends_on' => 'date',
            'suspended_at' => 'datetime',
        ];
    }
}
