<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Events;

use App\Support\Events\FinancialEvent;
use App\Support\Money\Money;

/**
 * Money moved, in either direction - PF-048.
 *
 * ## One event for both directions, where the documents got two
 *
 * An invoice and a credit note are different KINDS of document: one
 * creates a receivable and the other reduces it, and the posting rules
 * differ. A receipt and a disbursement are the same kind of act in
 * opposite directions, posted by one rule that reads the direction - so
 * a second event here would be two names for one thing, and every
 * listener would have to subscribe to both or silently handle half the
 * payments.
 *
 * `direction` is on the event because a listener needs it, not because
 * it distinguishes two events.
 */
final readonly class PaymentRecorded implements FinancialEvent
{
    public function __construct(
        private string $entityId,
        public string $paymentId,
        public string $number,
        public string $direction,
        public Money $amount,
        public string $valueDate,
        public ?string $partyId,
        public ?string $byUserId,
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'settlement.payment.recorded';
    }
}
