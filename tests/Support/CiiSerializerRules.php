<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Builders\SemanticInvoiceBuilder;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Serializers\Cii\CiiSequences;
use App\Exchange\Serializers\Cii\CiiSerializer;
use App\Exchange\Serializers\Ubl\UblSerializer;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Hashing\XmlCanonicalizer;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\Crediting;
use App\Tenant\Sales\Services\Issuer;
use Illuminate\Support\Facades\DB;
use Tests\Support\Exchange\TermPaths;

/**
 * CII D16B, and the proof that the model is syntax-independent - PF-113.
 *
 * ## The check that matters
 *
 * `the two syntaxes carry the same document`. One invoice, serialized to UBL
 * and to CII, read back through two independently written path tables, has
 * to yield the same business terms with the same values. That is the claim
 * the whole phase rests on: two serializers over one model, rather than two
 * invoice systems.
 *
 * It is also the check that would catch the cheap version of this work - a
 * CII serializer written by renaming the UBL one - because such a thing
 * would put the tax point at document level, write ISO dates and emit a
 * separate credit note document, and all three would fail.
 */
final class CiiSerializerRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['CII (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                'an invoice becomes a well-formed CrossIndustryInvoice' => fn (): array => $this->invoice(),
                'a credit note is the same document with type 381' => fn (): array => $this->creditNote(),
                'every element is in its schema position' => fn (): array => $this->order(),
                'dates are written in CII\'s own format, not ISO' => fn (): array => $this->dates(),
                'the tax point sits inside each trade tax' => fn (): array => $this->taxPoint(),
                'the same document serializes byte-identically' => fn (): array => $this->deterministic(),
                'the two syntaxes carry the same document' => fn (): array => $this->roundTrip(),
                'the round trip covers every term the model populates' => fn (): array => $this->coverage(),
                'the two serializers share nothing but the model' => fn (): array => $this->independent(),
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
        $xml = $this->cii($this->issue());
        $document = $this->parse($xml, $violations);

        if ($document === null) {
            return $violations;
        }

        if ($document->documentElement->localName !== 'CrossIndustryInvoice') {
            $violations[] = sprintf('the root element is <%s>', $document->documentElement->localName);
        }

        foreach ([
            'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
            'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
            'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        ] as $namespace) {
            if (! str_contains($xml, $namespace)) {
                $violations[] = sprintf('the namespace %s is not declared', $namespace);
            }
        }

        $guideline = $this->one($document, TermPaths::cii()['BT-24']);

        if ($guideline === null || ! str_contains($guideline, 'urn:cen.eu:en16931:2017')) {
            $violations[] = 'the guideline context parameter does not carry BT-24';
        }

        foreach (['ExchangedDocumentContext', 'ExchangedDocument', 'SupplyChainTradeTransaction'] as $section) {
            if ($this->nodes($document, sprintf('/*/*[local-name()="%s"]', $section)) === []) {
                $violations[] = sprintf('rsm:%s is absent', $section);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function creditNote(): array
    {
        $violations = [];
        $document = $this->parse($this->cii($this->credit()), $violations);

        if ($document === null) {
            return $violations;
        }

        /*
         | CII has ONE document.
         |
         | UBL has a separate root, a separate line element and a different
         | element order for a credit note. CII says 381 in the type code
         | and changes nothing else, and a serializer written by renaming
         | the UBL one would have invented a root nobody parses.
         */
        if ($document->documentElement->localName !== 'CrossIndustryInvoice') {
            $violations[] = sprintf(
                'a credit note serialized as <%s>. CII has no separate credit note document',
                $document->documentElement->localName,
            );
        }

        if ($this->one($document, TermPaths::cii()['BT-3']) !== '381') {
            $violations[] = 'the type code is not 381';
        }

        if ($this->one($document, TermPaths::cii()['BT-25']) === null) {
            $violations[] = 'the credit note does not reference the document it credits (BT-25)';
        }

        return $violations;
    }

    /** @return list<string> */
    private function order(): array
    {
        $violations = [];
        $document = $this->parse($this->cii($this->issue()), $violations);

        return $document === null
            ? $violations
            : [...$violations, ...$this->assertOrder($document->documentElement, CiiSequences::all())];
    }

    /** @return list<string> */
    private function assertOrder(\DOMElement $node, array $sequences): array
    {
        $violations = [];
        $name = $this->qualified($node);
        $sequence = $sequences[$name] ?? null;

        if ($sequence === null) {
            return [sprintf('<%s> has no declared sequence', $name)];
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

            if ($positions[$childName] < $last) {
                $violations[] = sprintf(
                    '<%s> follows <%s> inside <%s>, and the schema puts it before',
                    $childName,
                    $lastName,
                    $name,
                );
            }

            $last = max($last, $positions[$childName]);
            $lastName = $childName;

            if ($this->hasElementChildren($child)) {
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
    private function dates(): array
    {
        $violations = [];
        $document = $this->parse($this->cii($this->issue()), $violations);

        if ($document === null) {
            return $violations;
        }

        $dates = $this->nodes(
            $document,
            '//*[local-name()="DateTimeString" or local-name()="DateString"]',
        );

        if ($dates === []) {
            return [...$violations, 'the document carries no date at all'];
        }

        foreach ($dates as $date) {
            if ($date->getAttribute('format') !== '102') {
                $violations[] = sprintf(
                    'a date carries format "%s". CII dates are qualified by their format code and 102 '.
                    'is CCYYMMDD; a reader that assumes one is a reader that misreads the others',
                    $date->getAttribute('format'),
                );
            }

            if (! preg_match('/^\d{8}$/', trim($date->textContent))) {
                $violations[] = sprintf(
                    'a date is "%s" and format 102 is eight digits. ISO dates here are the signature '.
                    'of a CII serializer written by renaming a UBL one',
                    trim($date->textContent),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function taxPoint(): array
    {
        $violations = [];
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $model->root->set(\App\Exchange\Semantics\BusinessTerm::TaxPointDate, '2026-05-20');

        $document = $this->parse(app(CiiSerializer::class)->serialize($model), $violations);

        if ($document === null) {
            return $violations;
        }

        /*
         | Inside the trade tax, not at document level.
         |
         | CII has no document-level tax point element, which is one of the
         | structural differences between the two syntaxes rather than a
         | preference.
         */
        $inTax = $this->nodes($document, TermPaths::cii()['BT-7']);

        if ($inTax === []) {
            $violations[] = 'the tax point is not inside ram:ApplicableTradeTax, which is the only place '.
                'CII has for it';
        }

        $atDocumentLevel = $this->nodes(
            $document,
            '/*/*[local-name()="ExchangedDocument"]/*[local-name()="TaxPointDate"]',
        );

        if ($atDocumentLevel !== []) {
            $violations[] = 'the tax point was emitted at document level, where CII has no such element';
        }

        foreach ($inTax as $node) {
            if (trim($node->textContent) !== '20260520') {
                $violations[] = sprintf('the tax point is "%s" and format 102 is 20260520', trim($node->textContent));
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function deterministic(): array
    {
        $invoice = $this->issue();
        $first = $this->cii($invoice);
        $second = $this->cii($invoice->refresh());
        $violations = [];

        if ($first !== $second) {
            $violations[] = 'the same document serialized twice gave different bytes';
        }

        if (XmlCanonicalizer::hash($first) !== XmlCanonicalizer::hash($second)) {
            $violations[] = 'the canonical hash is not stable';
        }

        return $violations;
    }

    /**
     * One document, two syntaxes, the same business terms.
     *
     * @return list<string>
     */
    private function roundTrip(): array
    {
        $violations = [];

        foreach (['invoice' => $this->issue(), 'credit note' => $this->credit()] as $label => $invoice) {
            $model = app(SemanticInvoiceBuilder::class)->build($invoice);

            $ubl = $this->parse(app(UblSerializer::class)->serialize($model), $violations);
            $cii = $this->parse(app(CiiSerializer::class)->serialize($model), $violations);

            if ($ubl === null || $cii === null) {
                continue;
            }

            foreach ($model->populatedTerms() as $term) {
                $fromUbl = $this->values($ubl, TermPaths::ubl()[$term] ?? null);
                $fromCii = $this->values($cii, TermPaths::cii()[$term] ?? null);

                if (\in_array($term, TermPaths::datesInCompactForm(), true)) {
                    $fromUbl = array_map(static fn (string $v): string => str_replace('-', '', $v), $fromUbl);
                }

                if ($fromUbl === [] && $fromCii === []) {
                    /*
                     | Neither syntax carries it, which is a coverage
                     | question rather than a disagreement - and the
                     | coverage check is the one that reports it.
                     */
                    continue;
                }

                if ($fromUbl !== $fromCii) {
                    $violations[] = sprintf(
                        'on the %s, %s is %s in UBL and %s in CII. One document cannot say two things, '.
                        'and whichever syntax the recipient reads is the one that is wrong',
                        $label,
                        $term,
                        $fromUbl === [] ? 'absent' : '"'.implode('", "', $fromUbl).'"',
                        $fromCii === [] ? 'absent' : '"'.implode('", "', $fromCii).'"',
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * The round trip cannot quietly shrink.
     *
     * @return list<string>
     */
    private function coverage(): array
    {
        $violations = [];
        $ubl = TermPaths::ubl();
        $cii = TermPaths::cii();

        foreach (['invoice' => $this->issue(), 'credit note' => $this->credit()] as $label => $invoice) {
            $model = app(SemanticInvoiceBuilder::class)->build($invoice);

            foreach ($model->populatedTerms() as $term) {
                if (! isset($ubl[$term])) {
                    $violations[] = sprintf(
                        '%s is populated on the %s and the UBL path table has no entry for it, so the '.
                        'round trip does not check it',
                        $term,
                        $label,
                    );
                }

                if (! isset($cii[$term])) {
                    $violations[] = sprintf(
                        '%s is populated on the %s and the CII path table has no entry for it',
                        $term,
                        $label,
                    );
                }
            }
        }

        /*
         | And each syntax actually produced something for each covered term.
         |
         | A path that matches nothing is a path that would make the round
         | trip pass by finding nothing on both sides.
         */
        $model = app(SemanticInvoiceBuilder::class)->build($this->issue());
        $ublDocument = $this->parse(app(UblSerializer::class)->serialize($model), $violations);
        $ciiDocument = $this->parse(app(CiiSerializer::class)->serialize($model), $violations);

        if ($ublDocument === null || $ciiDocument === null) {
            return $violations;
        }

        $checked = 0;

        foreach ($model->populatedTerms() as $term) {
            if ($this->values($ublDocument, $ubl[$term] ?? null) !== []) {
                $checked++;
            }
        }

        if ($checked < 15) {
            $violations[] = sprintf(
                'only %d terms were actually read back out of UBL, which is too few for the round trip '.
                'to be evidence of anything',
                $checked,
            );
        }

        return $violations;
    }

    /**
     * The two serializers share nothing but the model.
     *
     * @return list<string>
     */
    private function independent(): array
    {
        $violations = [];

        $ubl = (string) file_get_contents(app_path('Exchange/Serializers/Ubl/UblSerializer.php'));
        $cii = (string) file_get_contents(app_path('Exchange/Serializers/Cii/CiiSerializer.php'));

        if (str_contains($ubl, 'Serializers\Cii') || str_contains($ubl, 'CiiSerializer')) {
            $violations[] = 'the UBL serializer references the CII one';
        }

        if (str_contains($cii, 'Serializers\Ubl') || str_contains($cii, 'UblSerializer')) {
            $violations[] = 'the CII serializer references the UBL one. If one syntax can be produced '.
                'from the other, the model is not what makes them syntax-independent and this phase '.
                'proves nothing';
        }

        /*
         | Over the CODE, not the comments.
         |
         | Measured: the moment the UBL serializer's documentation explained
         | why `ZatcaSerializer extends UblSerializer` would be wrong, this
         | check failed - reading the explanation as the thing it forbids.
         | A grep over a PHP file finds prose, and prose about a rule is not
         | a breach of it.
         */
        foreach ([$ubl, $cii] as $source) {
            if (preg_match('/\bextends\s+[A-Za-z]/', $this->codeOnly($source)) === 1) {
                $violations[] = 'a serializer extends a class. A shared base class is the shape in which '.
                    'one syntax quietly becomes the other\'s dialect';
            }
        }

        return $violations;
    }

    /** A source file with its comments and docblocks removed. */
    private function codeOnly(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
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

    private function cii(Invoice $invoice): string
    {
        return app(CiiSerializer::class)->serialize(
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

    /** @return list<string> */
    private function values(\DOMDocument $document, ?string $xpath): array
    {
        if ($xpath === null) {
            return [];
        }

        $found = (new \DOMXPath($document))->query($xpath);

        if ($found === false) {
            return [];
        }

        $values = [];

        foreach ($found as $node) {
            $values[] = trim($node->nodeValue ?? '');
        }

        sort($values);

        return $values;
    }

    private function one(\DOMDocument $document, string $xpath): ?string
    {
        $values = $this->values($document, $xpath);

        return $values === [] ? null : $values[0];
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
