<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Something the approval engine will not do - PF-136.
 *
 * Every message names who can act or what to change. "You cannot do this"
 * sends somebody to ask around, and on a purchase approval the person they
 * ask is frequently the one who created the document.
 *
 * ## Each refusal carries the problem type a client switches on - PF-265
 *
 * Who may not decide is a 403 (and approving your own document is the
 * segregation-of-duties 403, with its own type); a chain in the wrong state is
 * a 409; a decision missing its reason, or a document no rule covers, is a
 * 422. Without this the API answered every one of them 500 - "something went
 * wrong on our side" - for a request that was simply not allowed.
 */
final class ApprovalRefused extends \RuntimeException
{
    private function __construct(string $message, public readonly ProblemType $problemType = ProblemType::ValidationFailed)
    {
        parent::__construct($message);
    }

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

    /** PF-265: a bill enters the chain from draft, and only from draft. */
    public static function notADraft(string $status): self
    {
        return new self(sprintf(
            'Only a draft bill can be submitted for approval, and this one is %s. A posted bill has '.
            'already been booked; one awaiting approval is already in its chain.',
            str_replace('_', ' ', $status),
        ), ProblemType::StateTransitionNotAllowed);
    }

    /** PF-265: a decision is about a bill that is waiting for one. */
    public static function notAwaitingApproval(string $status): self
    {
        return new self(sprintf(
            'This bill is %s, not awaiting approval, so there is no decision to make on it. Submit it '.
            'first, or - if it was rejected - correct it and submit it again.',
            str_replace('_', ' ', $status),
        ), ProblemType::StateTransitionNotAllowed);
    }

    public static function notPending(string $status): self
    {
        return new self(sprintf(
            'This approval is %s, so there is nothing to decide. A second decision on a closed chain '.
            'would be recorded against a document whose fate was already settled.',
            $status,
        ), ProblemType::StateTransitionNotAllowed);
    }

    public static function documentChanged(): self
    {
        return new self(
            'The document has changed since the chain started, so this approval would be about a '.
            'different document. Approved at one amount and paid at another is the failure an approval '.
            'flag cannot see - the chain starts again, from the beginning, because the earlier '.
            'approvers also approved something else.',
            ProblemType::StaleWrite,
        );
    }

    public static function ownDocument(string $whoCan): self
    {
        return new self(sprintf(
            'You cannot approve a document you submitted. %s can. Money leaving needs a second person '.
            'in a way money arriving does not, and a system that let the requester approve would turn '.
            'the whole chain into paperwork.',
            ucfirst($whoCan),
        ), ProblemType::SegregationOfDutiesConflict);
    }

    public static function notTheirStep(string $whoCan): self
    {
        return new self(sprintf(
            'This step is not yours to clear. %s can.',
            ucfirst($whoCan),
        ), ProblemType::Forbidden);
    }

    public static function alreadyDecided(): self
    {
        return new self(
            'You have already decided on this step. A quorum of two cannot be met by one person '.
            'pressing approve twice, which is the obvious way to clear a step alone.',
            ProblemType::StateTransitionNotAllowed,
        );
    }

    public static function rejectionNeedsAReason(): self
    {
        return new self(
            'A rejection needs a reason. An approval may be silent - the chain is the reason - and a '.
            'rejection that is not returns the document to somebody with no idea what to change.',
        );
    }

    public static function noDelegation(string $forUser): self
    {
        return new self(sprintf(
            'You hold no delegation from %s, so you cannot act for them. A delegation has a window with '.
            'an end on it, and one with no window is a standing substitution nobody revisits.',
            $forUser,
        ), ProblemType::Forbidden);
    }

    public static function overTheirLimit(string $amount, string $limit, string $currency): self
    {
        return new self(sprintf(
            'This document is %s %s and the limit on this step is %s. A limit is tested on the '.
            'converted amount, so it cannot be dodged by invoicing in a weak currency.',
            $currency,
            $amount,
            $limit,
        ), ProblemType::Forbidden);
    }

    /**
     * The registry's type, and THIS message as the detail - because each one
     * names who can act or what to change, which the registry's generic
     * sentence cannot.
     */
    public function render(Request $request): Response
    {
        $problem = Problem::of($this->problemType)->withDetail($this->getMessage());

        /*
         | A stale document answers 412 with no current representation in the
         | body here - the chain restarts from the beginning, and the client
         | re-reads the bill to see where it stands.
         */
        return $problem->toResponse($request);
    }
}
