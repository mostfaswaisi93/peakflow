<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Posting;

use App\Tenant\Ledger\Enums\ControlRole;

/**
 * What a rule will do, stated without running it - PF-047.
 *
 * Shown on the rule-inspection screen so an operator can see which accounts a
 * document type will hit before anything is posted. A rule whose behaviour is
 * only discoverable by posting is a rule nobody reviews, and the first review
 * happens when the numbers are already wrong.
 */
final readonly class RuleDescription
{
    /**
     * @param  list<array{side: string, target: string, note: string}>  $effects
     */
    public function __construct(
        public string $key,
        public string $titleAr,
        public string $titleEn,
        public array $effects,
    ) {}

    /**
     * @param  list<array{0: string, 1: ControlRole|string, 2: string}>  $effects
     *   [side, role or description, note]
     */
    public static function of(string $key, string $titleAr, string $titleEn, array $effects): self
    {
        return new self($key, $titleAr, $titleEn, array_map(
            static fn (array $effect): array => [
                'side' => $effect[0],
                'target' => $effect[1] instanceof ControlRole ? $effect[1]->value : $effect[1],
                'note' => $effect[2],
            ],
            $effects,
        ));
    }

    public function title(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->titleAr : $this->titleEn;
    }
}
