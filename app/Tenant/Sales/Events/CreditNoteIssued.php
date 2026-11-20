<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Events;

use App\Support\Events\FinancialEvent;
use App\Support\Money\Money;

/**
 * A credit note became a document that reduces what somebody owes - PF-048.
 *
 * ## Separate from InvoiceIssued, and deliberately
 *
 * Both are issued documents and a single event with a `type` field would
 * carry both. It would also mean every listener starts with a branch,
 * and the first one somebody writes without the branch treats a credit
 * as a sale - which is a receivable that grows when it should shrink and
 * output tax declared on money being given back.
 *
 * The two events are the same shape and that is not duplication: it is
 * the type system saying which one arrived.
 *
 * `creditsInvoiceId` is null for a standalone credit, which is a real
 * case - a goodwill credit with no invoice behind it - rather than an
 * omission.
 */
final readonly class CreditNoteIssued implements FinancialEvent
{
    public function __construct(
        private string $entityId,
        public string $creditNoteId,
        public string $number,
        public string $partyId,
        public Money $gross,
        public string $issuedOn,
        public ?string $creditsInvoiceId,
        public ?string $byUserId,
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'sales.credit_note.issued';
    }
}
