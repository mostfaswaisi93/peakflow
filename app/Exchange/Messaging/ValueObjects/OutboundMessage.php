<?php

declare(strict_types=1);

namespace App\Exchange\Messaging\ValueObjects;

/**
 * One message, built and ready to go - PF-257.
 */
final readonly class OutboundMessage
{
    /** @param list<string> $variables in the template's own order */
    public function __construct(
        public string $to,
        public string $template,
        public string $language,
        public array $variables,
        /**
         * Our own id for this send, so a retry is not a second message.
         *
         * The same contract as PF-255's `client_id`: a network that
         * drops a response looks exactly like one that drops a request,
         * and a provider that charges per message makes the difference
         * cost money as well as confusion.
         */
        public string $reference,
        public ?string $documentUrl = null,
        /**
         * Files to attach, as `name shown to the reader => path on disk`.
         *
         * Empty on a channel that cannot carry one. WhatsApp sends a link
         * because a template message has no attachments; email carries
         * the PDF, and the XML where the profile requires it - PF-104.
         *
         * Paths rather than contents, because an invoice PDF is already
         * on disk and holding a second copy of it in memory for every
         * message in a batch is how a delivery run runs a worker out of
         * memory on the one day somebody sends a thousand.
         *
         * @var array<string, string>
         */
        public array $attachments = [],
    ) {}
}
