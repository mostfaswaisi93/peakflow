<?php

declare(strict_types=1);

namespace App\Exchange\Readers;

/**
 * Valid XML, and not a bill - PF-141.
 *
 * Kept apart from {@see \App\Support\Xml\MalformedXml}, which is about bytes
 * that are not XML at all. This document parsed: it is simply an Order, a
 * DespatchAdvice, an ApplicationResponse or something else entirely, all of
 * which are valid UBL and none of which is a document somebody is owed money
 * for.
 *
 * The distinction matters to whoever reads the message. "Malformed" sends
 * somebody to their XML writer; "this is an Order, not an Invoice" sends them
 * to the right endpoint.
 */
final class NotABillingDocument extends \RuntimeException
{
    public static function root(string $localName): self
    {
        return new self(sprintf(
            'The document root is <%s>, and a UBL billing document is <Invoice> or <CreditNote>. An '.
            'Order, a DespatchAdvice and an ApplicationResponse are all valid UBL and none of them is '.
            'a document anybody is owed money for.',
            $localName === '' ? 'nothing' : $localName,
        ));
    }
}
