<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Sales\Enums\QuoteState;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A quote that cannot be drafted, sent, revised or answered - PF-089. */
final class QuoteRefused extends DomainException
{
    public static function notACustomer(string $code): self
    {
        return new self(sprintf(
            'Party %s is not marked as a customer, and a quote is an offer to sell. Quoting a '.
            'supplier record is how an order ends up on the wrong side of a relationship.',
            $code,
        ));
    }

    public static function noCustomer(): self
    {
        return new self('The quote names no customer, so there is nobody to send the revision to.');
    }

    public static function ofNothing(): self
    {
        return new self('A quote with no lines offers nothing.');
    }

    public static function aNegativeLine(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d of the quote is negative. A discount is a line with a negative unit price on a '.
            'positive quantity or an allowance on the document, not a negative total: the difference '.
            'shows in the tax breakdown.',
            $lineNo,
        ));
    }

    public static function validityEndsBeforeItStarts(string $validUntil, string $issued): self
    {
        return new self(sprintf(
            'The quote is valid until %s and issued on %s. A quote that expired before it was sent is '.
            'one the expiry job closes the moment it is created, which looks like the job '.
            'misbehaving rather than like a date somebody mistyped.',
            $validUntil,
            $issued,
        ));
    }

    public static function theTransition(string $label, QuoteState $from, QuoteState $to): self
    {
        return new self(sprintf(
            'Quote %s is %s and cannot become %s. %s',
            $label,
            $from->label('en'),
            $to->label('en'),
            $from->isFinal()
                ? 'It is closed: re-opening it would change what the customer was told without '.
                  'leaving a record of the change, which is what a revision is for.'
                : 'The states it can reach from here are: '.implode(', ', array_map(
                    static fn (QuoteState $state): string => $state->label('en'),
                    $from->mayBecome(),
                )).'.',
        ));
    }

    public static function hasLapsed(string $label, string $validUntil): self
    {
        return new self(sprintf(
            'Quote %s was valid until %s. Accepting it now would bind the business to a price it '.
            'withdrew - send a revision instead, which is a new version the customer can read and '.
            'compare.',
            $label,
            $validUntil,
        ));
    }

    public static function anAnonymousAcceptance(): self
    {
        return new self(
            'An acceptance has to name somebody. The person accepting is the customer, who has no '.
            'login here, so their name and the address they came from are what a dispute about "we '.
            'never agreed to this" is argued with.',
        );
    }

    public static function alreadyTurnedInto(string $label, string $what): self
    {
        return new self(sprintf(
            'Quote %s has already become %s. Accepting it again would produce a second document for '.
            'one agreement, and the customer would be charged twice for work they ordered once.',
            $label,
            $what,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::StateTransitionNotAllowed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
