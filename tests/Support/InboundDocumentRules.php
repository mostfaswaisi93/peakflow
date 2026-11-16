<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Builders\SemanticInvoiceBuilder;
use App\Exchange\Inbound\BillFromDocument;
use App\Exchange\Inbound\InboundDocument;
use App\Exchange\Inbound\InboundDocuments;
use App\Exchange\Inbound\InboundRefused;
use App\Exchange\Readers\UblReader;
use App\Exchange\Serializers\Ubl\ApplicationResponse;
use App\Exchange\Serializers\Ubl\UblSerializer;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Parties\Models\Party;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Sales\Services\Issuer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Documents that arrive - PF-140, PF-141.
 *
 * ## The fixture is our own serializer
 *
 * Every document checked here is produced by {@see UblSerializer} from a real
 * issued invoice, which makes the round trip the test: a document we wrote,
 * read back by {@see UblReader}, has to come out carrying the same business
 * terms and no others. A hand-written XML fixture would check the reader
 * against whatever the fixture's author believed UBL looks like.
 *
 * It is not a substitute for the official corpus - PF-128 - and does not
 * claim to be. It is the strongest check available without one: a serializer
 * and a reader that disagree cannot both be right, and this finds the
 * disagreement.
 *
 * ## What the checks are about
 *
 * That nothing is half-imported, that nothing is guessed - the supplier least
 * of all - and that the original survives. Those are the three ways an
 * inbound path turns a stranger's file into a payment nobody authorised.
 */
final class InboundDocumentRules
{
    use \Tests\Support\Concerns\EntersBills;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['inbound documents (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();

            return $this->runEachInASavepoint([
                'a document we wrote reads back with the same terms' => fn (): array => $this->roundTrip(),
                'and with no violations it did not have' => fn (): array => $this->roundTripValidates(),
                'a credit note reads its own line and quantity elements' => fn (): array => $this->creditNote(),
                'a tax point that differs from the issue date survives' => fn (): array => $this->taxPoint(),
                'a sender\'s prefixes do not matter' => fn (): array => $this->prefixes(),
                'nothing is defaulted in for a missing element' => fn (): array => $this->nothingGuessed(),
                'an Order is refused as not a billing document' => fn (): array => $this->wrongDocument(),
                'bytes that are not XML are rejected with a report' => fn (): array => $this->notXml(),
                'a DOCTYPE is refused before anything is read' => fn (): array => $this->doctype(),
                'the original is archived as received, before parsing' => fn (): array => $this->archived(),
                'the same delivery twice is one row' => fn (): array => $this->idempotent(),
                'an unknown email sender is quarantined, not discarded' => fn (): array => $this->quarantine(),
                'a Peppol delivery needs no allowlist' => fn (): array => $this->peppolNeedsNoAllowlist(),
                'a failing document is rejected, never half-imported' => fn (): array => $this->neverHalfImported(),
                'the rejection carries the standard\'s own response' => fn (): array => $this->response(),
                'the supplier is matched by electronic address' => fn (): array => $this->matchByAddress(),
                'then by tax registration' => fn (): array => $this->matchByRegistration(),
                'and never by name' => fn (): array => $this->neverByName(),
                'an unmatched document waits for a person' => fn (): array => $this->awaitingSupplier(),
                'assigning a supplier by hand needs a reason' => fn (): array => $this->assignNeedsAReason(),
                'a validated document becomes a draft bill' => fn (): array => $this->becomesABill(),
                'the bill\'s tax ties to the document\'s own breakdown' => fn (): array => $this->taxTies(),
                'and ties where per-line rounding differs from the group\'s' => fn (): array => $this->taxRounding(),
                'the expense account comes from the supplier, not a guess' => fn (): array => $this->expenseAccount(),
                'one delivery becomes one bill' => fn (): array => $this->convertsOnce(),
                'an unvalidated document cannot become a bill' => fn (): array => $this->unvalidatedConversion(),

                // ---- Email intake - PF-140 -------------------------------
                'the intake address names the entity and nothing else does' => fn (): array => $this->intakeAddress(),
                'an email becomes one item per attachment and one for the body' => fn (): array => $this->emailBecomesItems(),
                'an attachment is sniffed and scanned before anything reads it' => fn (): array => $this->attachmentIsScanned(),
                'an empty message is refused rather than queued' => fn (): array => $this->emptyMessage(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function roundTrip(): array
    {
        [$semantic, $xml] = $this->document();

        $read = app(UblReader::class)->read($xml);

        $before = $semantic->populatedTerms();
        $after = $read->populatedTerms();
        sort($before);
        sort($after);

        $violations = [];
        $lost = array_values(array_diff($before, $after));
        $invented = array_values(array_diff($after, $before));

        if ($lost !== []) {
            $violations[] = sprintf(
                'the reader lost %s. A term the serializer emits and the reader does not read is a '.
                'field an inbound bill arrives without, and "nothing is guessed" then means "nothing '.
                'is there"',
                implode(', ', $lost),
            );
        }

        if ($invented !== []) {
            $violations[] = sprintf(
                'the reader produced %s, which the document does not carry. An invented term is a '.
                'guess wearing a business term\'s name',
                implode(', ', $invented),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function roundTripValidates(): array
    {
        [, $xml] = $this->document();

        $read = app(UblReader::class)->read($xml);
        $violations = $read->validate();

        if ($violations !== []) {
            return [sprintf(
                'a document our own serializer produced fails %d of the model\'s own rules after being '.
                'read back: %s. Either the serializer emits something the rules refuse or the reader '.
                'loses something they require, and both are defects in a round trip that has to be '.
                'lossless',
                \count($violations),
                implode(', ', array_map(
                    static fn ($violation): string => $violation->rule,
                    \array_slice($violations, 0, 4),
                )),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function creditNote(): array
    {
        [$semantic, $xml] = $this->document(\App\Tenant\Sales\Enums\DocumentType::CreditNote);

        if (! str_contains($xml, '<CreditNote')) {
            return ['the fixture did not produce a CreditNote document, so this check cannot run'];
        }

        $read = app(UblReader::class)->read($xml);
        $violations = [];

        if ($read->lines() === []) {
            $violations[] = 'the reader found no lines in a credit note. An Invoice carries '.
                '`cac:InvoiceLine` and a CreditNote carries `cac:CreditNoteLine`, and a reader that '.
                'knew only the first reads every credit note as having nothing on it';
        }

        if ($read->get(\App\Exchange\Semantics\BusinessTerm::InvoiceTypeCode) === null) {
            $violations[] = 'the reader found no BT-3 in a credit note, which carries '.
                '`cbc:CreditNoteTypeCode` rather than `cbc:InvoiceTypeCode` - and the cardinality '.
                'rules then report a missing type against a document that was correct';
        }

        $quantities = array_filter(array_map(
            static fn ($line): ?string => $line->get(\App\Exchange\Semantics\BusinessTerm::InvoicedQuantity),
            $read->lines(),
        ));

        if ($quantities === []) {
            $violations[] = 'no line carries a quantity: a credit note\'s is `cbc:CreditedQuantity`';
        }

        return $violations;
    }

    /**
     * BT-7 through the round trip.
     *
     * Its own check because the ordinary fixture does not carry one:
     * `SemanticInvoiceBuilder` emits BT-7 only where the tax point differs
     * from the issue date, which is right - a tax point equal to the issue
     * date is what BR-CO-3 lets a document leave out - and that means a
     * round trip over the ordinary document cannot tell whether the reader
     * reads it. Measured: deleting the reader's BT-7 line left the suite
     * green.
     *
     * @return list<string>
     */
    private function taxPoint(): array
    {
        [$semantic, ] = $this->document();

        /*
         | The same document with a tax point a week earlier.
         |
         | Built by adding the term to the model our builder produced, rather
         | than by writing XML by hand: the point is to check the reader
         | against the serializer, and a hand-written fixture checks it
         | against whatever its author believed UBL looks like.
         */
        $root = $semantic->root;
        $root->set(
            \App\Exchange\Semantics\BusinessTerm::TaxPointDate,
            $this->date->copy()->subDays(7)->toDateString(),
        );

        $adjusted = \App\Exchange\Semantics\SemanticInvoice::build(
            $root,
            $semantic->profile,
            $semantic->documentTypeCode,
        );

        $serializer = app(UblSerializer::class);
        $xml = $serializer->render($serializer->tree($adjusted), $adjusted);

        $read = app(UblReader::class)->read($xml);
        $taxPoint = $read->get(\App\Exchange\Semantics\BusinessTerm::TaxPointDate);

        if ($taxPoint !== $this->date->copy()->subDays(7)->toDateString()) {
            return [sprintf(
                'the tax point came back as %s. It decides which return the input tax falls in, so a '.
                'reader that drops it posts a March supply into April\'s return',
                $taxPoint ?? 'nothing',
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function prefixes(): array
    {
        [$semantic, $xml] = $this->document();

        /*
         | The same document, re-prefixed.
         |
         | `cac:` becomes `ns1:` and `cbc:` becomes `ns2:`, which is what
         | several large finance systems emit. A reader bound to the
         | prefixes we happen to write reads our own documents and nobody
         | else's.
         */
        $renamed = str_replace(
            ['xmlns:cac=', 'xmlns:cbc=', '<cac:', '</cac:', '<cbc:', '</cbc:'],
            ['xmlns:ns1=', 'xmlns:ns2=', '<ns1:', '</ns1:', '<ns2:', '</ns2:'],
            $xml,
        );

        $read = app(UblReader::class)->read($renamed);

        $before = $semantic->populatedTerms();
        $after = $read->populatedTerms();
        sort($before);
        sort($after);

        if ($before !== $after) {
            return [sprintf(
                'the same document with different prefixes reads back %d terms instead of %d. A '.
                'prefix is not a namespace: `ns2:Invoice` is as valid as `Invoice`',
                \count($after),
                \count($before),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function nothingGuessed(): array
    {
        [, $xml] = $this->document();

        // The currency element removed, as a supplier's system might omit it.
        $stripped = preg_replace('#<cbc:DocumentCurrencyCode>[^<]*</cbc:DocumentCurrencyCode>#', '', $xml);

        $read = app(UblReader::class)->read((string) $stripped);

        if ($read->get(\App\Exchange\Semantics\BusinessTerm::InvoiceCurrencyCode) !== null) {
            return [
                'a document with no `cbc:DocumentCurrencyCode` came back with a currency. A reader '.
                'that filled in "probably SAR" turns a supplier\'s mistake into our own, and the first '.
                'sign of it is a payment in the wrong currency',
            ];
        }

        $rules = array_map(static fn ($violation): string => $violation->rule, $read->validate());

        if ($rules === []) {
            return ['a document with no currency passed the model\'s own rules, so the absence is '.
                'invisible rather than reported'];
        }

        return [];
    }

    /** @return list<string> */
    private function wrongDocument(): array
    {
        $order = '<?xml version="1.0" encoding="UTF-8"?><Order xmlns="urn:oasis:names:specification:'
            .'ubl:schema:xsd:Order-2"><ID>PO-1</ID></Order>';

        try {
            app(UblReader::class)->read($order);

            return ['a UBL Order was read as a billing document. An Order, a DespatchAdvice and an '.
                'ApplicationResponse are all valid UBL and none of them is a document anybody is owed '.
                'money for'];
        } catch (\App\Exchange\Readers\NotABillingDocument $e) {
            if (! str_contains($e->getMessage(), 'Order')) {
                return ['the refusal does not say what the document actually was: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function notXml(): array
    {
        $document = app(InboundDocuments::class)->receive(
            entity: $this->entity(),
            bytes: 'This is a PDF, or an apology, or anything at all.',
            channel: 'peppol',
        );

        $examined = app(InboundDocuments::class)->examine($document, 'This is not XML.');
        $violations = [];

        if (! $examined->isRejected()) {
            $violations[] = sprintf('bytes that are not XML left the document %s', (string) $examined->status);
        }

        if ($examined->validation_report === null) {
            $violations[] = 'a rejection with no report is a verdict nobody can act on - and the '.
                'database refuses it, so this would have failed on the insert';
        }

        return $violations;
    }

    /** @return list<string> */
    private function doctype(): array
    {
        $bomb = '<?xml version="1.0"?><!DOCTYPE Invoice [<!ENTITY x "y">]><Invoice/>';

        $document = app(InboundDocuments::class)->receive(
            entity: $this->entity(),
            bytes: $bomb,
            channel: 'email',
            senderAddress: $this->allowlisted(),
        );

        $examined = app(InboundDocuments::class)->examine($document, $bomb);

        if (! $examined->isRejected()) {
            return [sprintf(
                'a document carrying a DOCTYPE left the inbox item %s. Entity expansion needs no '.
                'external reference: ten nested entities expand a few hundred bytes to a gigabyte, and '.
                'the parser is doing what the specification says',
                (string) $examined->status,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function archived(): array
    {
        $violations = [];
        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive(
            entity: $this->entity(),
            bytes: $xml,
            channel: 'peppol',
        );

        if ($document->archive_attachment_id === null) {
            $violations[] = 'the delivery was not archived. An XML document is the only evidence of '.
                'what the supplier actually sent, as opposed to what our parser made of it';
        }

        $stored = app(\App\Exchange\Archive\Archive::class)->read(
            $this->entityId,
            'inbound_document',
            (string) $document->getKey(),
            'received_xml',
        );

        if ($stored !== $xml) {
            $violations[] = 'the archived bytes are not the bytes that arrived';
        }

        if ((string) $document->content_hash !== hash('sha256', $xml)) {
            $violations[] = 'the recorded hash is not the hash of what arrived';
        }

        return $violations;
    }

    /** @return list<string> */
    private function idempotent(): array
    {
        [, $xml] = $this->document();

        $first = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');
        $second = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');

        if ((string) $first->getKey() !== (string) $second->getKey()) {
            return [
                'the same bytes delivered twice produced two inbox items. An access point retries, an '.
                'email is forwarded twice, an import is re-run - and the second row becomes a second '.
                'draft bill, which is the duplicate-payment error arriving through a door the '.
                'duplicate detector does not watch',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function quarantine(): array
    {
        $violations = [];
        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive(
            entity: $this->entity(),
            bytes: $xml,
            channel: 'email',
            senderAddress: 'somebody@nowhere.example',
        );

        if (! $document->isQuarantined()) {
            $violations[] = sprintf(
                'an email from an address nobody allowlisted is %s. It must not become a bill, and it '.
                'must not be dropped either: a supplier sending from a new address has done nothing '.
                'wrong, and a silently dropped invoice is found by their collections department',
                (string) $document->status,
            );
        }

        if ($document->archive_attachment_id === null) {
            $violations[] = 'a quarantined document was not archived, so the evidence of what arrived '.
                'depends on whoever decides what to do with it';
        }

        $examined = app(InboundDocuments::class)->examine($document, $xml);

        if (! $examined->isQuarantined()) {
            $violations[] = 'a quarantined document was examined anyway, which is the stranger\'s file '.
                'being read before anybody decided it should be';
        }

        return $violations;
    }

    /** @return list<string> */
    private function peppolNeedsNoAllowlist(): array
    {
        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive(
            entity: $this->entity(),
            bytes: $xml,
            channel: 'peppol',
            senderAddress: 'nobody-allowlisted@example.net',
        );

        if ($document->isQuarantined()) {
            return [
                'a Peppol delivery was quarantined for want of an allowlist entry. An access point has '.
                'already authenticated the sender, which is what the network is for - requiring a '.
                'second allowlist means every new supplier\'s first invoice sits in a queue',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function neverHalfImported(): array
    {
        $violations = [];
        [, $xml] = $this->document();

        // A total nobody can accept: BR-CO-14 compares it against the breakdown.
        $broken = preg_replace(
            '#(<cac:TaxTotal>\s*<cbc:TaxAmount[^>]*>)[^<]*#',
            '${1}99999.00',
            $xml,
            1,
        );

        $document = app(InboundDocuments::class)->receive($this->entity(), (string) $broken, 'peppol');
        $examined = app(InboundDocuments::class)->examine($document, (string) $broken);

        if (! $examined->isRejected()) {
            $violations[] = sprintf(
                'a document whose stated VAT total disagrees with its breakdown is %s',
                (string) $examined->status,
            );
        }

        $bills = Bill::query()->where('source', 'peppol')->count();

        if ($bills !== 0) {
            $violations[] = sprintf(
                'a rejected document left %d bill(s) behind. A failed import that leaves a draft bill '.
                'nobody raised is then approved by somebody who assumes a draft came from a colleague',
                $bills,
            );
        }

        if ($examined->document_number === null) {
            $violations[] = 'a rejected document records no number, so the supplier who rings about '.
                '"invoice 4471" cannot be answered';
        }

        return $violations;
    }

    /** @return list<string> */
    private function response(): array
    {
        $violations = [];
        [, $xml] = $this->document();

        $broken = preg_replace(
            '#(<cac:TaxTotal>\s*<cbc:TaxAmount[^>]*>)[^<]*#',
            '${1}99999.00',
            $xml,
            1,
        );

        $document = app(InboundDocuments::class)->receive($this->entity(), (string) $broken, 'peppol');
        $examined = app(InboundDocuments::class)->examine($document, (string) $broken);

        $response = app(InboundDocuments::class)->response($examined, '0088:7300010000001');

        if (! str_contains($response, '<ApplicationResponse')) {
            $violations[] = 'the response is not a UBL ApplicationResponse. An email reaches a person '.
                'who then has to describe a rule number to a developer; the sender\'s system raised the '.
                'document and the sender\'s system is what has to be told';
        }

        if (! str_contains($response, '<cbc:ResponseCode>'.ApplicationResponse::REJECTED.'</cbc:ResponseCode>')) {
            $violations[] = 'the response does not carry the UNCL 4343 rejection code';
        }

        if (! str_contains($response, 'BR-')) {
            $violations[] = 'the response names no rule. "Invalid" is not an instruction: a supplier '.
                'whose document failed a calculation rule needs to know which figure we read';
        }

        if ($examined->document_number !== null
            && ! str_contains($response, (string) $examined->document_number)) {
            $violations[] = 'the response does not reference the document it is about';
        }

        return $violations;
    }

    /** @return list<string> */
    private function matchByAddress(): array
    {
        $violations = [];

        /*
         | The supplier IS the selling entity of the fixture.
         |
         | Which is the honest way to produce a document whose seller we can
         | recognise: the serializer writes the entity's own registration
         | into BT-34, so a party carrying that registration is the match.
         */
        $party = $this->supplierCarrying('0088', '310000000000003');

        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');
        $examined = app(InboundDocuments::class)->examine($document, $xml);

        if ((string) $examined->supplier_party_id !== (string) $party->getKey()) {
            $violations[] = sprintf(
                'the document matched %s and the supplier carrying its electronic address is %s',
                (string) ($examined->supplier_party_id ?? 'nobody'),
                (string) $party->getKey(),
            );
        }

        if ((string) $examined->match_method !== 'electronic_address') {
            $violations[] = sprintf(
                'the match method is %s. The electronic address is what the network routes on and what '.
                'the supplier registered, which is why it is the first resort',
                (string) ($examined->match_method ?? 'none'),
            );
        }

        if ($examined->match_detail === null) {
            $violations[] = 'the match records no evidence, so "why do we think this is them" is '.
                'answered by reading the code';
        }

        return $violations;
    }

    /** @return list<string> */
    private function matchByRegistration(): array
    {
        /*
         | A supplier with the VAT number and NOT the electronic address.
         |
         | Which is the ordinary case for a supplier who has never sent us a
         | network document: we know their tax number from their paper
         | invoices and nothing else.
         */
        $party = $this->supplierCarrying(null, '310000000000003');

        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');
        $examined = app(InboundDocuments::class)->examine($document, $xml);

        if ((string) $examined->match_method !== 'tax_registration') {
            return [sprintf(
                'a document whose seller we know only by tax registration matched by %s. A tax '.
                'registration is issued by an authority and is unique within it, which is why it is '.
                'the second resort and a name is none',
                (string) ($examined->match_method ?? 'nothing'),
            )];
        }

        if ((string) $examined->supplier_party_id !== (string) $party->getKey()) {
            return ['the registration match found a different party'];
        }

        return [];
    }

    /** @return list<string> */
    private function neverByName(): array
    {
        $violations = [];

        /*
         | A supplier with the right NAME and nothing else.
         |
         | Two suppliers called "Gulf Trading" are two suppliers; a Latin
         | transliteration of an Arabic name has at least four plausible
         | spellings; and a bill paid to the wrong supplier is the hardest
         | error in accounts payable to get back.
         */
        Party::create([
            'code' => 'SUP-'.Str::upper(Str::random(6)),
            'legal_name' => 'Peakflow Trading Co.',
            'is_supplier' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');
        $examined = app(InboundDocuments::class)->examine($document, $xml);

        if ($examined->supplier_party_id !== null) {
            $violations[] = sprintf(
                'a supplier whose only resemblance is its name was matched, by %s. A fuzzy match is '.
                'right most of the time, and "most of the time" on a payment instruction is not a '.
                'standard anybody would defend out loud',
                (string) $examined->match_method,
            );
        }

        /*
         | And the database holds the line.
         |
         | A helpful-looking function added here later cannot record its
         | result: `match_method` enumerates what is allowed.
         */
        try {
            DB::connection('tenant')->table('inbound_documents')
                ->where('id', (string) $examined->getKey())
                ->update(['match_method' => 'name', 'supplier_party_id' => (string) $this->supplier?->getKey()]);

            $violations[] = 'the database accepted a match recorded as "name"';
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'match_method_is_known')) {
                $violations[] = 'the name match was refused by something else: '.$e->getMessage();
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function awaitingSupplier(): array
    {
        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');
        $examined = app(InboundDocuments::class)->examine($document, $xml);

        if ((string) $examined->status !== 'awaiting_supplier') {
            return [sprintf(
                'a valid document from a supplier nobody recognises is %s. That is not a failure: it '.
                'is the state that sends the document to a person, which is the honest answer when the '.
                'only remaining evidence is a name',
                (string) $examined->status,
            )];
        }

        if ((string) $examined->response_code !== ApplicationResponse::CONDITIONALLY_ACCEPTED) {
            return [sprintf(
                'the response code is %s. Saying "accepted" would tell the sender their invoice is in '.
                'our ledger when it is in a queue',
                (string) ($examined->response_code ?? 'none'),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function assignNeedsAReason(): array
    {
        $violations = [];
        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');
        $examined = app(InboundDocuments::class)->examine($document, $xml);

        try {
            app(InboundDocuments::class)->assignSupplier(
                $examined,
                (string) $this->supplier?->getKey(),
                $this->actorId(),
                'looks ok',
            );

            $violations[] = 'a supplier was assigned with "looks ok" as the evidence. Neither the '.
                'electronic address nor the tax registration identified anybody, so somebody is '.
                'deciding on other evidence - and whoever defends the payment later needs to know what '.
                'it was';
        } catch (InboundRefused) {
            // Expected.
        }

        $assigned = app(InboundDocuments::class)->assignSupplier(
            $examined,
            (string) $this->supplier?->getKey(),
            $this->actorId(),
            'their accounts department confirmed by telephone that invoice 4471 is theirs',
        );

        if ((string) $assigned->match_method !== 'human') {
            $violations[] = sprintf(
                'a hand-assigned supplier is recorded as %s rather than as a human decision',
                (string) ($assigned->match_method ?? 'nothing'),
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function becomesABill(): array
    {
        $violations = [];
        $party = $this->supplierCarrying('0088', '310000000000003');
        [$semantic, $xml] = $this->document();

        $bill = $this->convert($xml);

        if ((string) $bill->party_id !== (string) $party->getKey()) {
            $violations[] = 'the bill is against a different supplier than the document matched';
        }

        $expected = $semantic->get(\App\Exchange\Semantics\BusinessTerm::InvoiceNumber);

        if ((string) $bill->supplier_document_number !== (string) $expected) {
            $violations[] = sprintf(
                'the bill carries document number %s and the document says %s. The supplier\'s number '.
                'is theirs, and a bill that renumbered it cannot be found when they ring about it',
                (string) $bill->supplier_document_number,
                (string) $expected,
            );
        }

        if ($bill->lines->count() !== \count($semantic->lines())) {
            $violations[] = sprintf(
                'the bill has %d lines and the document has %d',
                $bill->lines->count(),
                \count($semantic->lines()),
            );
        }

        if ((string) $bill->status !== 'draft') {
            $violations[] = sprintf(
                'the bill is %s rather than a draft. An inbound document becomes something a person '.
                'approves, not something already approved',
                (string) $bill->status,
            );
        }

        if ((string) $bill->source !== 'peppol') {
            $violations[] = 'the bill does not record how it arrived, so "where did this come from" '.
                'needs a join to answer';
        }

        return $violations;
    }

    /** @return list<string> */
    private function taxTies(): array
    {
        $this->supplierCarrying('0088', '310000000000003');
        [$semantic, $xml] = $this->document();

        $bill = $this->convert($xml);

        $stated = BigDecimal::of((string) $semantic->totals()?->get(
            \App\Exchange\Semantics\BusinessTerm::TotalVatAmount,
        ));

        if (! BigDecimal::of((string) $bill->tax_amount)->isEqualTo($stated)) {
            return [sprintf(
                'the bill\'s tax is %s and the document states %s. EN 16931 has no per-line tax '.
                'amount, so each line\'s is derived from its rate - and the derived total has to be '.
                'reconciled to the breakdown the supplier sent, or the difference is found by whoever '.
                'reconciles the input tax to the return',
                (string) $bill->tax_amount,
                (string) $stated,
            )];
        }

        $net = BigDecimal::of((string) $semantic->totals()?->get(
            \App\Exchange\Semantics\BusinessTerm::SumOfLineNetAmounts,
        ));

        if (! BigDecimal::of((string) $bill->net_amount)->isEqualTo($net)) {
            return [sprintf(
                'the bill nets %s and the document says %s',
                (string) $bill->net_amount,
                (string) $net,
            )];
        }

        return [];
    }

    /**
     * The case the reconciliation exists for.
     *
     * Three lines of 1.03 at 15%: each line's tax is 0.1545 and their sum is
     * 0.4635, while the supplier states the group's tax on the group's
     * taxable total - 3.09 at 15%, written at the currency's two decimals as
     * 0.46. The two disagree by a third of a cent, which is exactly the
     * difference a bill built line by line would carry away from a document
     * that was correct.
     *
     * Measured: the ordinary fixture cannot show this, because its lines
     * multiply out exactly. Removing the reconciliation left the suite green.
     *
     * @return list<string>
     */
    private function taxRounding(): array
    {
        $this->supplierCarrying('0088', '310000000000003');
        [$semantic, $xml] = $this->awkwardDocument();

        $bill = $this->convert($xml);

        $stated = BigDecimal::of((string) $semantic->totals()?->get(
            \App\Exchange\Semantics\BusinessTerm::TotalVatAmount,
        ));

        if (! BigDecimal::of((string) $bill->tax_amount)->isEqualTo($stated)) {
            return [sprintf(
                'the bill\'s tax is %s and the document states %s. The supplier rounded once per rate '.
                'group and a bill built line by line rounds once per line, so the two differ - and the '.
                'difference is found by whoever reconciles the input tax to the return',
                (string) $bill->tax_amount,
                (string) $stated,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function expenseAccount(): array
    {
        $violations = [];
        $party = $this->supplierCarrying('0088', '310000000000003');
        [, $xml] = $this->document();

        // With no default configured, the lines carry no account.
        $bill = $this->convert($xml);

        foreach ($bill->lines as $line) {
            if ($line->expense_account_id !== null) {
                $violations[] = 'a line was given an expense account although the supplier has no '.
                    'default. EN 16931 has no term for it - the chart is ours, not theirs - and '.
                    'guessing from the item description is a guess';

                break;
            }
        }

        // And with one, every line carries it.
        $party->update(['default_expense_account_id' => $this->expenseAccountId]);

        [, $second] = $this->document();
        $billWithAccount = $this->convert($second);

        foreach ($billWithAccount->lines as $line) {
            if ((string) $line->expense_account_id !== $this->expenseAccountId) {
                $violations[] = 'a line did not take the supplier\'s configured default expense '.
                    'account, so every inbound bill waits for a person to code it - which is most of '.
                    'the work the automation was supposed to remove';

                break;
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function convertsOnce(): array
    {
        $this->supplierCarrying('0088', '310000000000003');
        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');
        $examined = app(InboundDocuments::class)->examine($document, $xml);
        $read = app(UblReader::class)->read($xml);

        app(BillFromDocument::class)->convert($examined, $this->entity(), $read, $this->date->copy());

        try {
            app(BillFromDocument::class)->convert(
                $examined->refresh(),
                $this->entity(),
                $read,
                $this->date->copy(),
            );

            return [
                'one delivery produced two bills. Both carry the same supplier number, so the '.
                'duplicate detector would catch the second - but only because of the number, and a '.
                'supplier who re-sends under a new number would get through',
            ];
        } catch (InboundRefused $e) {
            /*
             | And the refusal says it was already converted.
             |
             | Measured: the status moves validated -> converted, so the
             | readiness check catches this too and says "this document is
             | converted and has no supplier", which is neither true nor
             | useful. Without this assertion the check passed with the
             | specific guard removed.
             */
            if (! str_contains($e->getMessage(), 'already converted')) {
                return [sprintf(
                    'the refusal does not say the document was already converted: "%s"',
                    $e->getMessage(),
                )];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function unvalidatedConversion(): array
    {
        [, $xml] = $this->document();

        $document = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');
        $read = app(UblReader::class)->read($xml);

        try {
            app(BillFromDocument::class)->convert($document, $this->entity(), $read, $this->date->copy());

            return [
                'a document that had not been validated became a bill. A document becomes a bill once '.
                'it has passed every rule and somebody knows who sent it',
            ];
        } catch (InboundRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function intakeAddress(): array
    {
        $violations = [];
        $intake = app(\App\Exchange\Inbound\EmailIntake::class);

        $address = $intake->addressFor($this->entity());

        if (! str_contains($address, strtolower((string) $this->entity()->code))) {
            $violations[] = sprintf(
                'the intake address %s does not carry the entity code, so nothing in it identifies '.
                'which ledger an invoice joins',
                $address,
            );
        }

        $resolved = $intake->entityForAddress($address);

        if ($resolved === null || (string) $resolved->getKey() !== $this->entityId) {
            $violations[] = 'the address does not resolve back to the entity it was built for';
        }

        /*
         | And an address nobody recognises resolves to NOBODY.
         |
         | Not to the first entity in the table: delivering it there would
         | put one company's invoice in another company's ledger, and the two
         | may not even be in the same group.
         */
        if ($intake->entityForAddress('inbox+not-an-entity@invoices.peakflow.test') !== null) {
            $violations[] = 'an address naming no entity resolved to one anyway';
        }

        if ($intake->entityForAddress('somebody@example.com') !== null) {
            $violations[] = 'an address outside the intake domain resolved to an entity';
        }

        try {
            $intake->accept(['to' => 'somebody@example.com', 'body' => 'An invoice, allegedly.']);

            $violations[] = 'a message to an unrecognised address was accepted';
        } catch (InboundRefused) {
            // Expected.
        }

        return $violations;
    }

    /** @return list<string> */
    private function emailBecomesItems(): array
    {
        $violations = [];
        $intake = app(\App\Exchange\Inbound\EmailIntake::class);
        [, $xml] = $this->document();

        $items = $intake->accept([
            'to' => $intake->addressFor($this->entity()),
            'from' => 'invoices@gulf-components.example',
            'subject' => 'Invoice 4471',
            'body' => 'As discussed, the extra day is invoiced separately.',
            'attachments' => [
                ['filename' => 'invoice.xml', 'bytes' => $xml, 'content_type' => 'application/xml'],
            ],
        ]);

        if (\count($items) !== 2) {
            $violations[] = sprintf(
                'one attachment and a body produced %d item(s). A supplier who types an invoice into '.
                'the message is doing something irregular and is still owed money, and the body is '.
                'frequently the only record of what was agreed',
                \count($items),
            );
        }

        foreach ($items as $item) {
            if (! $item instanceof InboundDocument) {
                $violations[] = 'the intake returned something that is not an inbox item';

                break;
            }

            if ($item->bill_id !== null) {
                $violations[] = 'an email created a bill directly. Turning a stranger\'s attachment '.
                    'into a payable is the error the inbox exists to prevent';

                break;
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function attachmentIsScanned(): array
    {
        $intake = app(\App\Exchange\Inbound\EmailIntake::class);
        $sender = $this->allowlisted();

        /*
         | The sender is allowlisted FIRST, deliberately.
         |
         | Measured: without this the item was quarantined for want of an
         | allowlist entry, and the check passed with the attachment store
         | removed entirely - it was testing the allowlist and reporting on
         | the scanner. Two controls reaching the same status is exactly the
         | shape a vacuous check takes.
         */
        $items = $intake->accept([
            'to' => $intake->addressFor($this->entity()),
            'from' => $sender,
            'subject' => 'Invoice 4472',
            'attachments' => [[
                'filename' => 'invoice.xml',
                'bytes' => "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF\n",
                'content_type' => 'application/xml',
            ]],
        ]);

        if ($items === []) {
            return ['the attachment produced no inbox item at all, so nothing records that it arrived'];
        }

        $item = $items[0];

        if (! $item->isQuarantined()) {
            return [sprintf(
                'a file declared as XML whose bytes are a PDF is %s. The declared name and type come '.
                'from the sender and are not evidence of anything: a `.pdf` that is a zip and a 900MB '.
                '"invoice" are ordinary email',
                (string) $item->status,
            )];
        }

        $note = (string) $item->note;

        /*
         | And the reason is the STORE's, not the allowlist's.
         |
         | Which is what distinguishes "we would not open this file" from
         | "we do not know this sender", and they need different answers.
         */
        if (! str_contains($note, 'xml') && ! str_contains($note, 'pdf')
            && ! str_contains(strtolower($note), 'type')) {
            return [sprintf(
                'the quarantine reason does not mention the file at all: "%s". A supplier whose file '.
                'we would not open has to be told something, and "we never received it" is what they '.
                'will be told otherwise',
                $note,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function emptyMessage(): array
    {
        $intake = app(\App\Exchange\Inbound\EmailIntake::class);

        try {
            $intake->accept([
                'to' => $intake->addressFor($this->entity()),
                'from' => 'invoices@gulf-components.example',
                'subject' => 'Invoice attached',
                'body' => '   ',
            ]);

            return [
                'a message with no attachments and an empty body produced an inbox item. A row nobody '.
                'can action is a row somebody has to close by hand every day',
            ];
        } catch (InboundRefused) {
            return [];
        }
    }

    // ---- Helpers -------------------------------------------------------

    /**
     * An issued invoice, as a semantic model and as UBL.
     *
     * @return array{0: \App\Exchange\Semantics\SemanticInvoice, 1: string}
     */
    private function document(
        \App\Tenant\Sales\Enums\DocumentType $type = \App\Tenant\Sales\Enums\DocumentType::Invoice,
    ): array {
        /*
         | A credit note is CREDITED from an issued invoice, not drafted.
         |
         | `invoices_a_credit_note_references_what_it_adjusts` refuses one
         | that references nothing, which is EN 16931's BT-25 in the
         | database - so the only way to produce a real credit note is to
         | credit something. Measured: drafting one directly was refused by
         | that constraint, which is the constraint doing its job.
         */
        if ($type === \App\Tenant\Sales\Enums\DocumentType::CreditNote) {
            $original = app(Issuer::class)->issue(
                $this->draft(),
                $this->entity(),
                $this->actorId(),
                $this->decision,
                $this->terms,
            );

            $invoice = app(Issuer::class)->issue(
                app(\App\Tenant\Sales\Services\Crediting::class)->full($original, $this->date->copy()),
                $this->entity(),
                $this->actorId(),
                $this->decision,
                $this->terms,
            );
        } else {
            $invoice = app(Issuer::class)->issue(
                $this->draftWithTwoRateGroups(),
                $this->entity(),
                $this->actorId(),
                $this->decision,
                $this->terms,
            );
        }

        $semantic = app(SemanticInvoiceBuilder::class)->build($invoice);
        $serializer = app(UblSerializer::class);

        return [$semantic, $serializer->render($serializer->tree($semantic), $semantic)];
    }

    /**
     * Three lines of 1.03 at 15%, which do not multiply out evenly.
     *
     * Each line's tax is 0.1545 and their sum is 0.4635; the group's is
     * 3.09 at 15% written at two decimals, which is 0.46. That third of a
     * cent is the whole reason the reconciliation exists.
     */
    private function draftWithAwkwardRounding(): \App\Tenant\Sales\Models\Invoice
    {
        $net = '3.0900';
        $tax = '0.4600';
        $gross = '3.5500';

        $invoice = \App\Tenant\Sales\Models\Invoice::create([
            'entity_id' => $this->entityId,
            'series_key' => \App\Tenant\Sales\Enums\DocumentType::Invoice->seriesKey(),
            'document_type' => \App\Tenant\Sales\Enums\DocumentType::Invoice->value,
            'status' => 'draft',
            'party_id' => (string) $this->party?->getKey(),
            'issue_date' => $this->date->toDateString(),
            'tax_point' => $this->date->toDateString(),
            'currency' => 'SAR',
            'net_amount' => $net,
            'tax_amount' => $tax,
            'gross_amount' => $gross,
            'payable_amount' => $gross,
            'presentation_exponent' => 2,
        ]);

        foreach ([1, 2, 3] as $lineNo) {
            \App\Tenant\Sales\Models\InvoiceLine::create([
                'entity_id' => $this->entityId,
                'invoice_id' => (string) $invoice->getKey(),
                'line_no' => $lineNo,
                'description' => sprintf('An awkward unit, number %d', $lineNo),
                'unit_code' => 'C62',
                'quantity' => '1.00000000',
                'unit_price' => '1.0300',
                'net_amount' => '1.0300',
                'revenue_account_id' => $this->revenueAccountId,
                'tax_code_id' => $this->taxCodeId,
                'tax_category' => 'S',
                'tax_rate' => '15.000000',
                'tax_point' => $this->date->toDateString(),
                'place_of_supply' => 'SA',
                'place_of_supply_rule' => 'domestic',
            ]);
        }

        \App\Tenant\Sales\Models\InvoiceTaxBreakdownRow::create([
            'entity_id' => $this->entityId,
            'invoice_id' => (string) $invoice->getKey(),
            'category' => 'S',
            'rate' => '15.000000',
            'taxable_amount' => $net,
            'tax_amount' => $tax,
            'report_box' => 'standard_sales',
        ]);

        return $invoice->refresh();
    }

    /**
     * A document whose per-line arithmetic does not match its group's.
     *
     * Built by replacing a real document's lines and totals, so the XML is
     * still our serializer's and only the figures are chosen.
     *
     * @return array{0: \App\Exchange\Semantics\SemanticInvoice, 1: string}
     */
    private function awkwardDocument(): array
    {
        $invoice = app(Issuer::class)->issue(
            $this->draftWithAwkwardRounding(),
            $this->entity(),
            $this->actorId(),
            $this->decision,
            $this->terms,
        );

        $semantic = app(SemanticInvoiceBuilder::class)->build($invoice);
        $serializer = app(UblSerializer::class);

        return [$semantic, $serializer->render($serializer->tree($semantic), $semantic)];
    }

    /** A supplier carrying a registration, so a document's seller can match it. */
    private function supplierCarrying(?string $easCode, string $identifier): Party
    {
        $party = Party::create([
            'code' => 'SUP-'.Str::upper(Str::random(6)),
            'legal_name' => 'A supplier we can recognise',
            'is_supplier' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        \App\Tenant\Parties\Models\PartyTaxRegistration::create([
            'party_id' => $party->getKey(),
            'jurisdiction' => 'SA',
            'scheme' => 'VAT',
            'identifier' => $identifier,
            'eas_code' => $easCode,
            'is_primary' => true,
            'validity' => \App\Tenant\Parties\Models\PartyTaxRegistration::range(
                \Carbon\CarbonImmutable::parse('2019-01-01'),
            ),
        ]);

        return $party;
    }

    private function convert(string $xml): Bill
    {
        $document = app(InboundDocuments::class)->receive($this->entity(), $xml, 'peppol');
        $examined = app(InboundDocuments::class)->examine($document, $xml);
        $read = app(UblReader::class)->read($xml);

        return app(BillFromDocument::class)->convert(
            $examined,
            $this->entity(),
            $read,
            $this->date->copy(),
        );
    }

    /** An allowlisted email sender, for the channel that needs one. */
    private function allowlisted(): string
    {
        $address = 'invoices@gulf-components.example';

        app(\App\Exchange\Inbound\EmailIntake::class)->allowlist(
            $this->entity(),
            $address,
            $this->supplier === null ? null : (string) $this->supplier->getKey(),
        );

        return $address;
    }
}
