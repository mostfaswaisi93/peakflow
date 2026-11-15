<?php

declare(strict_types=1);

namespace App\Support\Events;

/**
 * An event whose posting must happen inside the originating transaction - PF-048.
 *
 * ## The rule this interface exists to make checkable
 *
 * **A posting that is eventually consistent with the document that caused it
 * is not a ledger.**
 *
 * An invoice that exists for four seconds with no journal entry is an invoice
 * whose VAT is not in the return, whose receivable is not in the aging, and
 * whose customer is not over their credit limit. Four seconds is enough for a
 * report to be run, and a queue that is down is enough for a day.
 *
 * So a financial event's posting listener is synchronous and in the same
 * transaction: if the posting fails, the document fails with it. That is the
 * correct outcome - an invoice nobody can account for is worse than an
 * invoice that was never issued, because the second one can be retried.
 *
 * ## And the side effects are the other way round
 *
 * Emailing the invoice, pushing a webhook, indexing it for search: all of
 * those are queued, and a failure in any of them must never fail or roll back
 * the posting. A mail server being down has no business reversing a journal
 * entry.
 *
 * That split is not a convention anybody can be relied on to remember, so it
 * is a marker on the event and an architecture rule that reads it: a listener
 * on a FinancialEvent that implements ShouldQueue is a build failure, and a
 * side-effect listener that does NOT is the same.
 *
 * ## Why an interface and not a base class
 *
 * An event is a value. Giving it a parent means giving it behaviour it does
 * not need, and the one thing this marks - "the ledger depends on this
 * happening now" - is a property of the event rather than something it does.
 */
interface FinancialEvent
{
    /**
     * The entity the posting belongs to.
     *
     * Every financial event has one, because a posting without an entity has
     * no chart of accounts to resolve roles against and no period to land
     * in. Required here so a listener never has to guess, and so an event
     * that genuinely has no entity is forced to admit it is not financial.
     */
    public function entityId(): string;

    /**
     * What this event is, for the audit entry the posting writes.
     *
     * A key rather than a class name: the class moves when a module is
     * reorganised and the audit log does not get to move with it.
     */
    public function eventKey(): string;
}
