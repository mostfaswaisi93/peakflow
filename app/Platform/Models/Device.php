<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A till, a handheld, or an integration that holds a token - PF-255.
 *
 * Lives in the operator's database because a token has to resolve to a
 * tenant before any tenant connection is opened, and because a
 * revocation has to be enforced in exactly one place - a device row per
 * tenant database means a stolen till is revoked in one and still
 * working in another.
 *
 * @property string $name
 * @property string $kind
 * @property ?string $revoked_at
 */
final class Device extends PlatformModel
{
    protected $table = 'devices';

    protected $fillable = [
        'tenant_id', 'name', 'kind', 'token_hash', 'token_hint',
        'registered_at', 'registered_by',
        'last_seen_at', 'last_seen_ip', 'last_seen_version',
        'revoked_at', 'revoked_by', 'revoked_reason',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
