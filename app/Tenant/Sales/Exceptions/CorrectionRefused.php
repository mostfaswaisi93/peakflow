<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use App\Tenant\Sales\Enums\CorrectionMechanism;
use App\Tenant\Sales\Enums\DocumentType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A debit note or corrected invoice that cannot be drafted - PF-098. */
final class CorrectionRefused extends DomainException
{
    public static function theWrongMechanism(
        DocumentType $type,
        string $profile,
        CorrectionMechanism $permitted,
    ): self {
        return new self(sprintf(
            'The %s profile corrects an issued document with %s, so a %s cannot be issued under it. '.
            'Both mechanisms produce the same balances; what differs is which document the authority '.
            'expects to receive and match, and this one would be filed under a procedure the regime '.
            'does not recognise.',
            $profile,
            $permitted->label('en'),
            $type->label('en'),
        ));
    }

    public static function anUnknownProfile(string $profile): self
    {
        return new self(sprintf(
            'No correction mechanism is declared for the "%s" profile. Guessing one would issue a '.
            'document under a procedure nobody chose, so it is refused: add it to '.
            'config/jurisdictions.php.',
            $profile,
        ));
    }

    public static function aDraft(): self
    {
        return new self(
            'A draft invoice cannot be corrected. Change it: it has no number, no posting and no '.
            'effect on any balance.',
        );
    }

    public static function notAnInvoice(string $number, string $type): self
    {
        return new self(sprintf(
            '%s is %s, and only an invoice can be corrected or charged against. Adjusting an '.
            'adjustment is a chain: correct the correction, which keeps every document in the returns '.
            'it was reported in.',
            $number,
            $type,
        ));
    }

    public static function alreadyReplaced(string $number, string $replacement): self
    {
        return new self(sprintf(
            'Invoice %s has already been replaced by %s, which is now the live document. Correct the '.
            'replacement instead: two replacements for one invoice is a question with no answer, and '.
            'adjusting the original as well would count the correction twice.',
            $number,
            $replacement,
        ));
    }

    public static function ofNothing(): self
    {
        return new self('A correction with no lines was asked for, which adjusts nothing.');
    }

    public static function anInvoiceWithNoLines(string $number): self
    {
        return new self(sprintf(
            'Invoice %s has no lines, so there is no tax treatment or place of supply for an '.
            'adjusting document to inherit.',
            $number,
        ));
    }

    public static function aChargeOfNothing(int $lineNo): self
    {
        return new self(sprintf(
            'Charge %d is for zero or less. A debit note adds to what is owed; reducing it is a credit '.
            'note, which is a different document and a different procedure.',
            $lineNo,
        ));
    }

    public static function aNegativeLine(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d of the corrected invoice is negative. A replacement carries the corrected figures '.
            'as they should have been, not the difference from what they were.',
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
