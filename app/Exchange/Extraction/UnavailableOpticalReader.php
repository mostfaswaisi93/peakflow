<?php

declare(strict_types=1);

namespace App\Exchange\Extraction;

/**
 * The reader for a deployment with no OCR engine - PF-142.
 *
 * ## It refuses rather than returning nothing
 *
 * "No fields were read" and "no engine is installed" look identical in a
 * queue and mean opposite things: the first is a document somebody should
 * re-scan, the second is a deployment somebody should fix. So this throws,
 * the pipeline records the proposal as `unavailable` with the reason, and
 * nobody re-scans a legible invoice.
 *
 * ## And it certainly does not invent figures
 *
 * A stub returning plausible values would make the suite assert the
 * behaviour of something that does not exist, and the first real engine
 * would have to match a fiction. The absence is recorded; it is not
 * simulated.
 */
final class UnavailableOpticalReader implements OpticalReader
{
    /** @return array{name: string, version: string} */
    public function identify(): array
    {
        return ['name' => 'none', 'version' => '0'];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    /**
     * @return list<array{field: string, value: ?string, confidence: float}>
     *
     * @throws OpticalReaderUnavailable
     */
    public function read(string $bytes, string $contentType): array
    {
        throw OpticalReaderUnavailable::because(
            'No optical reader is configured for this deployment, so a document with no embedded XML '.
            'cannot be read automatically. This is a deployment to fix rather than a document to '.
            're-scan: set `extraction.driver` once an engine is available.',
        );
    }
}
