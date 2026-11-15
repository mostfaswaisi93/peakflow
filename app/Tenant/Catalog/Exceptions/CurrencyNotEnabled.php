<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Exceptions;

/**
 * The entity does not transact in this currency - PF-067.
 *
 * The alternative to refusing is converting at some rate, and "some rate" is
 * the problem: nobody chose it, nobody reviewed it, and the resulting number
 * is plausible. A refusal here costs someone thirty seconds in a settings
 * screen; a silent conversion costs a reconciliation.
 */
final class CurrencyNotEnabled extends CatalogException
{
    public static function forEntity(string $currency, string $entityName, string $functional): self
    {
        return new self(sprintf(
            '%s does not transact in %s - its books are in %s. This was refused rather than converted, because '.
            'a conversion at a rate nobody chose produces a figure that looks right. Enable %s for this '.
            'company first if it genuinely deals in it.',
            $entityName,
            $currency,
            $functional,
            $currency,
        ));
    }

    public static function unknownCode(string $currency): self
    {
        return new self(sprintf(
            '"%s" is not an active ISO 4217 currency code, so it cannot be enabled.',
            $currency,
        ));
    }
}
