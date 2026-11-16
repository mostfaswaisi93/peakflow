<?php

declare(strict_types=1);

namespace App\Exchange\Xml;

/**
 * Render an element tree in the order its schema demands - PF-112.
 *
 * ## Order is not a style question in UBL
 *
 * Every UBL complex type is an `xsd:sequence`. An element in the wrong
 * position is not a warning: the document fails schema validation, and the
 * message a recipient's access point returns names the element it did not
 * expect rather than the one that is out of place - which is why
 * "cvc-complex-type.2.4.a: Invalid content was found starting with element
 * cbc:TaxPointDate" is a familiar and unhelpful sight.
 *
 * So ordering is not left to the control flow of whichever method emitted
 * the element. The serializer builds children in whatever order reads best,
 * and this writer sorts them into the declared sequence for their parent.
 * An element with no declared position is an **error**, not something to
 * append at the end: a name the sequence does not know is either a typo or a
 * term somebody added without checking where it goes, and both produce a
 * document that fails at the recipient.
 *
 * ## Determinism
 *
 * The same model renders byte-identically: no pretty-printing, no generated
 * ids, no timestamps, and repeated groups keep the order the model holds
 * them in. The archive hash has to be stable, and a formatter that indents
 * by nesting depth would change every byte the first time a line is added.
 */
final class XmlWriter
{
    /**
     * @param  array<string, list<string>>  $sequences  parent element name => the order of its children
     */
    public function __construct(private readonly array $sequences) {}

    /** @param array<string, string> $namespaces prefix (or '' for the default) => uri */
    public function render(Element $root, array $namespaces): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = false;

        $node = $this->node($document, $root);

        foreach ($namespaces as $prefix => $uri) {
            $node->setAttributeNS(
                'http://www.w3.org/2000/xmlns/',
                $prefix === '' ? 'xmlns' : 'xmlns:'.$prefix,
                $uri,
            );
        }

        $document->appendChild($node);

        $xml = $document->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('The document could not be rendered.');
        }

        return $xml;
    }

    private function node(\DOMDocument $document, Element $element): \DOMElement
    {
        $node = $element->text === null
            ? $document->createElement($element->name)
            : $document->createElement($element->name, '');

        if ($element->text !== null) {
            /*
             | Through a text node, not the constructor's second argument.
             |
             | `createElement($name, $value)` does not escape the value:
             | `&` and `<` in a customer's legal name produce a document that
             | is not well-formed, and the failure surfaces at the recipient
             | as a parse error on an invoice nobody can read.
             */
            $node->appendChild($document->createTextNode($element->text));
        }

        foreach ($element->attributes as $name => $value) {
            $node->setAttribute((string) $name, (string) $value);
        }

        foreach ($this->ordered($element) as $child) {
            $node->appendChild($this->node($document, $child));
        }

        return $node;
    }

    /** @return list<Element> */
    private function ordered(Element $element): array
    {
        $children = $element->children();

        if ($children === []) {
            return [];
        }

        $sequence = $this->sequences[$element->name] ?? null;

        if ($sequence === null) {
            throw new \LogicException(sprintf(
                'No child sequence is declared for <%s>. UBL and CII are both sequences throughout, '.
                'and emitting children in the order a method happened to build them produces a '.
                'document that fails schema validation at the recipient.',
                $element->name,
            ));
        }

        $positions = array_flip($sequence);
        $indexed = [];

        foreach ($children as $index => $child) {
            if (! isset($positions[$child->name])) {
                throw new \LogicException(sprintf(
                    '<%s> is not a declared child of <%s>. Either the name is wrong or a term was '.
                    'added without deciding where it goes, and both fail at the recipient.',
                    $child->name,
                    $element->name,
                ));
            }

            // The original index breaks ties, so repeated groups - three
            // invoice lines, two rate groups - keep the model's order and
            // the output stays byte-stable.
            $indexed[] = [$positions[$child->name], $index, $child];
        }

        usort($indexed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $row): Element => $row[2], $indexed);
    }
}
