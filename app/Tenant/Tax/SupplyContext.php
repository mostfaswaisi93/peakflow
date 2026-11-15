<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Support\Iso\CountryRegistry;
use App\Tenant\Tax\Enums\CustomerStatus;
use App\Tenant\Tax\Enums\SupplyType;
use App\Tenant\Tax\Exceptions\PlaceOfSupplyUndeterminable;
use JsonSerializable;

/**
 * Everything a place-of-supply rule may ask about a supply - PF-078.
 *
 * ## The delivery location is explicitly nullable
 *
 * And constructing it is a choice the caller makes, not a default this class
 * applies. `deliveredTo()` takes the country; `withNoDeliveryLocation()` says
 * there isn't one and reads like the decision it is.
 *
 * The alternative - defaulting the delivery location to the customer's
 * country - is the single most tempting line of code in this file and the one
 * that must not be written here. It is right most of the time, which is what
 * makes it dangerous: a customer in one country taking delivery in another is
 * an export or an intra-bloc supply, and the default would quietly tax it
 * domestically. Phase 05 defaults it on the document, where the value is
 * stored, shown and auditable.
 *
 * ## Countries are validated here
 *
 * Against the vendored ISO 3166-1 register, at construction, because a code
 * that no rule can match produces "no rule matched" - a refusal that sends
 * somebody looking at the rule book for a bug that is in the party record.
 *
 * @see docs/domain/place-of-supply.md
 */
final readonly class SupplyContext implements JsonSerializable
{
    private function __construct(
        public SupplyType $type,
        public string $supplierCountry,
        public string $customerCountry,
        public CustomerStatus $status,
        public ?string $deliveryCountry,
    ) {}

    /**
     * A supply with a known delivery location.
     *
     * @throws PlaceOfSupplyUndeterminable
     */
    public static function deliveredTo(
        SupplyType $type,
        string $supplierCountry,
        string $customerCountry,
        CustomerStatus $status,
        string $deliveryCountry,
    ): self {
        return new self(
            $type,
            self::country($supplierCountry, 'supplier'),
            self::country($customerCountry, 'customer'),
            $status,
            self::country($deliveryCountry, 'delivery'),
        );
    }

    /**
     * A supply with no delivery location, stated rather than defaulted.
     *
     * Correct for a service, and a refusal waiting to happen for goods - any
     * rule about goods tests where they went.
     *
     * @throws PlaceOfSupplyUndeterminable
     */
    public static function withNoDeliveryLocation(
        SupplyType $type,
        string $supplierCountry,
        string $customerCountry,
        CustomerStatus $status,
    ): self {
        return new self(
            $type,
            self::country($supplierCountry, 'supplier'),
            self::country($customerCountry, 'customer'),
            $status,
            null,
        );
    }

    /** Is this country the supplier's own? */
    public function isTheSupplierCountry(string $alpha2): bool
    {
        return $alpha2 === $this->supplierCountry;
    }

    /** The country a symbolic `place` resolves to, or null if it is not known. */
    public function place(string $symbol): ?string
    {
        return match ($symbol) {
            'supplier' => $this->supplierCountry,
            'customer' => $this->customerCountry,
            'delivery' => $this->deliveryCountry,
            default => null,
        };
    }

    public function describe(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        $delivery = $this->deliveryCountry
            ?? ($arabic ? 'غير محدد' : 'not stated');

        return $arabic
            ? sprintf(
                '%s من %s إلى %s (%s)، التسليم في %s',
                $this->type->label('ar'), $this->supplierCountry, $this->customerCountry,
                $this->status->label('ar'), $delivery,
            )
            : sprintf(
                '%s from %s to %s (%s), delivered in %s',
                $this->type->label('en'), $this->supplierCountry, $this->customerCountry,
                $this->status->label('en'), $delivery,
            );
    }

    /** @return array<string, string|null> */
    public function jsonSerialize(): array
    {
        return [
            'supply_type' => $this->type->value,
            'supplier_country' => $this->supplierCountry,
            'customer_country' => $this->customerCountry,
            'customer_status' => $this->status->value,
            'delivery_country' => $this->deliveryCountry,
        ];
    }

    /** @throws PlaceOfSupplyUndeterminable */
    private static function country(string $alpha2, string $role): string
    {
        if (! CountryRegistry::exists($alpha2)) {
            throw PlaceOfSupplyUndeterminable::unknownCountry($alpha2, $role);
        }

        return $alpha2;
    }
}
