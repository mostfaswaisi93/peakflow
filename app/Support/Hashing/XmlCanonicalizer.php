<?php

declare(strict_types=1);

namespace App\Support\Hashing;

use App\Support\Hashing\Exceptions\NotCanonicalizable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Canonical XML, for the syntaxes that are signed rather than hashed - PF-009.
 *
 * ## Why canonicalization is not formatting
 *
 * Two XML documents can be byte-different and information-identical: attribute
 * order is not significant, `<c/>` and `<c></c>` are the same element, an
 * unused namespace declaration changes nothing. A signature over the raw bytes
 * would therefore break when a document passes through any processor that
 * reserializes it - which every one of them does.
 *
 * Canonical XML defines one byte sequence per information set, so the signature
 * survives the trip and a tampered document still fails.
 *
 * ## C14N 1.1 is not reachable from PHP, and this says so
 *
 * PF-009 names C14N 1.1. PHP's `DOMNode::C14N()` takes a boolean `exclusive`
 * flag and nothing else: libxml 2.9.14 implements Canonical XML 1.0 and
 * Exclusive C14N 1.0, and the 1.1 mode is not exposed through the PHP DOM API
 * at all.
 *
 * What 1.1 changed is narrow. Its normative differences from 1.0 are `xml:base`
 * fixup and the treatment of inherited `xml:id`, `xml:lang` and `xml:space`
 * when canonicalizing a **document subset** whose ancestors are excluded from
 * the node set. PeakFlow canonicalizes whole documents, where no ancestor is
 * excluded and no fixup arises - so for the inputs this class accepts, 1.0 and
 * 1.1 coincide.
 *
 * That statement is derived from the two specifications' difference list, not
 * verified against a 1.1 implementation, because none is available here. So
 * rather than rely on it, a document carrying `xml:base` or `xml:id` is
 * REFUSED: those are exactly the attributes the two algorithms disagree about,
 * and producing 1.0 output while a signature claims 1.1 is a signature that
 * verifies nowhere else.
 *
 * ## The DOCTYPE is refused, and this is the part that matters
 *
 * Measured, three ways:
 *
 * - With `LIBXML_NOENT` - which reads as "no entities" and in libxml means
 *   "substitute entities" - a document declaring
 *   `<!ENTITY x SYSTEM "file:///etc/passwd">` canonicalized to the contents of
 *   `/etc/passwd`. The flag whose name suggests the defence is the one that
 *   removes it.
 * - Without it, the entity reference is not substituted and canonicalizes to
 *   NOTHING - so a document whose content sits in an entity hashes as though
 *   the content were absent, while a conformant processor would include it.
 *   A silently different hash is the failure this whole class exists to avoid.
 * - A nested-entity expansion (`&c;` -> `&b;` x10 -> `&a;` x100) is the same
 *   mechanism pointed at memory instead of at a file.
 *
 * Both behaviours are wrong, so neither is chosen: a DOCTYPE is refused. No
 * e-invoicing syntax needs one - UBL 2.1 and UN/CEFACT CII are schema-defined,
 * and neither declares entities.
 *
 * The external entity loader is also replaced with one that returns nothing for
 * the duration of the parse. The DOCTYPE check happens after parsing, so the
 * loader covers the window in between; it is restored in a `finally` so a
 * refusal cannot leave the process unable to load a legitimate resource later.
 */
final class XmlCanonicalizer
{
    /** The algorithm identifier an XMLDSig `<CanonicalizationMethod>` carries. */
    public const INCLUSIVE_1_0 = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    public const EXCLUSIVE_1_0 = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    /**
     * The attributes whose handling differs between C14N 1.0 and 1.1.
     *
     * @var list<string>
     */
    private const DISPUTED_ATTRIBUTES = ['xml:base', 'xml:id'];

    /**
     * Canonicalize a document.
     *
     * `$exclusive` selects Exclusive C14N, which omits namespace declarations
     * the node set does not use. That is what a signature over an embedded
     * fragment needs - an enveloped invoice signature, in Phase 06 - and it is
     * the wrong default for a whole document, where dropping an unused
     * declaration loses information the recipient's schema validator wants.
     *
     * @throws NotCanonicalizable
     */
    public static function canonicalize(string $xml, bool $exclusive = false): string
    {
        $document = self::parse($xml);

        $canonical = $document->C14N($exclusive, false);

        if (! \is_string($canonical) || $canonical === '') {
            throw NotCanonicalizable::xmlProducedNothing();
        }

        return $canonical;
    }

    /** @throws NotCanonicalizable */
    public static function hash(string $xml, bool $exclusive = false): string
    {
        return hash('sha256', self::canonicalize($xml, $exclusive));
    }

    /**
     * Canonicalize one element, by XPath, with its ancestors' namespaces.
     *
     * The case a signature actually needs: `<Invoice>` signed while the
     * `<Signature>` element it carries is excluded. Exclusive C14N is the
     * default here because that is what the signature profiles specify for a
     * fragment.
     *
     * @param  array<string, string>  $namespaces  prefix => URI, for the XPath
     *
     * @throws NotCanonicalizable
     */
    public static function canonicalizeFragment(
        string $xml,
        string $xpath,
        array $namespaces = [],
        bool $exclusive = true,
    ): string {
        $document = self::parse($xml);

        $selector = new DOMXPath($document);

        foreach ($namespaces as $prefix => $uri) {
            $selector->registerNamespace($prefix, $uri);
        }

        $nodes = $selector->query($xpath);

        if ($nodes === false || $nodes->length === 0) {
            throw NotCanonicalizable::xmlFragmentNotFound($xpath);
        }

        if ($nodes->length > 1) {
            throw NotCanonicalizable::xmlFragmentAmbiguous($xpath, $nodes->length);
        }

        $node = $nodes->item(0);

        if (! $node instanceof DOMElement) {
            throw NotCanonicalizable::xmlFragmentNotAnElement($xpath, get_debug_type($node));
        }

        $canonical = $node->C14N($exclusive, false);

        if (! \is_string($canonical) || $canonical === '') {
            throw NotCanonicalizable::xmlProducedNothing();
        }

        return $canonical;
    }

    /**
     * Parse, with every door that leads outside closed.
     *
     * @throws NotCanonicalizable
     */
    private static function parse(string $xml): DOMDocument
    {
        if (trim($xml) === '') {
            throw NotCanonicalizable::xmlEmpty();
        }

        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;

        /*
         | `LIBXML_NOENT` is deliberately NOT passed.
         |
         | Its name reads as "no entities" and in libxml it means "substitute
         | entities" - XML_PARSE_NOENT - so passing it is what turns an entity
         | declaration into a file read. Measured: with it, a document
         | declaring file:///etc/passwd canonicalized to the password file.
         |
         | LIBXML_DTDLOAD and LIBXML_DTDVALID are likewise absent, so no
         | external DTD is fetched, and LIBXML_NONET refuses the network even
         | if something else asked for one.
         */
        /*
         | The return value is not reliably the previous loader.
         |
         | PHP 8.4 made `libxml_set_external_entity_loader()` return the
         | callable it replaced; 8.3 and earlier return `true`. Restoring that
         | `true` is a TypeError - measured, as a fatal error on the first call
         | - so what comes back is only restored when it is actually a
         | callable, and otherwise the loader is reset to libxml's default.
         */
        $previousLoader = libxml_set_external_entity_loader(static fn (): null => null);
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
            $errors = libxml_get_errors();
        } catch (Throwable $e) {
            throw NotCanonicalizable::xmlUnparseable($e->getMessage());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            libxml_set_external_entity_loader(\is_callable($previousLoader) ? $previousLoader : null);
        }

        if ($loaded === false) {
            throw NotCanonicalizable::xmlUnparseable(
                $errors === [] ? 'the parser gave no reason' : trim($errors[0]->message),
            );
        }

        if ($document->doctype !== null) {
            throw NotCanonicalizable::xmlHasDoctype($document->doctype->name);
        }

        self::assertNoDisputedAttributes($document);

        return $document;
    }

    /** @throws NotCanonicalizable */
    private static function assertNoDisputedAttributes(DOMDocument $document): void
    {
        $selector = new DOMXPath($document);

        foreach (self::DISPUTED_ATTRIBUTES as $attribute) {
            $found = $selector->query('//@'.$attribute);

            if ($found !== false && $found->length > 0) {
                throw NotCanonicalizable::xmlUsesDisputedAttribute($attribute);
            }
        }
    }
}
