<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Overview;

/**
 * What the entity owes suppliers, and how soon - UX-032.
 *
 * Approved and posted bills, open balance through
 * `peakflow_document_open_balance` - the one definition of an open balance.
 */
final readonly class PayablesDue
{
    public function __construct(
        public string $total,
        public string $overdue,
        public string $dueThisWeek,
        public int $documents,
        public int $dueThisWeekDocuments,
        public int $otherCurrencyDocuments,
    ) {}
}
