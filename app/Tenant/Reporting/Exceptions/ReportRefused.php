<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Exceptions;

use RuntimeException;

/**
 * Reports that cannot honestly be produced - PF-182, PF-183.
 *
 * A reporting module refuses rather than approximates. The whole value
 * of a figure on a statement is that somebody can reproduce it, and a
 * report that filled a gap with a plausible number is one that cannot
 * be reproduced and does not say so.
 */
final class ReportRefused extends RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }

    /**
     * A presentation currency with no rate to get there.
     *
     * Refused rather than translated at the nearest rate or at one.
     * Both produce a report that looks right and reconciles to nothing,
     * and the reader has no way to tell.
     */
    public static function aTranslationWithNoRate(
        string $from,
        string $to,
        string $on,
        string $underlying = '',
    ): self {
        return new self(sprintf(
            'There is no %s/%s rate for %s, so this report cannot be presented in %s. It is not '.
            'translated at the nearest rate, and not at the inverse of the other direction: a '.
            'statement whose figures cannot be reproduced from a rate and a date is one that '.
            'reconciles to nothing, and the reader cannot tell. Record the rate, or read the report '.
            'in %s.%s',
            $from,
            $to,
            $on,
            $to,
            $from,
            $underlying === '' ? '' : ' ('.$underlying.')',
        ));
    }
}
