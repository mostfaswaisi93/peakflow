<?php

declare(strict_types=1);

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's advance consent for an operator to impersonate one of its users.
 *
 * Deliberately a grant from the tenant rather than a permission on the
 * operator: support access to a customer's financial records is the customer's
 * decision, it is time-limited, and it is revocable. The impersonated session
 * is banner-marked and writes an audit event in BOTH planes.
 */
final class ImpersonationGrant extends PlatformModel
{
    protected $table = 'impersonation_grants';

    protected $fillable = ['tenant_id', 'granted_by_user_id', 'reason', 'expires_at', 'revoked_at'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
