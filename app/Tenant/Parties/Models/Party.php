<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Models;

use App\Support\Database\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A counterparty - PF-059.
 *
 * ## Roles, not a type
 *
 * `is_customer` and `is_supplier` are independent flags. A single `type`
 * column forces the same company to be duplicated the day they start buying
 * from you as well as selling, and the duplicate is what diverges - an
 * address updated on one copy, a bank account on the other, and no way to say
 * which is current.
 *
 * ## Shared across the tenant, owed per entity
 *
 * No `entity_id` here. What a party OWES lives in `PartyEntitySetting`,
 * because a customer billed by two of your companies owes each of them
 * separately, and netting those is a decision somebody makes rather than a
 * consequence of the schema.
 *
 * @property string $legal_name
 * @property bool $is_customer
 * @property bool $is_supplier
 */
final class Party extends TenantModel
{
    use SoftDeletes;

    protected $table = 'parties';

    protected $fillable = [
        'code', 'legal_name', 'trading_name',
        'is_customer', 'is_supplier', 'is_employee',
        'country', 'default_currency', 'default_locale', 'registration_number',
        'tax_treatment', 'payment_term_id', 'risk_grade', 'active',
        'merged_into_id', 'merged_at',
    ];

    protected $casts = [
        'is_customer' => 'boolean',
        'is_supplier' => 'boolean',
        'is_employee' => 'boolean',
        'active' => 'boolean',
        'merged_at' => 'datetime',
    ];

    /** @return HasMany<PartyBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PartyBankAccount::class);
    }

    /** @return HasMany<PartyAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(PartyAddress::class);
    }

    /** @return HasMany<PartyEntitySetting, $this> */
    public function entitySettings(): HasMany
    {
        return $this->hasMany(PartyEntitySetting::class);
    }

    /** @return HasMany<PartyTaxRegistration, $this> */
    public function taxRegistrations(): HasMany
    {
        return $this->hasMany(PartyTaxRegistration::class, 'party_id');
    }

    /**
     * The registration that was valid in a jurisdiction on a date.
     *
     * Takes the date rather than defaulting to today, because the question a
     * reverse charge asks is about the TAX POINT - and a document raised in
     * May for a supply in March is judged on March. Defaulting to now would
     * answer with a registration the customer did not have at the time, which
     * is the direction that leaves the supplier owing the tax.
     */
    public function registrationIn(string $jurisdiction, \Carbon\CarbonInterface $on): ?PartyTaxRegistration
    {
        return $this->taxRegistrations()
            ->where('jurisdiction', $jurisdiction)
            ->effectiveOn($on)
            ->orderByDesc('is_primary')
            ->first();
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    /**
     * The address to COPY onto a document at issue - PF-060.
     *
     * Copied, never referenced. An issued invoice is a historical statement
     * about where a party was when it was issued; re-resolving the address
     * later silently rewrites documents that have already been sent, filed
     * and in some jurisdictions cleared by a tax authority.
     */
    public function addressFor(string $type): ?PartyAddress
    {
        return $this->addresses()
            ->where('type', $type)
            ->orderByDesc('is_default')
            ->first()
            ?? $this->addresses()->where('type', 'legal')->first();
    }
}
