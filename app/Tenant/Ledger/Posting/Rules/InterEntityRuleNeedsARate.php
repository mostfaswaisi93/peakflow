<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Posting\Rules;

use RuntimeException;

/**
 * An inter-entity side could not be translated - PF-057.
 *
 * Thrown rather than defaulted, because every default here is wrong. A rate
 * of 1 asserts the two currencies are at par; looking one up inside the rule
 * would make `Ledger` depend on the catalog; and posting the settlement
 * figure untranslated would leave the entry unbalanced by the whole
 * translation while still summing to zero in the settlement currency - which
 * is the kind of wrong that balances.
 */
final class InterEntityRuleNeedsARate extends RuntimeException
{
    public static function for(string $settlement, string $functional): self
    {
        return new self(sprintf(
            'An inter-entity transaction settled in %s cannot be posted into books kept in %s without a '.
            'rate. The rate is passed in rather than looked up, because the ledger does not know what the '.
            'catalog is - and it is not defaulted to 1, because that would assert the two currencies are '.
            'at par and the assertion would be invisible afterwards.',
            $settlement,
            $functional,
        ));
    }
}
