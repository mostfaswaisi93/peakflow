<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

final class InvalidAllocation extends MoneyException
{
    public static function noWeights(): self
    {
        return new self('An allocation needs at least one weight.');
    }

    public static function nonPositiveTotal(): self
    {
        return new self(
            'Allocation weights must sum to a positive value. A zero or negative total has no meaningful apportionment, and guessing one would produce a document whose lines do not sum to its total.',
        );
    }

    public static function negativeWeight(string $weight): self
    {
        return new self(sprintf('An allocation weight cannot be negative; "%s" was given.', $weight));
    }
}
