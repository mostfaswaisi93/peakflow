<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Builders\SemanticInvoiceBuilder;
use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Serializers\Ubl\UblSequences;
use App\Exchange\Serializers\Ubl\UblSerializer;
use App\Support\Xml\Element as E;
use App\Support\Xml\XmlWriter;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Hashing\XmlCanonicalizer;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\Crediting;
use App\Tenant\Sales\Services\Issuer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * UBL 2.1, from one model - PF-112.
 *
 * ## What is actually being defended
 *
 * UBL is a sequence throughout, and a validator's message about an element
 * in the wrong place names the element it did not expect rather than the one
 * that is misplaced. So the order is asserted here against the declared
 * sequence, element by element, on a document produced from a real issued
 * invoice - not on a fixture written to pass.
 *
 * And the credit note is checked as a **different document**, because it is
 * one: different root, different line element, different quantity element,
 * and `cbc:TaxPointDate` ahead of the type code rather than after it.
 */
final class UblSerializerRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the UBL serializer (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'an invoice becomes a well-formed UBL Invoice' => fn (): array => $this->invoice(),
                'a credit note becomes a CreditNote, not an Invoice' => fn (): array => $this->creditNote(),
                'every element is in its schema position' => fn (): array => $this->order(),
                'the credit note puts the tax point before its type code' => fn (): array => $this->creditNoteOrder(),
                'codes carry their list and identifiers their scheme' => fn (): array => $this->codeLists(),
                'no internal identifier reaches the document' => fn (): array => $this->noInternalIdentifiers(),
                'every amount carries currencyID and every quantity a unitCode' => fn (): array => $this->attributes(),
                'one TaxSubtotal per rate group, summing to the total' => fn (): array => $this->taxSubtotals(),
                'the totals in the XML are the document\'s own' => fn (): array => $this->totalsAreTheDocuments(),
                'the same document serializes byte-identically' => fn (): array => $this->deterministic(),
                'an ampersand in a name does not break the document' => fn (): array => $this->escaping(),
                'children built out of order come out in order' => fn (): array => $this->theWriterSorts(),
                'an element with no declared position is refused' => fn (): array => $this->undeclaredIsRefused(),
                'a parent with no declared sequence is refused' => fn (): array => $this->noSequenceIsRefused(),
                'and the ledger still proves its own invariants' => fn (): array => [],
            ]), $this->entityId);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function invoice(): array
    {
        $violations = [];
        $xml = $this->serialize($this->issue());
        $document = $this->parse($xml, $violations);

        if ($document === null) {
            return $violations;
        }

        if ($document->documentElement->localName !== 'Invoice') {
            $violations[] = sprintf('the root element is <%s>', $document->documentElement->localName);
        }

        $expected = [
            'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        ];

        foreach ($expected as $namespace) {
            if (! str_contains($xml, $namespace)) {
                $violations[] = sprintf('the namespace %s is not declared', $namespace);
            }
        }

        /*
         | BT-24 first, because it is what a recipient reads to choose a
         | rule set - a Peppol document carrying the bare EN 16931
         | identifier is validated against the wrong rules and rejected for
         | rules it was never built to satisfy.
         */
        $customization = $this->one($document, '//*[local-name()="CustomizationID"]');

        if ($customization === null) {
            $violations[] = 'cbc:CustomizationID is absent, so the recipient cannot tell which rule set applies';
        } elseif (! str_contains($customization, 'urn:cen.eu:en16931:2017')) {
            $violations[] = sprintf('cbc:CustomizationID is "%s"', $customization);
        }

        foreach (['ID', 'IssueDate', 'InvoiceTypeCode', 'DocumentCurrencyCode'] as $element) {
            if ($this->one($document, sprintf('/*/*[local-name()="%s"]', $element)) === null) {
                $violations[] = sprintf('cbc:%s is absent and EN 16931 makes it mandatory', $element);
            }
        }

        if (\count($this->nodes($document, '//*[local-name()="InvoiceLine"]')) === 0) {
            $violations[] = 'the document has no cac:InvoiceLine';
        }

        return $violations;
    }

    /** @return list<string> */
    private function creditNote(): array
    {
        $violations = [];
        $credit = $this->credit();
        $xml = $this->serialize($credit);
        $document = $this->parse($xml, $violations);

        if ($document === null) {
            return $violations;
        }

        if ($document->documentElement->localName !== 'CreditNote') {
            $violations[] = sprintf(
                'a credit note serialized as <%s>. It is a different UBL document type, not an invoice '.
                'with a different type code',
                $document->documentElement->localName,
            );
        }

        if (! str_contains($xml, 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2')) {
            $violations[] = 'the CreditNote namespace is not declared';
        }

        if ($this->nodes($document, '//*[local-name()="CreditNoteLine"]') === []) {
            $violations[] = 'the lines are not cac:CreditNoteLine';
        }

        if ($this->nodes($document, '//*[local-name()="CreditedQuantity"]') === []) {
            $violations[] = 'the quantity is not cbc:CreditedQuantity, which is the element a credit note has';
        }

        if ($this->nodes($document, '//*[local-name()="InvoicedQuantity"]') !== []) {
            $violations[] = 'a credit note emitted cbc:InvoicedQuantity';
        }

        if ($this->one($document, '//*[local-name()="CreditNoteTypeCode"]') !== '381') {
            $violations[] = 'the credit note type code is not 381';
        }

        /*
         | And it says what it credits - BT-25.
         |
         | Through cac:BillingReference, which is where the recipient's
         | system looks to apply it against the right invoice.
         */
        if ($this->one($credit === null ? null : $document, '//*[local-name()="BillingReference"]/*/*[local-name()="ID"]') === null) {
            $violations[] = 'the credit note carries no cac:BillingReference, so a recipient cannot tell '.
                'which document it reverses';
        }

        return $violations;
    }

    /**
     * Every element sits where its schema sequence puts it.
     *
     * @return list<string>
     */
    private function order(): array
    {
        $violations = [];
        $document = $this->parse($this->serialize($this->issue()), $violations);

        return $document === null
            ? $violations
            : [...$violations, ...$this->assertOrder($document->documentElement, UblSequences::all())];
    }

    /** @return list<string> */
    private function assertOrder(\DOMElement $node, array $sequences): array
    {
        $violations = [];
        $name = $this->qualified($node);
        $sequence = $sequences[$name] ?? null;

        if ($sequence === null) {
            return [sprintf('<%s> has no declared sequence, so nothing constrains its children', $name)];
        }

        $positions = array_flip($sequence);
        $last = -1;
        $lastName = '';

        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $childName = $this->qualified($child);

            if (! isset($positions[$childName])) {
                $violations[] = sprintf('<%s> is not a declared child of <%s>', $childName, $name);

                continue;
            }

            $position = $positions[$childName];

            if ($position < $last) {
                $violations[] = sprintf(
                    '<%s> follows <%s> inside <%s>, and the schema puts it before. A validator would '.
                    'report the element it did not expect rather than the one out of place',
                    $childName,
                    $lastName,
                    $name,
                );
            }

            $last = max($last, $position);
            $lastName = $childName;

            if ($child->childNodes->length > 0 && $this->hasElementChildren($child)) {
                $violations = [...$violations, ...$this->assertOrder($child, $sequences)];
            }
        }

        return $violations;
    }

    private function hasElementChildren(\DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function creditNoteOrder(): array
    {
        $sequences = UblSequences::all();
        $violations = [];

        $credit = array_flip($sequences['CreditNote']);
        $invoice = array_flip($sequences['Invoice']);

        if ($credit['cbc:TaxPointDate'] > $credit['cbc:CreditNoteTypeCode']) {
            $violations[] = 'the declared CreditNote sequence puts cbc:TaxPointDate after the type code, '.
                'and UBL puts it before';
        }

        if ($invoice['cbc:TaxPointDate'] < $invoice['cbc:InvoiceTypeCode']) {
            $violations[] = 'the declared Invoice sequence puts cbc:TaxPointDate before the type code, '.
                'and UBL puts it after';
        }

        /*
         | And the difference is exercised, not only declared.
         |
         | A credit note with a tax point is the document that fails if the
         | two sequences are shared, so one is built and its order checked.
         */
        $model = app(SemanticInvoiceBuilder::class)->build($this->credit());
        $model->root->set(BT::TaxPointDate, '2026-05-20');

        $document = $this->parse(app(UblSerializer::class)->serialize($model), $violations);

        if ($document === null) {
            return $violations;
        }

        $order = [];

        foreach ($document->documentElement->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $order[] = $child->localName;
            }
        }

        $taxPoint = array_search('TaxPointDate', $order, true);
        $typeCode = array_search('CreditNoteTypeCode', $order, true);

        if ($taxPoint === false || $typeCode === false) {
            $violations[] = 'the credit note did not emit both a tax point and a type code';
        } elseif ($taxPoint > $typeCode) {
            $violations[] = 'the serialized credit note has cbc:TaxPointDate after cbc:CreditNoteTypeCode';
        }

        return $violations;
    }

    /** @return list<string> */
    private function codeLists(): array
    {
        $violations = [];
        $document = $this->parse($this->serialize($this->issue()), $violations);

        if ($document === null) {
            return $violations;
        }

        foreach ($this->nodes($document, '//*[local-name()="TaxCategory"]/*[local-name()="ID"]') as $node) {
            if ($node->getAttribute('schemeID') !== 'UNCL5305') {
                $violations[] = sprintf(
                    'a tax category code "%s" carries schemeID "%s". `S` is a tax category in UNCL 5305 '.
                    'and something else in other lists, and a code without its list is a string the '.
                    'recipient has to guess',
                    $node->textContent,
                    $node->getAttribute('schemeID'),
                );
            }
        }

        foreach ($this->nodes($document, '//*[local-name()="ClassifiedTaxCategory"]/*[local-name()="ID"]') as $node) {
            if ($node->getAttribute('schemeID') !== 'UNCL5305') {
                $violations[] = 'a line tax category code carries no UNCL5305 schemeID';
            }
        }

        foreach ($this->nodes($document, '//*[local-name()="PaymentMeansCode"]') as $node) {
            if ($node->getAttribute('listID') !== 'UNCL4461') {
                $violations[] = 'the payment means code carries no UNCL4461 listID';
            }
        }

        foreach ($this->nodes($document, '//*[local-name()="TaxScheme"]/*[local-name()="ID"]') as $node) {
            if (! \in_array($node->textContent, ['VAT', '!VAT'], true)) {
                $violations[] = sprintf('a tax scheme is "%s" and EN 16931 uses VAT or !VAT', $node->textContent);
            }
        }

        return $violations;
    }

    /**
     * An internal reference never leaves the building.
     *
     * ## Measured, by reading the output
     *
     * The first document this serializer produced carried
     * `<cbc:CompanyID>SA:VAT:310000000000003</cbc:CompanyID>`. That is the
     * snapshot's internal qualified form - jurisdiction, scheme, number -
     * and it appears on no document anywhere. Every check passed, including
     * `BR-CO-9`, because the string does begin with `SA`.
     *
     * A recipient would have rejected it. So the shape is asserted, not only
     * the rule: an identifier a human would recognise has no colons in it.
     *
     * @return list<string>
     */
    private function noInternalIdentifiers(): array
    {
        $violations = [];
        $document = $this->parse($this->serialize($this->issue()), $violations);

        if ($document === null) {
            return $violations;
        }

        foreach ($this->nodes($document, '//*[local-name()="PartyTaxScheme"]/*[local-name()="CompanyID"]') as $node) {
            $value = trim($node->textContent);

            if (str_contains($value, ':')) {
                $violations[] = sprintf(
                    'a VAT identifier is "%s". That is the internal qualified reference, which appears '.
                    'on no document and which a recipient rejects',
                    $value,
                );
            }

            if (! preg_match('/^[A-Z]{2}/', $value)) {
                $violations[] = sprintf(
                    'the VAT identifier "%s" does not begin with its ISO country code - BR-CO-9',
                    $value,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function attributes(): array
    {
        $violations = [];
        $document = $this->parse($this->serialize($this->issue()), $violations);

        if ($document === null) {
            return $violations;
        }

        $amountElements = [
            'TaxAmount', 'TaxableAmount', 'LineExtensionAmount', 'TaxExclusiveAmount',
            'TaxInclusiveAmount', 'PayableAmount', 'PriceAmount', 'AllowanceTotalAmount',
            'ChargeTotalAmount', 'PrepaidAmount',
        ];

        foreach ($amountElements as $element) {
            foreach ($this->nodes($document, sprintf('//*[local-name()="%s"]', $element)) as $node) {
                if ($node->getAttribute('currencyID') === '') {
                    $violations[] = sprintf(
                        'cbc:%s has no currencyID. An amount with no currency in a document that '.
                        'states one is a number two systems will read differently',
                        $element,
                    );
                }
            }
        }

        foreach ($this->nodes($document, '//*[local-name()="InvoicedQuantity"]') as $node) {
            if ($node->getAttribute('unitCode') === '') {
                $violations[] = 'cbc:InvoicedQuantity has no unitCode, so "3" could be hours or pallets';
            }
        }

        return $violations;
    }

    /**
     * One `cac:TaxSubtotal` per rate group, on a document with two of them.
     *
     * ## Measured: with one group this check proved nothing
     *
     * A bite that collapsed the serializer to a single subtotal left every
     * check green, because the fixture's document had exactly one rate
     * group and one is what it emitted. "One per group" is the reason tax is
     * grouped at all - per-line tax and per-group tax differ by cents, and
     * `BR-CO-14` rejects the first shape - so the document here carries a
     * standard-rated line and a zero-rated one.
     *
     * @return list<string>
     */
    private function taxSubtotals(): array
    {
        $violations = [];
        $invoice = app(Issuer::class)->issue(
            $this->draftWithTwoRateGroups(),
            $this->entity(),
            $this->actorId(),
            $this->decision,
            $this->terms,
        );
        $document = $this->parse($this->serialize($invoice), $violations);

        if ($document === null) {
            return $violations;
        }

        $stored = DB::connection('tenant')->table('invoice_tax_breakdown')
            ->where('invoice_id', (string) $invoice->getKey())
            ->count();

        $subtotals = $this->nodes($document, '//*[local-name()="TaxSubtotal"]');

        if (\count($subtotals) !== $stored) {
            $violations[] = sprintf(
                'the document stores %d rate groups and the XML has %d cac:TaxSubtotal. One per group '.
                'is why the tax is grouped at all: per-line tax and per-group tax differ by cents on '.
                'any document with a few lines, and BR-CO-14 rejects the first shape',
                $stored,
                \count($subtotals),
            );
        }

        $sum = BigDecimal::zero();

        foreach ($subtotals as $subtotal) {
            foreach ($subtotal->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->localName === 'TaxAmount') {
                    $sum = $sum->plus(BigDecimal::of($child->textContent));
                }
            }
        }

        $total = $this->one($document, '/*/*[local-name()="TaxTotal"]/*[local-name()="TaxAmount"]');

        if ($total === null) {
            return [...$violations, 'the document has no cac:TaxTotal/cbc:TaxAmount'];
        }

        if (! BigDecimal::of($total)->isEqualTo($sum)) {
            $violations[] = sprintf(
                'cbc:TaxAmount is %s and the subtotals sum to %s - BR-CO-14',
                $total,
                (string) $sum,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function totalsAreTheDocuments(): array
    {
        $violations = [];
        $invoice = $this->issue();
        $document = $this->parse($this->serialize($invoice), $violations);

        if ($document === null) {
            return $violations;
        }

        $pairs = [
            ['TaxExclusiveAmount', (string) $invoice->net_amount],
            ['TaxInclusiveAmount', (string) $invoice->gross_amount],
            ['PayableAmount', (string) $invoice->payable_amount],
        ];

        foreach ($pairs as [$element, $stored]) {
            $emitted = $this->one($document, sprintf('//*[local-name()="%s"]', $element));

            if ($emitted === null) {
                $violations[] = sprintf('cbc:%s is absent', $element);

                continue;
            }

            if (! BigDecimal::of($emitted)->isEqualTo(BigDecimal::of($stored))) {
                $violations[] = sprintf(
                    'cbc:%s is %s and the stored document says %s. The XML is a serialization of the '.
                    'document, not a recomputation of it',
                    $element,
                    $emitted,
                    $stored,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function deterministic(): array
    {
        $invoice = $this->issue();
        $violations = [];

        $first = $this->serialize($invoice);
        $second = $this->serialize($invoice->refresh());

        if ($first !== $second) {
            $violations[] = 'the same document serialized twice gave different bytes, so the archive '.
                'hash moves on its own and a stored hash proves nothing';
        }

        $hashes = [
            XmlCanonicalizer::hash($first),
            XmlCanonicalizer::hash($second),
        ];

        if ($hashes[0] !== $hashes[1]) {
            $violations[] = 'the canonical hash of one document is not stable';
        }

        /*
         | And a third serialization after a round trip through a parser.
         |
         | Canonicalization exists because every processor reserializes, and
         | the hash has to survive that: a document that only hashes the same
         | when nothing touches it is a document whose signature breaks at
         | the first access point.
         */
        $reparsed = new \DOMDocument();
        $reparsed->loadXML($first, \LIBXML_NONET);
        $roundTripped = (string) $reparsed->saveXML();

        if (XmlCanonicalizer::hash($roundTripped) !== $hashes[0]) {
            $violations[] = 'the canonical hash changed after a parse and reserialize, which is what '.
                'every access point does to a document in transit';
        }

        return $violations;
    }

    /** @return list<string> */
    private function escaping(): array
    {
        $violations = [];
        $invoice = $this->issue();

        $model = app(SemanticInvoiceBuilder::class)->build($invoice);
        $model->group(BG::Buyer)?->set(BT::BuyerName, 'Smith & Sons <Holdings> Ltd');

        $xml = app(UblSerializer::class)->serialize($model);
        $document = $this->parse($xml, $violations);

        if ($document === null) {
            return [...$violations, 'a name with an ampersand produced a document that will not parse'];
        }

        $name = $this->one($document, '//*[local-name()="AccountingCustomerParty"]//*[local-name()="RegistrationName"]');

        if ($name !== 'Smith & Sons <Holdings> Ltd') {
            $violations[] = sprintf('the name came back as "%s"', (string) $name);
        }

        if (! str_contains($xml, 'Smith &amp; Sons &lt;Holdings&gt; Ltd')) {
            $violations[] = 'the name is not escaped in the bytes, so the document is not well-formed';
        }

        return $violations;
    }

    /**
     * The writer sorts; it does not rely on the caller.
     *
     * ## Measured: removing the sort changed nothing
     *
     * Every check still passed, because both serializers happen to build
     * their children in schema order. That makes the sort defence in depth
     * against code written later - and defence nothing exercises is
     * defence nobody notices the loss of. So this builds a document with
     * its children deliberately reversed and requires the output to be in
     * the schema's order.
     *
     * @return list<string>
     */
    private function theWriterSorts(): array
    {
        $writer = new XmlWriter([
            'Invoice' => ['cbc:ID', 'cbc:IssueDate', 'cbc:InvoiceTypeCode', 'cbc:DocumentCurrencyCode'],
        ]);

        $xml = $writer->render(
            E::of('Invoice')->with(
                E::text('cbc:DocumentCurrencyCode', 'SAR'),
                E::text('cbc:InvoiceTypeCode', '380'),
                E::text('cbc:IssueDate', '2026-05-18'),
                E::text('cbc:ID', 'INV-1'),
            ),
            ['' => 'urn:test'],
        );

        $violations = [];
        $document = $this->parse($xml, $violations);

        if ($document === null) {
            return $violations;
        }

        $order = [];

        foreach ($document->documentElement->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $order[] = $child->localName;
            }
        }

        $expected = ['cbc:ID', 'cbc:IssueDate', 'cbc:InvoiceTypeCode', 'cbc:DocumentCurrencyCode'];

        return $order === $expected
            ? []
            : [sprintf(
                'the writer emitted %s and the schema sequence is %s. A validator would report the '.
                'element it did not expect rather than the one out of place',
                implode(', ', $order),
                implode(', ', $expected),
            )];
    }

    /** @return list<string> */
    private function undeclaredIsRefused(): array
    {
        $writer = new XmlWriter(['Invoice' => ['cbc:ID']]);

        try {
            $writer->render(
                E::of('Invoice')->with(E::text('cbc:Invented', 'x')),
                ['' => 'urn:test'],
            );
        } catch (\LogicException $e) {
            return str_contains($e->getMessage(), 'cbc:Invented')
                ? []
                : ['the refusal does not name the element: '.$e->getMessage()];
        }

        return ['an undeclared element was emitted. A name the sequence does not know is either a typo '.
            'or a term somebody added without deciding where it goes, and both fail at the recipient'];
    }

    /** @return list<string> */
    private function noSequenceIsRefused(): array
    {
        $writer = new XmlWriter([]);

        try {
            $writer->render(E::of('Invoice')->with(E::text('cbc:ID', 'x')), ['' => 'urn:test']);
        } catch (\LogicException $e) {
            return str_contains($e->getMessage(), 'Invoice')
                ? []
                : ['the refusal does not name the parent: '.$e->getMessage()];
        }

        return ['children were emitted under a parent with no declared sequence, so their order is '.
            'whatever the building code happened to do'];
    }

    private function credit(): Invoice
    {
        $invoice = $this->issue();

        return app(Issuer::class)->issue(
            app(Crediting::class)->full($invoice, $this->date),
            $this->entity(),
            $this->actorId(),
            $this->decision,
        );
    }

    private function serialize(Invoice $invoice): string
    {
        return app(UblSerializer::class)->serialize(
            app(SemanticInvoiceBuilder::class)->build($invoice),
        );
    }

    private function parse(string $xml, array &$violations): ?\DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, \LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $violations[] = 'the document is not well-formed: '.implode(
                '; ',
                array_map(static fn (\LibXMLError $e): string => trim($e->message), $errors),
            );

            return null;
        }

        return $document;
    }

    private function one(?\DOMDocument $document, string $xpath): ?string
    {
        if ($document === null) {
            return null;
        }

        $nodes = $this->nodes($document, $xpath);

        return $nodes === [] ? null : trim($nodes[0]->textContent);
    }

    /** @return list<\DOMElement> */
    private function nodes(\DOMDocument $document, string $xpath): array
    {
        $found = (new \DOMXPath($document))->query($xpath);
        $nodes = [];

        if ($found === false) {
            return [];
        }

        foreach ($found as $node) {
            if ($node instanceof \DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function qualified(\DOMElement $node): string
    {
        return $node->prefix === '' ? $node->localName : $node->prefix.':'.$node->localName;
    }
}
