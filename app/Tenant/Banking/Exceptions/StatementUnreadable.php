<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Exceptions;

/**
 * A statement file that could not be read - PF-150 to PF-154.
 *
 * ## The message names the place, not just the problem
 *
 * Somebody is holding a file their bank just gave them. "Could not parse"
 * leaves them with a few thousand lines and no idea which one, and the usual
 * next step is to send the file to support and wait a day.
 *
 * So a malformed document carries the parser's line and column, a missing
 * element names the element and what it is for, and a file that is not what
 * it claims to be says what it looks like instead.
 */
final class StatementUnreadable extends \RuntimeException
{
    public static function because(string $why): self
    {
        return new self($why);
    }

    public static function malformed(string $format, string $reason, ?int $line, ?int $column): self
    {
        return new self(sprintf(
            'The %s file could not be parsed%s: %s. The position is where the parser gave up, which '.
            'is usually a line or two after the real problem - a tag left unclosed earlier shows up '.
            'at the next one that does not match.',
            strtoupper($format),
            $line === null
                ? ''
                : sprintf(' at line %d%s', $line, $column === null ? '' : sprintf(', column %d', $column)),
            rtrim($reason, " \n\r\t.")
        ));
    }

    public static function missing(string $format, string $element, string $whatItIsFor): self
    {
        return new self(sprintf(
            'The %s file has no %s, which is %s. A statement without it cannot be imported: the '.
            'figure is not something that can be inferred from the rest of the file, and guessing '.
            'it would produce an account that reconciles to a number nobody chose.',
            strtoupper($format),
            $element,
            $whatItIsFor,
        ));
    }

    public static function notTheFormatItClaims(string $claimed, string $looksLike): self
    {
        return new self(sprintf(
            'This was imported as %s and it is %s. Importing it anyway reads the wrong fields from '.
            'the right-looking places, which produces plausible rows rather than an error - so it is '.
            'refused instead.',
            strtoupper($claimed),
            $looksLike,
        ));
    }

    public static function unrecognised(string $known): self
    {
        return new self(sprintf(
            'This file is not in a format PeakFlow recognises. The ones it reads are: %s. If this is '.
            'a CSV your bank exports, import it as CSV and map its columns - the mapping is saved '.
            'and reused.',
            $known,
        ));
    }
}
