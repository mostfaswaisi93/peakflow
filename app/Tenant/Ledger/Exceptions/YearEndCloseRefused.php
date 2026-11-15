<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use RuntimeException;

/**
 * A year-end close that will not be performed - PF-052.
 *
 * ## Refusing is the only answer that cannot be wrong
 *
 * The tempting alternative to refusing a second close is to recompute and
 * post the difference, which looks idempotent and is not: the first close
 * moved the revenue accounts to zero, so a recomputation sees nothing and
 * posts nothing, while one that deliberately excludes the close's own
 * entries posts the same amount again.
 *
 * Both outcomes are wrong and one of them is silent. So the second run
 * refuses and the message says how to undo the first.
 */
final class YearEndCloseRefused extends RuntimeException
{
    public static function alreadyClosed(string $year, string $entryNumber): self
    {
        return new self(sprintf(
            '%s has already been closed by entry %s. Closing it again would post the same profit to '.
            'retained earnings twice - and recomputing instead would read the first close as activity and '.
            'close that. Reverse %s first if the close was wrong; it is an ordinary journal entry.',
            $year,
            $entryNumber,
            $entryNumber,
        ));
    }

    public static function notClosed(string $year): self
    {
        return new self(sprintf('%s has not been closed, so there is nothing to reverse.', $year));
    }

    /**
     * A year whose periods are not all closed.
     *
     * The one that matters most. A year closed while December is still open
     * means December can still take postings - which would land after the
     * closing entry and never be carried forward, so the year's revenue
     * would be understated and retained earnings short by exactly that
     * amount. Nothing in the system would disagree with anything else.
     *
     * @param  list<string>  $periods
     */
    public static function periodsStillOpen(string $year, array $periods): self
    {
        return new self(sprintf(
            '%s cannot be closed while %s still accept%s postings (%s). A posting made after the closing '.
            'entry would never be carried forward, so the year\'s revenue would be understated and retained '.
            'earnings short by exactly that amount - and no total anywhere would disagree with another.',
            $year,
            count($periods) === 1 ? 'one period' : count($periods).' periods',
            count($periods) === 1 ? 's' : '',
            implode(', ', array_slice($periods, 0, 6)).(count($periods) > 6 ? ', …' : ''),
        ));
    }

    public static function noPeriods(string $year): self
    {
        return new self(sprintf(
            '%s has no periods, so there is nowhere to post a closing entry. A fiscal year is generated with '.
            'its periods; one without them was created some other way.',
            $year,
        ));
    }

    /**
     * The new year has been traded in.
     *
     * Reversing a close once somebody has posted into the new year on top of
     * the carried-forward balances changes figures those postings were made
     * against, and the new year's trial balance stops tying to the old
     * year's closing one.
     */
    public static function newYearHasMovedOn(string $year, string $nextYear): self
    {
        return new self(sprintf(
            'The close of %s cannot be reversed: %s has already been posted into. Un-closing now would change '.
            'the balances those postings were made against, and %s would stop tying to the closing figures '.
            'it was opened from. Correct it with an adjusting entry in %s instead.',
            $year,
            $nextYear,
            $nextYear,
            $nextYear,
        ));
    }

    public static function reopeningNeedsAReason(string $year): self
    {
        return new self(sprintf(
            'Reversing the close of %s needs a reason somebody can read later. A year that was closed and '.
            'then un-closed is a question at the next audit, and the answer has to be in writing from the '.
            'moment it happened.',
            $year,
        ));
    }
}
