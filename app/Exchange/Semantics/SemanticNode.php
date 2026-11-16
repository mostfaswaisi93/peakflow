<?php

declare(strict_types=1);

namespace App\Exchange\Semantics;

/**
 * One instance of a business group, with its terms and its child groups.
 *
 * ## Why a tree and not a flat array of BT values
 *
 * EN 16931's cardinality is mostly a property of a group. `BT-84`, the
 * payment account identifier, is mandatory - inside `BG-17`, a credit
 * transfer, which is itself optional and repeatable. Flattened to "the IBAN
 * is optional" the rule that a credit transfer without an account identifier
 * is not a credit transfer disappears, and that is the rule the recipient's
 * validator enforces.
 *
 * A flat model also cannot express three invoice lines, each with its own
 * price details and its own item attributes. Serializers would then each
 * invent their own nesting, which is how two syntaxes of one document come
 * to disagree.
 *
 * ## A term on the wrong node is a programming error, not a validation one
 *
 * {@see set()} throws when a term does not belong to this node's group. A
 * validation message would be wrong: nobody entered the delivery address
 * into the payment instructions, the mapping code put it there, and the
 * person who can fix it is reading a stack trace rather than an invoice.
 */
final class SemanticNode
{
    /** @var array<string, string> */
    private array $terms = [];

    /** @var list<self> */
    private array $children = [];

    /**
     * The scheme, list or unit identifier qualifying a term's value.
     *
     * EN 16931 carries these as attributes - `schemeID` on an identifier,
     * `listID` on a code, `unitCode` on a quantity, `currencyID` on an
     * amount - and a bare code is not conformant: `BR-65` rejects an item
     * classification identifier with no scheme, because the recipient cannot
     * tell UNSPSC from GPC by looking at the digits.
     *
     * Held beside the value rather than inside it, so a serializer emits the
     * attribute and a validator can ask for it, and so no code has to parse
     * a convention out of a string.
     *
     * @var array<string, string>
     */
    private array $qualifiers = [];

    public function __construct(public readonly ?BusinessGroup $group = null) {}

    public static function root(): self
    {
        return new self(null);
    }

    public static function of(BusinessGroup $group): self
    {
        return new self($group);
    }

    /**
     * Set a term on this node.
     *
     * A null or empty value is treated as absent rather than stored, because
     * an element emitted with no content is a term the recipient's validator
     * reads as present and malformed - which is a worse failure than the
     * term being missing, since the message names the wrong problem.
     *
     * @throws \LogicException if the term does not belong to this node's group
     */
    public function set(BusinessTerm $term, string|int|float|\BackedEnum|null $value): self
    {
        if ($term->group() !== $this->group) {
            throw new \LogicException(sprintf(
                '%s (%s) belongs to %s and was set on %s. The group is part of the term\'s identity in '.
                'EN 16931 and a serializer that nests it elsewhere emits a document no validator accepts.',
                $term->value,
                $term->label(),
                $term->group()?->value ?? 'the document root',
                $this->group?->value ?? 'the document root',
            ));
        }

        $text = $this->text($value);

        if ($text === '') {
            unset($this->terms[$term->value]);

            return $this;
        }

        $this->terms[$term->value] = $text;

        return $this;
    }

    /**
     * Add a repeated value for a repeatable term - BT-29, BT-158.
     *
     * Stored with an ordinal suffix so the registry stays the single answer
     * to "which term is this", and read back through {@see all()}.
     *
     * @throws \LogicException if the term is not repeatable
     */
    public function add(BusinessTerm $term, string|int|float|\BackedEnum|null $value): self
    {
        if (! $term->cardinality()->isRepeatable()) {
            throw new \LogicException(sprintf(
                '%s (%s) is %s and cannot repeat. A second value would be silently dropped by one '.
                'serializer and emitted twice by another.',
                $term->value,
                $term->label(),
                $term->cardinality()->value,
            ));
        }

        if ($term->group() !== $this->group) {
            throw new \LogicException(sprintf(
                '%s belongs to %s and was added on %s.',
                $term->value,
                $term->group()?->value ?? 'the document root',
                $this->group?->value ?? 'the document root',
            ));
        }

        $text = $this->text($value);

        if ($text === '') {
            return $this;
        }

        $this->terms[$term->value.'#'.\count($this->all($term))] = $text;

        return $this;
    }

    /**
     * Qualify a term's value with its scheme, list or unit identifier.
     *
     * @throws \LogicException if the term is not populated
     */
    public function qualify(BusinessTerm $term, ?string $qualifier, int $ordinal = 0): self
    {
        if ($qualifier === null || trim($qualifier) === '') {
            return $this;
        }

        if ($this->all($term) === []) {
            throw new \LogicException(sprintf(
                'A scheme was set on %s, which is not populated. A qualifier with no value is an '.
                'attribute on an element that will not be emitted.',
                $term->value,
            ));
        }

        $this->qualifiers[$term->value.'@'.$ordinal] = trim($qualifier);

        return $this;
    }

    public function qualifier(BusinessTerm $term, int $ordinal = 0): ?string
    {
        return $this->qualifiers[$term->value.'@'.$ordinal] ?? null;
    }

    /**
     * A value as the document carries it.
     *
     * A code list value is frequently held as an enum - `TaxCategory::Standard`
     * rather than `'S'` - and an enum cast to a string in PHP is an error, not
     * a code. Unwrapping it here means the builder passes what it has instead
     * of writing `->value` at ninety call sites, one of which would be missed.
     */
    private function text(string|int|float|\BackedEnum|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \BackedEnum) {
            return trim((string) $value->value);
        }

        return trim((string) $value);
    }

    public function get(BusinessTerm $term): ?string
    {
        return $this->terms[$term->value] ?? ($this->terms[$term->value.'#0'] ?? null);
    }

    public function has(BusinessTerm $term): bool
    {
        return $this->get($term) !== null;
    }

    /** @return list<string> */
    public function all(BusinessTerm $term): array
    {
        $values = [];

        if (isset($this->terms[$term->value])) {
            $values[] = $this->terms[$term->value];
        }

        for ($i = 0; isset($this->terms[$term->value.'#'.$i]); $i++) {
            $values[] = $this->terms[$term->value.'#'.$i];
        }

        return $values;
    }

    /** Every term set on this node, keyed by BT id, repeats flattened. */
    public function terms(): array
    {
        return $this->terms;
    }

    /** @return list<BusinessTerm> the terms actually populated here */
    public function populated(): array
    {
        $ids = array_unique(array_map(
            static fn (string $key): string => explode('#', $key)[0],
            array_keys($this->terms),
        ));

        return array_values(array_map(
            static fn (string $id): BusinessTerm => BusinessTerm::from($id),
            $ids,
        ));
    }

    /**
     * Nest a group instance inside this one.
     *
     * @throws \LogicException if the group does not nest here
     */
    public function nest(self $child): self
    {
        if ($child->group === null) {
            throw new \LogicException('The document root cannot be nested inside a group.');
        }

        if ($child->group->parent() !== $this->group) {
            throw new \LogicException(sprintf(
                '%s (%s) nests inside %s, not inside %s.',
                $child->group->value,
                $child->group->label(),
                $child->group->parent()?->value ?? 'the document root',
                $this->group?->value ?? 'the document root',
            ));
        }

        $this->children[] = $child;

        return $this;
    }

    /** @return list<self> */
    public function children(?BusinessGroup $group = null): array
    {
        if ($group === null) {
            return $this->children;
        }

        return array_values(array_filter(
            $this->children,
            static fn (self $child): bool => $child->group === $group,
        ));
    }

    public function first(BusinessGroup $group): ?self
    {
        return $this->children($group)[0] ?? null;
    }

    /** The one value of a term inside a non-repeatable child group, if present. */
    public function nested(BusinessGroup $group, BusinessTerm $term): ?string
    {
        return $this->first($group)?->get($term);
    }
}
