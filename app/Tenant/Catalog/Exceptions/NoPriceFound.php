<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Exceptions;

/**
 * No price list covers this item, currency, party and date - PF-065.
 *
 * ## Refused, not defaulted to zero
 *
 * A zero price invoices nothing and still balances: the line appears, the
 * total is short, the VAT is short, and the customer pays what they were
 * asked for. It is found when the margin report is read, by which point the
 * invoice has been issued and the return filed.
 *
 * ## The message names all four coordinates
 *
 * A price depends on the item, the currency, the party and the DATE, and the
 * usual cause is the fourth: a list that expired, or one whose `valid_from`
 * is tomorrow because somebody set it up for a price rise. A message that
 * says only "no price for item X" sends the reader to the item.
 */
final class NoPriceFound extends CatalogException
{
    public static function forItem(
        string $itemId,
        string $currency,
        string $date,
        ?string $partyId,
    ): self {
        return new self(sprintf(
            'No price list covers this item in %s on %s%s. This was refused rather than priced at zero, '.
            'because a zero line invoices nothing, balances, and is found when somebody reads the margin '.
            'report. Check whether a list expired or starts later - a window is the usual cause.',
            $currency,
            $date,
            $partyId === null ? '' : ' for this customer',
        ));
    }

    /**
     * A percentage-off line with nothing underneath it.
     *
     * Refused rather than treated as the price or as free. "10% off" with no
     * base is a configuration mistake, and both alternatives invoice a
     * number nobody chose - one of them being zero.
     */
    public static function discountWithNoBase(
        string $listCode,
        string $itemId,
        string $currency,
        string $date,
    ): self {
        return new self(sprintf(
            'Price list "%s" gives a percentage off for this item, and there is no less specific list in %s '.
            'on %s for it to be a percentage OF. A discount needs something to discount; add a base price, '.
            'or put a price on this list instead of a percentage.',
            $listCode,
            $currency,
            $date,
        ));
    }
}
