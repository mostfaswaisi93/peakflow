<?php

declare(strict_types=1);

namespace App\Exchange\Xml;

/**
 * One element in a document being built, before it is ordered.
 *
 * ## Why a tree and not strings
 *
 * The serializers build this, a writer renders it, and the ordering rules
 * live in one place. Building XML by concatenation puts the element order in
 * the control flow of the method that happens to emit it, which is how a
 * conditional block ends up emitting `cbc:TaxPointDate` after
 * `cbc:DocumentCurrencyCode` on the one document that has both.
 */
final class Element
{
    /** @var list<self> */
    private array $children = [];

    /** @param array<string, string|null> $attributes */
    private function __construct(
        public readonly string $name,
        public readonly ?string $text = null,
        public readonly array $attributes = [],
    ) {}

    /** @param array<string, string|null> $attributes */
    public static function of(string $name, array $attributes = []): self
    {
        return new self($name, null, array_filter(
            $attributes,
            static fn (?string $value): bool => $value !== null && trim($value) !== '',
        ));
    }

    /** @param array<string, string|null> $attributes */
    public static function text(string $name, string $text, array $attributes = []): self
    {
        return new self($name, $text, array_filter(
            $attributes,
            static fn (?string $value): bool => $value !== null && trim($value) !== '',
        ));
    }

    public function with(?self ...$children): self
    {
        foreach ($children as $child) {
            if ($child !== null) {
                $this->children[] = $child;
            }
        }

        return $this;
    }

    /** @param list<self> $children */
    public function withAll(array $children): self
    {
        foreach ($children as $child) {
            $this->children[] = $child;
        }

        return $this;
    }

    /** @return list<self> */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * Swap one child for another, in place.
     *
     * For a national extension that has to add an attribute to an element
     * the shared serializer already emitted - ZATCA's transaction type on
     * the invoice type code. An `Element` is otherwise immutable, which is
     * what lets the same tree be rendered twice and give the same bytes, so
     * the replacement is a list operation rather than a mutation of the
     * child.
     */
    public function replace(self $existing, self $replacement): self
    {
        foreach ($this->children as $index => $child) {
            if ($child === $existing) {
                $this->children[$index] = $replacement;

                return $this;
            }
        }

        throw new \LogicException(sprintf(
            '<%s> is not a child of <%s>, so there is nothing to replace.',
            $existing->name,
            $this->name,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->text === null && $this->children === [] && $this->attributes === [];
    }
}
