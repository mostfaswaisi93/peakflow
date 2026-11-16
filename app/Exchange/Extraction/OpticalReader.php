<?php

declare(strict_types=1);

namespace App\Exchange\Extraction;

/**
 * An engine that reads figures off a picture - PF-142.
 *
 * ## Why this is an interface with one unavailable implementation
 *
 * The same reasoning as PF-073's virus scanner. No OCR engine is installable
 * in this environment, and the two honest options are a pipeline that cannot
 * be built until one is, or a pipeline whose engine is a declared absence.
 *
 * The second is better, and not because it is easier: everything around the
 * engine is where the risk is. A proposal is not a value, a confidence
 * belongs to a guess, a field below the threshold is flagged for a person,
 * no extracted bill posts without a confirmation, and a consistently
 * misread format is visible. All of that is testable with no engine at all,
 * and all of it is where an extraction pipeline goes wrong.
 *
 * What is NOT acceptable is a stub that returns plausible figures. A fake
 * reading that looks like a real one is how a test suite comes to assert the
 * behaviour of something that does not exist - and the first real engine
 * then has to match a fiction.
 *
 * @see UnavailableOpticalReader
 */
interface OpticalReader
{
    /** A name and version for the record: an accuracy figure needs both. */
    public function identify(): array;

    public function isAvailable(): bool;

    /**
     * Read what fields it can, each with a confidence.
     *
     * @return list<array{field: string, value: ?string, confidence: float}>
     *
     * @throws OpticalReaderUnavailable
     */
    public function read(string $bytes, string $contentType): array;
}
