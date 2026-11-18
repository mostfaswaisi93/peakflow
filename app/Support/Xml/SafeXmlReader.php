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
 * ## The DOCTYPE is refused on the BYTES, before the parser runs
 *
 * This is the part that was wrong, and it was wrong in the direction that
 * looks right. The refusal used to read `$document->doctype !== null`, which
 * is a question you can only ask once libxml has built the tree - and libxml
 * expands an internal general entity while it builds it whether or not
 * `LIBXML_NOENT` was passed. `LIBXML_NOENT` controls whether the expansion
 * is *flattened into text nodes* or left behind an entity-reference node
 * whose children already hold it. Either way the memory has been allocated.
 *
 * Measured on libxml 2.9.14: a 347-byte document declaring five levels of
 * nested entities parsed without complaint and `textContent` came back with
 * 1,000,000 characters in it - an amplification of roughly 2,880x paid in
 * full before any post-parse check could refuse the document. Six levels was
 * refused, by libxml's own amplification cap and not by anything here, which
 * is the worst arrangement available: the defence that fires is the one
 * nobody chose and the one somebody can raise with a flag.
 *
 * So the prolog is scanned first. Only four things may legally appear before
 * a root element - whitespace, a byte-order mark, the XML declaration or a
 * processing instruction, and comments - and a `<!DOCTYPE` among them is
 * refused with nothing allocated. The post-parse check is kept as a second
 * layer, the way `audit_log` keeps both a revoked privilege and a trigger:
 * two layers whose refusals are worded differently, so a check can tell
 * which one fired.
 *
 * ## The size cap comes before everything
 *
 * `exchange.xml.max_bytes`. An inbound document from a trading partner is
 * untrusted input, and the DOM libxml builds is several times the size of
 * the bytes it came from - so a cap applied after parsing is a cap that has
 * already been paid. It is checked on the string, first, before the prolog
 * is even looked at.
 *
 * ## And the depth limit is ours, not libxml's
 *
 * `exchange.xml.max_depth`. libxml refuses a document nested deeper than 256
 * on its own, which means a document 250 deep used to be accepted while the
 * configuration said 100 - a declared limit that nothing enforced. Depth is
 * measured iteratively over the parsed tree rather than before the parse:
 * with the size capped and the DOCTYPE already refused, nothing is left that
 * could make a bounded document expensive, and measuring beforehand would
 * mean parsing every legitimate invoice twice.
 *
 * `LIBXML_PARSEHUGE` is never passed, which is what keeps libxml's own 256 a
 * backstop underneath ours.
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

        self::assertTheBytesAreAcceptable($xml);

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

        /*
         | The second layer, and it must never be the one that fires.
         |
         | The prolog scan above is the defence; this is the assertion that
         | the scan did its job. It stays because the two are independent -
         | a prolog scanner that is one day rewritten and gets a case or an
         | encoding wrong fails here instead of silently admitting a
         | DOCTYPE - and it is cheap, being one property read on a tree that
         | has already been built.
         */
        if ($document->doctype !== null) {
            throw MalformedXml::hasDoctype((string) $document->doctype->name);
        }

        self::assertNotTooDeep($document);

        return $document;
    }

    /**
     * The guards that run on the BYTES, before any parser sees them.
     *
     * Public because one reader rewrites its input before parsing it, and a
     * guard applied to the rewrite is a guard applied to the wrong document.
     * {@see \App\Tenant\Banking\Parsers\OfxParser} closes the unclosed tags
     * of OFX 1.x's SGML into XML, and in doing so it truncates everything
     * before `<OFX>` - which happens to delete a DOCTYPE and leaves the
     * entity references in the body undefined.
     *
     * So the attack was refused, by accident, with the message "Entity 'e5'
     * not defined" shown to somebody holding a file their bank gave them.
     * That is the wrong kind of safe: it depends on a regular expression
     * written for a different purpose, nothing says so, and the next person to
     * improve the transform removes the protection without knowing it existed.
     *
     * Calling this first makes the refusal deliberate and gives it the real
     * reason.
     *
     * @throws MalformedXml
     */
    public static function assertTheBytesAreAcceptable(string $xml): void
    {
        self::assertWithinTheSizeCap($xml);
        self::assertThePrologDeclaresNoDoctype($xml);
    }

    /**
     * The configured limit, with a floor under it.
     *
     * A limit read as `0` because the configuration file was replaced or a
     * cache went stale would disable the check rather than tighten it, and a
     * disabled limit is the failure this class exists to prevent. So a
     * non-positive or absent value falls back to the compiled default rather
     * than to no limit at all.
     */
    private static function limit(string $key, int $fallback): int
    {
        $configured = \function_exists('config') ? config('exchange.xml.'.$key) : null;

        if (! \is_int($configured) && ! (\is_string($configured) && ctype_digit($configured))) {
            return $fallback;
        }

        $value = (int) $configured;

        return $value > 0 ? $value : $fallback;
    }

    /** @throws MalformedXml */
    private static function assertWithinTheSizeCap(string $xml): void
    {
        $cap = self::limit('max_bytes', 16 * 1024 * 1024);
        $bytes = \strlen($xml);

        if ($bytes > $cap) {
            throw MalformedXml::tooLarge($bytes, $cap);
        }
    }

    /**
     * Refuse a DOCTYPE on the bytes, before libxml is handed them.
     *
     * Only four things may appear before a root element: a byte-order mark,
     * whitespace, the XML declaration or another processing instruction, and
     * comments. So walking that grammar is exact rather than a search for a
     * string - and a search would have been wrong, because `<!DOCTYPE` inside
     * a comment is not a declaration and `<!DOCTYPE` inside a CDATA section
     * cannot occur before the root at all.
     *
     * Anything the walk does not recognise is left to libxml. A document
     * which is not XML gets the parser's own message with a line and a
     * column in it, which is what somebody holding the file needs; a scanner
     * that tried to diagnose malformed input would be a second, worse XML
     * parser.
     *
     * @throws MalformedXml
     */
    private static function assertThePrologDeclaresNoDoctype(string $xml): void
    {
        $length = \strlen($xml);
        $offset = str_starts_with($xml, "\xEF\xBB\xBF") ? 3 : 0;

        while ($offset < $length) {
            $offset += strspn($xml, " \t\r\n", $offset);

            if ($offset >= $length || $xml[$offset] !== '<') {
                return;
            }

            /*
             | `<?` is the XML declaration or a processing instruction, and
             | `<!--` a comment. Both may legally precede the DOCTYPE, so
             | stopping at the first one would miss
             | `<?xml ...?><!DOCTYPE ...>` - which is how every real payload
             | is written.
             */
            if (str_starts_with(substr($xml, $offset, 2), '<?')) {
                $end = strpos($xml, '?>', $offset + 2);

                if ($end === false) {
                    return;
                }

                $offset = $end + 2;

                continue;
            }

            if (str_starts_with(substr($xml, $offset, 4), '<!--')) {
                $end = strpos($xml, '-->', $offset + 4);

                if ($end === false) {
                    return;
                }

                $offset = $end + 3;

                continue;
            }

            /*
             | Compared without regard to case on purpose.
             |
             | `<!doctype` is not a well-formed declaration and libxml
             | refuses it, so matching only the canonical spelling would be
             | correct today. It would also mean this layer's behaviour
             | depended on libxml continuing to be strict about it, and the
             | cost of being stricter here is nothing: a document whose
             | prolog holds `<!doctype` was never going to parse.
             */
            if (strcasecmp(substr($xml, $offset, 9), '<!DOCTYPE') === 0) {
                throw MalformedXml::prologDeclaresADoctype();
            }

            return;
        }
    }

    /**
     * How deep the tree nests, measured iteratively.
     *
     * Iteratively, with an explicit stack, because a recursive walk over a
     * document whose depth is the thing under suspicion runs out of PHP
     * stack before it can report the problem - and a segmentation fault is
     * not a refusal.
     *
     * Only elements count. Attribute and text nodes cannot nest, and
     * counting them would make the limit mean something different from what
     * the configuration says.
     *
     * @throws MalformedXml
     */
    private static function assertNotTooDeep(\DOMDocument $document): void
    {
        $limit = self::limit('max_depth', 100);
        $root = $document->documentElement;

        if ($root === null) {
            return;
        }

        $stack = [[$root, 1]];

        while ($stack !== []) {
            /** @var array{0: \DOMElement, 1: int} $frame */
            $frame = array_pop($stack);
            [$node, $depth] = $frame;

            if ($depth > $limit) {
                throw MalformedXml::tooDeep($depth, $limit);
            }

            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $stack[] = [$child, $depth + 1];
                }
            }
        }
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
