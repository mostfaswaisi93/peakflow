<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use RuntimeException;

/** A caller named a period that does not fit what it is posting - PF-051. */
final class PeriodDoesNotBelong extends RuntimeException
{
    public static function toEntity(string $period, string $entityCode): self
    {
        return new self(sprintf(
            'Period %s does not belong to entity %s. Posting into another entity\'s period would put the '.
            'figure in books nobody is looking at, and every report in both entities would be wrong in '.
            'opposite directions.',
            $period,
            $entityCode,
        ));
    }

    public static function toTheYear(
        string $period,
        string $date,
        string $year,
        string $starts,
        string $ends,
    ): self {
        return new self(sprintf(
            'Period %s belongs to fiscal year %s (%s to %s), and this entry is dated %s. An adjustment '.
            'posted into the wrong year\'s adjusting period is a figure in a set of accounts that has '.
            'already been filed.',
            $period,
            $year,
            $starts,
            $ends,
            $date,
        ));
    }
}
