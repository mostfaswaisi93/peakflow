<?php

declare(strict_types=1);

namespace App\Exchange\Extraction;

use App\Exchange\Inbound\InboundDocument;
use App\Support\Audit\AuditTrail;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Getting the figures out of a document that arrived - PF-142.
 *
 * ## Embedded XML first, and OCR never where there is XML
 *
 * PF-142's first criterion, and the one it is easiest to get wrong. A
 * Factur-X PDF looks like every other PDF invoice, and an extraction
 * pipeline that starts with OCR never asks whether the figures are already
 * in the file - exactly. The result is a bill whose totals are nearly right,
 * which is worse than one that is obviously wrong.
 *
 * So: a PDF is searched for an embedded invoice, and where there is one the
 * optical reader is not called at all. {@see routeFor()} is the decision and
 * it is checked.
 *
 * ## A proposal is not a value
 *
 * An OCR reading is a machine's interpretation of a picture. It is recorded
 * as a **proposal** with a confidence per field, fields below the threshold
 * are flagged, and a person confirms or corrects before anything becomes a
 * bill. The database refuses a proposal that names a bill without a
 * confirmation behind it, because a machine reading that posted itself is a
 * payment nobody authorised.
 *
 * ## A confidence belongs to a guess
 *
 * Embedded XML carries none - the figures were never in doubt - and an OCR
 * field must carry one. A trigger enforces both, because a confidence of
 * 1.0 beside a fact is how a reviewer learns to ignore the column.
 *
 * ## Accuracy is per supplier template
 *
 * Every confirmation updates the template's counters, so "which supplier's
 * invoices do we keep correcting" is a question a list answers. A format that
 * is consistently misread is a format to fix, by asking the supplier for
 * Peppol or by teaching the engine - and either way somebody has to be able
 * to see it.
 */
final class DocumentExtraction
{
    /** Below this, a field is somebody's to check. */
    public const REVIEW_BELOW = '0.9000';

    public function __construct(
        private readonly EmbeddedXml $embedded,
        private readonly OpticalReader $reader,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * How this document should be read, and why.
     *
     * Separated from the reading so the decision can be checked on its own.
     * "A structured document must never be sent to OCR" is a routing rule,
     * and a rule buried inside the method that acts on it is a rule nobody
     * can test without running the engine.
     *
     * @return array{route: string, why: string, xml: ?string}
     */
    public function routeFor(InboundDocument $document, string $bytes): array
    {
        if (! EmbeddedXml::looksLikeAPdf($bytes)) {
            /*
             | Not a PDF: it is either XML already or something nobody can
             | read automatically.
             |
             | A document that is already XML needs no extraction at all -
             | it goes through the inbound reader, which is exact.
             */
            return str_contains(substr($bytes, 0, 1024), '<')
                ? [
                    'route' => 'structured',
                    'why' => 'The document is XML already, so there is nothing to extract: it is read '.
                        'by the inbound reader, exactly.',
                    'xml' => $bytes,
                ]
                : [
                    'route' => 'optical',
                    'why' => 'The document is neither XML nor a PDF, so nothing in it can be read '.
                        'without optical recognition.',
                    'xml' => null,
                ];
        }

        $xml = $this->embedded->from($bytes);

        if ($xml !== null) {
            return [
                'route' => 'embedded_xml',
                'why' => 'The PDF carries the invoice as an embedded XML file, so the figures are in '.
                    'the document exactly. Running optical recognition over the picture of them would '.
                    'be choosing a guess over a fact, and the result is a bill whose totals are nearly '.
                    'right.',
                'xml' => $xml,
            ];
        }

        return [
            'route' => 'optical',
            'why' => 'The PDF carries no embedded invoice, so the only way to read it is optically - '.
                'which proposes values rather than asserting them.',
            'xml' => null,
        ];
    }

    /**
     * Read a document, by whichever route applies.
     *
     * Returns the proposal. Where the route was `embedded_xml` or
     * `structured` the proposal has no fields: the XML is handed to the
     * inbound reader, which populates every business term exactly, and
     * copying those into proposal fields would invite somebody to "confirm"
     * figures that were never in question.
     */
    public function extract(InboundDocument $document, string $bytes, ?string $templateKey = null): ExtractionProposal
    {
        $route = $this->routeFor($document, $bytes);
        $template = $this->template($document, $templateKey ?? $route['route']);

        if ($route['route'] !== 'optical') {
            return $this->record(
                document: $document,
                template: $template,
                source: 'embedded_xml',
                status: 'proposed',
                fields: [],
                engine: null,
                note: $route['why'],
            );
        }

        try {
            $read = $this->reader->read($bytes, (string) ($document->content_type ?? 'application/pdf'));
        } catch (OpticalReaderUnavailable $e) {
            /*
             | Recorded as unavailable, with the reason.
             |
             | Not as a document nothing could be read from: the first sends
             | somebody to the deployment and the second sends them to the
             | scanner, and they would re-scan an invoice that was perfectly
             | legible.
             */
            return $this->record(
                document: $document,
                template: $template,
                source: 'ocr',
                status: 'unavailable',
                fields: [],
                engine: $this->reader->identify(),
                note: $e->getMessage(),
            );
        }

        return $this->record(
            document: $document,
            template: $template,
            source: 'ocr',
            status: 'proposed',
            fields: $read,
            engine: $this->reader->identify(),
            note: $route['why'],
        );
    }

    /**
     * Confirm a proposal, with whatever a person corrected.
     *
     * @param  array<string, string|null>  $corrections  field => the value a person read
     *
     * @throws ExtractionRefused
     */
    public function confirm(
        ExtractionProposal $proposal,
        string $userId,
        array $corrections = [],
        ?string $billId = null,
    ): ExtractionProposal {
        $proposal = $proposal->refresh()->load('fields');

        if ((string) $proposal->status === 'confirmed') {
            return $proposal;
        }

        if ((string) $proposal->status !== 'proposed') {
            throw ExtractionRefused::because(sprintf(
                'This proposal is %s, so there is nothing to confirm.%s',
                (string) $proposal->status,
                (string) $proposal->status === 'unavailable'
                    ? ' No engine read it, so there are no figures to agree with.'
                    : '',
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($proposal, $userId, $corrections, $billId): ExtractionProposal {
            $corrected = 0;

            foreach ($proposal->fields as $field) {
                $value = $corrections[(string) $field->field] ?? null;

                if ($value === null || $value === (string) $field->proposed_value) {
                    continue;
                }

                $field->update([
                    'corrected_value' => $value,
                    'corrected_at' => now(),
                    'corrected_by' => $userId,
                ]);

                $corrected++;
            }

            $proposal->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
                'bill_id' => $billId,
            ]);

            /*
             | The template's counters, updated once per confirmation.
             |
             | Which is what makes a consistently misread format visible -
             | and the reason they are counters rather than a rate computed
             | on read: the fields of a confirmed proposal are frequently
             | pruned, and a derived rate would fall to zero as they went,
             | reading as an engine that improved.
             */
            if ($proposal->extraction_template_id !== null) {
                DB::connection('tenant')->table('extraction_templates')
                    ->where('id', (string) $proposal->extraction_template_id)
                    ->update([
                        'proposals' => DB::raw('proposals + 1'),
                        'fields_proposed' => DB::raw(sprintf('fields_proposed + %d', $proposal->fields->count())),
                        'fields_corrected' => DB::raw(sprintf('fields_corrected + %d', $corrected)),
                        'last_seen_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $this->audit->record(
                category: 'exchange',
                action: 'extraction.confirmed',
                subjectType: 'inbound_document',
                subjectId: (string) $proposal->inbound_document_id,
                subjectLabel: (string) $proposal->source,
                after: [
                    'proposal_id' => (string) $proposal->getKey(),
                    'fields' => $proposal->fields->count(),
                    'corrected' => $corrected,
                    'bill_id' => $billId,
                    'engine' => $proposal->engine,
                ],
                entityId: (string) $proposal->entity_id,
            );

            return $proposal->refresh();
        });
    }

    /**
     * Reject a proposal, with a reason.
     *
     * @throws ExtractionRefused
     */
    public function reject(ExtractionProposal $proposal, string $userId, string $reason): ExtractionProposal
    {
        if (\strlen(trim($reason)) <= 5) {
            throw ExtractionRefused::because(
                'Rejecting a reading needs a reason. "Wrong" with nothing behind it tells nobody '.
                'whether the engine misread the document or the document was not an invoice.',
            );
        }

        $proposal->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejected_by' => $userId,
            'rejection_reason' => trim($reason),
        ]);

        $this->audit->record(
            category: 'exchange',
            action: 'extraction.rejected',
            subjectType: 'inbound_document',
            subjectId: (string) $proposal->inbound_document_id,
            subjectLabel: (string) $proposal->source,
            after: ['proposal_id' => (string) $proposal->getKey()],
            reason: trim($reason),
            entityId: (string) $proposal->entity_id,
        );

        return $proposal->refresh();
    }

    /**
     * Accuracy per template - PF-142's fourth criterion.
     *
     * Worst first, because the question is which format to fix. A format
     * that is consistently misread is one to ask the supplier about - "can
     * you send this by Peppol" - and a list ordered by name hides it among
     * the ones that read perfectly.
     *
     * @return list<array<string, mixed>>
     */
    public function accuracy(string $entityId): array
    {
        $rows = DB::connection('tenant')->table('extraction_templates as t')
            ->leftJoin('parties as p', 'p.id', '=', 't.party_id')
            ->where('t.entity_id', $entityId)
            ->where('t.fields_proposed', '>', 0)
            /*
             | A deterministic base order, beneath the sort below.
             |
             | Without one PostgreSQL returns the rows in whatever order the
             | plan produces, and it is free to change between runs on the
             | same data - which makes a check of the sort pass by luck.
             | Measured three times: removing the sort left the suite green
             | because the unordered rows came back worst-first, then
             | because a base order by `key` happened to as well, and then
             | because `last_seen_at` is the same instant for every row -
             | `now()` is fixed for the duration of a request, so a
             | timestamp written twice in one call is written twice with one
             | value.
             |
             | By id, which is a ULID and therefore creation order. The
             | point of the base order is determinism rather than meaning:
             | it is what stops the sort below being the only thing between
             | this report and an order that changes between runs, and what
             | stops pagination over the same data skipping rows.
             */
            ->orderBy('t.id')
            ->select([
                't.id', 't.key', 't.label', 't.proposals',
                't.fields_proposed', 't.fields_corrected', 't.last_seen_at',
                'p.legal_name as supplier',
            ])
            ->get()
            ->map(static function (object $row): array {
                $out = (array) $row;

                $out['corrected_share'] = (string) BigDecimal::of((string) $row->fields_corrected)
                    ->dividedBy((string) $row->fields_proposed, 4, \Brick\Math\RoundingMode::HalfUp)
                    ->multipliedBy('100');

                return $out;
            })
            ->all();

        usort($rows, static fn (array $a, array $b): int => BigDecimal::of($b['corrected_share'])
            ->compareTo(BigDecimal::of($a['corrected_share'])));

        return $rows;
    }

    /** The fields somebody has to look at on a proposal. */
    public function needingReview(ExtractionProposal $proposal): array
    {
        return $proposal->fields
            ->filter(static fn (ExtractionField $field): bool => (bool) $field->needs_review)
            ->map(static fn (ExtractionField $field): array => [
                'field' => (string) $field->field,
                'proposed' => $field->proposed_value,
                'confidence' => $field->confidence === null ? null : (string) $field->confidence,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{field: string, value: ?string, confidence: float}>  $fields
     * @param  array{name: string, version: string}|null  $engine
     */
    private function record(
        InboundDocument $document,
        ?ExtractionTemplate $template,
        string $source,
        string $status,
        array $fields,
        ?array $engine,
        ?string $note,
    ): ExtractionProposal {
        return DB::connection('tenant')->transaction(function () use (
            $document, $template, $source, $status, $fields, $engine, $note,
        ): ExtractionProposal {
            /*
             | One proposal per document, replaced rather than duplicated.
             |
             | A second reading is a replacement: two proposals would be two
             | sets of figures with no answer to which a person confirmed.
             */
            $proposal = ExtractionProposal::query()->updateOrCreate(
                ['inbound_document_id' => (string) $document->getKey()],
                [
                    'entity_id' => (string) $document->entity_id,
                    'extraction_template_id' => $template === null ? null : (string) $template->getKey(),
                    'source' => $source,
                    'status' => $status,
                    'engine' => $engine === null ? null : $engine['name'],
                    'engine_version' => $engine === null ? null : $engine['version'],
                    'note' => $note,
                ],
            );

            ExtractionField::query()
                ->where('extraction_proposal_id', (string) $proposal->getKey())
                ->delete();

            $threshold = BigDecimal::of(self::REVIEW_BELOW);

            foreach ($fields as $field) {
                $confidence = BigDecimal::of((string) $field['confidence']);

                ExtractionField::create([
                    'entity_id' => (string) $document->entity_id,
                    'extraction_proposal_id' => (string) $proposal->getKey(),
                    'field' => (string) $field['field'],
                    'proposed_value' => $field['value'],
                    'confidence' => (string) $confidence,
                    /*
                     | Flagged below the threshold, and the flag is stored.
                     |
                     | Computed on read it would move the first time somebody
                     | changed the threshold, and a reviewer who confirmed a
                     | field that is now flagged would look careless rather
                     | than consistent.
                     */
                    'needs_review' => $confidence->isLessThan($threshold),
                ]);
            }

            return $proposal->refresh()->load('fields');
        });
    }

    /** The template a document's format belongs to, per supplier. */
    private function template(InboundDocument $document, string $key): ?ExtractionTemplate
    {
        return ExtractionTemplate::query()->firstOrCreate(
            [
                'entity_id' => (string) $document->entity_id,
                'party_id' => $document->supplier_party_id === null
                    ? null
                    : (string) $document->supplier_party_id,
                'key' => $key,
            ],
            [
                'label' => sprintf('%s via %s', $key, (string) $document->channel),
                'last_seen_at' => Carbon::now(),
            ],
        );
    }
}
