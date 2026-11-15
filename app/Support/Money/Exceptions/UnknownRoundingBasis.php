<?php

declare(strict_types=1);

namespace App\Support\Money\Exceptions;

/**
 * A rounding basis the money layer does not implement.
 *
 * Refused rather than defaulted, unlike an absent profile. An absent profile
 * means nobody has configured a jurisdiction yet and the platform default is
 * the common case; a basis somebody WROTE and spelled wrong is a decision that
 * was made and then silently discarded, and the discarding shows up as a
 * one-cent discrepancy on a return that nobody can trace.
 */
final class UnknownRoundingBasis extends MoneyException
{
    public static function name(string $name): self
    {
        return new self(sprintf(
            'Unknown rounding basis "%s". Tax is rounded either once per rate group - EN 16931 '.
            'BR-CO-14, written "per_rate_group" - or on each line, written "per_line". Three lines of '.
            '10.03 at 15%% are 4.51 one way and 4.50 the other, so a basis nobody read is a cent on a '.
            'return that cannot be traced.',
            $name,
        ));
    }
}
