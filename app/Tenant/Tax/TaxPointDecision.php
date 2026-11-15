<?php

declare(strict_types=1);

namespace App\Tenant\Tax;

use App\Tenant\Tax\Enums\TaxPointBasis;
use Carbon\CarbonImmutable;
use JsonSerializable;

/**
 * A derived tax point, and the reasoning that produced it - PF-077.
 *
 * ## Why the reasoning travels with the date
 *
 * A tax point decides which return period a supply belongs to, and when an
 * accountant disagrees with it the question is never "what date did you pick"
 * - it is "why". A bare date forces them to reconstruct the rule from the
 * other dates on the document, and they will reconstruct it differently.
 *
 * So the decision carries the basis that applied, which of the candidate
 * dates won, and the EN 16931 `BT-8` code where there is one. All three go on
 * the document at issue and are frozen there, because the rule may change and
 * the document may not.
 */
final readonly class TaxPointDecision implements JsonSerializable
{
    /**
     * @param  CarbonImmutable  $date  the tax point itself - EN 16931 `BT-7`
     * @param  TaxPointBasis  $basis  the rule the jurisdiction applies
     * @param  string  $winningDate  which candidate won: `invoice`, `delivery` or `payment`
     * @param  string  $profile  the jurisdiction profile whose rule this was
     */
    public function __construct(
        public CarbonImmutable $date,
        public TaxPointBasis $basis,
        public string $winningDate,
        public string $profile,
    ) {}

    /**
     * The EN 16931 `BT-8` code for what this date represents.
     *
     * For an "earlier of" basis the standard has no code for the rule, so the
     * code reports whichever candidate actually won - which is the honest
     * answer and the one a receiving system can validate.
     */
    public function documentCode(): string
    {
        return $this->basis->documentCode() ?? match ($this->winningDate) {
            'delivery' => '35',
            'payment' => '432',
            default => '3',
        };
    }

    /**
     * One sentence an accountant can check, for the invoice's tax panel.
     *
     * PF-078 asks for the place-of-supply decision to be visible for the same
     * reason: a determination nobody can read is a determination nobody can
     * dispute, and the disputes happen anyway - later, with an authority.
     */
    public function explain(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        return $arabic
            ? sprintf(
                'نقطة الضريبة %s، وفقاً لقاعدة %s: %s.',
                $this->date->toDateString(),
                $this->profile,
                $this->basis->explanation('ar'),
            )
            : sprintf(
                'Tax point %s, by the %s rule: %s.',
                $this->date->toDateString(),
                $this->profile,
                $this->basis->explanation('en'),
            );
    }

    /**
     * @return array{date: string, basis: string, winning_date: string, profile: string, document_code: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'basis' => $this->basis->value,
            'winning_date' => $this->winningDate,
            'profile' => $this->profile,
            'document_code' => $this->documentCode(),
        ];
    }
}
