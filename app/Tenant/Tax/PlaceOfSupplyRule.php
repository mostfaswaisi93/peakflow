<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Tenant\Tax\Enums\EvidenceKind;
use App\Tenant\Tax\Enums\RuleOutcome;
use App\Tenant\Tax\Enums\TaxCategory;

/**
 * One row of the place-of-supply rule book - PF-078.
 *
 * ## The vocabulary is small on purpose
 *
 * Five conditions, each one of a handful of words. No expressions, no
 * callbacks, no "and"/"or" nesting. A rule book with a language in it is a
 * program, and a program in configuration is a program nobody tests.
 *
 * What the vocabulary cannot express is added as a word with a meaning
 * written down - which is a change to this class, under review, with a check -
 * rather than as a clever condition in a row somebody edits at 2am.
 *
 * @see config/place_of_supply.php for the vocabulary and the rows
 */
final readonly class PlaceOfSupplyRule
{
    /**
     * @param  string|list<string>  $supplyType
     * @param  string|list<string>  $supplier
     * @param  string|list<string>  $customer
     * @param  string|list<string>  $status
     * @param  string|list<string>  $delivery
     * @param  list<EvidenceKind>  $evidence  what must be on record to claim this treatment
     */
    public function __construct(
        public int $precedence,
        public string $id,
        public string|array $supplyType,
        public string|array $supplier,
        public string|array $customer,
        public string|array $status,
        public string|array $delivery,
        public string $place,
        public TaxCategory $category,
        public array $evidence,
        public string $source,
    ) {}

    /**
     * Evaluate every condition against a supply.
     *
     * The delivery condition is evaluated LAST and separately, so that a rule
     * ruled out by its supply type is a plain `NoMatch` rather than a refusal
     * about a delivery location it never needed. A services supply has no
     * delivery location and must not be blocked by a rule about goods.
     */
    public function evaluate(SupplyContext $supply, Blocs $blocs): RuleOutcome
    {
        $holds = $this->matchesAny($this->supplyType, $supply->type->value)
            && $this->matchesAny($this->status, $supply->status->value)
            && $this->countryHolds($this->supplier, $supply->supplierCountry, $supply, $blocs, false) === true
            && $this->countryHolds($this->customer, $supply->customerCountry, $supply, $blocs, true) === true;

        if (! $holds) {
            return RuleOutcome::NoMatch;
        }

        $delivery = $this->countryHolds($this->delivery, $supply->deliveryCountry, $supply, $blocs, true);

        return match ($delivery) {
            true => RuleOutcome::Matched,
            false => RuleOutcome::NoMatch,
            null => RuleOutcome::NeedsTheDeliveryLocation,
        };
    }

    /** Does this rule's answer depend on where the goods went? */
    public function readsTheDeliveryLocation(): bool
    {
        return $this->delivery !== '*' || $this->place === 'delivery';
    }

    /**
     * @param  string|list<string>  $condition
     * @param  bool  $relativeToTheSupplier  false for the supplier's own country
     * @return bool|null null where the condition cannot be evaluated at all
     */
    private function countryHolds(
        string|array $condition,
        ?string $actual,
        SupplyContext $supply,
        Blocs $blocs,
        bool $relativeToTheSupplier,
    ): ?bool {
        foreach (\is_array($condition) ? $condition : [$condition] as $word) {
            if ($word === '*') {
                return true;
            }

            if ($actual === null) {
                /*
                 | Not false.
                 |
                 | "The condition asks about a country we do not have" is a
                 | different fact from "the country is wrong", and collapsing
                 | the two is how a rule book silently skips its own highest-
                 | precedence rule.
                 */
                return null;
            }

            $held = match (true) {
                $word === 'same' => $actual === $supply->supplierCountry,
                $word === 'abroad' => $actual !== $supply->supplierCountry,
                $word === 'outside' => $actual !== $supply->supplierCountry
                    && ! $blocs->share($supply->supplierCountry, $actual),
                /*
                 | On the customer and the delivery location, `bloc:x`
                 | excludes the supplier's own country, so a bloc rule can
                 | never also catch the domestic case.
                 |
                 | On the SUPPLIER it cannot: there is nothing for the
                 | supplier to differ from, and excluding its own country
                 | would make `supplier => 'bloc:gcc'` match nothing at all -
                 | a condition that is always false, in rules that would then
                 | never fire, which is exactly the kind of defect a rule book
                 | hides. The caller says which side it is asking about.
                 */
                str_starts_with($word, 'bloc:') => (! $relativeToTheSupplier
                    || $actual !== $supply->supplierCountry)
                    && $blocs->isMember(substr($word, 5), $actual),
                default => $actual === $word,
            };

            if ($held) {
                return true;
            }
        }

        return false;
    }

    /** @param string|list<string> $condition */
    private function matchesAny(string|array $condition, string $actual): bool
    {
        foreach (\is_array($condition) ? $condition : [$condition] as $word) {
            if ($word === '*' || $word === $actual) {
                return true;
            }
        }

        return false;
    }
}
