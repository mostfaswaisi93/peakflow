<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exchange\Builders\SemanticInvoiceBuilder;
use App\Exchange\Extraction\DocumentExtraction;
use App\Exchange\Extraction\EmbeddedXml;
use App\Exchange\Extraction\ExtractionField;
use App\Exchange\Extraction\ExtractionProposal;
use App\Exchange\Extraction\ExtractionRefused;
use App\Exchange\Extraction\OpticalReader;
use App\Exchange\Extraction\OpticalReaderUnavailable;
use App\Exchange\Inbound\InboundDocument;
use App\Exchange\Inbound\InboundDocuments;
use App\Exchange\Serializers\Ubl\UblSerializer;
use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Sales\Services\Issuer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Document extraction - PF-142.
 *
 * ## The one that matters most is a routing decision
 *
 * A Factur-X PDF looks like every other PDF invoice, and an extraction
 * pipeline that starts with OCR never asks whether the figures are already in
 * the file - exactly. The result is a bill whose totals are nearly right,
 * which is worse than one that is obviously wrong.
 *
 * ## There is no OCR engine here, and the checks say so rather than pretend
 *
 * A fake reader returning plausible figures would make this suite assert the
 * behaviour of something that does not exist, and the first real engine would
 * have to match a fiction. So the checks that need a reading use a **stated
 * fake**, declared as one, and the checks about the absence use the real
 * `UnavailableOpticalReader`.
 *
 * Everything around the engine is where the risk is, and all of it is
 * testable with no engine: a proposal is not a value, a confidence belongs to
 * a guess, a field below the threshold is flagged, no extracted bill posts
 * without a confirmation, and a consistently misread format is visible.
 */
final class ExtractionRules
{
    use \Tests\Support\Concerns\EntersBills;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $static = [
            'an embedded invoice is extracted, compressed or not' => $this->extractsEmbedded(),
            'a PDF with no embedded invoice yields nothing' => $this->noEmbedded(),
            'bytes that are not a PDF are not searched' => $this->notAPdf(),
            'a stream under an unknown filter is refused' => $this->unknownFilter(),
            'a stray endstream does not derail the search' => $this->strayEndstream(),
            'a stream ends where its length says, not where a newline is' => $this->declaredLength(),
        ];

        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return [...$static, 'extraction (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();

            return [...$static, ...$this->runEachInASavepoint([
                'a structured document is never routed to OCR' => fn (): array => $this->neverOcrAStructuredDocument(),
                'a scan is routed to OCR, which is honest about being absent' => fn (): array => $this->scanIsRoutedToOcr(),
                'an absent engine is recorded as absent, with the reason' => fn (): array => $this->absentEngine(),
                'an unreadable proposal cannot be confirmed' => fn (): array => $this->confirmingNothing(),
                'an embedded reading carries no confidence' => fn (): array => $this->exactCarriesNoConfidence(),
                'and an OCR reading must carry one' => fn (): array => $this->ocrMustCarryConfidence(),
                'a field below the threshold is flagged for review' => fn (): array => $this->lowConfidenceIsFlagged(),
                'the flag is stored, not derived from today\'s threshold' => fn (): array => $this->flagIsStored(),
                'a proposal proposes: the values are not asserted' => fn (): array => $this->proposalIsNotAValue(),
                'no extracted bill exists without a confirmation' => fn (): array => $this->billNeedsAConfirmation(),
                'a confirmation is audited' => fn (): array => $this->confirmationIsAudited(),
                'a correction is kept beside what was proposed' => fn (): array => $this->correctionIsKept(),
                'a rejection needs a reason' => fn (): array => $this->rejectionNeedsAReason(),
                'accuracy is tracked per template, worst first' => fn (): array => $this->accuracy(),
                'a second reading replaces the first' => fn (): array => $this->secondReadingReplaces(),
            ])];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function extractsEmbedded(): array
    {
        $violations = [];
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Invoice><ID>INV-1</ID></Invoice>';
        $extractor = new EmbeddedXml();

        foreach ([false, true] as $compressed) {
            $found = $extractor->from($this->pdfCarrying($xml, $compressed));

            if ($found !== $xml) {
                $violations[] = sprintf(
                    'a %s embedded invoice came back as %s. A Factur-X PDF carries the figures exactly, '.
                    'and an extraction pipeline that cannot find them sends the picture of them to OCR',
                    $compressed ? 'FlateDecode' : 'stored',
                    $found === null ? 'nothing' : Str::limit($found, 40),
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function noEmbedded(): array
    {
        /*
         | A PDF with a stream that is NOT an invoice.
         |
         | Measured: with a PDF carrying no streams at all, a bite that
         | removed the subtype and file-name filter changed nothing - there
         | was nothing for it to wrongly accept. An ordinary page content
         | stream is what every scanned invoice actually contains.
         */
        /*
         | A content stream containing a hex string.
         |
         | Measured: with a stream of plain drawing operators, a bite that
         | removed the subtype filter still returned null - the "it has to
         | look like XML" guard caught it, so the filter was not what the
         | check was testing. `<FEFF...>` is how a PDF writes a Unicode
         | string, and it appears in most real documents.
         */
        $content = "BT /F1 12 Tf <0041006E> Tj ET";

        $pdf = "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Page /Contents 2 0 R >>\nendobj\n"
            .'2 0 obj\n<< /Length '.\strlen($content)." >>\nstream\n".$content."\nendstream\nendobj\n"
            ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $found = (new EmbeddedXml())->from($pdf);

        if ($found !== null) {
            return [sprintf(
                'a PDF whose only stream is page content produced %s. Most PDF invoices carry no XML, '.
                'and that is what the OCR fallback is for - taking any stream would hand a parser the '.
                'drawing instructions for a picture of an invoice',
                Str::limit($found, 40),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function notAPdf(): array
    {
        $violations = [];
        $extractor = new EmbeddedXml();

        /*
         | An HTML email body carrying both keywords with markup between.
         |
         | A body is an inbox item in its own right - PF-140 keeps them - and
         | this is the shape that gets furthest: the bytes between the two
         | keywords are markup, so the "it has to look like XML" guard does
         | not refuse them.
         |
         | What refuses them is the missing stream dictionary, and that is
         | worth being precise about: the `%PDF-` test at the top of `from()`
         | is an early return rather than the control. Removing it leaves the
         | behaviour unchanged, because a stream with no `<<` above it
         | carries no subtype and no file name - so this check asserts the
         | outcome and does not claim the early return is what produces it.
         */
        $body = '<html><body><p>stream</p><p>Invoice attached</p><p>endstream</p></body></html>';

        if ($extractor->from($body) !== null) {
            $violations[] = 'an HTML email body carrying the words "stream" and "endstream" with '.
                'markup between them came back as though it were an embedded invoice';
        }

        if (EmbeddedXml::looksLikeAPdf($body)) {
            $violations[] = 'an HTML document is reported as a PDF, so the router would send it to '.
                'the embedded-XML route and the optical one would never see it';
        }

        if (! EmbeddedXml::looksLikeAPdf("%PDF-1.7\n%%EOF\n")) {
            $violations[] = 'a PDF is not reported as one, so every Factur-X invoice would be routed '.
                'to OCR - the exact failure PF-142 is about';
        }

        return $violations;
    }

    /** @return list<string> */
    private function unknownFilter(): array
    {
        $xml = '<?xml version="1.0"?><Invoice><ID>INV-1</ID></Invoice>';

        /*
         | A stream labelled as XML under a filter nobody can decode.
         |
         | `/Crypt` means the file is encrypted and `/DCTDecode` means
         | somebody has labelled a JPEG as an invoice. Guessing at either
         | hands a parser bytes that are not a document.
         */
        $pdf = "%PDF-1.7\n"
            ."1 0 obj\n<< /Type /EmbeddedFile /Subtype /text#2Fxml /Filter /DCTDecode /Length "
            .\strlen($xml)." >>\nstream\n".$xml."\nendstream\nendobj\n"
            ."trailer\n<< >>\n%%EOF\n";

        if ((new EmbeddedXml())->from($pdf) !== null) {
            return [
                'a stream under an unknown filter was read as though it were plain. `/Crypt` means the '.
                'file is encrypted and `/DCTDecode` means somebody has labelled a JPEG as an invoice',
            ];
        }

        return [];
    }

    /**
     * A stream whose own last byte is a carriage return.
     *
     * Measured, and the reason this check exists: trimming a trailing EOL
     * instead of honouring `/Length` ate that byte. The chunk ends CR LF, a
     * `\r\n` strip removes both, the deflate stream is one byte short, it
     * inflates to nothing - and the document reads as a PDF with no invoice
     * in it and goes to OCR. The exact failure this class exists to prevent,
     * on a file that was perfectly valid.
     *
     * It is not a contrived shape: roughly one compressed stream in 256 ends
     * with that byte, so it is one Factur-X invoice in 256 sent to an engine
     * that guesses.
     *
     * @return list<string>
     */
    private function declaredLength(): array
    {
        $violations = [];

        /*
         | Search for a payload whose compression ends with CR.
         |
         | Bounded, and the check says so if it cannot find one rather than
         | passing quietly on a case it never built.
         */
        /*
         | Found by varying the payload's LENGTH, not its content.
         |
         | The last byte of a zlib stream is the low byte of the Adler-32
         | checksum, and over a set of near-identical short payloads it
         | takes only a handful of values - twenty thousand tries over
         | changing digits found none ending in CR. Changing the length
         | walks the checksum properly, and 211 characters of padding is the
         | first that lands on one.
         */
        $payload = null;

        for ($n = 1; $n < 4000; $n++) {
            $candidate = '<?xml version="1.0"?><Invoice><ID>'.str_repeat('A', $n).'</ID></Invoice>';

            if (str_ends_with((string) gzcompress($candidate, 6), "\r")) {
                $payload = $candidate;

                break;
            }
        }

        if ($payload === null) {
            return ['no payload compressing to a trailing carriage return was found, so this check '.
                'did not exercise the case it is about'];
        }

        $found = (new EmbeddedXml())->from($this->pdfCarrying($payload, true));

        if ($found !== $payload) {
            $violations[] = sprintf(
                'a stream whose last byte is a carriage return yielded %s. Trimming a trailing EOL '.
                'eats that byte, the deflate stream is one short, it inflates to nothing, and a '.
                'perfectly valid Factur-X invoice is sent to an engine that guesses',
                $found === null ? 'nothing' : Str::limit($found, 40),
            );
        }

        return $violations;
    }

    /**
     * A malformed file with `endstream` before any stream.
     *
     * `endstream` contains `stream`, so a search for the next stream can
     * land inside it - and the keyword that follows is then read as data.
     * This reads a file a stranger sent, and "malformed" is a shape they
     * control.
     *
     * @return list<string>
     */
    private function strayEndstream(): array
    {
        $xml = '<?xml version="1.0"?><Invoice><ID>INV-STRAY</ID></Invoice>';

        $pdf = "%PDF-1.7\n"
            ."1 0 obj\n<< /Type /Catalog >>\nendstream\nendobj\n"
            ."2 0 obj\n<< /Type /EmbeddedFile /Subtype /text#2Fxml /Length ".\strlen($xml)
            ." >>\nstream\n".$xml."\nendstream\nendobj\n"
            ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $found = (new EmbeddedXml())->from($pdf);

        if ($found !== $xml) {
            return [sprintf(
                'a file with a stray endstream before the real one yielded %s. The keyword contains '.
                '"stream", so a search for the next stream lands inside it and reads the keyword that '.
                'follows as data',
                $found === null ? 'nothing' : Str::limit($found, 40),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function neverOcrAStructuredDocument(): array
    {
        $violations = [];
        $xml = $this->realInvoiceXml();

        $document = $this->received($this->pdfCarrying($xml, true), 'application/pdf');

        /*
         | A reader that records being called.
         |
         | Which is the only way to check "never sent to OCR": asserting on
         | the proposal's source would pass even if the engine had been
         | called and its answer thrown away, and the cost of an OCR call is
         | the point - it is a guess where a fact was available.
         */
        $spy = new class implements OpticalReader {
            public bool $called = false;

            public function identify(): array
            {
                return ['name' => 'a reader that should not be called', 'version' => '0'];
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function read(string $bytes, string $contentType): array
            {
                $this->called = true;

                return [['field' => 'total', 'value' => '1.00', 'confidence' => 0.4]];
            }
        };

        $extraction = new DocumentExtraction(
            new EmbeddedXml(),
            $spy,
            app(\App\Support\Audit\AuditTrail::class),
        );

        $route = $extraction->routeFor($document, $this->pdfCarrying($xml, true));

        if (($route['route'] ?? null) !== 'embedded_xml') {
            $violations[] = sprintf(
                'a PDF carrying an embedded invoice routes to %s. The figures are in the document '.
                'exactly, and running optical recognition over the picture of them is choosing a guess '.
                'over a fact',
                (string) ($route['route'] ?? 'nothing'),
            );
        }

        if (($route['xml'] ?? null) !== $xml) {
            $violations[] = 'the route did not carry the embedded XML, so the exact figures are lost '.
                'and the caller has nothing to read';
        }

        $proposal = $extraction->extract($document, $this->pdfCarrying($xml, true));

        if ($spy->called) {
            $violations[] = 'the optical reader was called for a document carrying embedded XML. A '.
                'Factur-X PDF looks like every other PDF invoice, and a pipeline that starts with OCR '.
                'never asks - the result is a bill whose totals are nearly right';
        }

        if (! $proposal->isExact()) {
            $violations[] = sprintf('the proposal records its source as %s', (string) $proposal->source);
        }

        if ($proposal->fields->isNotEmpty()) {
            $violations[] = 'an exact reading produced proposal fields, which invites somebody to '.
                '"confirm" figures that were never in question';
        }

        return $violations;
    }

    /** @return list<string> */
    private function scanIsRoutedToOcr(): array
    {
        $document = $this->received("%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n%%EOF\n", 'application/pdf');

        $route = app(DocumentExtraction::class)->routeFor(
            $document,
            "%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n%%EOF\n",
        );

        if (($route['route'] ?? null) !== 'optical') {
            return [sprintf(
                'a PDF with no embedded invoice routes to %s. There is no other way to read it, and '.
                'the honest answer is a route that proposes rather than asserts',
                (string) ($route['route'] ?? 'nothing'),
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function absentEngine(): array
    {
        $violations = [];
        $bytes = "%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n%%EOF\n";
        $document = $this->received($bytes, 'application/pdf');

        $proposal = app(DocumentExtraction::class)->extract($document, $bytes);

        if (! $proposal->isUnavailable()) {
            $violations[] = sprintf(
                'with no engine installed the proposal is %s. "Nothing could be read from this '.
                'document" and "no engine is installed" look identical in a queue and mean opposite '.
                'things: the first sends somebody to re-scan a legible invoice',
                (string) $proposal->status,
            );
        }

        if ($proposal->note === null || ! str_contains((string) $proposal->note, 'deployment')) {
            $violations[] = sprintf(
                'the proposal does not say why nothing was read: "%s"',
                (string) ($proposal->note ?? 'nothing'),
            );
        }

        /*
         | And the real reader refuses rather than returning nothing.
         |
         | An empty result would be indistinguishable from a document with
         | no legible figures.
         */
        try {
            app(OpticalReader::class)->read($bytes, 'application/pdf');

            $violations[] = 'the unavailable reader returned a result instead of refusing';
        } catch (OpticalReaderUnavailable) {
            // Expected.
        }

        return $violations;
    }

    /** @return list<string> */
    private function confirmingNothing(): array
    {
        $bytes = "%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n%%EOF\n";
        $document = $this->received($bytes, 'application/pdf');
        $proposal = app(DocumentExtraction::class)->extract($document, $bytes);

        try {
            app(DocumentExtraction::class)->confirm($proposal, $this->actorId());

            return [
                'a proposal nothing read was confirmed. There are no figures to agree with, and a '.
                'confirmation of nothing is a bill somebody will later believe was checked',
            ];
        } catch (ExtractionRefused) {
            return [];
        }
    }

    /** @return list<string> */
    private function exactCarriesNoConfidence(): array
    {
        $xml = $this->realInvoiceXml();
        $document = $this->received($this->pdfCarrying($xml, true), 'application/pdf');
        $proposal = app(DocumentExtraction::class)->extract($document, $this->pdfCarrying($xml, true));

        /*
         | Straight at the table, as a later feature might.
         |
         | The source is on the proposal and the confidence is on the field,
         | so a CHECK cannot see across the two - which is why this is a
         | trigger and why it is worth checking.
         */
        try {
            ExtractionField::create([
                'entity_id' => $this->entityId,
                'extraction_proposal_id' => (string) $proposal->getKey(),
                'field' => 'total',
                'proposed_value' => '1150.00',
                'confidence' => '1.0000',
            ]);

            return [
                'a field read from embedded XML was given a confidence. The figures are in the document '.
                'exactly, and a confidence of 1.0 beside a fact is how a reviewer learns to ignore the '.
                'column',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'carries no confidence')) {
                return ['the confidence was refused by something other than its trigger: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function ocrMustCarryConfidence(): array
    {
        $proposal = $this->ocrProposal([
            ['field' => 'total', 'value' => '1150.00', 'confidence' => 0.97],
        ]);

        try {
            ExtractionField::create([
                'entity_id' => $this->entityId,
                'extraction_proposal_id' => (string) $proposal->getKey(),
                'field' => 'supplier_name',
                'proposed_value' => 'Gulf Components Trading Co.',
                'confidence' => null,
            ]);

            return [
                'a field read by OCR was recorded with no confidence. A reading of a picture stored as '.
                'a plain value is a guess nobody can tell from a fact two screens later',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'needs a confidence')) {
                return ['the field was refused by something other than its trigger: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function lowConfidenceIsFlagged(): array
    {
        $violations = [];

        $proposal = $this->ocrProposal([
            ['field' => 'total', 'value' => '1150.00', 'confidence' => 0.99],
            ['field' => 'supplier_number', 'value' => 'GC-4471', 'confidence' => 0.42],
            ['field' => 'document_date', 'value' => '2026-05-18', 'confidence' => 0.88],
        ]);

        $flagged = array_column(app(DocumentExtraction::class)->needingReview($proposal), 'field');
        sort($flagged);

        if ($flagged !== ['document_date', 'supplier_number']) {
            $violations[] = sprintf(
                'the fields flagged for review are [%s], and the two below the threshold are the '.
                'supplier number at 0.42 and the date at 0.88. A reviewer shown every field reads none '.
                'of them',
                implode(', ', $flagged),
            );
        }

        $confident = $proposal->fields->firstWhere('field', 'total');

        if ($confident !== null && (bool) $confident->needs_review) {
            $violations[] = 'a field read at 0.99 is flagged for review, so the flag means nothing';
        }

        return $violations;
    }

    /** @return list<string> */
    private function flagIsStored(): array
    {
        $proposal = $this->ocrProposal([
            ['field' => 'total', 'value' => '1150.00', 'confidence' => 0.95],
        ]);

        $field = $proposal->fields->first();

        if ((bool) $field->needs_review) {
            return ['a field at 0.95 was flagged under a 0.90 threshold'];
        }

        /*
         | The threshold moves, and the stored flag does not.
         |
         | A flag computed on read would move with it - and a reviewer who
         | confirmed a field that is now flagged would look careless rather
         | than consistent.
         */
        config(['extraction.review_below' => '0.99']);

        $reread = ExtractionField::query()->find((string) $field->getKey());

        config(['extraction.review_below' => '0.90']);

        if ((bool) $reread->needs_review) {
            return [
                'raising the threshold re-flagged a field that was already reviewed. The flag is a '.
                'record of what somebody was asked to look at, not a view over today\'s setting',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function proposalIsNotAValue(): array
    {
        $violations = [];

        $proposal = $this->ocrProposal([
            ['field' => 'total', 'value' => '1150.00', 'confidence' => 0.6],
        ]);

        if ((string) $proposal->status !== 'proposed') {
            $violations[] = sprintf('a fresh reading is %s rather than proposed', (string) $proposal->status);
        }

        if ($proposal->bill_id !== null) {
            $violations[] = 'a reading produced a bill on its own. The point of an extraction pipeline '.
                'is to save typing, not to remove the decision';
        }

        $values = $proposal->values();

        if (($values['total'] ?? null) !== '1150.00') {
            $violations[] = 'the proposal does not expose what it read';
        }

        return $violations;
    }

    /** @return list<string> */
    private function billNeedsAConfirmation(): array
    {
        $proposal = $this->ocrProposal([
            ['field' => 'total', 'value' => '1150.00', 'confidence' => 0.95],
        ]);

        $bill = $this->enterBill();

        try {
            DB::connection('tenant')->table('extraction_proposals')
                ->where('id', (string) $proposal->getKey())
                ->update(['bill_id' => (string) $bill->getKey()]);

            return [
                'the database accepted a proposal naming a bill with no confirmation behind it. A '.
                'machine reading of a picture that posted itself is a payment nobody authorised',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'confirmed_by_somebody')) {
                return ['the bill was refused by something other than its constraint: '.$e->getMessage()];
            }

            return [];
        }
    }

    /** @return list<string> */
    private function confirmationIsAudited(): array
    {
        $violations = [];

        $proposal = $this->ocrProposal([
            ['field' => 'total', 'value' => '1150.00', 'confidence' => 0.95],
        ]);

        $bill = $this->enterBill();

        $confirmed = app(DocumentExtraction::class)->confirm(
            $proposal,
            $this->actorId(),
            ['total' => '1150.00'],
            (string) $bill->getKey(),
        );

        if (! $confirmed->isConfirmed() || $confirmed->confirmed_by === null) {
            $violations[] = 'a confirmed proposal does not record who confirmed it';
        }

        $audited = DB::connection('tenant')->table('audit_log')
            ->where('action', 'extraction.confirmed')
            ->where('subject_id', (string) $proposal->inbound_document_id)
            ->count();

        if ($audited === 0) {
            $violations[] = 'the confirmation reached no audit record. "Who agreed that this is what '.
                'the document says" is the question an extracted bill raises, and it has to be '.
                'answerable without reading the code';
        }

        return $violations;
    }

    /** @return list<string> */
    private function correctionIsKept(): array
    {
        $violations = [];

        $proposal = $this->ocrProposal([
            ['field' => 'total', 'value' => '1150.00', 'confidence' => 0.55],
        ]);

        $confirmed = app(DocumentExtraction::class)->confirm(
            $proposal,
            $this->actorId(),
            ['total' => '1510.00'],
        );

        $field = $confirmed->fields->firstWhere('field', 'total');

        if ((string) $field->proposed_value !== '1150.00') {
            $violations[] = 'the correction overwrote what the engine proposed, so nothing records '.
                'that it was misread - and the accuracy figure the next check needs has no input';
        }

        if ((string) $field->corrected_value !== '1510.00') {
            $violations[] = 'the correction was not kept';
        }

        if ($field->corrected_by === null) {
            $violations[] = 'the correction does not record who made it';
        }

        if (($confirmed->values()['total'] ?? null) !== '1510.00') {
            $violations[] = 'the bill would be built from the proposed value rather than the corrected '.
                'one, which is the whole distinction a proposal exists to draw';
        }

        return $violations;
    }

    /** @return list<string> */
    private function rejectionNeedsAReason(): array
    {
        $violations = [];

        $proposal = $this->ocrProposal([
            ['field' => 'total', 'value' => '1150.00', 'confidence' => 0.3],
        ]);

        try {
            app(DocumentExtraction::class)->reject($proposal, $this->actorId(), 'wrong');

            $violations[] = 'a reading was rejected with "wrong", which tells nobody whether the engine '.
                'misread the document or the document was not an invoice';
        } catch (ExtractionRefused) {
            // Expected.
        }

        $rejected = app(DocumentExtraction::class)->reject(
            $proposal,
            $this->actorId(),
            'the scan is of page two only, so the totals are not on it',
        );

        if ((string) $rejected->status !== 'rejected') {
            $violations[] = 'a rejected reading does not say so';
        }

        return $violations;
    }

    /** @return list<string> */
    private function accuracy(): array
    {
        $violations = [];

        /*
         | Two templates: one read cleanly, one corrected every time.
         |
         | The question the report answers is which format to fix, and a
         | list ordered by name hides the bad one among the good ones.
         */
        $good = $this->ocrProposal(
            [['field' => 'total', 'value' => '1150.00', 'confidence' => 0.98]],
            templateKey: 'reads-cleanly',
        );

        app(DocumentExtraction::class)->confirm($good, $this->actorId(), ['total' => '1150.00']);

        $bad = $this->ocrProposal(
            [
                ['field' => 'total', 'value' => '1150.00', 'confidence' => 0.5],
                ['field' => 'supplier_number', 'value' => 'GC-447I', 'confidence' => 0.4],
            ],
            templateKey: 'always-misread',
        );

        app(DocumentExtraction::class)->confirm($bad, $this->actorId(), [
            'total' => '1510.00',
            'supplier_number' => 'GC-4471',
        ]);

        $report = app(DocumentExtraction::class)->accuracy($this->entityId);

        if (\count($report) < 2) {
            return [sprintf('the accuracy report holds %d template(s) and two were read', \count($report))];
        }

        if ((string) $report[0]['key'] !== 'always-misread') {
            $violations[] = sprintf(
                'the report puts %s first. A format that is consistently misread is one to ask the '.
                'supplier about, and a list ordered by name hides it among the ones that read '.
                'perfectly',
                (string) $report[0]['key'],
            );
        }

        if (! BigDecimal::of((string) $report[0]['corrected_share'])->isEqualTo('100.0000')) {
            $violations[] = sprintf(
                'the misread template reports %s%% of its fields corrected, and both of them were',
                (string) $report[0]['corrected_share'],
            );
        }

        $clean = null;

        foreach ($report as $row) {
            if ((string) $row['key'] === 'reads-cleanly') {
                $clean = $row;
            }
        }

        if ($clean !== null && ! BigDecimal::of((string) $clean['corrected_share'])->isZero()) {
            $violations[] = sprintf(
                'a template nobody corrected reports %s%% corrected',
                (string) $clean['corrected_share'],
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function secondReadingReplaces(): array
    {
        $document = $this->received("%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n%%EOF\n", 'application/pdf');

        $first = $this->ocrProposal(
            [['field' => 'total', 'value' => '1150.00', 'confidence' => 0.5]],
            document: $document,
        );

        $second = $this->ocrProposal(
            [['field' => 'total', 'value' => '1510.00', 'confidence' => 0.9]],
            document: $document,
        );

        if ((string) $first->getKey() !== (string) $second->getKey()) {
            return [
                'a second reading of one document produced a second proposal. Two would be two sets of '.
                'figures with no answer to which a person confirmed',
            ];
        }

        if (($second->values()['total'] ?? null) !== '1510.00') {
            return ['the second reading did not replace the first one\'s fields'];
        }

        return [];
    }

    // ---- Helpers -------------------------------------------------------

    /**
     * A PDF carrying an embedded invoice, built to the specification.
     *
     * Hand-built because no PDF library is installable here, and stated as
     * such: it exercises the structure the extractor walks - a stream, a
     * filter, a subtype and a file name - and it is not a real Factur-X
     * file. A real one would be better and is PF-125's business.
     */
    private function pdfCarrying(string $xml, bool $compressed): string
    {
        $data = $compressed ? (string) gzcompress($xml, 6) : $xml;

        return "%PDF-1.7\n"
            ."1 0 obj\n<< /Type /Catalog /Names 3 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /EmbeddedFile /Subtype /text#2Fxml"
            .($compressed ? ' /Filter /FlateDecode' : '')
            .' /Length '.\strlen($data)." >>\nstream\n".$data."\nendstream\nendobj\n"
            ."3 0 obj\n<< /EmbeddedFiles << /Names [(factur-x.xml) 2 0 R] >> >>\nendobj\n"
            ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** A real document's XML, so the embedded case carries a real invoice. */
    private function realInvoiceXml(): string
    {
        $invoice = app(Issuer::class)->issue(
            $this->draft(),
            $this->entity(),
            $this->actorId(),
            $this->decision,
            $this->terms,
        );

        $semantic = app(SemanticInvoiceBuilder::class)->build($invoice);
        $serializer = app(UblSerializer::class);

        return $serializer->render($serializer->tree($semantic), $semantic);
    }

    private function received(string $bytes, string $contentType): InboundDocument
    {
        return app(InboundDocuments::class)->receive(
            entity: $this->entity(),
            bytes: $bytes,
            channel: 'email',
            senderAddress: 'invoices@gulf-components.example',
            contentType: $contentType,
        );
    }

    /**
     * A proposal from a **stated fake** reader.
     *
     * Declared as a fake rather than dressed up as an engine: the checks it
     * serves are about what happens around a reading - the confidence, the
     * flag, the confirmation, the accuracy - and none of them is about
     * whether an engine can read a picture. A stub that returned plausible
     * figures would make this suite assert the behaviour of something that
     * does not exist.
     *
     * @param  list<array{field: string, value: ?string, confidence: float}>  $fields
     */
    private function ocrProposal(
        array $fields,
        ?InboundDocument $document = null,
        string $templateKey = 'a-scan',
    ): ExtractionProposal {
        $document ??= $this->received(
            "%PDF-1.4\n1 0 obj\n<< /Type /Page /Id (".Str::random(8).") >>\nendobj\n%%EOF\n",
            'application/pdf',
        );

        $fake = new class($fields) implements OpticalReader {
            /** @param list<array{field: string, value: ?string, confidence: float}> $fields */
            public function __construct(private readonly array $fields) {}

            public function identify(): array
            {
                return ['name' => 'a stated fake, for the checks around a reading', 'version' => '1'];
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function read(string $bytes, string $contentType): array
            {
                return $this->fields;
            }
        };

        $extraction = new DocumentExtraction(
            new EmbeddedXml(),
            $fake,
            app(\App\Support\Audit\AuditTrail::class),
        );

        return $extraction->extract(
            $document,
            "%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n%%EOF\n",
            $templateKey,
        );
    }
}
