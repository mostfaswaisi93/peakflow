<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Events;

use App\Support\Events\FinancialEvent;

/**
 * A fiscal year was closed into retained earnings - PF-048, PF-052.
 *
 * Carries the closing entry's NUMBER as well as its id, because every
 * listener that tells a human about this - a notification, a report header,
 * a webhook - needs the number, and looking it up means reaching into
 * `Ledger`'s models from wherever the listener lives.
 */
final readonly class YearClosed implements FinancialEvent
{
    public function __construct(
        private string $entityId,
        public string $fiscalYearId,
        public string $yearLabel,
        public ?string $entryId,
        public ?string $entryNumber,
        public string $carriedToRetainedEarnings,
        public string $currency,
        public int $accountsClosed,
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'ledger.year.closed';
    }
}
