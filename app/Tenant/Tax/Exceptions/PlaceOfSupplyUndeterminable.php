<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use App\Tenant\Tax\PlaceOfSupplyRule;
use App\Tenant\Tax\SupplyContext;
use RuntimeException;

/**
 * A place of supply that will not be guessed - PF-078.
 *
 * ## The fallback is a refusal, and that is the design
 *
 * Every alternative fallback is wrong in a way that issues a document. Taxing
 * it domestically overcharges a customer who will not pay it and understates
 * a liability abroad. Zero-rating it is the error an authority assesses years
 * later with penalties. Picking the customer's country invents a registration
 * the business may not have.
 *
 * So the engine refuses, and the refusal says what it would have needed. A
 * document that cannot be placed cannot be issued, which is an argument the
 * business has with its own data rather than with a tax authority.
 */
final class PlaceOfSupplyUndeterminable extends RuntimeException
{
    public static function unknownCountry(string $alpha2, string $role): self
    {
        return new self(sprintf(
            'The %s country "%s" is not an ISO 3166-1 alpha-2 code. Checked here rather than left to the '.
            'rule book, because a code no rule can match produces "no rule matched" - which sends '.
            'somebody looking for a missing rule when the defect is in the party record.',
            $role,
            $alpha2,
        ));
    }

    public static function noRuleMatched(SupplyContext $supply, int $rules): self
    {
        return new self(sprintf(
            'No place-of-supply rule matches this supply: %s. The rule book holds %d rules and none of '.
            'them covers this combination, so there is no jurisdiction to charge. This refuses rather '.
            'than defaulting to the supplier\'s country: a default would issue a document, and a '.
            'document issued in the wrong jurisdiction is found by an authority rather than by the '.
            'business. Add the rule, with its legal source, to config/place_of_supply.php.',
            $supply->describe('en'),
            $rules,
        ));
    }

    public static function needsTheDeliveryLocation(PlaceOfSupplyRule $rule, SupplyContext $supply): self
    {
        return new self(sprintf(
            'The place of supply cannot be decided without a delivery location: %s. Rule "%s" '.
            '(precedence %d) applies to this supply in every other respect and turns on where the goods '.
            'went. Continuing to a lower-precedence rule would answer with a rule that this one '.
            'outranks, which is the engine guessing while appearing to follow its own order. The '.
            'delivery location may be defaulted from the customer\'s address - on the document, where '.
            'it is stored and visible, not here.',
            $supply->describe('en'),
            $rule->id,
            $rule->precedence,
        ));
    }
}
