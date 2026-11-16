<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Exceptions;

/**
 * Something the approval engine will not do - PF-136.
 *
 * Every message names who can act or what to change. "You cannot do this"
 * sends somebody to ask around, and on a purchase approval the person they
 * ask is frequently the one who created the document.
 */
final class ApprovalRefused extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function noRuleMatches(string $subjectType, string $amount, string $currency): self
    {
        return new self(sprintf(
            'No approval rule matches a %s for %s %s. A document with no chain cannot be approved and '.
            'must not be silently treated as approved: an entity needs at least one rule with no '.
            'conditions, which is what "everything needs one approver" looks like.',
            $subjectType,
            $currency,
            $amount,
        ));
    }

    public static function notPending(string $status): self
    {
        return new self(sprintf(
            'This approval is %s, so there is nothing to decide. A second decision on a closed chain '.
            'would be recorded against a document whose fate was already settled.',
            $status,
        ));
    }

    public static function documentChanged(): self
    {
        return new self(
            'The document has changed since the chain started, so this approval would be about a '.
            'different document. Approved at one amount and paid at another is the failure an approval '.
            'flag cannot see - the chain starts again, from the beginning, because the earlier '.
            'approvers also approved something else.',
        );
    }

    public static function ownDocument(string $whoCan): self
    {
        return new self(sprintf(
            'You cannot approve a document you submitted. %s can. Money leaving needs a second person '.
            'in a way money arriving does not, and a system that let the requester approve would turn '.
            'the whole chain into paperwork.',
            ucfirst($whoCan),
        ));
    }

    public static function notTheirStep(string $whoCan): self
    {
        return new self(sprintf(
            'This step is not yours to clear. %s can.',
            ucfirst($whoCan),
        ));
    }

    public static function alreadyDecided(): self
    {
        return new self(
            'You have already decided on this step. A quorum of two cannot be met by one person '.
            'pressing approve twice, which is the obvious way to clear a step alone.',
        );
    }

    public static function rejectionNeedsAReason(): self
    {
        return new self(
            'A rejection needs a reason. An approval may be silent - the chain is the reason - and a '.
            'rejection that is not returns the document to somebody with no idea what to change.',
        );
    }

    public static function overTheirLimit(string $amount, string $limit, string $currency): self
    {
        return new self(sprintf(
            'This document is %s %s and the limit on this step is %s. A limit is tested on the '.
            'converted amount, so it cannot be dodged by invoicing in a weak currency.',
            $currency,
            $amount,
            $limit,
        ));
    }
}
