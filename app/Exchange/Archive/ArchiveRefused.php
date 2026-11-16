<?php

declare(strict_types=1);

namespace App\Exchange\Archive;

/**
 * Something the archive will not do - PF-129.
 */
final class ArchiveRefused extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    /** @param list<string> $known */
    public static function unknownKind(string $kind, array $known): self
    {
        return new self(sprintf(
            '"%s" is not an artefact kind. The archive holds %s: a list rather than free text, because '.
            'a kind nobody declared is an artefact no manifest lists and no restore drill samples.',
            $kind,
            implode(', ', $known),
        ));
    }

    public static function alreadyArchived(string $kind, string $stored, string $offered): self
    {
        return new self(sprintf(
            'A %s is already archived for this document and hashes to %s; these bytes hash to %s. There '.
            'cannot be two candidates for "the bytes that were sent" - the recipient holds one of them, '.
            'and nothing here could say which.',
            $kind,
            substr($stored, 0, 12),
            substr($offered, 0, 12),
        ));
    }

    public static function missing(string $hash, string $path): self
    {
        return new self(sprintf(
            'The archived object %s is not in storage at %s. The row says it is there, which is the '.
            'divergence `archive:verify` exists to find before an inspector does.',
            substr($hash, 0, 12),
            $path,
        ));
    }

    public static function diverged(string $expected, string $actual): self
    {
        return new self(sprintf(
            'The archived object hashes to %s and the row says %s. The copy the recipient holds matches '.
            'one of them, and it is not this one.',
            substr($actual, 0, 12),
            substr($expected, 0, 12),
        ));
    }
}
