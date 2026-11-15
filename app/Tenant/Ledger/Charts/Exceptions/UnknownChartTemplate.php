<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Charts\Exceptions;

use RuntimeException;

/** A template was asked for by a key nothing ships - PF-039. */
final class UnknownChartTemplate extends RuntimeException
{
    /** @param  list<string>  $available */
    public static function key(string $key, array $available): self
    {
        return new self(sprintf(
            'There is no chart template "%s". The ones available are: %s.',
            $key,
            implode(', ', $available),
        ));
    }
}
