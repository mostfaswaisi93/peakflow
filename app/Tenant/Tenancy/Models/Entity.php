<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Models;

use App\Support\Database\TenantModel;
use App\Tenant\Tenancy\Exceptions\FunctionalCurrencyImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A legal company that files its own return - PF-025.
 *
 * ## An entity is not a tenant
 *
 * A tenant is a customer. An entity is a company. One tenant may hold twelve
 * entities, and the distinction is the whole reason this platform is shaped
 * the way it is.
 *
 * An entity owns EXCLUSIVELY: its chart of accounts, its functional currency,
 * its fiscal calendar, its tax registrations, its numbering series, and every
 * posting ever made in it.
 *
 * Shared across the tenant: users, parties, items, currencies, exchange rates
 * and uploaded documents. A customer record is the same customer whichever of
 * your companies is invoicing them; a trial balance never is.
 *
 * ## The functional currency cannot be edited
 *
 * IAS 21 permits a change in functional currency only when the underlying
 * transactions change, and requires the change to be applied PROSPECTIVELY -
 * translating at the rate on the date of change, with no restatement of
 * comparatives. That is a re-presentation of the accounts, not a field edit.
 *
 * So once anything is posted the column is frozen, here and by a database
 * trigger. The trigger is not redundant: this guard protects a user, that one
 * protects the data from a console command, a seeder, or a future maintainer
 * writing raw SQL at 2am.
 *
 * @property string $code
 * @property string $legal_name
 * @property string $country
 * @property string $functional_currency
 * @property ?string $presentation_currency
 * @property bool $active
 */
final class Entity extends TenantModel
{
    use SoftDeletes;

    protected $table = 'entities';

    protected $fillable = [
        'code', 'legal_name', 'trading_name', 'registration_number',
        'country', 'functional_currency', 'presentation_currency',
        'jurisdiction_profiles', 'address', 'logo_path', 'active', 'created_by',
        'timezone',
    ];

    protected $casts = [
        'jurisdiction_profiles' => 'array',
        'address' => 'array',
        'active' => 'boolean',
    ];

    /**
     * The event that lets other modules open what they need for a new entity.
     *
     * Announced rather than called: Tenancy sits below Ledger in the module
     * graph, so calling the chart template from here would invert the
     * dependency and make the tenancy layer unusable without the ledger.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => \App\Tenant\Tenancy\Events\EntityCreated::class,
    ];

    protected static function booted(): void
    {
        static::updating(function (self $entity): void {
            if (! $entity->isDirty('functional_currency')) {
                return;
            }

            if ($entity->hasPostings()) {
                throw FunctionalCurrencyImmutable::hasPostings(
                    $entity->code,
                    (string) $entity->getOriginal('functional_currency'),
                    $entity->functional_currency,
                );
            }
        });
    }

    /** @return HasMany<TaxRegistration, $this> */
    public function taxRegistrations(): HasMany
    {
        return $this->hasMany(TaxRegistration::class);
    }

    /** @return HasMany<EntitySetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(EntitySetting::class);
    }

    /**
     * The registration that identifies this entity to a tax authority today.
     *
     * Registrations are dated because an entity can be re-registered under a
     * new scheme, and an invoice issued last year must carry the number that
     * was current when it was issued - not the one current now.
     */
    public function primaryTaxRegistration(?string $jurisdiction = null): ?TaxRegistration
    {
        return $this->taxRegistrations()
            ->when($jurisdiction !== null, fn ($q) => $q->where('jurisdiction', $jurisdiction))
            ->where('is_primary', true)
            ->whereDate('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()))
            ->first();
    }

    /**
     * The registration that identified this entity on a given date.
     *
     * {@see primaryTaxRegistration()} answers for today, which is the wrong
     * question for a document: its own docblock says an invoice issued last
     * year must carry the number that was current when it was issued, and
     * `now()` cannot answer that. PF-079 needs it as at the TAX POINT - a
     * document raised in May for a supply in March is judged on March - so
     * the date is a parameter and the caller supplies it.
     */
    public function taxRegistrationOn(string $jurisdiction, CarbonInterface $on): ?TaxRegistration
    {
        $date = $on->toDateString();

        return $this->taxRegistrations()
            ->where('jurisdiction', $jurisdiction)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('is_primary')
            ->first();
    }

    /**
     * Has anything been posted in this entity?
     *
     * Checked against the journal rather than against invoices, because the
     * journal is where a financial fact exists. An invoice in draft has
     * changed nothing and must not freeze the currency.
     *
     * The table may not exist yet - the ledger arrives in Phase 02 - so its
     * absence means "nothing is posted", which is true.
     */
    public function hasPostings(): bool
    {
        $connection = $this->getConnection();

        $exists = $connection->selectOne(
            "SELECT to_regclass('public.journal_entries') IS NOT NULL AS present",
        );

        if (! ($exists->present ?? false)) {
            return false;
        }

        return $connection->table('journal_entries')
            ->where('entity_id', $this->getKey())
            ->exists();
    }
}
