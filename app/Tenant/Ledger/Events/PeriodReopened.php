<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Events;

use App\Support\Events\FinancialEvent;

/**
 * A closed period was reopened - PF-048, PF-050.
 *
 * ## Its own event, not a PeriodClosed with a different state
 *
 * Because the responses are different in kind. A close finalises a pack; a
 * reopen means a figure that has been reported may change, which is a
 * disclosure question under IAS 10 rather than an operational one.
 *
 * A single event with a state field makes every listener branch on it, and
 * one of them will forget - in the direction that treats a reopening as
 * routine.
 */
final readonly class PeriodReopened implements FinancialEvent
{
    public function __construct(
        private string $entityId,
        public string $periodId,
        public string $periodLabel,
        public string $fromState,
        public string $reason,
        public ?string $byUserId,
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'ledger.period.reopened';
    }
}
