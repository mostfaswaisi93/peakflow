<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use RuntimeException;

/**
 * Why an opening balance set was not posted - PF-038.
 *
 * Each message says what to do instead, because the person reading it is
 * setting up a new set of books and has forty figures in front of them.
 */
final class OpeningBalancesRefused extends RuntimeException
{
    public static function notPermitted(): self
    {
        return new self(
            'Entering opening balances needs the `ledger.opening_balance` permission, which is separate '.
            'from posting a journal because it is the one way a control account can be reached by hand. '.
            'It belongs to the controller and the owner.',
        );
    }

    public static function windowClosed(string $blocker): self
    {
        return new self(sprintf(
            'Opening balances cannot be entered now: %s is already in this entity\'s ledger. The window '.
            'is open only before the books have anything in them, because an opening balance typed six '.
            'months into the year is indistinguishable from the real one. Correct an opening balance with '.
            'a reversal and a new one.',
            $blocker,
        ));
    }

    public static function needsAReason(): self
    {
        return new self(
            'Opening balances need a reason of at least twenty characters saying where the figures came '.
            'from - the previous system, a signed trial balance, an auditor\'s schedule. It is read during '.
            'the first audit of these books, when the only artefact is what somebody typed.',
        );
    }

    public static function nothingToPost(): self
    {
        return new self('There are no balances to post.');
    }

    /** @param list<string> $codes */
    public static function unknownAccounts(array $codes): self
    {
        return new self(sprintf(
            'No account in this entity has the code(s) %s. Nothing was posted - a partial opening balance '.
            'is worse than none, because the difference lands in retained earnings and looks deliberate.',
            implode(', ', $codes),
        ));
    }

    /** @param list<string> $codes */
    public static function unpostableAccounts(array $codes): self
    {
        return new self(sprintf(
            'The account(s) %s are group headings, not posting accounts. An opening balance on a heading '.
            'would be a balance that no child explains and that every drill-down loses.',
            implode(', ', $codes),
        ));
    }
}
