<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use code for a user who has lost their second factor - PF-030.
 *
 * Hashed, because a recovery code is a password: a readable list of them in
 * the database is a list of account takeovers waiting for a database leak.
 *
 * Single use, enforced by `used_at`. Codes are not re-issued individually -
 * the whole set is replaced - because a user who has used three of ten has a
 * problem that topping up does not solve, and because partial regeneration
 * makes "how many does this user have left" unanswerable.
 *
 * @property ?\Illuminate\Support\Carbon $used_at
 */
final class RecoveryCode extends TenantModel
{
    protected $table = 'recovery_codes';

    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    protected $hidden = ['code_hash'];

    protected $casts = ['used_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
