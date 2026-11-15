<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Events;

use App\Support\Events\FinancialEvent;

/**
 * A period moved to a closed state - PF-048, PF-050.
 *
 * ## It carries ids and strings, not models
 *
 * A `Period` on this event would make every listener - in Reporting, in
 * Exchange, in a tenant's own webhook payload - depend on `Ledger`'s models.
 * The module graph forbids most of them from doing that, and the ones it
 * allows would still be coupled to a schema they have no business knowing.
 *
 * So a cross-module event carries primitives and Support value objects. Which
 * also means it serialises onto a queue without a model's relations coming
 * along, and deserialises in a worker that may be running the next release.
 *
 * ## Financial, so the posting listeners are synchronous
 *
 * Closing a period is what makes a reporting pack final. A listener that
 * reacted to it an hour later would publish a pack from a period that was
 * still open when it was built.
 */
final readonly class PeriodClosed implements FinancialEvent
{
    public function __construct(
        private string $entityId,
        public string $periodId,
        public string $periodLabel,
        public string $state,
        public string $reason,
        public ?string $byUserId,
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'ledger.period.closed';
    }
}
