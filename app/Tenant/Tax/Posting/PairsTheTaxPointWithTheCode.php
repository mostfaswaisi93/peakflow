<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Posting;

use App\Tenant\Tax\Exceptions\TaxPostingRefused;

/**
 * A tax-bearing line carries the tax point that decides its return - PF-086.
 *
 * The database pairs the two columns, and this is the same rule stated where
 * the message can be useful: the constraint says a CHECK was violated, and
 * this says which event field is missing and why it matters.
 *
 * Stated once rather than in each rule, because the five rules would drift -
 * and the one that drifted would post lines that no return can see.
 */
trait PairsTheTaxPointWithTheCode
{
    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string|null, 1: string|null} the code and the tax point
     *
     * @throws TaxPostingRefused
     */
    private function taxCodeAndPoint(array $event, string $rule): array
    {
        $code = $event['tax_code_id'] ?? null;
        $point = $event['tax_point'] ?? null;

        $code = \is_string($code) && $code !== '' ? $code : null;
        $point = \is_string($point) && $point !== '' ? $point : null;

        if ($code !== null && $point === null) {
            throw TaxPostingRefused::aTaxCodeNeedsATaxPoint($rule);
        }

        if ($point !== null && $code === null) {
            throw TaxPostingRefused::aTaxPointNeedsATaxCode($rule);
        }

        return [$code, $point];
    }
}
