<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Services;

use App\Tenant\Catalog\Models\PaymentTerm;
use App\Tenant\Catalog\Services\DueDateCalculator;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Parties\Models\PartyAddress;
use App\Tenant\Parties\Models\PartyTaxRegistration;
use App\Tenant\Sales\Exceptions\SnapshotRefused;
use App\Tenant\Tenancy\Models\Entity;
use App\Tenant\Tenancy\Models\TaxRegistration;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Freezes what an issued document says about the world - PF-091.
 *
 * ## Why a service rather than a cast on the model
 *
 * Because freezing is an act with a date. "The customer's registration" is
 * meaningless without "as at when": a party can hold three registrations in
 * one jurisdiction over time, and the one that belongs on a document is the
 * one valid on its tax point - not the primary one, and not the latest.
 *
 * ## Refuses rather than freezing a blank
 *
 * A snapshot with an empty legal name is worse than no snapshot: the document
 * looks complete, the reprint shows nothing where the customer's name should
 * be, and nobody finds out until a dispute. So the pieces a document cannot
 * legally omit are required here, and the refusal says which.
 *
 * What is NOT required is an address: plenty of regimes accept an invoice
 * without the customer's, and refusing one would make the stricter rule the
 * universal one. The e-invoicing profiles in Phase 06 are where a jurisdiction
 * says it needs more.
 */
final class Snapshots
{
    public function __construct(private readonly DueDateCalculator $dueDates) {}

    /**
     * The issuing entity's own legal identity.
     *
     * @return array<string, mixed>
     *
     * @throws SnapshotRefused
     */
    public function supplier(Entity $entity, string $jurisdiction, CarbonInterface $on): array
    {
        $name = trim((string) $entity->legal_name);

        if ($name === '') {
            throw SnapshotRefused::withoutALegalName('supplier');
        }

        $registration = $entity->taxRegistrationOn($jurisdiction, $on);

        return [
            'entity_id' => (string) $entity->getKey(),
            'code' => (string) $entity->code,
            'legal_name' => $name,
            'trading_name' => $entity->trading_name === null ? null : (string) $entity->trading_name,
            'registration_number' => $entity->registration_number === null
                ? null
                : (string) $entity->registration_number,
            'country' => (string) $entity->country,
            'address' => $entity->address,
            'registration' => $registration === null ? null : $this->entityRegistration($registration),
            'frozen_at' => $on->toDateString(),
        ];
    }

    /**
     * The customer, as at the tax point.
     *
     * @return array<string, mixed>
     *
     * @throws SnapshotRefused
     */
    public function customer(Party $party, string $jurisdiction, CarbonInterface $on): array
    {
        $name = trim((string) $party->legal_name);

        if ($name === '') {
            throw SnapshotRefused::withoutALegalName('customer');
        }

        $registration = $party->registrationIn($jurisdiction, $on);
        $billing = $party->addressFor('billing') ?? $party->addressFor('registered');

        return [
            'party_id' => (string) $party->getKey(),
            'code' => (string) $party->code,
            'legal_name' => $name,
            'trading_name' => $party->trading_name === null ? null : (string) $party->trading_name,
            'registration_number' => $party->registration_number === null
                ? null
                : (string) $party->registration_number,
            'country' => (string) $party->country,
            'address' => $billing === null ? null : $this->address($billing),
            'registration' => $registration === null ? null : $this->partyRegistration($registration),
            'frozen_at' => $on->toDateString(),
        ];
    }

    /**
     * The payment terms and the dates they produced.
     *
     * The DATES, not just the terms. "Net 30 from the end of the month,
     * adjusted for weekends and the holiday calendar" resolves to a date
     * through a policy that an operator can change, so a document that stored
     * only the terms would have its due date move - and a dunning run would
     * chase an invoice that was not yet due, or miss one that was.
     *
     * @return array<string, mixed>
     */
    public function terms(
        PaymentTerm $terms,
        Entity $entity,
        CarbonInterface $documentDate,
        ?\App\Support\Money\Money $total = null,
    ): array {
        $resolved = $this->dueDates->for(
            $terms,
            Carbon::parse($documentDate->toDateString()),
            (string) $entity->getKey(),
            $total,
        );

        return [
            'payment_term_id' => (string) $terms->getKey(),
            'code' => (string) $terms->code,
            'label_en' => (string) $terms->name_en,
            'label_ar' => (string) $terms->name_ar,
            'due_dates' => $resolved['due_dates'],
            'discount' => $resolved['discount'],
            'frozen_at' => $documentDate->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function entityRegistration(TaxRegistration $registration): array
    {
        return [
            'jurisdiction' => (string) $registration->jurisdiction,
            'scheme' => (string) $registration->scheme,
            'identifier' => (string) $registration->identifier,
            /*
             | The EAS scheme, frozen with the rest - PF-120, BT-34.
             |
             | Peppol routes on the seller's electronic address and its
             | scheme. Frozen rather than read live, because a document
             | reserialized next year must carry the address it was sent
             | from: the recipient's copy says that one, and the two have to
             | hash the same.
             */
            'eas_code' => $registration->eas_code === null ? null : (string) $registration->eas_code,
            'qualified' => sprintf(
                '%s:%s:%s',
                $registration->jurisdiction,
                $registration->scheme,
                $registration->identifier,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function partyRegistration(PartyTaxRegistration $registration): array
    {
        return [
            'jurisdiction' => (string) $registration->jurisdiction,
            'scheme' => (string) $registration->scheme,
            'identifier' => (string) $registration->identifier,
            'eas_code' => $registration->eas_code === null ? null : (string) $registration->eas_code,
            'qualified' => $registration->qualified(),
            'valid_from' => $registration->startsOn()->toDateString(),
            'valid_until' => $registration->endsBefore()?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function address(PartyAddress $address): array
    {
        return [
            'type' => (string) $address->type,
            'line_1' => $address->line_1 === null ? null : (string) $address->line_1,
            'line_2' => $address->line_2 === null ? null : (string) $address->line_2,
            'line_3' => $address->line_3 === null ? null : (string) $address->line_3,
            'city' => $address->city === null ? null : (string) $address->city,
            'postal_code' => $address->postal_code === null ? null : (string) $address->postal_code,
            'country_subdivision' => $address->country_subdivision === null
                ? null
                : (string) $address->country_subdivision,
            'country' => (string) $address->country,
        ];
    }
}
