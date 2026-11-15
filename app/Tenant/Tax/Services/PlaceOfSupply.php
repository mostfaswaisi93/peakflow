<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Services;

use App\Tenant\Tax\Enums\RuleOutcome;
use App\Tenant\Tax\Exceptions\PlaceOfSupplyUndeterminable;
use App\Tenant\Tax\PlaceOfSupplyDecision;
use App\Tenant\Tax\PlaceOfSupplyRule;
use App\Tenant\Tax\SupplyContext;

/**
 * Decides which jurisdiction's tax applies to a supply - PF-078.
 *
 * ## First match wins, and so does the first block
 *
 * Rules are evaluated in ascending precedence. The loop stops at the first
 * rule that is not `NoMatch` - which means a rule blocked for want of a
 * delivery location stops it too.
 *
 * That is the point of the whole class. Continuing past a blocked rule would
 * let a lower-precedence rule answer a question that a higher-precedence rule
 * had a claim on, and the answer would look perfectly ordinary: a rule id, a
 * country, a legal source. Nothing in the result would say that the rule
 * which should have decided was never evaluated.
 *
 * ## Nothing here knows a country
 *
 * Every country, bloc and article is in `config/place_of_supply.php`. This
 * class knows how to compare and how to refuse.
 *
 * @see docs/domain/place-of-supply.md
 */
final class PlaceOfSupply
{
    public function __construct(private readonly PlaceOfSupplyRuleBook $book) {}

    /**
     * @throws PlaceOfSupplyUndeterminable
     */
    public function decide(SupplyContext $supply): PlaceOfSupplyDecision
    {
        $rules = $this->book->rules();
        $blocs = $this->book->blocs();

        foreach ($rules as $rule) {
            $outcome = $rule->evaluate($supply, $blocs);

            if ($outcome === RuleOutcome::NoMatch) {
                continue;
            }

            if ($outcome === RuleOutcome::NeedsTheDeliveryLocation) {
                throw PlaceOfSupplyUndeterminable::needsTheDeliveryLocation($rule, $supply);
            }

            $country = $supply->place($rule->place);

            if ($country === null) {
                /*
                 | Unreachable, and asserted rather than assumed.
                 |
                 | The rule book refuses a rule that answers with the delivery
                 | location without constraining it, so a matched rule always
                 | has a country. If that validation is ever weakened this
                 | refuses instead of returning a decision with an empty
                 | country, which would reach a document.
                 */
                throw PlaceOfSupplyUndeterminable::needsTheDeliveryLocation($rule, $supply);
            }

            return PlaceOfSupplyDecision::from($rule, $country, $supply);
        }

        throw PlaceOfSupplyUndeterminable::noRuleMatched($supply, \count($rules));
    }

    /**
     * The rule that would decide, without deciding.
     *
     * For the tax panel and for explaining a refusal: it reports the first
     * rule that has a claim on the supply even when that rule cannot be
     * evaluated, which is the one an accountant needs to see.
     */
    public function rulePreferredFor(SupplyContext $supply): ?PlaceOfSupplyRule
    {
        $blocs = $this->book->blocs();

        foreach ($this->book->rules() as $rule) {
            if ($rule->evaluate($supply, $blocs) !== RuleOutcome::NoMatch) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Every rule, for the documentation and the checks.
     *
     * @return list<PlaceOfSupplyRule>
     */
    public function rules(): array
    {
        return $this->book->rules();
    }
}
