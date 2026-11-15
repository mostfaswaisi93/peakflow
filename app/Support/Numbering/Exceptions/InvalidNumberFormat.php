<?php

declare(strict_types=1);

namespace App\Support\Numbering\Exceptions;

use RuntimeException;

/**
 * A number format that cannot be used - PF-071.
 *
 * Every one of these is refused at CONFIGURATION time rather than at the
 * moment a document is issued, because the format is frozen once the series
 * has issued its first document - so a bad format caught at issue time is a
 * series that has to be abandoned, and a document number that has to be
 * explained.
 */
final class InvalidNumberFormat extends RuntimeException
{
    public static function empty(): self
    {
        return new self('A number format cannot be empty; it needs at least a {seq} placeholder.');
    }

    public static function withoutSequence(string $template): self
    {
        return new self(sprintf(
            'The format "%s" has no {seq} placeholder, so every document in the series would be given the '.
            'same number. Use {seq} or {seq:6} for a zero-padded one.',
            $template,
        ));
    }

    /**
     * Two sequence placeholders.
     *
     * Refused rather than rendering both, because `INV-{seq}-{seq:4}` is
     * somebody who meant a second field - a branch, a year - and the
     * rendered result looks deliberate enough that nobody questions it.
     */
    public static function withTwoSequences(string $template): self
    {
        return new self(sprintf(
            'The format "%s" has more than one {seq} placeholder. One number cannot appear twice in its own '.
            'document number; the second one is probably meant to be {branch}, {YYYY} or {entity}.',
            $template,
        ));
    }

    public static function unknownPlaceholder(string $template): self
    {
        return new self(sprintf(
            'The format "%s" contains a brace that is not a known placeholder, so it would be printed '.
            'literally on every document the series issues. Known: {seq}, {seq:n}, {YYYY}, {YY}, {MM}, {DD}, '.
            '{entity}, {branch}, {key}.',
            $template,
        ));
    }

    public static function sequenceTooWide(int $width): self
    {
        return new self(sprintf(
            'A sequence padded to %d digits is wider than any real series needs and wider than the counter '.
            'column; 12 is the maximum.',
            $width,
        ));
    }

    /**
     * A yearly reset with no year in the format.
     *
     * The combination issues `INV-00001` again every January. It is not a
     * malformed template - which is why it has to be refused explicitly,
     * because nothing about the format alone looks wrong.
     */
    public static function resetsWithoutAYear(string $template): self
    {
        return new self(sprintf(
            'The format "%s" has no year in it, and the series restarts every year - so it will issue the '.
            'same numbers again next January. Either add {YYYY} or {YY}, or stop the series resetting.',
            $template,
        ));
    }
}
