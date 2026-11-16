<?php

declare(strict_types=1);

namespace App\Support\Xml;

/**
 * Parse XML that came from outside - PF-114.
 *
 * ## Every reader, explicitly, not assumed off
 *
 * A received invoice is a file a stranger wrote. The three ways an XML
 * parser can be turned into a file reader, a network client or a memory
 * bomb are all **on by default in libxml** or one careless flag away, so
 * they are refused here by construction rather than left to the defaults of
 * whichever PHP build the container happens to have:
 *
 * - **External entities.** `LIBXML_NOENT` reads as "no entities" and means
 *   *substitute* entities - `XML_PARSE_NOENT` - so passing it is what turns
 *   `<!ENTITY x SYSTEM "file:///etc/passwd">` into a file read. It is not
 *   passed, and the external entity loader is replaced with one that returns
 *   nothing while this reader runs.
 * - **DTDs.** `LIBXML_DTDLOAD` and `LIBXML_DTDVALID` are absent, so no
 *   external DTD is fetched, and a document carrying any DOCTYPE at all is
 *   refused: a DOCTYPE in a received invoice has no legitimate purpose, and
 *   "billion laughs" is a DOCTYPE with no external reference and therefore
 *   not caught by refusing the network.
 * - **The network.** `LIBXML_NONET` refuses it even if something else asked.
 *
 * ## Why refusing a DOCTYPE is the real defence
 *
 * Entity expansion needs no external reference. A document declaring ten
 * nested entities, each repeating the previous one ten times, expands to a
 * gigabyte of memory from a few hundred bytes - and the parser is doing
 * exactly what the specification says. libxml has a limit, and the limit is
 * a tuning parameter somebody can raise. A received document with no
 * DOCTYPE cannot do it at all.
 *
 * ## It is not an error handler
 *
 * A malformed document gets a {@see MalformedXml} exception carrying the
 * parser's first message, line and column, because "the document could not
 * be parsed" is not something a person can act on.
 */
final class SafeXmlReader
{
    /**
     * @throws MalformedXml
     */
    public static function parse(string $xml): \DOMDocument
    {
        if (trim($xml) === '') {
            throw MalformedXml::empty();
        }

        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;

        /*
         | The previous loader is not reliably a callable.
         |
         | PHP 8.4 returns the callable it replaced; 8.3 and earlier return
         | `true`, and restoring that `true` is a TypeError. So it is only
         | restored when it is actually callable.
         */
        $previousLoader = libxml_set_external_entity_loader(static fn (): null => null);
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, \LIBXML_NONET);
            $errors = libxml_get_errors();
        } catch (\Throwable $e) {
            throw MalformedXml::unparseable($e->getMessage());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            libxml_set_external_entity_loader(\is_callable($previousLoader) ? $previousLoader : null);
        }

        if ($loaded === false) {
            $first = $errors[0] ?? null;

            throw MalformedXml::unparseable(
                $first === null ? 'the parser gave no reason' : trim($first->message),
                $first?->line,
                $first?->column,
            );
        }

        if ($document->doctype !== null) {
            throw MalformedXml::hasDoctype((string) $document->doctype->name);
        }

        return $document;
    }

    /**
     * An XPath selector over a parsed document, with no namespace prefixes
     * registered.
     *
     * Expressions use `local-name()`, because a sender may use any prefix
     * they like - `ns2:Invoice` is as valid as `Invoice` - and a selector
     * bound to the prefixes we happen to emit reads our own documents and
     * nobody else's.
     */
    public static function selector(\DOMDocument $document): \DOMXPath
    {
        return new \DOMXPath($document);
    }
}
