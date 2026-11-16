<?php

declare(strict_types=1);

namespace App\Exchange\Inbound;

/**
 * Who we decided sent a document, and on what evidence - PF-141.
 *
 * A pair rather than an id, because the method is half the answer. "Matched
 * on the electronic address in BT-34" and "a person decided" are the same
 * supplier and a different level of confidence, and the second is the one an
 * auditor asks about.
 */
final readonly class SupplierMatch
{
    private function __construct(
        public string $partyId,
        public string $method,
        public string $detail,
    ) {}

    public static function byElectronicAddress(string $partyId, string $scheme, string $address): self
    {
        return new self(
            $partyId,
            'electronic_address',
            sprintf(
                'BT-34 carried %s:%s, which is this supplier\'s registered electronic address. The '.
                'scheme is half of the identifier - the same digits are a company number in one '.
                'registry and a VAT number in another.',
                $scheme,
                $address,
            ),
        );
    }

    public static function byTaxRegistration(string $partyId, string $scheme, string $identifier): self
    {
        return new self(
            $partyId,
            'tax_registration',
            sprintf(
                'No electronic address matched, and the document\'s %s registration %s does. A tax '.
                'registration is issued by an authority and is unique within it, which is why it is '.
                'the second resort and a name is none.',
                $scheme,
                $identifier,
            ),
        );
    }

    public static function bySenderAllowlist(string $partyId, string $address): self
    {
        return new self(
            $partyId,
            'sender_allowlist',
            sprintf(
                'The document carried no identifier that matched, and %s is on this supplier\'s sender '.
                'allowlist - which somebody added deliberately and is answerable for.',
                $address,
            ),
        );
    }
}
