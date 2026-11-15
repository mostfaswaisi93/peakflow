<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\PartyTaxRegistration;
use App\Tenant\Tax\CounterpartyRegistrations;
use App\Tenant\Tax\RegisteredIdentifier;
use Carbon\CarbonImmutable;

/**
 * Reads a party's registrations into the shape the tax engine takes - PF-079.
 *
 * ## Why this lives in Sales
 *
 * Because the layering ruleset lets Sales depend on both Parties and Tax, and
 * lets Tax depend on neither. The tax engine declares what it needs - a
 * jurisdiction, a scheme, an identifier and a period - and the module that
 * actually has a party is the one that supplies it.
 *
 * This is the first file in the Sales module and it is deliberately the
 * smallest possible one: a translation, no decisions. Phase 05 builds the rest
 * around it.
 */
final class CounterpartyRegistrationReader
{
    public function for(Party $party): CounterpartyRegistrations
    {
        $registrations = $party->taxRegistrations()
            ->get()
            ->map(static fn (PartyTaxRegistration $r): RegisteredIdentifier => new RegisteredIdentifier(
                $r->jurisdiction,
                $r->scheme,
                $r->identifier,
                $r->startsOn(),
                $r->endsBefore(),
            ))
            ->all();

        return new CounterpartyRegistrations(array_values($registrations));
    }

    /** One party, one jurisdiction, as at a date - the common case. */
    public function validOn(Party $party, string $jurisdiction, CarbonImmutable $date): ?RegisteredIdentifier
    {
        return $this->for($party)->validOn($jurisdiction, $date);
    }
}
