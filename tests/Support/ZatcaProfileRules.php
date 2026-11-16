<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Builders\SemanticInvoiceBuilder;
use App\Exchange\Pipeline\ValidationPipeline;
use App\Exchange\Profiles\ProfileRegistry;
use App\Exchange\Profiles\Zatca\TlvQrCode;
use App\Exchange\Profiles\Zatca\ZatcaProfile;
use App\Exchange\Profiles\Zatca\ZatcaSerializer;
use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Semantics\SemanticNode;
use App\Exchange\Validation\SemanticViolation;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Models\Invoice;
use App\Tenant\Sales\Services\Issuer;
use Illuminate\Support\Facades\DB;

/**
 * The Saudi regime - PF-123.
 *
 * ## The two things worth the most attention
 *
 * **Clearance is not reporting.** A standard invoice is submitted before it
 * is shared and the authority's stamped copy is the legal one; a simplified
 * invoice is handed over at the till and reported within twenty-four hours.
 * A system that treats them alike either makes a checkout queue depend on a
 * government API or delivers a B2B invoice the authority has never seen.
 *
 * **The QR is read with no network.** An inspector scans the code on a
 * printed receipt and sees the seller, the registration, the time, the total
 * and the tax. So the tags are checked by number and the payload is decoded
 * back - a reader written to the specification looks for tag 4 and expects
 * the gross total, and a producer who reordered them has produced a code
 * that reads as something else.
 */
final class ZatcaProfileRules
{
    use \Tests\Support\Concerns\IssuesInvoices;
    use \Tests\Support\Concerns\ProvesTheLedger;

    private const ARABIC_SELLER = 'شركة بيك فلو التجارية';

    private const ARABIC_ITEM = 'خدمات استشارية لشهر مايو ٢٠٢٦';

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $static = [
            'the TLV encoder is the format the specification describes' => $this->tlv(),
            'a value too long for its length byte is refused' => $this->tlvTooLong(),
            'a truncated payload is refused rather than half-read' => $this->tlvTruncated(),
            'the genesis hash is the one the specification names' => $this->genesis(),
            'the profile is built and deliberately not selectable' => $this->notYetSelectable(),
            'clearance and reporting are different flows' => $this->twoFlows(),
        ];

        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return [...$static, 'ZATCA (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheSellingEntity();

            return [
                ...$static,
                ...$this->withTheLedgerProof($this->runEachInASavepoint([
                    'a ZATCA-ready standard invoice passes stage 4' => fn (): array => $this->passes(),
                    'the document is a 388, not a 380' => fn (): array => $this->typeCode(),
                    'the chain is in the document as ICV and PIH' => fn (): array => $this->chain(),
                    'the first document carries the specified genesis hash' => fn (): array => $this->firstPih(),
                    'the QR payload is in the document and decodes' => fn (): array => $this->qrInDocument(),
                    'the QR states the registration without its country prefix' => fn (): array => $this->qrVatNumber(),
                    'a seller name with no Arabic is refused' => fn (): array => $this->arabicSeller(),
                    'an item described only in English is refused' => fn (): array => $this->arabicItem(),
                    'a standard invoice without the buyer\'s VAT number is refused' => fn (): array => $this->buyerVat(),
                    'a simplified invoice needs no buyer VAT number' => fn (): array => $this->simplifiedBuyer(),
                    'a simplified invoice is a 02 transaction, a standard one 01' => fn (): array => $this->transactionType(),
                    'a document in another currency states its tax in riyals' => fn (): array => $this->currency(),
                    'the serializer adds to the UBL tree and changes nothing else' => fn (): array => $this->addsOnly(),
                    'the same document serializes byte-identically' => fn (): array => $this->deterministic(),
                    'and the ledger still proves its own invariants' => fn (): array => [],
                ]), $this->entityId),
            ];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function tlv(): array
    {
        $violations = [];

        $payload = TlvQrCode::encode([
            TlvQrCode::TAG_SELLER_NAME => 'Acme',
            TlvQrCode::TAG_VAT_NUMBER => '310000000000003',
        ]);

        /*
         | Tag, length, value - byte for byte.
         |
         | `\x01\x04Acme` then `\x02\x0F310000000000003`. Asserted on the
         | bytes rather than through the decoder, because a decoder that
         | shares a bug with the encoder agrees with it.
         */
        $expected = "\x01\x04Acme\x02\x0F310000000000003";

        if ($payload !== $expected) {
            $violations[] = sprintf(
                'the payload is %s and TLV gives %s',
                bin2hex($payload),
                bin2hex($expected),
            );
        }

        /*
         | The round trip, with its own failure reported as a violation.
         |
         | Measured: a bite that swapped the tag and the length bytes made
         | the decoder throw, and the whole suite reported "could not be
         | built" - which found the defect and said nothing about it. A
         | check that explodes tells the reader less than one that explains.
         */
        try {
            $decoded = TlvQrCode::decodeBase64(base64_encode($payload));

            if (($decoded[TlvQrCode::TAG_SELLER_NAME] ?? null) !== 'Acme') {
                $violations[] = sprintf(
                    'tag 1 came back as "%s" and the seller name is "Acme"',
                    (string) ($decoded[TlvQrCode::TAG_SELLER_NAME] ?? ''),
                );
            }
        } catch (\Throwable $e) {
            $violations[] = 'the payload this encoder produced cannot be decoded: '.$e->getMessage();
        }

        // Tags come out in tag order whatever order they went in.
        $reordered = TlvQrCode::encode([
            TlvQrCode::TAG_VAT_NUMBER => '310000000000003',
            TlvQrCode::TAG_SELLER_NAME => 'Acme',
        ]);

        if ($reordered !== $expected) {
            $violations[] = 'the tags are emitted in the order they were given, not in tag order. A '.
                'reader written to the specification looks for tag 4 and expects the gross total';
        }

        // An absent value is absent, not an empty tag.
        $withEmpty = TlvQrCode::encode([
            TlvQrCode::TAG_SELLER_NAME => 'Acme',
            TlvQrCode::TAG_SIGNATURE => null,
            TlvQrCode::TAG_PUBLIC_KEY => '',
        ]);

        if ($withEmpty !== "\x01\x04Acme") {
            $violations[] = 'an empty tag was emitted. Tag 7 with no bytes tells a reader the signature '.
                'is present and is nothing, which is a worse answer than "not sealed"';
        }

        return $violations;
    }

    /** @return list<string> */
    private function tlvTooLong(): array
    {
        try {
            TlvQrCode::encode([TlvQrCode::TAG_SELLER_NAME => str_repeat('م', 200)]);
        } catch (\LengthException $e) {
            return str_contains($e->getMessage(), '255')
                ? []
                : ['the refusal does not say what the limit is: '.$e->getMessage()];
        }

        return ['a 400-byte value was encoded into a one-byte length field. An Arabic name is two or '.
            'three bytes per character, and a QR code carrying half a seller\'s name is worse than one '.
            'that failed to build'];
    }

    /** @return list<string> */
    private function tlvTruncated(): array
    {
        $violations = [];

        // Tag 1 declares four bytes and two follow.
        try {
            TlvQrCode::decode("\x01\x04Ac");
            $violations[] = 'a truncated payload was read as a short one, so a corrupted code looks '.
                'like a valid one';
        } catch (\UnexpectedValueException) {
            // As intended.
        }

        try {
            TlvQrCode::decode("\x01");
            $violations[] = 'a tag with no length byte was accepted';
        } catch (\UnexpectedValueException) {
            // As intended.
        }

        try {
            TlvQrCode::decodeBase64('not base64 at all!!');
            $violations[] = 'a payload that is not base64 was accepted';
        } catch (\UnexpectedValueException) {
            // As intended.
        }

        return $violations;
    }

    /** @return list<string> */
    private function genesis(): array
    {
        /*
         | The specification names the first PIH: the base64 of sha256("0")
         | written as hex. Asserted against the literal, so a change to how
         | it is derived cannot quietly change the value.
         */
        $expected = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

        return ZatcaSerializer::genesisHash() === $expected
            ? []
            : [sprintf(
                'the genesis hash is %s and the specification names %s. A first document with the wrong '.
                'genesis is rejected for a hash that is correctly computed over the wrong convention',
                ZatcaSerializer::genesisHash(),
                $expected,
            )];
    }

    /** @return list<string> */
    private function notYetSelectable(): array
    {
        $registry = app(ProfileRegistry::class);
        $violations = [];

        if (! $registry->has('zatca')) {
            return ['the ZATCA profile is not registered'];
        }

        if ($registry->isEnabled('zatca')) {
            $violations[] = 'ZATCA is enabled while the cryptographic stamp and the clearance API are '.
                'not built, so a tenant could issue documents that validate and cannot be cleared';
        }

        $profile = $registry->get('zatca');

        if ($profile->qrPolicy() !== 'tlv_base64') {
            $violations[] = 'the profile does not declare the TLV QR policy';
        }

        if ($profile->sealing() === 'none') {
            $violations[] = 'the profile declares no sealing, and a Saudi document carries a '.
                'cryptographic stamp';
        }

        if ($profile->retentionYears() !== 6) {
            $violations[] = sprintf(
                'the retention period is %d years and the Saudi VAT Implementing Regulations say six',
                $profile->retentionYears(),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function twoFlows(): array
    {
        $profile = app(ZatcaProfile::class);
        $violations = [];

        $standard = SemanticInvoice::build(SemanticNode::root(), 'zatca', '388');
        $simplified = SemanticInvoice::build(SemanticNode::root(), 'zatca', '388')
            ->withJurisdictionData('document.simplified', '1');

        if ($profile->submissionFor($standard) !== 'clearance') {
            $violations[] = 'a standard invoice is not cleared';
        }

        if ($profile->submissionFor($simplified) !== 'reporting') {
            $violations[] = 'a simplified invoice is cleared rather than reported, which would make a '.
                'checkout queue depend on a government API being up';
        }

        if ($profile->mayDeliverBeforeSubmission($standard)) {
            $violations[] = 'a standard invoice may be delivered before clearance. The copy the buyer '.
                'receives has to be the authority\'s stamped one, not the one that was submitted';
        }

        if (! $profile->mayDeliverBeforeSubmission($simplified)) {
            $violations[] = 'a simplified invoice may not be handed to the customer until it is '.
                'reported, which is not what the regime asks and not how a till works';
        }

        if (ZatcaProfile::REPORTING_WINDOW_HOURS !== 24) {
            $violations[] = 'the reporting window is not twenty-four hours';
        }

        return $violations;
    }

    /** @return list<string> */
    private function passes(): array
    {
        $report = app(ValidationPipeline::class)->run($this->zatcaReady(), app(ZatcaProfile::class));

        return $report->passed() ? [] : array_map(
            static fn (SemanticViolation $v): string => sprintf('%s: %s', $v->rule, (string) $v->detail),
            $report->all(),
        );
    }

    /** @return list<string> */
    private function typeCode(): array
    {
        $violations = [];
        $model = $this->zatcaReady();

        if ($model->get(BT::InvoiceTypeCode) !== '388') {
            $violations[] = sprintf(
                'the document is a %s. The regime\'s tax invoice is 388 and 380 is a commercial '.
                'invoice, so the rejection would name a code list rather than the problem',
                (string) $model->get(BT::InvoiceTypeCode),
            );
        }

        // And the same stored invoice is still a 380 to everyone else.
        $generic = app(SemanticInvoiceBuilder::class)->build($this->issue());

        if ($generic->get(BT::InvoiceTypeCode) !== '380') {
            $violations[] = 'the generic profile now emits the Saudi code, so one regime\'s code list '.
                'has leaked into every document';
        }

        $xml = $this->serialize($model);

        if (! str_contains($xml, '<cbc:InvoiceTypeCode name="0100000">388</cbc:InvoiceTypeCode>')) {
            $violations[] = 'the type code does not carry the standard-invoice transaction type';
        }

        return $violations;
    }

    /** @return list<string> */
    private function chain(): array
    {
        $violations = [];
        $invoice = $this->issueZatca();
        $model = $this->zatcaReady($invoice);
        $xml = $this->serialize($model);

        $document = $this->parse($xml, $violations);

        if ($document === null) {
            return $violations;
        }

        $icv = $this->one($document, '//*[local-name()="AdditionalDocumentReference"][*[local-name()="ID"]="ICV"]/*[local-name()="UUID"]');

        if ($icv !== (string) $invoice->chain_index) {
            $violations[] = sprintf(
                'the invoice counter in the document is "%s" and the chain says %s. A counter that '.
                'jumps is how the authority detects a document that was issued and not reported',
                (string) $icv,
                (string) $invoice->chain_index,
            );
        }

        $pih = $this->one($document, '//*[local-name()="AdditionalDocumentReference"][*[local-name()="ID"]="PIH"]//*[local-name()="EmbeddedDocumentBinaryObject"]');

        if ($pih === null) {
            return [...$violations, 'the document carries no previous invoice hash'];
        }

        $expected = $invoice->previous_hash === str_repeat('0', 64)
            ? ZatcaSerializer::genesisHash()
            : base64_encode((string) $invoice->previous_hash);

        if ($pih !== $expected) {
            $violations[] = sprintf('the PIH is "%s" and the chain gives "%s"', $pih, $expected);
        }

        if ($invoice->previous_hash !== str_repeat('0', 64)
            && base64_decode($pih, true) !== $invoice->previous_hash) {
            $violations[] = 'the PIH does not decode to the hash PeakFlow\'s own chain holds, so the '.
                'document and the chain disagree';
        }

        return $violations;
    }

    /** @return list<string> */
    private function firstPih(): array
    {
        $violations = [];
        $invoice = $this->issueZatca();

        if ($invoice->previous_hash !== str_repeat('0', 64)) {
            // Not the first document in this tenant's chain, so the genesis
            // path is not what this check can exercise - it is covered by
            // the static check on the constant.
            return [];
        }

        $xml = $this->serialize($this->zatcaReady($invoice));

        if (! str_contains($xml, ZatcaSerializer::genesisHash())) {
            $violations[] = 'the first document does not carry the specified genesis hash. PeakFlow\'s '.
                'chain starts at sixty-four zeros and the regime names a different first value, so the '.
                'profile has to translate';
        }

        if (str_contains($xml, base64_encode(str_repeat('0', 64)))) {
            $violations[] = 'the document carries PeakFlow\'s own genesis, which the authority rejects '.
                'for a hash that is correctly computed over the wrong convention';
        }

        return $violations;
    }

    /** @return list<string> */
    private function qrInDocument(): array
    {
        $violations = [];
        $model = $this->zatcaReady();
        $xml = $this->serialize($model);
        $document = $this->parse($xml, $violations);

        if ($document === null) {
            return $violations;
        }

        $encoded = $this->one($document, '//*[local-name()="AdditionalDocumentReference"][*[local-name()="ID"]="QR"]//*[local-name()="EmbeddedDocumentBinaryObject"]');

        if ($encoded === null) {
            return [...$violations, 'the document carries no QR payload'];
        }

        $values = TlvQrCode::decodeBase64($encoded);

        $expected = [
            TlvQrCode::TAG_SELLER_NAME => self::ARABIC_SELLER,
            TlvQrCode::TAG_TOTAL_WITH_VAT => (string) $model->totals()?->get(BT::TotalWithVat),
            TlvQrCode::TAG_VAT_TOTAL => (string) $model->totals()?->get(BT::TotalVatAmount),
        ];

        foreach ($expected as $tag => $value) {
            if (($values[$tag] ?? null) !== $value) {
                $violations[] = sprintf(
                    'QR tag %d is "%s" and the document says "%s". An inspector with no network reads '.
                    'the code and sees whatever is in it',
                    $tag,
                    (string) ($values[$tag] ?? ''),
                    $value,
                );
            }
        }

        if (($values[TlvQrCode::TAG_TIMESTAMP] ?? null) === null) {
            $violations[] = 'the QR carries no timestamp. BT-2 is a date by design - a tax point is a '.
                'day - and the code needs the moment';
        }

        /*
         | Tags 6 to 9 are absent, not empty.
         |
         | The stamp arrives with PF-124, and a tag with no bytes would tell
         | a reader the signature is there and is nothing.
         */
        foreach ([6, 7, 8, 9] as $tag) {
            if (\array_key_exists($tag, $values)) {
                $violations[] = sprintf('QR tag %d is present and the document is not sealed', $tag);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function qrVatNumber(): array
    {
        $model = $this->zatcaReady();
        $values = app(ZatcaSerializer::class)->qrValues($model);
        $number = (string) ($values[TlvQrCode::TAG_VAT_NUMBER] ?? '');

        if ($number === '310000000000003') {
            return [];
        }

        return [sprintf(
            'the QR states the registration as "%s". BT-31 carries the ISO prefix because BR-CO-9 '.
            'requires it, and the authority\'s own fifteen-digit number is what an inspector\'s reader '.
            'checks against a register that has no prefix in it',
            $number,
        )];
    }

    /** @return list<string> */
    private function arabicSeller(): array
    {
        return $this->expect('ZATCA-ARABIC-SELLER-NAME', function (SemanticNode $root): void {
            $root->first(BG::Seller)?->set(BT::SellerName, 'Peakflow Trading Co.');
        });
    }

    /** @return list<string> */
    private function arabicItem(): array
    {
        return $this->expect('ZATCA-ARABIC-ITEM-NAME', function (SemanticNode $root): void {
            $root->children(BG::InvoiceLine)[0]
                ->first(BG::ItemInformation)
                ?->set(BT::ItemName, 'Advisory services, May 2026');
        });
    }

    /** @return list<string> */
    private function buyerVat(): array
    {
        return $this->expect('ZATCA-STANDARD-BUYER-VAT', function (SemanticNode $root): void {
            $root->first(BG::Buyer)?->set(BT::BuyerVatIdentifier, null);
        }, alsoAllow: ['BR-CO-26']);
    }

    /** @return list<string> */
    private function simplifiedBuyer(): array
    {
        $model = $this->zatcaReady()->withJurisdictionData('document.simplified', '1');
        $model->group(BG::Buyer)?->set(BT::BuyerVatIdentifier, null);

        $rules = array_map(
            static fn (SemanticViolation $v): string => $v->rule,
            app(ValidationPipeline::class)->run($model, app(ZatcaProfile::class))->all(),
        );

        return \in_array('ZATCA-STANDARD-BUYER-VAT', $rules, true)
            ? ['a simplified invoice was told to state the buyer\'s VAT registration. It is handed to a '.
                'consumer at a till, and requiring one would mean no receipt could be issued to a '.
                'member of the public']
            : [];
    }

    /** @return list<string> */
    private function transactionType(): array
    {
        $violations = [];

        $standard = $this->serialize($this->zatcaReady());
        $simplified = $this->serialize($this->zatcaReady()->withJurisdictionData('document.simplified', '1'));

        if (! str_contains($standard, 'name="0100000"')) {
            $violations[] = 'a standard invoice does not carry the 01 subtype';
        }

        if (! str_contains($simplified, 'name="0200000"')) {
            $violations[] = 'a simplified invoice does not carry the 02 subtype, so the authority reads '.
                'it as a standard one and expects it to have been cleared';
        }

        return $violations;
    }

    /** @return list<string> */
    private function currency(): array
    {
        return $this->expect('ZATCA-SAR-TAX-AMOUNT', function (SemanticNode $root): void {
            $root->set(BT::InvoiceCurrencyCode, 'USD');
        });
    }

    /**
     * The ZATCA serializer adds, and changes nothing else.
     *
     * @return list<string>
     */
    private function addsOnly(): array
    {
        $violations = [];
        $model = $this->zatcaReady();

        $ubl = app(\App\Exchange\Serializers\Ubl\UblSerializer::class)->serialize($model);
        $zatca = $this->serialize($model);

        $violations = [];
        $ublDocument = $this->parse($ubl, $violations);
        $zatcaDocument = $this->parse($zatca, $violations);

        if ($ublDocument === null || $zatcaDocument === null) {
            return $violations;
        }

        /*
         | Every element the plain UBL document has, the ZATCA one has - with
         | the same value.
         |
         | An extension that quietly changed a total would be the worst
         | possible defect in this phase: two documents for one invoice,
         | both well-formed, disagreeing about what is owed.
         */
        foreach (['TaxExclusiveAmount', 'TaxInclusiveAmount', 'PayableAmount', 'ID', 'IssueDate'] as $element) {
            $from = $this->one($ublDocument, sprintf('//*[local-name()="%s"]', $element));
            $to = $this->one($zatcaDocument, sprintf('//*[local-name()="%s"]', $element));

            if ($from !== $to) {
                $violations[] = sprintf(
                    'cbc:%s is "%s" in UBL and "%s" under ZATCA. An extension adds; it does not change',
                    $element,
                    (string) $from,
                    (string) $to,
                );
            }
        }

        $added = \count($this->nodes($zatcaDocument, '//*[local-name()="AdditionalDocumentReference"]'))
            - \count($this->nodes($ublDocument, '//*[local-name()="AdditionalDocumentReference"]'));

        if ($added !== 3) {
            $violations[] = sprintf(
                'the extension added %d document references and ICV, PIH and QR are three',
                $added,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function deterministic(): array
    {
        $model = $this->zatcaReady();
        $first = $this->serialize($model);
        $second = $this->serialize($model);

        return $first === $second
            ? []
            : ['the same model serialized twice gave different bytes, so the archive hash moves on its own'];
    }

    /**
     * @param  list<string>  $alsoAllow
     * @return list<string>
     */
    private function expect(string $rule, callable $break, array $alsoAllow = []): array
    {
        $model = $this->zatcaReady();
        $profile = app(ZatcaProfile::class);
        $pipeline = app(ValidationPipeline::class);

        if (! $pipeline->run($model, $profile)->passed()) {
            return ['the untampered document does not pass, so this check proves nothing'];
        }

        $break($model->root);

        $rules = array_values(array_unique(array_map(
            static fn (SemanticViolation $v): string => $v->rule,
            $pipeline->run($model, $profile)->all(),
        )));

        if (! \in_array($rule, $rules, true)) {
            return [sprintf(
                '%s did not fire. Reported instead: %s',
                $rule,
                $rules === [] ? 'nothing at all' : implode(', ', $rules),
            )];
        }

        $unexpected = array_diff($rules, [$rule], $alsoAllow);

        return $unexpected === [] ? [] : [sprintf('%s fired, and so did %s', $rule, implode(', ', $unexpected))];
    }

    /**
     * A document that satisfies the Saudi requirements.
     *
     * Built from a real issued invoice, then given the Arabic the regime
     * requires. The fixture's entity is named in English because every
     * other check reads it that way; what matters here is that the rule is
     * exercised in both directions.
     */
    private function zatcaReady(?Invoice $invoice = null): SemanticInvoice
    {
        $model = app(SemanticInvoiceBuilder::class)->build($invoice ?? $this->issueZatca());

        /*
         | BT-3 is NOT set here.
         |
         | Measured: it used to be, and a bite that removed the 380 to 388
         | mapping from the profile left every check green - because the
         | fixture was asserting a value the fixture itself had written. The
         | document is a 388 because the invoice's frozen profile is `zatca`
         | and the builder asks the profile what the code is called.
         */
        $model->root->first(BG::Seller)?->set(BT::SellerName, self::ARABIC_SELLER);

        foreach ($model->lines() as $line) {
            $line->first(BG::ItemInformation)?->set(BT::ItemName, self::ARABIC_ITEM);
        }

        $model->root->first(BG::ProcessControl)
            ?->set(BT::SpecificationIdentifier, app(ZatcaProfile::class)->specificationIdentifier());

        return $model;
    }

    /** An invoice issued under the ZATCA profile, so its frozen profile is right. */
    private function issueZatca(): Invoice
    {
        $draft = $this->draft();
        $draft->update(['profile' => 'zatca']);

        return app(Issuer::class)->issue(
            $draft->refresh(),
            $this->entity(),
            $this->actorId(),
            $this->decision,
            $this->terms,
        );
    }

    private function serialize(SemanticInvoice $model): string
    {
        return app(ZatcaSerializer::class)->serialize($model);
    }

    private function parse(string $xml, array &$violations): ?\DOMDocument
    {
        try {
            return \App\Support\Xml\SafeXmlReader::parse($xml);
        } catch (\App\Support\Xml\MalformedXml $e) {
            $violations[] = $e->getMessage();

            return null;
        }
    }

    private function one(\DOMDocument $document, string $xpath): ?string
    {
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
}
