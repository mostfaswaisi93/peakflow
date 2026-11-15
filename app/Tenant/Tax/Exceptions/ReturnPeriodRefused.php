<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * A document or a filing that will not be accepted into a return period - PF-085.
 */
final class ReturnPeriodRefused extends RuntimeException
{
    public static function alreadyFiled(string $label, string $jurisdiction, string $taxPoint): self
    {
        return new self(sprintf(
            'The tax point %s falls in the %s %s return, which has already been filed. The document is '.
            'refused rather than moved into the next open period: a supply quietly reported a quarter '.
            'late is a mis-statement of both returns that nobody chose and nothing records. If it '.
            'belongs in the filed period, the correction is an adjustment carrying a reference to the '.
            'period it corrects - PF-087.',
            $taxPoint,
            $jurisdiction,
            $label,
        ));
    }

    public static function noPeriodCovers(string $jurisdiction, string $taxPoint): self
    {
        return new self(sprintf(
            'No %s return period covers the tax point %s. Refused rather than generated on demand: a '.
            'period created by whichever document happened to arrive first takes its frequency from a '.
            'guess, and the frequency decides the window every later document falls into. Generate the '.
            'year\'s periods from the registration\'s declared frequency first.',
            $jurisdiction,
            $taxPoint,
        ));
    }

    public static function noFrequencyDeclared(string $jurisdiction): self
    {
        return new self(sprintf(
            'The %s registration does not say how often it files, so there is nothing to generate '.
            'periods from. Defaulting to monthly would create eleven periods nobody asked for and file '.
            'the first of them.',
            $jurisdiction,
        ));
    }

    public static function notRegistered(string $jurisdiction, string $date): self
    {
        return new self(sprintf(
            'This entity has no %s tax registration valid on %s, so it has no return to file there. A '.
            'period generated anyway would be a return nobody is obliged to submit and the authority '.
            'would not accept.',
            $jurisdiction,
            $date,
        ));
    }

    public static function alreadyGenerated(string $label, string $jurisdiction): self
    {
        return new self(sprintf(
            'The %s %s return period already exists. Regenerating it is refused: if the frequency '.
            'changed, the existing periods were the ones documents have already been reported in, and '.
            'replacing them would move supplies between returns.',
            $jurisdiction,
            $label,
        ));
    }

    public static function cannotFileAnEmptyPeriod(string $label): self
    {
        return new self(sprintf(
            'The %s period cannot be filed: it is already filed. A second filing is an amendment, which '.
            'is a different legal act with its own reference - PF-087.',
            $label,
        ));
    }
}
