<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A self-billed invoice that cannot be drafted - PF-100. */
final class SelfBillingRefused extends DomainException
{
    public static function notASupplier(string $code): self
    {
        return new self(sprintf(
            'Party %s is not a supplier, and a self-billed invoice is a purchase: it records money '.
            'owed to the party it names. Issuing one against a customer would credit a payable to '.
            'somebody who owes you money.',
            $code,
        ));
    }

    public static function withoutAnAgreement(string $code, string $date): self
    {
        return new self(sprintf(
            'No self-billing agreement with %s covers %s. The buyer issuing the supplier\'s invoice '.
            'needs a prior agreement between them - every regime that permits this requires one and '.
            'most require it to be produced on request - and an agreement that lapsed is not an '.
            'agreement.',
            $code,
            $date,
        ));
    }

    public static function ofNothing(): self
    {
        return new self('A self-billed invoice with no lines was asked for.');
    }

    public static function aLineOfNothing(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d is for zero or less. A self-billed invoice records what is owed to the supplier, '.
            'and a negative line is a credit note issued on their behalf, which is a separate document.',
            $lineNo,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::DocumentValidationFailed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
