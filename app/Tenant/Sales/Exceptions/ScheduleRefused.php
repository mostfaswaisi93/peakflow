<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Exceptions;

use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A recurring schedule that cannot be drafted, activated or generated - PF-105. */
final class ScheduleRefused extends DomainException
{
    public static function notACustomer(string $code): self
    {
        return new self(sprintf('Party %s is not marked as a customer.', $code));
    }

    public static function ofNothing(): self
    {
        return new self(
            'A recurring schedule with no lines bills nothing, every month, until somebody notices.',
        );
    }

    public static function twoEndConditions(): self
    {
        return new self(
            'A schedule ends on a date or after a number of occurrences, not both - the same rule RFC '.
            '5545 has for COUNT and UNTIL, and for the same reason: the two answer one question '.
            'differently and nothing says which wins.',
        );
    }

    public static function endsBeforeItStarts(string $endsOn, string $startsOn): self
    {
        return new self(sprintf(
            'The schedule ends on %s and starts on %s, so it has no occurrences at all.',
            $endsOn,
            $startsOn,
        ));
    }

    public static function anUnknownTimezone(string $timezone): self
    {
        return new self(sprintf(
            '"%s" is not an IANA timezone. Scheduled billing runs at the local time where the '.
            'business is - "daily at 01:00" means 01:00 there - and a name nobody can resolve would '.
            'silently become UTC, which is right for about a third of the world.',
            $timezone,
        ));
    }

    public static function alreadyEnded(string $reference): self
    {
        return new self(sprintf(
            'Schedule %s has ended. Re-activating it would resume billing from wherever the rule '.
            'happens to land next, which is not the same thing as a new agreement: create a new '.
            'schedule, so the old one stays as the record of what was billed.',
            $reference,
        ));
    }

    public static function aLineWithNoPriceAndNoItem(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d has no price and no item to resolve one from. A line priced by the price list '.
            'needs an item; a line priced on the schedule needs a figure.',
            $lineNo,
        ));
    }

    public static function noPriceOnThatDate(int $lineNo, string $date, string $why): self
    {
        return new self(sprintf(
            'Line %d has no price in force on %s, so the occurrence cannot be billed: %s. A schedule '.
            'tied to a price list bills at the price of the day, and a gap in the list is a decision '.
            'somebody has not made yet rather than a zero.',
            $lineNo,
            $date,
            $why,
        ));
    }

    public static function notActive(string $reference, string $status): self
    {
        return new self(sprintf(
            'Schedule %s is %s. Only an active schedule generates: a paused one is a decision '.
            'somebody made, and generating anyway would undo it silently.',
            $reference,
            $status,
        ));
    }

    public static function nothingToBill(string $reference, string $date): self
    {
        return new self(sprintf(
            'Schedule %s had nothing to bill on %s: every line prorated to zero, which happens when '.
            'the occurrence falls outside the schedule\'s own start and end. An invoice for nothing '.
            'is worse than none - it consumes a number from a gapless series and arrives at a '.
            'customer who owes nothing.',
            $reference,
            $date,
        ));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::ValidationFailed)
            ->withDetail($this->getMessage())
            ->toResponse($request);
    }
}
