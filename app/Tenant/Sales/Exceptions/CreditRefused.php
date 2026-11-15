<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A credit note that cannot be drafted - PF-097.
 *
 * Separate from `IssueRefused` because these are refusals about the
 * RELATIONSHIP between two documents - what is left to credit, which invoice
 * a line belongs to - and the person acting on them is deciding what to
 * credit rather than whether to issue. A single exception type for both would
 * produce one problem type and one message shape for two different decisions.
 */
final class CreditRefused extends DomainException
{
    public static function aDraft(): self
    {
        return new self(
            'A draft invoice cannot be credited. There is nothing to credit: a draft has no number, no '.
            'posting and no effect on any balance. Change it, or delete it.',
        );
    }

    public static function aCreditNote(string $number): self
    {
        return new self(sprintf(
            'Credit note %s cannot itself be credited. Reversing a credit means issuing a debit note '.
            'or a new invoice, so that both documents remain in the record and in the returns they '.
            'were reported in.',
            $number,
        ));
    }

    public static function ofNothing(): self
    {
        return new self(
            'A credit note for nothing was asked for. Select the lines to credit, or give an amount.',
        );
    }

    public static function aLineOfNothing(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d was asked to be credited for zero or less. A credit of nothing is not a credit, '.
            'and a negative credit is an invoice.',
            $lineNo,
        ));
    }

    public static function aLineOfAnotherInvoice(string $lineId): self
    {
        return new self(sprintf(
            'Line %s does not belong to the invoice being credited. A credit note whose lines came '.
            'from two invoices reduces a receivable nobody can trace to a supply.',
            $lineId,
        ));
    }

    public static function moreThanTheLine(int $lineNo, string $remaining, string $asked): self
    {
        return new self(sprintf(
            'Line %d has %s left to credit and %s was asked for. Crediting more than was charged is a '.
            'refund, and a refund is a payment - it leaves the customer owed money rather than owing '.
            'less.',
            $lineNo,
            $remaining,
            $asked,
        ));
    }

    public static function moreThanTheInvoice(string $number, string $remaining, string $asked): self
    {
        return new self(sprintf(
            'Invoice %s has %s left to credit and %s was asked for. The database refuses this too, '.
            'under a lock on the invoice, so two credit notes issued at the same instant cannot both '.
            'take the last of it.',
            $number,
            $remaining,
            $asked,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::DocumentValidationFailed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
