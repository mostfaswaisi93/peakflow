<?php

declare(strict_types=1);

namespace App\Support\Attachments;

/**
 * An attachment the store will not take, or will not serve - PF-073.
 *
 * Every message says what is wrong with the file in terms the person holding
 * it can act on. "This type is not allowed" sends somebody to a settings
 * page; "this file is named .pdf and is an HTML document" tells them they
 * have been sent something odd, which is usually the truth.
 */
final class AttachmentRefused extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function tooLarge(int $bytes, int $cap): self
    {
        return new self(sprintf(
            'The file is %s and the limit is %s. A cap exists because an upload path with none is a '.
            'way to fill the disk, and a full disk stops the ledger accepting postings.',
            self::human($bytes),
            self::human($cap),
        ));
    }

    public static function typeNotPermitted(string $sniffed, ?string $declared): self
    {
        return new self(sprintf(
            'The file is %s, which is not a type PeakFlow stores%s. The list is an allowlist rather '.
            'than a denylist: a denylist is a list of the dangerous formats somebody thought of.',
            $sniffed,
            $declared === null || $declared === $sniffed ? '' : sprintf(' (it was offered as %s)', $declared),
        ));
    }

    public static function notClean(string $result, string $detail): self
    {
        return new self(match ($result) {
            'infected' => sprintf(
                'The file was found to be infected and is not readable%s.',
                $detail === '' ? '' : ': '.$detail,
            ),
            'pending' => 'The file has not been scanned yet, so it is not readable. A scan that runs '.
                'after the file is downloadable is a scan that tells you what you already served.',
            default => sprintf(
                'The file has not been found clean, so it is not readable%s. A scanner that could not '.
                'reach a verdict has told you nothing, and treating nothing as fine is what makes a '.
                'virus scan decorative.',
                $detail === '' ? '' : ': '.$detail,
            ),
        });
    }

    private static function human(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        if ($bytes >= 1024) {
            return sprintf('%.1f kB', $bytes / 1024);
        }

        return sprintf('%d bytes', $bytes);
    }
}
