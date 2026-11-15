<?php

declare(strict_types=1);

namespace App\Tenant\Parties\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refused: a payment to an account nobody has confirmed - PF-061.
 *
 * The message says what verification MEANS, because the natural reading of
 * "verify the account" is "click the button", and the whole value of the
 * control is in the phone call.
 */
final class BankAccountNotVerified extends DomainException
{
    public static function sinceItChanged(string $masked): self
    {
        return new self(sprintf(
            'The bank account %s changed and has not been confirmed by a second person, so no payment can be '.
            'released to it. Confirm it through a channel this system did not supply - the number on an older '.
            'invoice or on the supplier\'s published site, never the one in the message asking for the change, '.
            'because that message is what an attacker sends.',
            $masked,
        ));
    }

    public static function becauseItIsInactive(string $masked): self
    {
        return new self(sprintf(
            'The bank account %s is no longer active, so it cannot receive a payment. If it should be used '.
            'again it has to be re-added and confirmed, which is the same control as any other change.',
            $masked,
        ));
    }

    public static function bySameUser(): self
    {
        return new self(
            'A bank account cannot be confirmed by the person who changed it. The control is a SECOND pair of '.
            'eyes - one person acting alone is exactly the case it exists to catch, and it is the case that '.
            'has already happened by the time anyone is looking.',
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::SegregationOfDutiesConflict)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
