<?php

declare(strict_types=1);

namespace App\Support\Hashing;

use App\Support\Hashing\Exceptions\NotCanonicalizable;
use App\Support\Xml\MalformedXml;
use App\Support\Xml\SafeXmlReader;
use DOMDocument;
use DOMElement;
use DOMXPath;

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
     * ## Why this now delegates to SafeXmlReader
     *
     * It used to hold its own copy of the hardening: the same nulled external
     * entity loader, the same absent `LIBXML_NOENT`, the same DOCTYPE refusal.
     * Two copies of a security property is one copy and one liability, and
     * which one it was became clear when the DOCTYPE refusal was found to run
     * AFTER the parse in both files - libxml expands an internal entity while
     * building the tree, so the check fired on memory already allocated. The
     * fix belonged in one place.
     *
     * {@see \App\Support\Xml\SafeXmlReader} refuses the DOCTYPE on the bytes,
     * caps the size before parsing and bounds the nesting depth, and this
     * class gains all three: a signature is verified over a document a
     * stranger sent, which is the same threat the reader was written for.
     *
     * The refusal is translated rather than propagated, because a caller here
     * is canonicalizing - `NotCanonicalizable` is the exception its callers
     * catch, and leaking a `MalformedXml` out of `hash()` would be a second
     * failure type nobody handles.
     *
     * @throws NotCanonicalizable
     */
    private static function parse(string $xml): DOMDocument
    {
        if (trim($xml) === '') {
            throw NotCanonicalizable::xmlEmpty();
        }

        try {
            $document = SafeXmlReader::parse($xml);
        } catch (MalformedXml $e) {
            throw NotCanonicalizable::xmlUnparseable($e->getMessage());
        }

        /*
         | Kept, and unreachable through SafeXmlReader, on purpose.
         |
         | The reader refuses a DOCTYPE twice over, so this branch cannot fire
         | today. It is the assertion that the delegation above still carries
         | the property this class depends on: should the reader ever be
         | rewritten into something that admits one, a signature produced here
         | would be a signature over `/etc/passwd`, and that must fail loudly
         | in the canonicalizer's own vocabulary rather than silently succeed.
         */
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
