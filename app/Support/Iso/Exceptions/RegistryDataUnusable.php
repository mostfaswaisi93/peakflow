<?php

declare(strict_types=1);

namespace App\Support\Iso\Exceptions;

use RuntimeException;

/**
 * A vendored standards file that cannot be trusted to be read - PF-007.
 *
 * Every one of these is thrown rather than worked around, which is the whole
 * point. A registry reader that skips a malformed row, or trims a padded
 * field, hands the application a currency table with a hole in it - and the
 * hole surfaces as a currency that "does not exist" on one invoice, months
 * later, with nothing to connect it to the commit that caused it.
 */
final class RegistryDataUnusable extends RuntimeException
{
    public static function missing(string $file): self
    {
        return new self(sprintf(
            'The vendored registry database/iso/%s is not there. These files are the source of the '.
            'currency minor units, country codes and unit codes - the application cannot guess them.',
            $file,
        ));
    }

    public static function noHeader(string $file): self
    {
        return new self(sprintf(
            'database/iso/%s has no header row. The header names the columns, and a reader that assumed '.
            'their order would break silently the first time a column was inserted.',
            $file,
        ));
    }

    public static function empty(string $file): self
    {
        return new self(sprintf(
            'database/iso/%s has a header and no rows. An empty registry makes every code unknown, which '.
            'reads as "this currency does not exist" rather than as a missing file.',
            $file,
        ));
    }

    public static function fieldCount(string $name, int $line, int $expected, int $found): self
    {
        return new self(sprintf(
            'database/iso/%s.tsv line %d has %d field(s) where the header declares %d. The row is refused '.
            'rather than padded: a currency row short of its exponent would default to 2, and two decimal '.
            'places on a three-decimal currency is a 1000x error that still looks like money.',
            $name,
            $line,
            $found,
            $expected,
        ));
    }

    public static function padded(string $name, int $line, string $field): self
    {
        return new self(sprintf(
            'database/iso/%s.tsv line %d has the whitespace-padded field "%s". Refused rather than trimmed, '.
            'because a trimmed code makes the file disagree with itself - the row looks right and the diff '.
            'that added the space is invisible.',
            $name,
            $line,
            $field,
        ));
    }

    public static function duplicate(string $name, string $key, int $first, int $second): self
    {
        return new self(sprintf(
            'database/iso/%s.tsv declares "%s" twice, on lines %d and %d. One of them wins and nothing says '.
            'which, so a reviewed change to the loser has no effect at all.',
            $name,
            $key,
            $first,
            $second,
        ));
    }

    public static function manifestShape(): self
    {
        return new self(
            'database/iso/provenance.json is not an object with a "files" map. Without it nothing can say '.
            'where the registries came from, and the digests that detect an unreviewed edit have nowhere '.
            'to be recorded.',
        );
    }

    public static function column(string $name, string $column): self
    {
        return new self(sprintf(
            'database/iso/%s.tsv has no "%s" column. The reader asks for columns by name so that inserting '.
            'one is safe; asking for a column that is not there is a change to the file that the reader '.
            'was not updated for.',
            $name,
            $column,
        ));
    }

    public static function value(string $name, string $key, string $column, string $value): self
    {
        return new self(sprintf(
            'database/iso/%s.tsv gives "%s" the %s "%s", which is not a value this column can hold. '.
            'Refused at load: a nonsense exponent or an unknown direction becomes a wrong invoice rather '.
            'than an error, and the invoice is seen by a customer before anybody sees the row.',
            $name,
            $key,
            $column,
            $value,
        ));
    }
}
