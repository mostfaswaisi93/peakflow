<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use RuntimeException;

/**
 * A document that will not be issued - PF-095.
 *
 * Every refusal here leaves the system exactly as it was: no number
 * consumed, no posting, no document in an issued state. That is the
 * property the issuing transaction exists to provide, and it is why these
 * are exceptions inside a transaction rather than return values.
 */
final class IssueRefused extends RuntimeException
{
    /** @param  list<string>  $findings */
    public static function theDraftIsNotValid(array $findings): self
    {
        return new self(sprintf(
            'This document will not be issued while %d thing%s wrong with it: %s. Nothing has been '.
            'consumed - no number, no posting - so the draft is exactly as it was.',
            \count($findings),
            \count($findings) === 1 ? ' is' : 's are',
            implode(' | ', $findings),
        ));
    }

    public static function alreadyIssued(string $number): self
    {
        return new self(sprintf(
            'This document was already issued as %s. Issuing it again would consume a second number for '.
            'one supply, and the customer would hold two invoices for it.',
            $number,
        ));
    }

    public static function aDocumentOfNothing(): self
    {
        return new self(
            'A document whose gross is zero has nothing to post. A zero invoice is either a draft '.
            'somebody has not filled in or a line that should have been removed, and issuing one '.
            'consumes a number from a gapless series for a supply that did not happen.',
        );
    }

    public static function aLineWithNoRevenueAccount(): self
    {
        return new self(
            'A line has no revenue account, so the credit has nowhere to go. The item\'s per-entity '.
            'default is the usual source and a free-text line has none, which is why the account is on '.
            'the line - leaving the posting rule to guess is how revenue ends up in suspense.',
        );
    }

    public static function theChainIsBroken(string $expected, string $found): self
    {
        return new self(sprintf(
            'The previous document in this series hashes to %s and the chain says %s. Issuing on top of '.
            'a broken chain would bury the divergence under a document that looks correct, and the '.
            'first divergence is the only one worth reporting.',
            $expected,
            $found,
        ));
    }

    public static function aCorrectionOfNothing(): self
    {
        return new self(
            'A corrected invoice was posted with nothing to replace. A replacement reverses what the '.
            'document it replaces said and restates it, and a posting with only half of that in it '.
            'leaves the receivable counted twice.',
        );
    }
}
