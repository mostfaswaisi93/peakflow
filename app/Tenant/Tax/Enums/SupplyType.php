<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Enums;

/**
 * What is being supplied, for place-of-supply purposes only - PF-078.
 *
 * ## Three cases, not a product taxonomy
 *
 * This is not what an item IS - that is the catalogue's business, and a
 * catalogue has dozens of types. This is the only distinction the place-of-
 * supply rules turn on: goods move and services do not, and electronically
 * supplied services are the exception that is taxed where the customer is
 * rather than where the supplier is.
 *
 * A fourth case belongs here the day a rule needs it. Services connected
 * with immovable property, passenger transport and admissions to events each
 * have their own place-of-supply rule in every regime that has one, and each
 * would be a case plus a row in `config/place_of_supply.php`. They are absent
 * because the rows are absent, and `docs/domain/place-of-supply.md` says so -
 * an engine that silently treated a hotel night as an ordinary service would
 * place it in the wrong country, which is why the gap is written down rather
 * than left to be discovered.
 */
enum SupplyType: string
{
    /**
     * Something physical that can be delivered somewhere.
     *
     * The place of supply follows the goods, which is why every rule about
     * goods tests the delivery location and none of them tests the customer's
     * address. A customer in one country can take delivery in another, and
     * when they do it is the delivery that decides.
     */
    case Goods = 'goods';

    /**
     * Anything supplied that is not goods and not electronic.
     *
     * The general rules apply: taxed where the customer is established if
     * the customer is a business, and where the supplier is established if
     * the customer is a consumer. That second one reads backwards to most
     * people and is correct.
     */
    case Services = 'services';

    /**
     * Electronically supplied services, taxed at destination.
     *
     * Downloads, streaming, hosting, software as a service, automated
     * teaching. The defining feature in the code lists is that supply is
     * essentially automated and impossible without information technology -
     * a consultant who emails a report is supplying services, not digital
     * services, and the distinction decides which country is paid.
     */
    case DigitalServices = 'digital_services';

    /** Is this supply one whose place follows a physical movement? */
    public function moves(): bool
    {
        return $this === self::Goods;
    }

    public function label(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return match ($this) {
            self::Goods => $arabic ? 'سلع' : 'goods',
            self::Services => $arabic ? 'خدمات' : 'services',
            self::DigitalServices => $arabic ? 'خدمات رقمية' : 'digital services',
        };
    }
}
