<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Sales\Enums\OrderState;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A sales order that cannot be drafted, confirmed, invoiced or cancelled - PF-090. */
final class OrderRefused extends DomainException
{
    public static function notACustomer(string $code): self
    {
        return new self(sprintf('Party %s is not marked as a customer.', $code));
    }

    public static function noCustomer(): self
    {
        return new self('The quote names no customer, so the order would be for nobody.');
    }

    public static function ofNothing(): self
    {
        return new self('An order with no lines commits to nothing.');
    }

    public static function aLineOfNothing(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d of the order is for zero or less. An order is a commitment to supply, and a line '.
            'of nothing is a line somebody meant to delete.',
            $lineNo,
        ));
    }

    public static function fromAnUnacceptedQuote(string $label, string $state): self
    {
        return new self(sprintf(
            'Quote %s is %s. An order follows an acceptance: raising one before the customer has '.
            'agreed commits the business to work nobody asked for.',
            $label,
            $state,
        ));
    }

    public static function theQuoteAlreadyBecameSomething(string $label, string $outcome): self
    {
        return new self(sprintf(
            'Quote %s has already become an %s. A second one would be a second commitment for one '.
            'agreement, and the customer would be charged twice for work they ordered once.',
            $label,
            $outcome,
        ));
    }

    public static function theTransition(string $reference, OrderState $from, OrderState $to): self
    {
        return new self(sprintf(
            'Order %s is %s and cannot become %s. %s',
            $reference,
            $from->label('en'),
            $to->label('en'),
            $from->mayBecome() === []
                ? 'It is closed.'
                : 'The states it can reach from here are: '.implode(', ', array_map(
                    static fn (OrderState $state): string => $state->label('en'),
                    $from->mayBecome(),
                )).'.',
        ));
    }

    public static function notConfirmed(string $reference, string $state): self
    {
        return new self(sprintf(
            'Order %s is %s and only a confirmed order may be invoiced. Invoicing a draft bills a '.
            'customer for an agreement that has not been made.',
            $reference,
            $state,
        ));
    }

    public static function overInvoiced(int $lineNo, string $remaining, string $asked): self
    {
        return new self(sprintf(
            'Line %d of the order has %s left to invoice and %s was asked for. Invoicing more than was '.
            'ordered bills the customer for something nobody agreed to supply - and the database '.
            'refuses it too, so a second invoicing path cannot do it either.',
            $lineNo,
            $remaining,
            $asked,
        ));
    }

    public static function aNegativeQuantity(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d was asked to be invoiced for a negative quantity. Reducing what has been '.
            'invoiced is a credit note, which puts the balance back on the order.',
            $lineNo,
        ));
    }

    public static function nothingLeftToInvoice(string $reference): self
    {
        return new self(sprintf(
            'Order %s has nothing left to invoice. Every line is fully invoiced, so a further '.
            'document would be for work outside the order: raise a new order, or a debit note '.
            'against the invoice if it is an additional charge on the same supply.',
            $reference,
        ));
    }

    public static function alreadyInvoiced(string $reference, string $invoiced): self
    {
        return new self(sprintf(
            'Order %s has %s invoiced against it and cannot be cancelled. The invoice exists, and an '.
            'order that says it never happened leaves a document in a legal sequence pointing at an '.
            'agreement nobody made: credit the invoices first, which puts the balance back and leaves '.
            'both documents in the record.',
            $reference,
            $invoiced,
        ));
    }

    public static function aCancellationWithNoReason(): self
    {
        return new self(
            'A cancellation needs a reason, as a sentence. An order cancelled with no reason is a '.
            'conversation nobody can reconstruct, and the customer\'s version of it is the one that '.
            'survives.',
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::StateTransitionNotAllowed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
