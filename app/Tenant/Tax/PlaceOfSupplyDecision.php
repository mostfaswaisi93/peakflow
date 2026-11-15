<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Tenant\Tax\Enums\EvidenceKind;
use App\Tenant\Tax\Enums\TaxCategory;
use JsonSerializable;

/**
 * Which jurisdiction's tax applies, and which rule said so - PF-078.
 *
 * ## The reasoning travels with the answer
 *
 * The country alone is useless to the person who has to defend it. What an
 * accountant checking an invoice needs is the rule that decided, its
 * precedence, and the article behind it - and what they need it for is to
 * disagree, which is only possible if the reasoning is visible.
 *
 * So this carries the winning rule's id, its precedence, its legal source and
 * the supply it was decided from, and `JsonSerializable` so the whole
 * decision can be frozen onto a document rather than recomputed from party
 * records that will have changed by the time anybody looks.
 */
final readonly class PlaceOfSupplyDecision implements JsonSerializable
{
    /** @param  list<EvidenceKind>  $evidence */
    public function __construct(
        public string $country,
        public TaxCategory $category,
        public string $rule,
        public int $precedence,
        public string $source,
        public SupplyContext $supply,
        public array $evidence = [],
    ) {}

    public static function from(PlaceOfSupplyRule $rule, string $country, SupplyContext $supply): self
    {
        return new self(
            $country,
            $rule->category,
            $rule->id,
            $rule->precedence,
            $rule->source,
            $supply,
            $rule->evidence,
        );
    }

    /** Does the customer account for the tax rather than the supplier? */
    public function isReverseCharge(): bool
    {
        return $this->category->isReverseCharge();
    }

    /**
     * Must evidence be held before this treatment can be claimed?
     *
     * Read from the WINNING RULE, not from the category - with the category's
     * own answer as a floor, so a rule that forgot its list cannot claim less
     * than the code list demands.
     *
     * The rule has to be the authority because the category is not enough.
     * EN 16931 codes an export `G` and the Saudi regime zero-rates it as `Z`,
     * and `Z` is also what a domestic zero-rated foodstuff carries. Derive the
     * requirement from the category and either every loaf of bread needs a
     * customs declaration, or no Saudi export needs one.
     */
    public function requiresEvidence(): bool
    {
        return $this->evidence !== [] || $this->category->requiresEvidence();
    }

    /**
     * What must be on record, as kinds.
     *
     * @return list<EvidenceKind>
     */
    public function evidenceRequired(): array
    {
        return $this->evidence;
    }

    /** Is the tax charged somewhere other than where the supplier is? */
    public function isForeign(): bool
    {
        return ! $this->supply->isTheSupplierCountry($this->country);
    }

    public function explain(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return $arabic
            ? sprintf(
                'مكان التوريد %s، بالقاعدة "%s" (الأولوية %d)، والفئة %s - %s.',
                $this->country, $this->rule, $this->precedence,
                $this->category->value, $this->category->label('ar'),
            )
            : sprintf(
                'The place of supply is %s, by rule "%s" (precedence %d), in category %s - %s.',
                $this->country, $this->rule, $this->precedence,
                $this->category->value, $this->category->label('en'),
            );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'country' => $this->country,
            'category' => $this->category->value,
            'rule' => $this->rule,
            'precedence' => $this->precedence,
            'source' => $this->source,
            'reverse_charge' => $this->isReverseCharge(),
            'requires_evidence' => $this->requiresEvidence(),
            'evidence_required' => array_map(
                static fn (EvidenceKind $kind): string => $kind->value,
                $this->evidence,
            ),
            'supply' => $this->supply->jsonSerialize(),
        ];
    }
}
