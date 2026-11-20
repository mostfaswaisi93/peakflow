<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Events;

use App\Support\Events\FinancialEvent;
use App\Support\Money\Money;

/**
 * A bank line was tied to the document it paid - PF-048.
 *
 * ## Why matching is a financial event at all
 *
 * Until a line is matched, the money is in the bank and in a clearing
 * position, and the document it belongs to is still open. Matching is
 * what closes both - so it posts, and a listener on it is inside that
 * posting's transaction.
 *
 * ## `matchedType` is a string, and that is the boundary
 *
 * A line can be matched to an invoice, a bill, a payment or a journal
 * entry, and those are four modules' models. Carrying the type as a
 * string with the id is what lets this event cross a boundary that a
 * typed reference could not - the listener that cares asks the module
 * that owns it.
 */
final readonly class StatementLineMatched implements FinancialEvent
{
    public function __construct(
        private string $entityId,
        public string $matchId,
        public string $bankTransactionId,
        public string $matchedType,
        public string $matchedId,
        public Money $amount,
        public string $bookingDate,
        public ?string $byUserId,
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'banking.statement_line.matched';
    }
}
