<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * A return that will not be filed - PF-086.
 */
final class FilingRefused extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public static function theChecklistIsNotClear(string $label, array $reasons): self
    {
        return new self(sprintf(
            'The %s return has %d blocking item%s on its pre-filing checklist and will not be filed: '.
            '%s. Filing anyway is a statement to an authority that the business knows to be '.
            'incomplete, and the figures become immutable the moment it is made.',
            $label,
            \count($reasons),
            \count($reasons) === 1 ? '' : 's',
            implode(' | ', $reasons),
        ));
    }

    public static function alreadyFiled(string $label): self
    {
        return new self(sprintf(
            'The %s return is already filed. A second submission is an amendment, which carries its own '.
            'reference and its own schedule - PF-087.',
            $label,
        ));
    }

    public static function overridesNeedAReason(string $label): self
    {
        return new self(sprintf(
            'Filing the %s return over a blocking checklist needs a written reason. Somebody will read '.
            'it during an audit, and "override" is not one.',
            $label,
        ));
    }
}
