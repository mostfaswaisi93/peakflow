<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Exceptions;

use App\Tenant\Ledger\Manual\ImportReport;
use RuntimeException;

/**
 * Why a spreadsheet was not imported - PF-055.
 *
 * The report travels with the exception, because the caller's next action is
 * to show it. An exception carrying only a message would make the screen run
 * the dry run again to find out what was wrong.
 */
final class ImportRefused extends RuntimeException
{
    public ?ImportReport $report = null;

    public static function notPermitted(): self
    {
        return new self(
            'Importing journal entries needs the `journal.post` permission. A file of four hundred lines '.
            'is four hundred postings, and the permission is the same one a single entry needs.',
        );
    }

    public static function needsAReason(): self
    {
        return new self(
            'An import needs a reason of at least ten characters saying where the file came from. It is '.
            'what somebody reads when they ask why forty entries appeared on the same day.',
        );
    }

    public static function theFileHasProblems(ImportReport $report): self
    {
        $rows = count(array_unique(array_column($report->rejections, 'row')));

        $exception = new self(sprintf(
            'The file was not imported: %d of %d rows have a problem. Nothing was written - a file that '.
            'posts the rows it can leaves a partial month in the ledger and a spreadsheet to diff '.
            'against it. The first few: %s',
            $rows,
            $report->rows,
            implode('; ', array_map(
                static fn (array $r): string => sprintf('row %d: %s', $r['row'], $r['message_en']),
                array_slice($report->rejections, 0, 3),
            )),
        ));

        $exception->report = $report;

        return $exception;
    }
}
