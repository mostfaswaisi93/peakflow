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
    ) {}
}
