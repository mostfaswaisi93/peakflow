<?php

declare(strict_types=1);

namespace App\Exchange\Messaging\ValueObjects;

/**
 * A template the provider has approved, and what it expects - PF-257.
 *
 * ## The variables are part of the identity
 *
 * A WhatsApp template is approved with a fixed number of placeholders.
 * Sending four values to a three-placeholder template is refused by the
 * provider, and sending three to a four-placeholder one renders a
 * message with a literal `{{4}}` in it - which the customer reads.
 *
 * So the count travels with the name, and the send path checks it
 * before anything leaves. The alternative is discovering it from a
 * customer.
 */
final readonly class TemplateDefinition
{
    /** @param list<string> $variables what each placeholder means, in order */
    public function __construct(
        public string $name,
        public string $language,
        public array $variables,
        public string $category,
    ) {}

    public function placeholders(): int
    {
        return \count($this->variables);
    }

    /** The key a template is identified by: a name AND a language. */
    public function key(): string
    {
        return $this->name.':'.$this->language;
    }
}
