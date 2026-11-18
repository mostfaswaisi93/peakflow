<?php

declare(strict_types=1);

namespace App\Exchange\Messaging\ValueObjects;

/**
 * What the provider said - PF-257.
 *
 * ## A failure is a result, not an exception
 *
 * Because a delivery failure must not change the document's state: it
 * is issued, and undelivered. A method that threw would make every
 * caller decide what that means, and the first one to get it wrong
 * would roll back an issued invoice.
 */
final readonly class SendResult
{
    private function __construct(
        public bool $accepted,
        public ?string $providerMessageId,
        public ?string $failureCode,
        public string $why,
    ) {}

    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, null, sprintf(
            'The provider accepted it as %s. Accepted is not delivered: the delivery and the read '.
            'arrive later, as their own records.',
            $providerMessageId,
        ));
    }

    /**
     * Refused by the provider, with the code they gave.
     *
     * The code matters because the right response differs: a template
     * not approved needs somebody to submit it, an unreachable number
     * needs the customer contacted another way, and a rate limit needs
     * only patience. A single "failed" sends all three to the same
     * place, which is nowhere.
     */
    public static function refused(string $code, string $why): self
    {
        return new self(false, null, $code, $why);
    }
}
