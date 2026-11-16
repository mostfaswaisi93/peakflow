<?php

declare(strict_types=1);

namespace App\Exchange\Pipeline;

use App\Exchange\Validation\SemanticViolation;

/**
 * What the pipeline found, and where - PF-114, PF-116, PF-117.
 *
 * ## Which stage matters as much as which rule
 *
 * "The document is invalid" sends the reader looking in the wrong place.
 * `BR-CO-14` at stage 3 is a bug in `Tax`; the same figure rejected by a
 * CIUS at stage 4 is a jurisdiction restriction the supplier has to satisfy;
 * a schema failure at stage 2 is a serializer defect. Three different
 * people fix those, and the report says which.
 *
 * ## Internal findings are separated, not hidden
 *
 * A calculation error reaching the EN 16931 rule set is our defect, not the
 * customer's. {@see internal()} is what an operator sees and what a job
 * fails on; {@see forTheUser()} is what goes in front of a supplier. Mixing
 * them produces a screen that asks a bookkeeper to correct arithmetic they
 * never entered.
 */
final readonly class ValidationReport
{
    /**
     * @param  array<string, list<SemanticViolation>>  $byStage
     * @param  list<string>  $ran
     * @param  list<string>  $skipped  stage => why, for stages that could not run
     */
    public function __construct(
        public string $profile,
        public array $byStage,
        public array $ran,
        public array $skipped = [],
    ) {}

    /** @return list<SemanticViolation> */
    public function all(): array
    {
        $violations = [];

        foreach ($this->byStage as $stage) {
            $violations = [...$violations, ...$stage];
        }

        return $violations;
    }

    public function passed(): bool
    {
        return $this->all() === [];
    }

    /**
     * The failures that are ours to fix.
     *
     * @return list<SemanticViolation>
     */
    public function internal(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (SemanticViolation $v): bool => $v->isInternal(),
        ));
    }

    /**
     * The failures a supplier can act on.
     *
     * @return list<SemanticViolation>
     */
    public function forTheUser(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (SemanticViolation $v): bool => ! $v->isInternal(),
        ));
    }

    /** The stage that stopped the document, if one did. */
    public function failedAt(): ?string
    {
        foreach ($this->byStage as $stage => $violations) {
            if ($violations !== []) {
                return (string) $stage;
            }
        }

        return null;
    }

    /**
     * The RFC 9457 problem document, keyed by business term - PF-117.
     *
     * `errors` is an array of objects each carrying the business term, the
     * rule and the bilingual explanation. Keyed by term rather than by field
     * path because the term is what the supplier is looking at and what the
     * recipient's own rejection will quote.
     *
     * @return array<string, mixed>
     */
    public function toProblemDocument(string $type, string $instance): array
    {
        $errors = [];

        foreach ($this->forTheUser() as $violation) {
            $errors[] = [
                'term' => $violation->address(),
                ...$violation->toArray(),
            ];
        }

        return [
            'type' => $type,
            'title' => 'The document does not satisfy the rules of its profile.',
            'status' => 422,
            'detail' => sprintf(
                '%d rule%s failed at the %s stage of the %s profile.',
                \count($errors),
                \count($errors) === 1 ? '' : 's',
                (string) $this->failedAt(),
                $this->profile,
            ),
            'instance' => $instance,
            'profile' => $this->profile,
            'stage' => $this->failedAt(),
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $stages = [];

        foreach ($this->byStage as $stage => $violations) {
            $stages[(string) $stage] = array_map(
                static fn (SemanticViolation $v): array => $v->toArray(),
                $violations,
            );
        }

        return [
            'profile' => $this->profile,
            'passed' => $this->passed(),
            'ran' => $this->ran,
            'skipped' => $this->skipped,
            'failed_at' => $this->failedAt(),
            'stages' => $stages,
        ];
    }
}
