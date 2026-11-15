<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A prepayment that cannot be invoiced or applied - PF-099. */
final class PrepaymentRefused extends DomainException
{
    public static function anUndeclaredProfile(string $profile): self
    {
        return new self(sprintf(
            'The "%s" profile does not declare when a prepayment is taxed. Guessing is a quarter\'s '.
            'cash either way: taxing at the final invoice under a regime that taxes on receipt files a '.
            'return short by the tax on every prepayment in the period, and the customer has already '.
            'claimed it. Declare it in config/jurisdictions.php.',
            $profile,
        ));
    }

    public static function ofNothing(): self
    {
        return new self('A prepayment invoice for zero or less was asked for.');
    }

    public static function noRateOnThatDate(string $code, string $date): self
    {
        return new self(sprintf(
            'The tax code %s has no rate in force on %s, so the prepayment cannot be taxed. A rate '.
            'that is missing for a date is a gap in the code\'s history, not a zero rate.',
            $code,
            $date,
        ));
    }

    public static function theFinalInvoiceIsIssued(string $number): self
    {
        return new self(sprintf(
            'Invoice %s has already been issued, so a prepayment cannot be applied to it. The '.
            'deduction is part of what the document says, and an issued document does not change: '.
            'credit it and issue the invoice again with the prepayment applied.',
            $number,
        ));
    }

    public static function notAPrepayment(string $number): self
    {
        return new self(sprintf(
            '%s is not a prepayment invoice. Only money taken before performance can be deducted from '.
            'a later invoice; anything else is a payment, which is allocated rather than deducted.',
            $number,
        ));
    }

    public static function aDraftPrepayment(): self
    {
        return new self(
            'A draft prepayment invoice cannot be applied. It has no number and no posting, so there '.
            'is no liability to release and nothing the customer has been asked to pay.',
        );
    }

    public static function anotherCustomer(string $number): self
    {
        return new self(sprintf(
            'Prepayment %s was issued to a different customer. Applying one customer\'s prepayment to '.
            'another\'s invoice releases a liability owed to the first and collects from the second.',
            $number,
        ));
    }

    public static function anotherCurrency(string $prepayment, string $invoice): self
    {
        return new self(sprintf(
            'The prepayment is in %s and the invoice is in %s. Deducting one from the other needs a '.
            'rate and a decision about who carries the difference, which is a settlement and not a '.
            'deduction.',
            $prepayment,
            $invoice,
        ));
    }

    public static function alreadyApplied(string $number, string $applied): self
    {
        return new self(sprintf(
            'Prepayment %s has already been applied to %s. A prepayment is applied to exactly one '.
            'invoice: splitting one across several needs a balance per prepayment, ordering rules for '.
            'when two invoices compete for the remainder, and a report of what is left - which is an '.
            'allocation ledger rather than a column. To return part of it, credit the prepayment.',
            $number,
            $applied,
        ));
    }

    public static function largerThanTheInvoice(string $prepaid, string $gross): self
    {
        return new self(sprintf(
            'The prepayment is %s and the invoice is for %s. A prepayment larger than the supply '.
            'leaves the customer in credit, which is a refund rather than an invoice: issue the '.
            'invoice and credit the balance back.',
            $prepaid,
            $gross,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::DocumentValidationFailed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
