<?php

declare(strict_types=1);

namespace App\Support\Attachments;

/**
 * No scanner is installed, and it says so - PF-073.
 *
 * Returns `failed` with the reason. The read path refuses anything that is
 * not `clean`, so a development machine can exercise the entire upload path -
 * the sniffing, the size cap, the deduplication, the reference counting -
 * and still cannot serve a file nobody has looked at.
 *
 * The alternative, returning `clean`, is what makes a virus scan decorative:
 * every environment where the scanner is missing becomes an environment where
 * the scan passes.
 */
final class UnavailableScanner implements VirusScanner
{
    public function name(): string
    {
        return 'unavailable';
    }

    public function scan(string $absolutePath): ScanVerdict
    {
        return ScanVerdict::failed(
            $this->name(),
            'No virus scanner is configured, so these bytes have not been looked at. The file is '.
            'stored and is not readable: a scan that runs after the file is downloadable is a scan '.
            'that tells you what you already served.',
        );
    }
}
