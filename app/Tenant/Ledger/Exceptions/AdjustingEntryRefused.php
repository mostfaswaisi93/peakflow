<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use RuntimeException;

/** Why an adjusting entry was not posted - PF-051. */
final class AdjustingEntryRefused extends RuntimeException
{
    public static function notPermitted(): self
    {
        return new self(
            'Posting an adjusting entry needs the `period.post_adjusting` permission, with step-up '.
            'authentication. It is a figure entered after a year has been reported, which is why it is '.
            'not covered by the everyday posting permission.',
        );
    }

    public static function needsAReason(): self
    {
        return new self(
            'An adjusting entry needs a reason of at least twenty characters. "Audit adjustment" is not a '.
            'reason - which auditor, which schedule and which finding is, and that is what the disclosure '.
            'under IAS 10 is written from.',
        );
    }

    public static function noAdjustingPeriod(string $date): self
    {
        return new self(sprintf(
            'The fiscal year containing %s has no adjusting period. It is optional, and this entity was '.
            'set up without one - so an adjustment has to be posted into the last month instead, dated '.
            'within it. Turn `ledger.adjusting_period` on and regenerate the year to get period 13.',
            $date,
        ));
    }

    public static function mustBeDatedAtTheYearEnd(string $given, string $yearEnd, string $period): self
    {
        return new self(sprintf(
            'An adjusting entry must be dated %s, the last day of the year it adjusts, and this one is '.
            'dated %s. The adjusting period %s carries no calendar days of its own, so a line whose date '.
            'falls inside the year while its period ends in December is a line two reports disagree '.
            'about: one bounds by date and includes it, the other bounds by period and does not. The '.
            'adjustment belongs to the year it adjusts, and the year-end is the date that says so.',
            $yearEnd,
            $given,
            $period,
        ));
    }

    public static function periodNotOpen(string $period, string $state): self
    {
        return new self(sprintf(
            'The adjusting period %s is %s. A period that is generated as `future` has to be opened '.
            'deliberately, which is what stops an adjustment landing in a year nobody has started '.
            'closing.',
            $period,
            $state,
        ));
    }
}
