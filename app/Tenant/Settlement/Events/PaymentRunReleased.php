<?php

declare(strict_types=1);

namespace App\Tenant\Settlement\Events;

use App\Support\Events\FinancialEvent;
use App\Support\Money\Money;

/**
 * A payment run was released, and the money is irrevocably on its way - PF-048.
 *
 * ## The one event on this list that cannot be undone
 *
 * An invoice can be credited, a bill can be reversed, a match can be
 * unmatched. A released run has left for the bank: the file is generated
 * and what happens next is the bank's. So a listener here is listening
 * to a fact rather than to a state that may change, and anything it does
 * has to assume it cannot be taken back.
 *
 * `releasedBy` is not nullable. Release is the one act in this module
 * that requires a second person - the preparer may not release - so an
 * event that could not name who did it would be an event that cannot
 * answer the question the control exists to answer.
 */
final readonly class PaymentRunReleased implements FinancialEvent
{
    public function __construct(
        private string $entityId,
        public string $runId,
        public string $reference,
        public Money $total,
        public int $itemCount,
        public string $releasedBy,
        public string $payOn,
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'settlement.payment_run.released';
    }
}
