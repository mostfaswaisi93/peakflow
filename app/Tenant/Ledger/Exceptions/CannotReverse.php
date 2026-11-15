<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refused: this entry cannot be reversed - PF-056.
 *
 * Each reason says what to do instead, because "cannot be reversed" leaves a
 * user with a wrong figure and no route to a right one - and the route they
 * invent is usually a second wrong figure.
 */
final class CannotReverse extends DomainException
{
    public static function becauseItIsADraft(string $number): self
    {
        return new self(sprintf(
            'Entry %s is still a draft, so there is nothing to reverse - edit or delete it instead. A reversal '.
            'exists to undo something the books already record.',
            $number,
        ));
    }

    public static function becauseItIsAlreadyReversed(string $number): self
    {
        return new self(sprintf(
            'Entry %s has already been reversed. Reversing it again would double the correction; if the '.
            'reversal itself was wrong, post a new entry restating what should have been recorded.',
            $number,
        ));
    }

    public static function becauseItIsItselfAReversal(string $number): self
    {
        return new self(sprintf(
            'Entry %s is a reversal, and a reversal cannot be reversed - a chain of them obscures the original '.
            'beyond recovery. If the correction was wrong, post a new entry restating the position: one entry '.
            'to read rather than four to reconcile.',
            $number,
        ));
    }

    public static function withoutAReason(string $number): self
    {
        return new self(sprintf(
            'Reversing entry %s needs a written reason. It appears on the drill-down beside both entries, and '.
            'it is the only thing that will explain the pair to whoever finds them next year.',
            $number,
        ));
    }

    public static function becauseItHasNoLines(string $number): self
    {
        return new self(sprintf(
            'Entry %s has no lines, so reversing it would produce an empty entry. That is a data problem worth '.
            'investigating rather than correcting.',
            $number,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::StateTransitionNotAllowed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
