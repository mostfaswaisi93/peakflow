<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof that a user re-authenticated for ONE specific act - PF-032.
 *
 * ## Scoped to the act, not to the session
 *
 * `authorized_for` is a permission key and `subject_id` is the thing it
 * applies to. An assertion obtained to release payment PV-41 does not
 * authorise releasing PV-42.
 *
 * That is the entire point. A session-wide "recently authenticated" flag is
 * what most applications implement, and it means a user who proved themselves
 * to approve one invoice has, for the next fifteen minutes, silently proved
 * themselves for every other thing re-authentication protects - including the
 * one an attacker with their unlocked laptop wants.
 *
 * ## Single use
 *
 * `consumed_at` is set when the act happens. An assertion is not a window
 * during which the act may be repeated; it is permission for one act. Two
 * payments require two proofs.
 *
 * @property string $authorized_for
 * @property ?string $subject_id
 * @property \Illuminate\Support\Carbon $expires_at
 * @property ?\Illuminate\Support\Carbon $consumed_at
 */
final class StepUpAssertion extends TenantModel
{
    protected $table = 'step_up_assertions';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'session_id', 'authorized_for', 'subject_id',
        'factor_type', 'asserted_at', 'expires_at', 'consumed_at',
    ];

    protected $casts = [
        'asserted_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->greaterThan(now());
    }
}
