<?php

declare(strict_types=1);

namespace App\Tenant\Purchasing\Events;

use App\Support\Events\FinancialEvent;
use App\Support\Money\Money;

/**
 * A supplier bill became a liability - PF-048.
 *
 * ## Posted, not approved, and the difference matters
 *
 * PF-048 names this `BillApproved`, and approval is not when the money
 * becomes owed: an approved bill is one somebody agreed to pay, and a
 * posted one is one the ledger carries. The payable and the input tax
 * arrive at posting, so that is where the financial event belongs - a
 * listener on approval would post a liability for a document that may
 * still be rejected.
 *
 * Named for what happens rather than for the task's first guess at it.
 */
final readonly class BillPosted implements FinancialEvent
{
    public function __construct(
        private string $entityId,
        public string $billId,
        public string $supplierDocumentNumber,
        public string $partyId,
        public Money $gross,
        public string $postedOn,
        public ?string $byUserId,
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'purchasing.bill.posted';
    }
}
