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

    /**
     * A list derived from one that has no price for this item - PF-254.
     *
     * Refused rather than falling through to the next candidate. A rule
     * with nothing to apply to is a configuration mistake, and quietly
     * using the next list down prices the order from a list the
     * agreement does not mention.
     */
    public static function aDerivationWithNoBase(
        string $listCode,
        string $itemId,
        string $currency,
        string $on,
    ): self {
        return new self(sprintf(
            'Price list %s is computed from another list, and that list has no price in %s for this '.
            'item on %s. A rule with nothing to apply to cannot produce a figure, and using the next '.
            'list down would price the order from a list the agreement does not mention. Item: %s.',
            $listCode,
            $currency,
            $on,
            $itemId,
        ));
    }

    /**
     * Two lists deriving from each other - PF-254.
     *
     * The column constraint stops a list deriving from itself. A cycle
     * of two is still expressible, and without this the symptom is a
     * stack overflow whose message names a stack rather than a price
     * list.
     */
    public static function aDerivationThatLoops(string $listCode, string $itemId): self
    {
        return new self(sprintf(
            'Price list %s is computed from a list that is computed, directly or through others, from '.
            '%s. The chain has no bottom, so there is no figure at the end of it. Item: %s.',
            $listCode,
            $listCode,
            $itemId,
        ));
    }

    public static function anUnknownDerivation(string $listCode, string $kind): self
    {
        return new self(sprintf(
            'Price list %s says it is computed by a "%s", which is not a rule this release knows. The '.
            'three it knows are a margin on the selling price, a markup on the cost, and a discount off '.
            'the base - and they are different arithmetic.',
            $listCode,
            $kind,
        ));
    }
}
