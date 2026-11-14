<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Platform\Enums\PlanFeature;
use App\Platform\Enums\ProvisionMode;
use App\Platform\Enums\TenantStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer of the platform.
 *
 * A tenant is NOT a company - it is a customer, and a customer may run several
 * legal companies. A group with a holding company, two operating companies and
 * a free-zone subsidiary is ONE tenant with FOUR entities. Conflating the two
 * is a migration across every financial table in the system, which is why the
 * distinction is in the schema from the first commit.
 *
 * What a tenant owns: users, parties, items, currencies, FX rates, documents.
 * What an ENTITY owns and does not share: the chart of accounts, the functional
 * currency, the fiscal calendar, tax registrations, numbering series, and every
 * posting.
 */
final class Tenant extends PlatformModel
{
    use SoftDeletes;

    protected $table = 'tenants';

    protected $fillable = [
        'slug', 'name', 'default_locale', 'timezone', 'data_region',
        'status', 'provision_mode', 'schema_version', 'provisioned_at',
    ];

    public function connection(): HasOne
    {
        return $this->hasOne(DatabaseConnection::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function impersonationGrants(): HasMany
    {
        return $this->hasMany(ImpersonationGrant::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** The subscription that currently governs access, if any. */
    public function currentSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->whereIn('status', ['trial', 'active', 'past_due'])
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * Can a request be served against this tenant right now?
     *
     * Two independent gates. The STATUS answers "is this workspace usable",
     * and the SUBSCRIPTION answers "is this customer entitled". A suspended
     * tenant with a valid subscription is still refused, and an active tenant
     * whose subscription lapsed is too - they are different facts with
     * different remedies, and collapsing them produces an error message that
     * tells nobody what to do.
     */
    public function isServable(?DateTimeInterface $asOf = null): bool
    {
        if (! $this->status->isServable()) {
            return false;
        }

        return $this->currentSubscription()?->entitlesAccess($asOf) ?? false;
    }

    public function isMigrating(): bool
    {
        return $this->status === TenantStatus::Migrating;
    }

    public function hasFeature(PlanFeature $feature): bool
    {
        return $this->currentSubscription()?->plan->has($feature) ?? false;
    }

    public function allowsJurisdiction(string $profile): bool
    {
        return $this->currentSubscription()?->plan->allowsJurisdiction($profile) ?? false;
    }

    /**
     * Whether an operator may currently impersonate a user here.
     *
     * Consent is recorded by the TENANT, in advance, and expires. An operator
     * can never read a tenant's document contents without impersonation, so
     * the access is always attributable - and never unilateral.
     */
    public function allowsImpersonation(): bool
    {
        return $this->impersonationGrants()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /** Is the tenant's database behind the application's expected schema? */
    public function isBehindSchema(string $expected): bool
    {
        return $this->schema_version !== null && $this->schema_version !== $expected;
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'provision_mode' => ProvisionMode::class,
            'provisioned_at' => 'datetime',
            'migrating_since' => 'datetime',
        ];
    }
}
