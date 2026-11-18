<?php

declare(strict_types=1);

namespace App\Exchange\Messaging\Contracts;

use App\Exchange\Messaging\ValueObjects\OutboundMessage;
use App\Exchange\Messaging\ValueObjects\SendResult;
use App\Exchange\Messaging\ValueObjects\TemplateDefinition;

/**
 * A channel a document can be delivered on - PF-257.
 *
 * ## Why an interface with one implementation and one fake
 *
 * A product wired to a single vendor's SDK is a product that cannot
 * change vendor. That is not a hypothetical: WhatsApp Business is
 * reached through resellers, their pricing and their approval queues
 * differ, and a business that outgrows one has to move. A move that
 * means rewriting the sending path is a move that does not happen, and
 * the business stays on a provider it has stopped trusting.
 *
 * The fake is not a convenience either. Every check in this phase runs
 * against it, which means the checks assert OUR behaviour - the refusal
 * of an unapproved template, the recording of an attempt, the handling
 * of a failure - rather than a vendor's uptime. A suite that needed a
 * live account would be a suite nobody runs.
 *
 * ## What the interface deliberately does NOT expose
 *
 * No "send raw text". WhatsApp Business refuses unsolicited free-form
 * messages outside a customer-initiated window, and a method that
 * offered it would be called, would work in testing against the fake,
 * and would be refused in production for a reason nobody connects to
 * the code. Everything outbound goes through an approved template.
 */
interface MessageChannel
{
    /** Which channel this is, as it appears on a delivery attempt. */
    public function key(): string;

    /**
     * The templates this provider has APPROVED for this account.
     *
     * Approved by the provider, not declared by us. A template we think
     * exists and they have not approved is refused at their end with a
     * generic error, hours later, and the invoice is simply not
     * delivered - so this is read before sending rather than after.
     *
     * @return list<TemplateDefinition>
     */
    public function approvedTemplates(): array;

    /**
     * Send one message built from an approved template.
     *
     * Never throws for a provider-side refusal: a failed send is an
     * OUTCOME to record, not an exception to handle. A delivery failure
     * must not change the document's state - it is issued, and
     * undelivered.
     */
    public function send(OutboundMessage $message): SendResult;

    /**
     * Whether this number has been verified as reachable.
     *
     * Sending an invoice to a wrong number is a data breach with a
     * timestamp on it, so nothing is sent to a number nobody checked.
     */
    public function isReachable(string $number): bool;
}
