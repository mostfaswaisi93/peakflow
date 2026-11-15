<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Platform\Exceptions\ImpersonationRefused;
use App\Platform\Models\ActivityLog;
use App\Platform\Models\ImpersonationGrant;
use App\Platform\Models\Operator;
use App\Platform\Models\Tenant;
use App\Tenant\Identity\Models\User;
use App\Tenant\Identity\Services\AuditLog;
use Illuminate\Http\Request;

/**
 * Lets an operator act inside a tenant, with the customer's prior consent -
 * PF-036.
 *
 * ## Consent is recorded in advance, by the customer
 *
 * An operator cannot grant themselves access. `ImpersonationGrant` is created
 * by a user OF THE TENANT, carries a reason and an expiry, and can be revoked.
 * Without a usable grant this refuses, and the refusal is itself recorded.
 *
 * That is the difference between a support tool and a back door. A back door
 * is one an operator can open; this one is opened from the inside.
 *
 * ## The event is written in BOTH planes
 *
 * The platform log records that an operator did it - that is our accountability
 * and it survives the tenant being deleted. The tenant's own audit log records
 * that it happened to them - that is their evidence, and it stays in their
 * hash chain where they can verify it.
 *
 * One without the other is a gap someone will find: a platform-only record is
 * invisible to the customer during their own audit, and a tenant-only record
 * disappears with the tenant.
 */
final class ImpersonationService
{
    public function __construct(
        private readonly TenantConnectionResolver $resolver,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Begin impersonating, if the tenant has consented.
     *
     * @throws ImpersonationRefused
     */
    public function begin(Operator $operator, Tenant $tenant, User $as, Request $request): ImpersonationGrant
    {
        $grant = ImpersonationGrant::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('expires_at')
            ->first();

        if ($grant === null) {
            $this->refuse($operator, $tenant, 'the tenant has granted no usable impersonation consent');

            throw ImpersonationRefused::withoutConsent($tenant);
        }

        // OUR record: survives the tenant being deleted.
        ActivityLog::create([
            'operator_id' => $operator->getKey(),
            'tenant_id' => $tenant->getKey(),
            'category' => 'impersonation',
            'action' => 'impersonation.started',
            'context' => [
                'grant_id' => (string) $grant->getKey(),
                'as_user_id' => (string) $as->getKey(),
                'reason' => $grant->reason,
                'expires_at' => $grant->expires_at?->toIso8601String(),
            ],
            'ip_address' => $request->ip(),
        ]);

        // THEIR record: inside their hash chain, where they can verify it.
        $this->audit->record(
            category: 'impersonation',
            action: 'impersonation.started',
            subjectType: User::class,
            subjectId: (string) $as->getKey(),
            subjectLabel: $as->name,
            after: [
                'operator' => $operator->email,
                'grant_id' => (string) $grant->getKey(),
                'expires_at' => $grant->expires_at?->toIso8601String(),
            ],
            reason: (string) $grant->reason,
            actor: $as,
        );

        $request->session()->put('impersonated_by', $operator->email);
        $request->session()->put('impersonation_grant', (string) $grant->getKey());
        $request->session()->put('tenant', $tenant->slug);

        return $grant;
    }

    public function end(Request $request, ?Operator $operator, ?Tenant $tenant): void
    {
        $email = $request->session()->pull('impersonated_by');
        $request->session()->forget('impersonation_grant');

        if (! is_string($email)) {
            return;
        }

        ActivityLog::create([
            'operator_id' => $operator?->getKey(),
            'tenant_id' => $tenant?->getKey(),
            'category' => 'impersonation',
            'action' => 'impersonation.ended',
            'context' => ['operator' => $email],
            'ip_address' => $request->ip(),
        ]);
    }

    private function refuse(Operator $operator, Tenant $tenant, string $because): void
    {
        ActivityLog::create([
            'operator_id' => $operator->getKey(),
            'tenant_id' => $tenant->getKey(),
            'category' => 'impersonation',
            'action' => 'impersonation.refused',
            'context' => ['because' => $because],
        ]);
    }
}
