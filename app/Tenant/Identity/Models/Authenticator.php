<?php

declare(strict_types=1);

namespace App\Tenant\Identity\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * A second factor belonging to one user - PF-030.
 *
 * Two types share this table because they answer the same question - "is this
 * the person" - and differ only in how. A passkey carries `credential_id` and
 * `public_key`; a TOTP authenticator carries `secret_encrypted`.
 *
 * ## `sign_count` means the same thing for both
 *
 * For a passkey it is WebAuthn's signature counter. For TOTP it is the last
 * accepted time step. In both cases it is a monotonic counter that must
 * STRICTLY INCREASE, and in both cases a value at or below the stored one
 * means a replay: a cloned authenticator for a passkey, a code captured in
 * transit for TOTP.
 *
 * Reusing the column is deliberate rather than thrifty. The invariant is
 * identical, so one column means one place to get it right.
 *
 * ## The secret is never returned
 *
 * `$hidden` keeps it out of serialization and the accessor is explicit, so
 * reading a TOTP secret is always a visible act in the code that does it. A
 * secret that can be reached by `toArray()` reaches a log line eventually.
 *
 * @property string $type
 * @property ?int $sign_count
 * @property ?\Illuminate\Support\Carbon $confirmed_at
 */
final class Authenticator extends TenantModel
{
    public const TYPE_TOTP = 'totp';

    public const TYPE_PASSKEY = 'passkey';

    protected $table = 'authenticators';

    protected $fillable = [
        'user_id', 'type', 'name', 'credential_id', 'public_key',
        'sign_count', 'confirmed_at', 'last_used_at',
    ];

    protected $hidden = ['secret_encrypted', 'public_key'];

    protected $casts = [
        'sign_count' => 'integer',
        'confirmed_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setSecret(string $secret): void
    {
        $this->attributes['secret_encrypted'] = Crypt::encryptString($secret);
    }

    /**
     * The TOTP secret, decrypted.
     *
     * Deliberately a method and not an accessor: `$authenticator->secret`
     * would be reachable by anything that iterates attributes, and the point
     * of this shape is that every read is written down.
     */
    public function secret(): ?string
    {
        $encrypted = $this->attributes['secret_encrypted'] ?? null;

        return $encrypted === null ? null : Crypt::decryptString((string) $encrypted);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
