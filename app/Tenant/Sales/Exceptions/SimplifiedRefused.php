<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A document that cannot be issued in the simplified form - PF-101. */
final class SimplifiedRefused extends DomainException
{
    public static function anUndeclaredProfile(string $profile): self
    {
        return new self(sprintf(
            'The "%s" profile does not declare whether it has a simplified invoice. Inventing one '.
            'issues documents the authority rejects; inventing the reduced field set issues documents '.
            'that are missing something. Declare it in config/jurisdictions.php.',
            $profile,
        ));
    }

    public static function notPermitted(string $profile): self
    {
        return new self(sprintf(
            'The %s profile has no simplified invoice. Where a regime handles consumer sales through '.
            'a separate receipt system, offering a simplified invoice instead produces a document it '.
            'will not accept.',
            $profile,
        ));
    }

    public static function aboveTheThreshold(
        string $profile,
        string $gross,
        string $currency,
        ?string $threshold,
        ?string $thresholdCurrency,
    ): self {
        return new self($threshold === null
            ? sprintf(
                'This document is for %s %s and the %s profile\'s simplified form cannot be compared '.
                'against a threshold in another currency. Issue it as a full invoice: the standard '.
                'form is always acceptable and the simplified one is the concession.',
                $gross,
                $currency,
                $profile,
            )
            : sprintf(
                'This document is for %s %s and the %s profile permits the simplified form up to %s '.
                '%s. Above the limit the form is not available at all, so it has to be issued as a '.
                'full invoice - with the customer\'s registration and address, which is what the '.
                'simplified form exists to avoid.',
                $gross,
                $currency,
                $profile,
                $threshold,
                (string) $thresholdCurrency,
            ));
    }

    public static function theWrongType(string $type): self
    {
        return new self(sprintf(
            'There is no simplified form of %s. The regimes define it for an invoice and the credit '.
            'note that corrects one; everything else is a full document.',
            $type,
        ));
    }

    public static function anIssuedDocument(string $number): self
    {
        return new self(sprintf(
            'Document %s has been issued, and which form it was issued in is part of what it says. '.
            'Credit it and issue it again in the other form.',
            $number,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::DocumentValidationFailed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
