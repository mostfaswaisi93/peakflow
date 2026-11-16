<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching;

use App\Support\Money\Money;

/**
 * One thing a pass thinks a transaction might be - PF-156, PF-161.
 *
 * ## It carries its factors, not only its score
 *
 * A confidence of 0.87 is a number nobody can argue with. The factors are
 * what let somebody say "it scored that high because the amount matched
 * exactly, and an exact amount means less than you think when the customer
 * pays round numbers" - which is the conversation that improves the engine,
 * and the one a bare score makes impossible.
 *
 * They are also what makes the score reproducible. A model documented in
 * prose drifts from the code within two changes; a model that emits its
 * own working cannot.
 */
final readonly class Candidate
{
    /**
     * @param  list<array{factor: string, weight: string, why: string}>  $factors
     * @param  list<string>  $components  the documents a subset-sum combination settles
     */
    public function __construct(
        public string $pass,
        public string $matchedType,
        public ?string $matchedId,
        public string $label,
        public Money $amount,
        public float $confidence,  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
        public string $reason,
        public array $factors = [],
        public array $components = [],
        public ?string $partyId = null,
        public ?string $ruleId = null,
        public bool $proposalOnly = false,
    ) {}

    /** The same target, by type and id - what deduplication compares. */
    public function target(): string
    {
        return $this->matchedType.':'.($this->matchedId ?? $this->label);
    }

    public function with(float $confidence, array $factors, string $reason): self  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
    {
        return new self(
            pass: $this->pass,
            matchedType: $this->matchedType,
            matchedId: $this->matchedId,
            label: $this->label,
            amount: $this->amount,
            confidence: $confidence,
            reason: $reason,
            factors: $factors,
            components: $this->components,
            partyId: $this->partyId,
            ruleId: $this->ruleId,
            proposalOnly: $this->proposalOnly,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pass' => $this->pass,
            'matched_type' => $this->matchedType,
            'matched_id' => $this->matchedId,
            'label' => $this->label,
            'amount' => (string) $this->amount->amount,
            'currency' => $this->amount->currency,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'factors' => $this->factors,
            'components' => $this->components,
            'party_id' => $this->partyId,
            'rule_id' => $this->ruleId,
            'proposal_only' => $this->proposalOnly,
        ];
    }
}
