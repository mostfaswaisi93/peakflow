<?php

declare(strict_types=1);

namespace App\Exchange\Semantics;

use App\Exchange\Validation\CalculationRules;
use App\Exchange\Validation\CardinalityRules;
use App\Exchange\Validation\ConditionalRules;
use App\Exchange\Validation\SemanticViolation;

/**
 * One invoice, expressed in EN 16931 business terms - PF-110.
 *
 * ## The whole architecture rests on this class
 *
 * PeakFlow holds **one** semantic model and serializes it into whatever
 * syntax a recipient needs. A tenant selling into the EU and into Saudi
 * Arabia needs Peppol BIS and ZATCA, which disagree about almost everything
 * at the XML level and agree about nearly everything at the meaning level.
 * Two serializers over one model is a week's work; two invoice systems is a
 * product, and the second one is always a year behind the first.
 *
 * ## Built from the stored document, never from a draft
 *
 * {@see \App\Exchange\Builders\SemanticInvoiceBuilder} reads an issued
 * invoice and its frozen snapshots. A model built from a draft, or from the
 * live party and the live tax rate, would serialize something other than
 * what was issued - and the document that was issued is the one with legal
 * effect. This class therefore has no setters that reach outside itself and
 * no knowledge of how to load anything.
 *
 * ## Profile
 *
 * The profile is carried because `BT-24` is its customization identifier and
 * because the serializers and validators need to know which CIUS applies.
 * It is frozen on the document at issue, so a model built today from a
 * document issued last year serializes under last year's profile.
 */
final class SemanticInvoice
{
    /**
     * Values a jurisdiction requires that EN 16931 does not model.
     *
     * @var array<string, string>
     */
    private array $jurisdictionData = [];

    private function __construct(
        public readonly SemanticNode $root,
        public readonly string $profile,
        public readonly string $documentTypeCode,
    ) {}

    public static function build(SemanticNode $root, string $profile, string $documentTypeCode): self
    {
        return new self($root, $profile, $documentTypeCode);
    }

    /**
     * The keys a jurisdiction may ask for, and why each exists.
     *
     * ## Why this is a CLOSED list and not a bag
     *
     * EN 16931 genuinely has no business term for some things a national
     * regime requires. ZATCA's previous-invoice hash is the clearest case:
     * the standard models no hash chain at all, so there is no `BT` to carry
     * it and the mapping document cannot describe it.
     *
     * An open extension map would be the easy answer and the end of the
     * model: within a year every profile would be reading values nobody had
     * reviewed, the mapping document would describe half the document, and
     * "where does this come from" would have as many answers as there are
     * profiles.
     *
     * So the set is declared here with a reason per key, and `ExchangeRules`
     * fails the build if the builder populates one that is not listed. A new
     * key is a decision somebody makes in this file.
     *
     * @return array<string, string>
     */
    public static function jurisdictionKeys(): array
    {
        return [
            'chain.previous_hash' => 'ZATCA PIH. EN 16931 models no hash chain, so no BT carries the '.
                'previous document\'s hash - PF-096 and PF-123.',
            'chain.index' => 'ZATCA ICV, the invoice counter value. The chain\'s position, which is '.
                'likewise not a business term.',
            'document.uuid' => 'ZATCA cbc:UUID. A document identifier distinct from BT-1, which the '.
                'authority\'s API keys its responses on.',
            'document.issued_at' => 'The issuing timestamp. BT-2 is a DATE by design - a tax point is '.
                'a day, not a moment - and ZATCA\'s QR carries a date-time.',
            'document.simplified' => 'Whether the document is a simplified (B2C) one. It decides '.
                'clearance against reporting, which is a flow and not a field.',
        ];
    }

    /**
     * @throws \LogicException if the key is not in the declared set
     */
    public function withJurisdictionData(string $key, string|int|null $value): self
    {
        if (! \array_key_exists($key, self::jurisdictionKeys())) {
            throw new \LogicException(sprintf(
                '"%s" is not a declared jurisdiction key. EN 16931 has no term for it and nobody has '.
                'written down why a regime needs it, which is how a semantic model becomes a bag.',
                $key,
            ));
        }

        if ($value !== null && trim((string) $value) !== '') {
            $this->jurisdictionData[$key] = trim((string) $value);
        }

        return $this;
    }

    public function jurisdictionData(string $key): ?string
    {
        return $this->jurisdictionData[$key] ?? null;
    }

    /** @return array<string, string> */
    public function allJurisdictionData(): array
    {
        return $this->jurisdictionData;
    }

    public function isSimplified(): bool
    {
        return $this->jurisdictionData('document.simplified') === '1';
    }

    public function get(BusinessTerm $term): ?string
    {
        return $this->root->get($term);
    }

    public function group(BusinessGroup $group): ?SemanticNode
    {
        return $this->root->first($group);
    }

    /** @return list<SemanticNode> */
    public function groups(BusinessGroup $group): array
    {
        return $this->root->children($group);
    }

    /** @return list<SemanticNode> */
    public function lines(): array
    {
        return $this->root->children(BusinessGroup::InvoiceLine);
    }

    /** @return list<SemanticNode> */
    public function vatBreakdown(): array
    {
        return $this->root->children(BusinessGroup::VatBreakdown);
    }

    public function totals(): ?SemanticNode
    {
        return $this->root->first(BusinessGroup::DocumentTotals);
    }

    public function currency(): string
    {
        return (string) ($this->get(BusinessTerm::InvoiceCurrencyCode) ?? '');
    }

    /**
     * Stage 1: the model's own rules.
     *
     * Cardinality, conditionality and the calculation rules, in that order -
     * because a calculation rule over a missing total reports arithmetic
     * when the real answer is that the total is absent, and the reader fixes
     * the wrong thing.
     *
     * @return list<SemanticViolation>
     */
    public function validate(): array
    {
        $cardinality = app(CardinalityRules::class)->check($this->root);

        if ($cardinality !== []) {
            return $cardinality;
        }

        return [
            ...app(ConditionalRules::class)->check($this->root),
            ...app(CalculationRules::class)->check($this->root),
        ];
    }

    /** Every term populated anywhere in the document, as BT ids. */
    public function populatedTerms(): array
    {
        $found = [];
        $this->collect($this->root, $found);
        sort($found);

        return array_values(array_unique($found));
    }

    private function collect(SemanticNode $node, array &$found): void
    {
        foreach ($node->populated() as $term) {
            $found[] = $term->value;
        }

        foreach ($node->children() as $child) {
            $this->collect($child, $found);
        }
    }
}
