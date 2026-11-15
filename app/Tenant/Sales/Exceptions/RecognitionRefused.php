<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Revenue that cannot be planned or recognised - PF-107. */
final class RecognitionRefused extends DomainException
{
    public static function aServicePeriodIsMissing(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d is recognised straight-line and has no service period. A straight-line schedule '.
            'over an unknown period is a schedule nobody can compute, and defaulting it to a month '.
            'would recognise a year\'s support in four weeks.',
            $lineNo,
        ));
    }

    public static function aServicePeriodRunsBackwards(int $lineNo, string $from, string $until): self
    {
        return new self(sprintf(
            'Line %d is serviced from %s until %s, which is backwards.',
            $lineNo,
            $from,
            $until,
        ));
    }

    public static function nothingToRelease(string $period): self
    {
        return new self(sprintf(
            'Nothing is due for recognition in %s. An entry for zero is a posting that says nothing '.
            'and a line that the ledger refuses - so the run reports it rather than writing it.',
            $period === '' ? 'that period' : $period,
        ));
    }

    public static function aReleaseWithNoAccount(): self
    {
        return new self(
            'A release has no revenue account to credit. The plan carries the account the line was '.
            'to be recognised into, because the line\'s account may have been re-pointed since - and '.
            'revenue released into a different account than it was deferred from is a reconciliation '.
            'nobody can close.',
        );
    }

    public static function thePeriodIsNotOpen(string $period, string $state): self
    {
        return new self(sprintf(
            'Period %s is %s, so revenue cannot be recognised into it. Recognition posts a journal '.
            'entry like any other: the period that refuses a sale refuses this too, and the '.
            'recognition that belongs in a closed period is PF-087\'s procedure rather than a '.
            'posting backdated into it.',
            $period,
            $state,
        ));
    }

    public static function aDocumentThatIsNotIssued(): self
    {
        return new self(
            'A draft has no revenue to defer: nothing has been invoiced, nothing has been posted, and '.
            'a plan written now would describe a document that may never exist.',
        );
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::ValidationFailed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
