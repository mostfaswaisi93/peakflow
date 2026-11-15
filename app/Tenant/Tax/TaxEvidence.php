<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Tenant\Tax\Enums\EvidenceKind;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use JsonSerializable;

/**
 * The evidence recorded against a document - PF-080.
 *
 * ## A reference, not a boolean
 *
 * `hasExportProof => true` is worth nothing to the person who has to produce
 * the document three years later. What is worth something is the customs
 * declaration number, so each item carries a reference and the date it was
 * recorded.
 *
 * A blank reference is refused by the gate rather than stored, because a
 * ticked box with nothing behind it is the state that makes an assessment
 * unanswerable: the system says the evidence exists and nobody can find it.
 */
final readonly class TaxEvidence implements JsonSerializable
{
    /** @param  array<string, array{reference: string, recorded_on: string}>  $items */
    private function __construct(private array $items = []) {}

    public static function none(): self
    {
        return new self([]);
    }

    public function with(EvidenceKind $kind, string $reference, ?CarbonInterface $recordedOn = null): self
    {
        $items = $this->items;

        $items[$kind->value] = [
            'reference' => trim($reference),
            'recorded_on' => ($recordedOn === null
                ? CarbonImmutable::now()
                : CarbonImmutable::parse($recordedOn->toDateString()))->toDateString(),
        ];

        return new self($items);
    }

    /** Is this kind recorded, with something actually in it? */
    public function has(EvidenceKind $kind): bool
    {
        return ($this->items[$kind->value]['reference'] ?? '') !== '';
    }

    public function referenceFor(EvidenceKind $kind): ?string
    {
        $reference = $this->items[$kind->value]['reference'] ?? '';

        return $reference === '' ? null : $reference;
    }

    public function recordedOn(EvidenceKind $kind): ?CarbonImmutable
    {
        $date = $this->items[$kind->value]['recorded_on'] ?? null;

        return \is_string($date) ? CarbonImmutable::parse($date) : null;
    }

    /**
     * Which of these kinds are not on record.
     *
     * @param  list<EvidenceKind>  $required
     * @return list<EvidenceKind>
     */
    public function missingFrom(array $required): array
    {
        return array_values(array_filter(
            $required,
            fn (EvidenceKind $kind): bool => ! $this->has($kind),
        ));
    }

    /** @return list<EvidenceKind> */
    public function kinds(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?EvidenceKind => EvidenceKind::tryFrom($value),
            array_keys($this->items),
        )));
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return array<string, array{reference: string, recorded_on: string}> */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
