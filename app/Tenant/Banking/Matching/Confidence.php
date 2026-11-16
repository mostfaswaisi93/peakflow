<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching;

/**
 * The scoring model, in one place - PF-161.
 *
 * ## Why the model is a table and not a feeling
 *
 * A score decides whether a match is applied without anybody looking at it.
 * So it has to be reproducible, arguable, and the same next month - and a
 * model scattered through six passes as `0.85` literals is none of those.
 *
 * Every pass starts from a documented base and moves by named factors. The
 * factors reach the proposal, so a score can always be taken apart.
 *
 * ## The bases, and why each sits where it does
 *
 * | Pass | Base | Why |
 * |---|---|---|
 * | `rule_engine` | 0.95 | A human wrote the rule and said what it means. The engine is not guessing; it is doing what it was told. |
 * | `structured_remittance` | 0.90 | The payer put the document number in a field that exists for it. Nothing else is this deliberate. |
 * | `exact_reference` | 0.80 | The reference is there, in free text - which the payer typed, and which their bank may have mangled. |
 * | `amount_party_window` | 0.60 | Circumstantial. Right far more often than not, and never on its own evidence. |
 * | `subset_sum` | 0.55 | Arithmetic, not evidence. A combination that adds up is a combination that adds up. |
 * | `fuzzy_party` | 0.40 | A guess about who, with nothing about what. |
 *
 * ## The ceiling on fuzzy matching is not a threshold
 *
 * `fuzzy_party` is capped below any threshold a tenant can set. Not
 * configured low - capped, so that no setting anywhere makes it apply by
 * itself. PF-160 asks for that explicitly, and the reason is that the pass
 * answers "who" and never "what": applying it alone would settle a document
 * nobody identified, against a party whose name merely resembled one.
 */
final class Confidence
{
    /** The documented base for each pass. */
    public const BASES = [
        'rule_engine' => 0.95,
        'structured_remittance' => 0.90,
        'exact_reference' => 0.80,
        'payment_run' => 0.75,
        'amount_party_window' => 0.60,
        'subset_sum' => 0.55,
        'fuzzy_party' => 0.40,
    ];

    /**
     * What a pass can never exceed, whatever its factors.
     *
     * Only one pass has a ceiling, and it is the point of the pass.
     */
    public const CEILINGS = [
        'fuzzy_party' => 0.60,
    ];

    /** @var list<array{factor: string, weight: string, why: string}> */
    private array $factors = [];

    private float $score;  // @arch-allow: a confidence is a proportion, not money - see the class docblock.

    public function __construct(private readonly string $pass)
    {
        $this->score = self::BASES[$pass] ?? 0.50;

        $this->factors[] = [
            'factor' => 'base',
            'weight' => $this->signed($this->score),
            'why' => sprintf('%s starts at %s', str_replace('_', ' ', $pass), $this->formatted($this->score)),
        ];
    }

    public static function for(string $pass): self
    {
        return new self($pass);
    }

    /** Raise or lower the score by a named, explained amount. */
    public function because(string $factor, float $weight, string $why): self  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
    {
        $this->score += $weight;

        $this->factors[] = [
            'factor' => $factor,
            'weight' => $this->signed($weight),
            'why' => $why,
        ];

        return $this;
    }

    /** Apply a factor only when a condition holds. */
    public function when(bool $condition, string $factor, float $weight, string $why): self  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
    {
        return $condition ? $this->because($factor, $weight, $why) : $this;
    }

    public function score(): float
    {
        $ceiling = self::CEILINGS[$this->pass] ?? 1.0;
        $capped = min($this->score, $ceiling);

        if ($capped < $this->score) {
            /*
             | A ceiling is recorded as a factor, not applied in silence.
             |
             | Otherwise a fuzzy match that scored 0.72 on its own evidence
             | reports 0.60 with nothing saying why, and the next person to
             | read it concludes the scoring is arbitrary.
             */
            $this->factors[] = [
                'factor' => 'ceiling',
                'weight' => $this->signed($capped - $this->score),
                'why' => sprintf(
                    'this pass is capped at %s however well it scores, because it answers who and '.
                    'never what',
                    $this->formatted($ceiling),
                ),
            ];

            $this->score = $capped;
        }

        return round(max(0.0, min(1.0, $this->score)), 4);
    }

    /** @return list<array{factor: string, weight: string, why: string}> */
    public function factors(): array
    {
        $this->score();

        return $this->factors;
    }

    /**
     * The factors as a sentence, after the pass's own reason.
     *
     * Only the ones that moved it, and only the first few: a reason that
     * lists eleven factors is one nobody finishes reading, and the factors
     * are all on the proposal for anybody who wants them.
     */
    public function summary(): string
    {
        $moved = array_values(array_filter(
            $this->factors,
            static fn (array $factor): bool => $factor['factor'] !== 'base'
                && $factor['weight'] !== '+0.00'
                && $factor['weight'] !== '-0.00',
        ));

        if ($moved === []) {
            return '';
        }

        $shown = \array_slice($moved, 0, 3);

        return implode('; ', array_map(
            static fn (array $factor): string => sprintf('%s (%s)', $factor['why'], $factor['weight']),
            $shown,
        ));
    }

    private function signed(float $weight): string  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
    {
        return sprintf('%s%.2f', $weight < 0 ? '-' : '+', abs($weight));
    }

    private function formatted(float $value): string  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
    {
        return number_format($value, 2);
    }
}
