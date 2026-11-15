<?php

declare(strict_types=1);

namespace App\Tenant\Tax\Exceptions;

use RuntimeException;

/**
 * A return form that cannot be used to file - PF-085.
 *
 * Every refusal here is a form that would otherwise produce a return with a
 * figure missing or in the wrong box, which the authority sees before we do.
 */
final class ReturnFormUnusable extends RuntimeException
{
    public static function unknownForm(string $form): self
    {
        return new self(sprintf(
            'There is no return form "%s" with any boxes. A jurisdiction with no form of its own falls '.
            'back to the generic one explicitly, in config/tax_returns.php, where somebody can see that '.
            'it does.',
            $form,
        ));
    }

    /** @param  list<string>  $sides */
    public static function boxWithoutASide(string $form, string $box, array $sides): self
    {
        return new self(sprintf(
            'Box "%s" on the %s return declares no side (%s). PF-088 reconciles the movement on the '.
            'output and input control accounts against the boxes that carry each side, so a box with no '.
            'side could not be reconciled against anything - and the box nobody reconciles is the one '.
            'that is wrong.',
            $box,
            $form,
            implode(', ', $sides),
        ));
    }

    public static function boxWithoutALabel(string $form, string $box, string $locale): self
    {
        return new self(sprintf(
            'Box "%s" on the %s return has no %s label. A return is read by a person before it is '.
            'submitted, and an unlabelled box is a figure nobody checks.',
            $box,
            $form,
            $locale,
        ));
    }

    public static function withoutASource(string $form): self
    {
        return new self(sprintf(
            'The %s return form cites no source. A form changes, and the citation is what tells the next '.
            'person which published version the boxes came from.',
            $form,
        ));
    }
}
