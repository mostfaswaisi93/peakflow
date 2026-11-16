<?php

declare(strict_types=1);

namespace App\Exchange\Inbound;

use App\Exchange\Archive\Archive;
use App\Exchange\Pipeline\ValidationPipeline;
use App\Exchange\Pipeline\ValidationReport;
use App\Exchange\Profiles\ProfileRegistry;
use App\Exchange\Readers\NotABillingDocument;
use App\Exchange\Readers\UblReader;
use App\Exchange\Semantics\BusinessGroup as BG;
use App\Exchange\Semantics\BusinessTerm as BT;
use App\Exchange\Semantics\SemanticInvoice;
use App\Exchange\Serializers\Ubl\ApplicationResponse;
use App\Support\Audit\AuditTrail;
use App\Support\Xml\MalformedXml;
use App\Tenant\Tenancy\Models\Entity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Documents that arrive, and what becomes of them - PF-140, PF-141.
 *
 * ## An inbox item is not a bill
 *
 * Which is the whole design. A document arrives from somebody who may not be
 * a supplier, in a format that may not be valid, and it becomes a row in
 * `inbound_documents` first. Turning it straight into a bill means a
 * stranger's attachment creates a payable, and the worst version of that is
 * an invoice from a supplier who does not exist for goods nobody ordered.
 *
 * ## Validated before anything of ours is written
 *
 * PF-141's second criterion. The bytes are read into a
 * {@see SemanticInvoice} - which is not a document of ours, it is the
 * standard's own model - and validated there. Only a document that passes
 * every stage can be converted, and the conversion happens in one
 * transaction. There is no state in which half a supplier's invoice is in
 * our ledger.
 *
 * A reader that wrote into `bills` and validated afterwards would have that
 * state, and "afterwards" is where the rollback does not reach: a failed
 * import leaves a draft bill nobody raised, which is then approved by
 * somebody who assumes a draft came from a colleague.
 *
 * ## Nothing is guessed, and the supplier least of all
 *
 * Matching is by electronic address, then by tax registration, then by a
 * person. A name match is deliberately unavailable: two suppliers called
 * "Gulf Trading" are two suppliers, and a bill paid to the wrong one of them
 * is money gone somewhere it was never owed. The database agrees - the
 * `match_method` column enumerates what is allowed, so the absence of a name
 * match stays absent.
 *
 * ## The original is kept as it arrived
 *
 * In the archive, under `received_xml`, which is a different kind from
 * `sent_xml`: the two answer different questions. A dispute about an inbound
 * invoice is settled by the bytes that arrived rather than by what our
 * parser made of them.
 */
final class InboundDocuments
{
    public function __construct(
        private readonly UblReader $reader,
        private readonly ValidationPipeline $pipeline,
        private readonly ProfileRegistry $profiles,
        private readonly Archive $archive,
        private readonly ApplicationResponse $responses,
        private readonly SupplierMatcher $matcher,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Record what arrived, before anything is read from it.
     *
     * @throws InboundRefused
     */
    public function receive(
        Entity $entity,
        string $bytes,
        string $channel = 'peppol',
        ?string $senderAddress = null,
        ?string $subject = null,
        ?string $contentType = 'application/xml',
        ?Carbon $at = null,
    ): InboundDocument {
        if (trim($bytes) === '') {
            throw InboundRefused::because(
                'There are no bytes to receive. An empty delivery is not a document, and recording one '.
                'as an inbox item would put a row in a queue that nobody can ever action.',
            );
        }

        $entityId = (string) $entity->getKey();
        $at ??= Carbon::now();
        $hash = hash('sha256', $bytes);

        /*
         | The same bytes twice are one row.
         |
         | A Peppol access point retries, an email is forwarded twice, an
         | import is re-run. Without this the second delivery becomes a
         | second draft bill - the duplicate-payment error arriving through
         | a door the duplicate detector does not watch, because the two
         | would have the same supplier number and the detector only sees
         | bills.
         */
        $existing = InboundDocument::query()
            ->where('entity_id', $entityId)
            ->where('content_hash', $hash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /*
         | The sender allowlist, for channels where anybody can send - PF-140.
         |
         | Quarantined rather than discarded: a supplier who sends from a new
         | address has done nothing wrong, and a silently dropped invoice is
         | found by the supplier's collections department. Peppol is exempt
         | because an access point has already authenticated the sender,
         | which is what the network is for.
         */
        $quarantine = $channel === 'email' && ! $this->matcher->isAllowlisted($entityId, $channel, $senderAddress);

        try {
            $document = InboundDocument::create([
                'entity_id' => $entityId,
                'channel' => $channel,
                'received_at' => $at,
                'status' => $quarantine ? 'quarantined' : 'received',
                'sender_address' => $senderAddress,
                'subject' => $subject,
                'content_hash' => $hash,
                'content_type' => $contentType,
                'content_bytes' => \strlen($bytes),
                'note' => $quarantine
                    ? sprintf(
                        'Quarantined: %s is not an allowlisted sender for this entity. A supplier '.
                        'sending from a new address has done nothing wrong, so the document is held '.
                        'rather than dropped.',
                        $senderAddress ?? 'an unidentified sender',
                    )
                    : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             | Another worker recorded the same delivery in between.
             |
             | Which is what the unique index exists for. Returning its row
             | is the right answer: both callers are describing one arrival.
             */
            return InboundDocument::query()
                ->where('entity_id', $entityId)
                ->where('content_hash', $hash)
                ->firstOrFail();
        }

        /*
         | Archived immediately, as received, before anything is parsed.
         |
         | An email is frequently the only evidence of what was agreed, and
         | an XML document is the only evidence of what the supplier actually
         | sent - as opposed to what our parser made of it. Archiving after
         | a successful parse would keep the documents we could read and lose
         | the ones we are going to argue about.
         */
        $stored = $this->archive->store(
            entityId: $entityId,
            subjectType: 'inbound_document',
            subjectId: (string) $document->getKey(),
            kind: 'received_xml',
            bytes: $bytes,
            profile: $this->profiles->forDocument(null),
            contentType: (string) ($contentType ?? 'application/xml'),
            asOf: $at,
        );

        $document->update(['archive_attachment_id' => (string) ($stored['id'] ?? null)]);

        $this->audit->record(
            category: 'exchange',
            action: 'inbound_document.received',
            subjectType: 'inbound_document',
            subjectId: (string) $document->getKey(),
            subjectLabel: $senderAddress ?? $channel,
            after: [
                'channel' => $channel,
                'bytes' => \strlen($bytes),
                'content_hash' => $hash,
                'quarantined' => $quarantine,
            ],
            entityId: $entityId,
        );

        return $document->refresh();
    }

    /**
     * Read it into the model, validate it there, and match the supplier.
     *
     * Returns the document. Its status says what happened and
     * `validation_report` says why - "rejected" with no report is a verdict
     * nobody can act on, and the database refuses it.
     */
    public function examine(InboundDocument $document, string $bytes): InboundDocument
    {
        $document = $document->refresh();

        if ($document->status === 'quarantined') {
            return $document;
        }

        try {
            $invoice = $this->reader->read($bytes);
        } catch (MalformedXml|NotABillingDocument $e) {
            return $this->reject($document, null, $e->getMessage());
        }

        $profile = $this->profiles->forDocument($invoice->profile);
        $report = $this->pipeline->run($invoice, $profile);

        /*
         | The header is recorded whether or not it validated.
         |
         | A rejected document still has to be findable: the supplier will
         | ring about "invoice 4471", and a queue row with no number on it
         | cannot be found by anybody. The figures are what the document
         | claimed, not what we accepted.
         */
        $document->update([
            'document_number' => $invoice->get(BT::InvoiceNumber),
            'document_type' => $invoice->get(BT::InvoiceTypeCode),
            'document_date' => $invoice->get(BT::IssueDate),
            'currency' => $invoice->get(BT::InvoiceCurrencyCode),
            'gross_amount' => $invoice->totals()?->get(BT::TotalWithVat),
            'sender_electronic_address' => $invoice->group(BG::Seller)?->get(BT::SellerElectronicAddress),
            'sender_scheme' => $invoice->group(BG::Seller)?->qualifier(BT::SellerElectronicAddress),
            'validation_report' => $report->toArray(),
        ]);

        if (! $report->passed()) {
            return $this->reject(
                $document,
                $report,
                sprintf(
                    'The document does not meet %s: it failed at the %s stage. The response names each '.
                    'rule.',
                    $profile->label('en'),
                    (string) ($report->failedAt() ?? 'validation'),
                ),
            );
        }

        $match = $this->matcher->match($document->refresh(), $invoice);

        $document->update([
            'status' => $match === null ? 'awaiting_supplier' : 'validated',
            'supplier_party_id' => $match === null ? null : $match->partyId,
            'match_method' => $match?->method,
            'match_detail' => $match?->detail,
            'response_code' => $match === null
                ? ApplicationResponse::CONDITIONALLY_ACCEPTED
                : ApplicationResponse::ACCEPTED,
        ]);

        $this->audit->record(
            category: 'exchange',
            action: 'inbound_document.validated',
            subjectType: 'inbound_document',
            subjectId: (string) $document->getKey(),
            subjectLabel: (string) ($document->document_number ?? $document->getKey()),
            after: [
                'profile' => $profile->key(),
                'stages' => $report->ran,
                'match_method' => $match?->method,
                'supplier' => $match?->partyId,
            ],
            entityId: (string) $document->entity_id,
            partyId: $match?->partyId,
        );

        return $document->refresh();
    }

    /**
     * Assign a supplier by hand - the third and last resort.
     *
     * Recorded as `human` with the reason, because a match nobody can
     * explain is a match nobody can defend. This is the path a document
     * takes when neither the electronic address nor the tax registration
     * identified anybody, and it is deliberately the only path a person has:
     * there is no "match by name" button to press instead.
     *
     * @throws InboundRefused
     */
    public function assignSupplier(
        InboundDocument $document,
        string $partyId,
        string $userId,
        string $reason,
    ): InboundDocument {
        $document = $document->refresh();

        if (\strlen(trim($reason)) <= 10) {
            throw InboundRefused::because(
                'Assigning a supplier by hand needs a reason. Neither the electronic address nor the '.
                'tax registration identified anybody, so somebody is deciding on other evidence - and '.
                'whoever defends the payment later needs to know what that evidence was.',
            );
        }

        if (! \in_array($document->status, ['awaiting_supplier', 'validated'], true)) {
            throw InboundRefused::because(sprintf(
                'This document is %s, so it is not waiting for a supplier.',
                (string) $document->status,
            ));
        }

        $document->update([
            'status' => 'validated',
            'supplier_party_id' => $partyId,
            'match_method' => 'human',
            'match_detail' => trim($reason),
        ]);

        $this->audit->record(
            category: 'exchange',
            action: 'inbound_document.supplier_assigned',
            subjectType: 'inbound_document',
            subjectId: (string) $document->getKey(),
            subjectLabel: (string) ($document->document_number ?? $document->getKey()),
            after: ['supplier' => $partyId, 'method' => 'human'],
            reason: trim($reason),
            entityId: (string) $document->entity_id,
            partyId: $partyId,
        );

        return $document->refresh();
    }

    /**
     * The standard's own response to this document.
     *
     * Built from what was recorded rather than re-validated, so the response
     * a supplier receives and the reason in our queue are the same sentence.
     * A response regenerated from a second validation run could disagree
     * with the one already sent - the profile's rules may have changed since.
     */
    public function response(InboundDocument $document, ?string $ourEndpoint = null): string
    {
        $document = $document->refresh();

        return $this->responses->render(
            responseCode: (string) ($document->response_code ?? ApplicationResponse::REJECTED),
            document: [
                'number' => $document->document_number,
                'issue_date' => $document->document_date?->toDateString(),
                'type_code' => $document->document_type,
            ],
            /*
             | The violations as they were recorded, not as a fresh run
             | reports them.
             |
             | The response a supplier receives and the reason in our queue
             | have to be the same sentence - and a second validation run
             | could disagree with the first, because a profile's rules
             | change. Only a rejection carries them: an accepted document's
             | response says "accepted", and listing the warnings it passed
             | with would read as a complaint.
             */
            violations: $document->status === 'rejected' ? $this->recordedViolations($document) : [],
            senderEndpoint: $document->sender_electronic_address === null
                ? null
                : $this->qualified($document),
            receiverEndpoint: $ourEndpoint,
            reason: $document->status === 'rejected'
                ? (string) $document->rejection_reason
                : null,
            at: Carbon::parse($document->received_at->toDateTimeString()),
        );
    }

    /**
     * The violations the stored report holds, flattened across its stages.
     *
     * @return list<array<string, mixed>>
     */
    private function recordedViolations(InboundDocument $document): array
    {
        $stages = $document->validation_report['stages'] ?? [];
        $flat = [];

        foreach ($stages as $violations) {
            if (! \is_array($violations)) {
                continue;
            }

            foreach ($violations as $violation) {
                if (\is_array($violation)) {
                    $flat[] = $violation;
                }
            }
        }

        return $flat;
    }

    /** The queue a person works: what arrived and has not become a bill. */
    public function queue(string $entityId): array
    {
        return InboundDocument::query()
            ->where('entity_id', $entityId)
            ->whereIn('status', ['received', 'quarantined', 'rejected', 'validated', 'awaiting_supplier'])
            ->orderByDesc('received_at')
            ->get()
            ->map(static fn (InboundDocument $document): array => [
                'id' => (string) $document->getKey(),
                'status' => (string) $document->status,
                'channel' => (string) $document->channel,
                'received_at' => $document->received_at->toDateTimeString(),
                'sender' => $document->sender_address ?? $document->sender_electronic_address,
                'number' => $document->document_number,
                'currency' => $document->currency,
                'gross' => $document->gross_amount,
                'supplier_party_id' => $document->supplier_party_id,
                'match_method' => $document->match_method,
                'why' => $document->rejection_reason ?? $document->note,
            ])
            ->all();
    }

    /** @throws InboundRefused */
    private function reject(InboundDocument $document, ?ValidationReport $report, string $reason): InboundDocument
    {
        $document->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'response_code' => ApplicationResponse::REJECTED,
            /*
             | A report, even where the failure was the XML itself.
             |
             | The database refuses a rejection with no report, and rightly:
             | "rejected" is a verdict and a report is a reason. Where the
             | bytes would not parse there are no business rules to list, so
             | the report records that stage and the parser's own message.
             */
            'validation_report' => $report?->toArray() ?? [
                'profile' => 'unknown',
                'stages' => ['read' => [['rule' => 'SYNTAX', 'detail' => $reason]]],
                'ran' => ['read'],
                'skipped' => [
                    'semantic' => 'the document could not be read, so its terms were never populated',
                ],
            ],
        ]);

        $this->audit->record(
            category: 'exchange',
            action: 'inbound_document.rejected',
            subjectType: 'inbound_document',
            subjectId: (string) $document->getKey(),
            subjectLabel: (string) ($document->document_number ?? $document->getKey()),
            after: ['failed_at' => $report?->failedAt() ?? 'read'],
            reason: $reason,
            entityId: (string) $document->entity_id,
        );

        return $document->refresh();
    }

    /** The sender's endpoint with its scheme, the way Peppol writes it. */
    private function qualified(InboundDocument $document): string
    {
        $scheme = (string) ($document->sender_scheme ?? '');
        $address = (string) $document->sender_electronic_address;

        return $scheme === '' ? $address : $scheme.':'.$address;
    }

    /** Only for the conversion path, which lives in Purchasing. */
    public function markConverted(InboundDocument $document, string $billId, ?string $userId = null): InboundDocument
    {
        $document->update([
            'status' => 'converted',
            'bill_id' => $billId,
            'converted_at' => now(),
            'converted_by' => $userId,
        ]);

        $this->audit->record(
            category: 'exchange',
            action: 'inbound_document.converted',
            subjectType: 'inbound_document',
            subjectId: (string) $document->getKey(),
            subjectLabel: (string) ($document->document_number ?? $document->getKey()),
            after: ['bill_id' => $billId],
            entityId: (string) $document->entity_id,
            partyId: $document->supplier_party_id === null ? null : (string) $document->supplier_party_id,
        );

        return $document->refresh();
    }
}
