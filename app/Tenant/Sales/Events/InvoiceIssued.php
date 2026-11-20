<?php

declare(strict_types=1);

namespace App\Tenant\Sales\Events;

use App\Support\Events\FinancialEvent;
use App\Support\Money\Money;

/**
 * An invoice became a document somebody owes - PF-048.
 *
 * ## Why this is a FinancialEvent and not a notification
 *
 * Issuing is what creates the receivable and the output tax. A listener
 * on this is inside the issuing transaction, so a posting it makes
 * either commits with the invoice or does not happen - a posting that is
 * eventually consistent with the document that caused it is not a
 * ledger.
 *
 * Anything that is not a posting - the email, a webhook, a search index -
 * is a `SideEffect`, queued, and cannot fail the issue. The split is a
 * build failure either way, which is what stops somebody attaching a
 * mail send to this in a hurry.
 *
 * ## Ids and Money, never the Invoice
 *
 * The invoice is a `Sales` model and a listener in another module may
 * not touch one. Carrying the id means a listener that needs the
 * document asks Sales for it through a contract, which is the boundary
 * Deptrac compiles - and it means this event can be replayed from a log
 * where the model would be a pointer into a database that has moved on.
 */
final readonly class InvoiceIssued implements FinancialEvent
{
    public function __construct(
        private string $entityId,
        public string $invoiceId,
        public string $number,
        public string $partyId,
        public Money $gross,
        public string $issuedOn,
        public ?string $byUserId,
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'sales.invoice.issued';
    }
}
