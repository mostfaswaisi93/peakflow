<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * A correction that will not be recorded as written - PF-087.
 */
final class AdjustmentRefused extends RuntimeException
{
    public static function thePeriodIsNotFiled(string $label): self
    {
        return new self(sprintf(
            'The %s return has not been filed, so there is nothing to correct. A change to an unfiled '.
            'period is an ordinary document in that period - posting it as an adjustment would report a '.
            'correction to an authority that never received the figure being corrected.',
            $label,
        ));
    }

    public static function noOpenPeriod(string $jurisdiction, string $label): self
    {
        return new self(sprintf(
            'There is no open %s return period after %s to report this correction in. Generate the '.
            'periods first: an adjustment with nowhere to go would either sit unreported or be forced '.
            'into a period that is itself filed.',
            $jurisdiction,
            $label,
        ));
    }

    public static function thisRegimeAmends(string $profile, string $label): self
    {
        return new self(sprintf(
            'The %s profile corrects a filed return by AMENDING it, not by adjusting the next one. '.
            'Reporting this in the %s return would file a figure the authority cannot match against '.
            'anything - it is expecting a new submission for the original period, with its own '.
            'reference. The amendment flow is what this regime needs.',
            $profile,
            $label,
        ));
    }

    public static function theReasonIsTooShort(): self
    {
        return new self(
            'A correction needs a reason somebody can read on a schedule an authority sees: at least a '.
            'short sentence, and more than one word. "Adjustment" is not a reason - it is exactly ten '.
            'characters, which is how a length check on its own came to accept it - and neither is a '.
            'reference number by itself. Say what was wrong.',
        );
    }

    public static function itChangesNothing(): self
    {
        return new self(
            'An adjustment of zero in both figures changes nothing and would still appear on the '.
            'schedule, inviting the question the schedule exists to answer.',
        );
    }

    public static function unknownBox(string $box, string $form): self
    {
        return new self(sprintf(
            'There is no box "%s" on the %s return, so the correction has nowhere to appear. A figure '.
            'in a box the form does not have is a figure the portal rejects.',
            $box,
            $form,
        ));
    }
}
