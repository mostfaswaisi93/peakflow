<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * A tax code that will not be resolved or changed - PF-075, PF-076.
 */
final class TaxCodeRefused extends RuntimeException
{
    public static function noneInForce(string $code, string $date): self
    {
        return new self(sprintf(
            'No version of the tax code "%s" was in force on %s. A document resolves its rate from its '.
            'TAX POINT, not from today, so this is a gap in the code\'s history rather than a missing '.
            'code - and guessing the nearest version would silently apply a rate that was not law on '.
            'the day of the supply.',
            $code,
            $date,
        ));
    }

    public static function ambiguous(string $code, string $date, int $count): self
    {
        return new self(sprintf(
            '%d versions of the tax code "%s" were in force on %s. An exclusion constraint makes this '.
            'impossible to commit, so either the constraint was dropped or this query reached a database '.
            'that never had it. Which version a document would get depends on row order, so the same '.
            'invoice computed twice could produce two different tax amounts.',
            $count,
            $code,
            $date,
        ));
    }

    public static function unknown(string $code): self
    {
        return new self(sprintf(
            'There is no tax code "%s" for this entity. A code is created once, with its category and '.
            'jurisdiction; only its RATE is versioned.',
            $code,
        ));
    }

    public static function nothingOpenEnded(string $code): self
    {
        return new self(sprintf(
            'No version of "%s" is open-ended, so there is nothing to supersede. A rate change closes '.
            'the OPEN version and opens a new one; closing an already-closed version would leave a gap '.
            'in the history, and a gap is a date on which no rate was in force.',
            $code,
        ));
    }

    public static function notInTheFuture(string $code, string $from, string $currentFrom): self
    {
        return new self(sprintf(
            'A new version of "%s" cannot start on %s: the current version starts on %s. A rate change '.
            'that starts before the version it replaces would have to edit history, and every document '.
            'already issued between those dates resolved its rate from the version being overwritten.',
            $code,
            $from,
            $currentFrom,
        ));
    }

    /*
     | There is deliberately no `categoryWouldChange` refusal any more.
     |
     | The first version of this engine versioned the category along with the
     | rate, so `supersede()` had to refuse a category change in application
     | code. The category now lives on the code and not on the version, so the
     | mistake cannot be expressed - which is better than a refusal that has
     | to remember to fire.
     */
}
