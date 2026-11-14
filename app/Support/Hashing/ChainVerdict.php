<?php

declare(strict_types=1);

namespace App\Support\Hashing;

use JsonSerializable;

/**
 * The outcome of walking a chain, in a form an operator can act on at 3am.
 */
final readonly class ChainVerdict implements JsonSerializable
{
    private function __construct(
        public bool $intact,
        public int $documentsChecked,
        public ?string $firstDivergenceReference = null,
        public ?int $firstDivergenceIndex = null,
        public ?string $reason = null,
    ) {}

    public static function intact(int $checked): self
    {
        return new self(intact: true, documentsChecked: $checked);
    }

    public static function altered(string $reference, int $index, int $checked): self
    {
        return new self(
            intact: false,
            documentsChecked: $checked,
            firstDivergenceReference: $reference,
            firstDivergenceIndex: $index,
            reason: 'The document\'s content no longer matches the hash recorded when it was issued, so a legally significant field has been changed.',
        );
    }

    public static function broken(string $reference, int $index, int $checked): self
    {
        return new self(
            intact: false,
            documentsChecked: $checked,
            firstDivergenceReference: $reference,
            firstDivergenceIndex: $index,
            reason: 'This document does not follow the one before it, so a document has been inserted or removed from the series.',
        );
    }

    public static function badStart(string $reference, int $index, int $checked): self
    {
        return new self(
            intact: false,
            documentsChecked: $checked,
            firstDivergenceReference: $reference,
            firstDivergenceIndex: $index,
            reason: 'The series does not begin at a genesis link, so its first document is missing.',
        );
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'intact' => $this->intact,
            'documents_checked' => $this->documentsChecked,
            'first_divergence_reference' => $this->firstDivergenceReference,
            'first_divergence_index' => $this->firstDivergenceIndex,
            'reason' => $this->reason,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
