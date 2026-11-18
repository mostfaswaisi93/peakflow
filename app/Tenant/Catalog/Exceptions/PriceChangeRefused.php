<?php

declare(strict_types=1);

namespace App\Tenant\Catalog\Exceptions;

use RuntimeException;

/**
 * Price changes that cannot be recorded, and why - PF-254.
 */
final class PriceChangeRefused extends RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }

    public static function aSecondPointOnOneDay(string $listCode, string $on): self
    {
        return new self(sprintf(
            'List %s already records a price change for this tier on %s. Two prices on one date is an '.
            'ambiguity with no tiebreak anybody could defend, and the symptom is an invoice whose price '.
            'depends on which row the planner happened to read. Change the date, or record the new '.
            'figure from tomorrow.',
            $listCode,
            $on,
        ));
    }

    /**
     * A point landing beneath one already in force.
     *
     * Deliberately not refused outright. Sometimes it is right - a price
     * entered as 1500 that should have been 15.00, caught before
     * anything was invoiced - and a product that said no would have the
     * correction made by an UPDATE somebody runs against the database,
     * which loses the reason and the name with it.
     */
    public static function aBackdatedChange(string $listCode, string $on, string $inForceFrom): self
    {
        return new self(sprintf(
            'This would record a price on list %s from %s, beneath the point already in force from %s - '.
            'so it changes an answer the resolver has already given. The invoices that quoted the old '.
            'price do not change with it, and the person who finds out is a customer comparing two of '.
            'them. If that is what you mean, say who is accepting it and why, in a sentence.',
            $listCode,
            $on,
            $inForceFrom,
        ));
    }
}
