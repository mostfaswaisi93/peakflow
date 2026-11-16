<?php

declare(strict_types=1);

namespace App\Support\Attachments;

/**
 * A verdict on some bytes - PF-073.
 *
 * ## Why this is an interface with a refusing default
 *
 * The scan has to happen before the file is readable, which means the
 * application needs *something* to ask even when no scanner is installed.
 * There are two honest ways to handle a missing scanner and one dishonest
 * one:
 *
 * - **Refuse the upload.** Safe, and makes a fresh clone unable to attach a
 *   file at all.
 * - **Accept it and say it is unscanned**, leaving it unreadable. Safe, and
 *   the file sits there until a scanner exists.
 * - Accept it and call it clean. This is the dishonest one, and it is what
 *   every "scanning is optional" implementation does.
 *
 * {@see UnavailableScanner} is the second. It returns `failed`, which the
 * read path refuses, so a development machine can exercise the whole upload
 * path and still cannot serve an unscanned file. A production deployment
 * configured with it is reported by `ProductionConfiguration`.
 */
interface VirusScanner
{
    /** A name for the audit record: `clamav`, `unavailable`. */
    public function name(): string;

    /**
     * Scan the bytes at a local path.
     *
     * @return ScanVerdict
     */
    public function scan(string $absolutePath): ScanVerdict;
}
