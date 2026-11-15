<?php

declare(strict_types=1);

namespace App\Support\Iso\Exceptions;

use App\Support\Iso\UnitRegistry;
use RuntimeException;

/**
 * A unit of measure that is not in the curated Rec 20 subset.
 *
 * Refused rather than passed through, because the code goes onto an
 * e-invoice and a receiving system validates it against Rec 20 - an invalid
 * code is REJECTED by the clearance platform, not disputed, with a schema
 * error nobody in sales can act on.
 */
final class UnknownUnit extends RuntimeException
{
    public static function code(string $code): self
    {
        /*
         | The nearest few, because the usual cause is a plausible guess:
         | `HR` for `HUR`, `KG` for `KGM`, `EACH` for `EA`, `PCS` for `C62`.
         |
         | Ranked by edit distance, not filtered by it. An unranked filter at
         | distance 2 matched half the two- and three-character codes in the
         | list, so `HR` was answered with "did you mean EA, NPR, BX, CT" -
         | four codes, none of them an hour, which is worse than no
         | suggestion at all.
         |
         | The unit's NAME is matched too, since the guess is as often a word
         | as an abbreviation.
         */
        $needle = strtolower($code);
        $scored = [];

        foreach (UnitRegistry::codes() as $known) {
            $byCode = levenshtein($known, $code);
            $name = strtolower(UnitRegistry::name($known));

            $byName = match (true) {
                $name === $needle => 0,
                str_starts_with($name, $needle) => 1,
                str_contains($name, $needle) => 2,
                default => PHP_INT_MAX,
            };

            $distance = min($byCode, $byName);

            if ($distance <= 2) {
                $scored[$known] = $distance;
            }
        }

        asort($scored);
        $suggestions = array_keys($scored);

        return new self(sprintf(
            '"%s" is not a unit of measure this system knows. The code goes onto the e-invoice and is '.
            'validated against UN/CEFACT Rec 20 by the receiving system, so an unknown one is rejected '.
            'rather than queried.%s',
            $code,
            $suggestions === []
                ? ' Use C62 for a plain count.'
                : ' Did you mean '.implode(', ', array_slice($suggestions, 0, 4)).'?',
        ));
    }
}
