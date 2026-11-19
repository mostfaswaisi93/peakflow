<?php

declare(strict_types=1);

namespace App\Exchange\Archive;

use App\Exchange\Profiles\JurisdictionProfile;
use App\Support\Audit\AuditTrail;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keep the bytes that were sent - PF-129.
 *
 * ## What is archived, and what is not
 *
 * The artefacts. The sent XML, the authority's cleared XML where there is
 * one, the signature, the receipt, the PDF, the QR payload. Not the data
 * they were built from, which the ledger and the document tables already
 * hold - and not a promise to rebuild them.
 *
 * **A re-rendered document is not an archive copy.** Five years from now the
 * font has changed, the template has changed, a rounding policy has changed,
 * and the hash the recipient holds matches nothing. The only copy that can
 * be reconciled against theirs is the one that was sent.
 *
 * ## Retention is computed once and enforced in the database
 *
 * `retain_until` comes from the profile's declared period at the moment of
 * archiving, with the basis in words beside it. A trigger refuses a delete
 * before that date, and refuses any change to the bytes at all.
 *
 * That includes a tenant exercising a right to erasure. The obligation is
 * the tax authority's and the tenant cannot waive it on the authority's
 * behalf - and a system that deleted on request and said nothing is the
 * worst of the three options available: the tenant believes the document is
 * gone, the authority believes it is retained, and nobody finds out until
 * one of them asks. {@see retentionNoticeFor()} is what the product says
 * instead.
 *
 * ## The manifest hashes the LIST
 *
 * Each artefact's own hash proves its bytes. An artefact removed from
 * storage *and* from the table together would leave every remaining hash
 * correct, and only a hash over the list notices that something is gone. So
 * a period is sealed with a hash over its ordered artefact list, and the
 * manifest itself is immutable - one that can be resealed always matches
 * whatever is there now, which proves nothing about what was there.
 */
final class Archive
{
    /** The artefact kinds, in the order a manifest lists them. */
    /**
     * The artefacts an archive holds.
     *
     * `received_xml` is apart from `sent_xml` deliberately - PF-141. The two
     * answer different questions: what we sent them, and what they actually
     * sent us as opposed to what our parser made of it. A dispute about an
     * inbound invoice is settled by the bytes that arrived, and one kind for
     * both would make the uniqueness rule below treat our copy and their
     * original as rival answers to the same question.
     */
    public const KINDS = ['sent_xml', 'received_xml', 'cleared_xml', 'signature', 'receipt', 'pdf', 'qr'];

    /**
     * The kinds the archive holds that nothing in the application writes yet.
     *
     * ## Why an absent call site is declared rather than left to be noticed
     *
     * `received_xml` is written by `InboundDocuments`, and that is the only
     * place in `app/` that archives anything. Every other kind is a kind the
     * archive accepts, stores, hashes, retains and refuses to let anybody
     * delete - and which no code path produces, because the thing that
     * produces it is not built: there is no transport, no cryptographic
     * stamp, no clearance call and no PDF/A pipeline.
     *
     * That is a legitimate state and it is also exactly the state that reads
     * as finished work. An artefact kind in an array, a retention period, a
     * WORM trigger and a manifest all exist for `receipt`, and a reader
     * reasonably concludes that receipts are archived. So each one names the
     * task that will write it, and `EInvoicingCoverageRules` asserts the
     * list both ways: a kind here with a call site in `app/` fails, and a
     * kind with no call site and no entry here fails. The day PF-122 archives
     * an AS4 receipt, deleting the line below is part of the change rather
     * than something somebody might notice - PF-129.
     *
     * @var array<string, string>
     */
    public const AWAITING_A_CALL_SITE = [
        'sent_xml' => 'PF-122 and PF-124 - nothing transmits a document yet, so there are as yet no '.
            '"bytes that were sent". The round trip is exercised by the archive checks, which serialize '.
            'through the profile\'s own serializer and archive that string.',
        'cleared_xml' => 'PF-124 - the authority\'s cleared copy, which is the legal document. It needs '.
            'onboarding, a production CSID and the clearance API.',
        'signature' => 'PF-124 - the cryptographic stamp, with the key in a KMS or HSM.',
        'receipt' => 'PF-122 - the AS4 receipt, which is the evidence the document was delivered.',
        'pdf' => 'PF-102 and PF-125 - the PDF/A-3 pipeline, whose toolchain is not installable here.',
        'qr' => 'PF-123 builds the TLV QR payload and puts it in the document; archiving it separately '.
            'belongs with the submission that sends it, so PF-124.',
    ];

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Store an artefact's exact bytes.
     *
     * Idempotent on `(subject, kind)`: archiving the same artefact twice is
     * what a retried job does, and the second attempt must not produce a
     * second candidate for "the bytes that were sent".
     *
     * @throws ArchiveRefused if the same subject and kind already hold different bytes
     */
    public function store(
        string $entityId,
        string $subjectType,
        string $subjectId,
        string $kind,
        string $bytes,
        JurisdictionProfile $profile,
        string $contentType,
        ?Carbon $asOf = null,
        ?string $periodLabel = null,
    ): array {
        if (! \in_array($kind, self::KINDS, true)) {
            throw ArchiveRefused::unknownKind($kind, self::KINDS);
        }

        if ($bytes === '') {
            throw ArchiveRefused::because('There are no bytes to archive, and an empty artefact is not '.
                'the document that was sent.');
        }

        $hash = hash('sha256', $bytes);
        $at = ($asOf ?? Carbon::now())->copy();

        $existing = DB::connection('tenant')->table('archived_artefacts')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('kind', $kind)
            ->first();

        if ($existing !== null) {
            if ((string) $existing->content_hash !== $hash) {
                throw ArchiveRefused::alreadyArchived($kind, (string) $existing->content_hash, $hash);
            }

            return (array) $existing;
        }

        $path = $this->pathFor($entityId, $kind, $hash);
        $this->disk()->put($path, $bytes);

        $row = [
            'id' => (string) Str::ulid(),
            'entity_id' => $entityId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'kind' => $kind,
            'profile' => $profile->key(),
            'period_label' => $periodLabel ?? $at->format('Y-m'),
            'content_hash' => $hash,
            'disk' => (string) config('archive.disk', 'archive'),
            'path' => $path,
            'byte_size' => \strlen($bytes),
            'content_type' => $contentType,
            'archived_at' => $at,
            'retain_until' => $at->copy()->addYears($profile->retentionYears())->toDateString(),
            'retention_basis' => $this->basisFor($profile, $at),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('tenant')->table('archived_artefacts')->insert($row);

        $this->audit->record(
            category: 'exchange',
            action: 'archive.stored',
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $kind,
            after: [
                'content_hash' => $hash,
                'bytes' => \strlen($bytes),
                'profile' => $profile->key(),
                'retain_until' => $row['retain_until'],
            ],
            entityId: $entityId,
        );

        return $row;
    }

    /**
     * Read an archived artefact, with its hash checked.
     *
     * @throws ArchiveRefused
     */
    public function read(string $entityId, string $subjectType, string $subjectId, string $kind): string
    {
        $row = DB::connection('tenant')->table('archived_artefacts')
            ->where('entity_id', $entityId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('kind', $kind)
            ->first();

        if ($row === null) {
            throw ArchiveRefused::because(sprintf(
                'No %s is archived for %s %s.',
                $kind,
                $subjectType,
                $subjectId,
            ));
        }

        $disk = $this->disk((string) $row->disk);

        if (! $disk->exists((string) $row->path)) {
            throw ArchiveRefused::missing((string) $row->content_hash, (string) $row->path);
        }

        $bytes = (string) $disk->get((string) $row->path);
        $actual = hash('sha256', $bytes);

        if ($actual !== (string) $row->content_hash) {
            throw ArchiveRefused::diverged((string) $row->content_hash, $actual);
        }

        return $bytes;
    }

    /**
     * Seal a period's manifest.
     *
     * @throws ArchiveRefused if the period is already sealed
     */
    public function seal(string $entityId, string $periodLabel, ?string $sealedBy = null): array
    {
        $existing = DB::connection('tenant')->table('archive_manifests')
            ->where('entity_id', $entityId)
            ->where('period_label', $periodLabel)
            ->first();

        if ($existing !== null) {
            throw ArchiveRefused::because(sprintf(
                'The archive for %s is already sealed, and a manifest that can be resealed always '.
                'matches whatever is in the archive now - which proves nothing about what was there.',
                $periodLabel,
            ));
        }

        $artefacts = DB::connection('tenant')->table('archived_artefacts')
            ->where('entity_id', $entityId)
            ->where('period_label', $periodLabel)
            ->orderBy('subject_type')
            ->orderBy('subject_id')
            ->orderBy('kind')
            ->get();

        $lines = [];
        $bytes = 0;

        foreach ($artefacts as $artefact) {
            /*
             | One line per artefact, in a fixed order.
             |
             | The hash is over this text, so the ordering has to be total
             | and stable: a manifest whose lines come back in a different
             | order hashes differently, and a verification would report
             | divergence on an archive that is intact.
             */
            $lines[] = sprintf(
                '%s %s %s %s %d',
                (string) $artefact->content_hash,
                (string) $artefact->subject_type,
                (string) $artefact->subject_id,
                (string) $artefact->kind,
                (int) $artefact->byte_size,
            );

            $bytes += (int) $artefact->byte_size;
        }

        $manifest = implode("\n", $lines);

        $row = [
            'id' => (string) Str::ulid(),
            'entity_id' => $entityId,
            'period_label' => $periodLabel,
            'artefact_count' => \count($lines),
            'byte_size' => $bytes,
            'manifest_hash' => hash('sha256', $manifest),
            'manifest' => $manifest,
            'sealed_at' => now(),
            'sealed_by' => $sealedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('tenant')->table('archive_manifests')->insert($row);

        $this->audit->record(
            category: 'exchange',
            action: 'archive.sealed',
            subjectType: 'archive_period',
            subjectId: $entityId,
            subjectLabel: $periodLabel,
            after: [
                'artefacts' => \count($lines),
                'bytes' => $bytes,
                'manifest_hash' => $row['manifest_hash'],
            ],
            entityId: $entityId,
        );

        return $row;
    }

    /**
     * Re-checksum a period against storage and against its manifest.
     *
     * @return array{
     *     period: string, checked: int, missing: list<string>, diverged: list<string>,
     *     manifest: string|null, removed: list<string>, added: list<string>,
     * }
     */
    public function verify(string $entityId, string $periodLabel): array
    {
        $report = [
            'period' => $periodLabel,
            'checked' => 0,
            'missing' => [],
            'diverged' => [],
            'manifest' => null,
            'removed' => [],
            'added' => [],
        ];

        $artefacts = DB::connection('tenant')->table('archived_artefacts')
            ->where('entity_id', $entityId)
            ->where('period_label', $periodLabel)
            ->orderBy('content_hash')
            ->get();

        foreach ($artefacts as $artefact) {
            $report['checked']++;
            $disk = $this->disk((string) $artefact->disk);

            if (! $disk->exists((string) $artefact->path)) {
                $report['missing'][] = (string) $artefact->content_hash;

                continue;
            }

            if (hash('sha256', (string) $disk->get((string) $artefact->path)) !== (string) $artefact->content_hash) {
                $report['diverged'][] = (string) $artefact->content_hash;
            }
        }

        $manifest = DB::connection('tenant')->table('archive_manifests')
            ->where('entity_id', $entityId)
            ->where('period_label', $periodLabel)
            ->first();

        if ($manifest === null) {
            return $report;
        }

        /*
         | The set, not just the files.
         |
         | An artefact deleted from storage AND from the table would leave
         | every remaining hash correct. Comparing the sealed list against
         | what is there now is the only thing that notices.
         */
        $sealed = array_filter(explode("\n", (string) $manifest->manifest));
        $sealedHashes = array_map(static fn (string $line): string => explode(' ', $line)[0], $sealed);
        $presentHashes = $artefacts->pluck('content_hash')->map(static fn ($h): string => (string) $h)->all();

        $report['removed'] = array_values(array_diff($sealedHashes, $presentHashes));
        $report['added'] = array_values(array_diff($presentHashes, $sealedHashes));
        $report['manifest'] = hash('sha256', implode("\n", $sealed)) === (string) $manifest->manifest_hash
            ? 'intact'
            : 'diverged';

        return $report;
    }

    /**
     * Restore a sample and check it - the drill.
     *
     * ## Why a drill and not a report
     *
     * A backup nobody has restored is a hope. The hashes in the table prove
     * the rows agree with themselves; the drill proves the bytes come back
     * out of storage and still hash to what the row says - which is the
     * thing a tax inspector's request actually exercises, five years later,
     * after a bucket migration nobody remembers.
     *
     * The sample is random, so a drill that passed last month does not
     * guarantee it checked the same artefacts.
     */
    public function drill(string $entityId, int $sampleSize = 10): array
    {
        $artefacts = DB::connection('tenant')->table('archived_artefacts')
            ->where('entity_id', $entityId)
            ->inRandomOrder()
            ->limit(max(1, $sampleSize))
            ->get();

        $restored = 0;
        $diverged = [];
        $missing = [];

        foreach ($artefacts as $artefact) {
            $disk = $this->disk((string) $artefact->disk);

            if (! $disk->exists((string) $artefact->path)) {
                $missing[] = (string) $artefact->content_hash;

                continue;
            }

            $bytes = (string) $disk->get((string) $artefact->path);

            if (hash('sha256', $bytes) !== (string) $artefact->content_hash) {
                $diverged[] = (string) $artefact->content_hash;

                continue;
            }

            $restored++;
        }

        $outcome = $artefacts->isEmpty()
            ? 'empty'
            : ($diverged === [] && $missing === [] ? 'passed' : 'failed');

        $detail = $outcome === 'passed'
            ? null
            : trim(sprintf(
                '%s%s',
                $missing === [] ? '' : sprintf('missing: %s. ', implode(', ', $missing)),
                $diverged === [] ? '' : sprintf('diverged: %s.', implode(', ', $diverged)),
            ));

        $row = [
            'id' => (string) Str::ulid(),
            'entity_id' => $entityId,
            'ran_at' => now(),
            'sampled' => $artefacts->count(),
            'restored' => $restored,
            'diverged' => \count($diverged),
            'missing' => \count($missing),
            'outcome' => $outcome,
            'detail' => $detail === '' ? null : $detail,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('tenant')->table('archive_restore_drills')->insert($row);

        return $row;
    }

    /**
     * What the product says when a tenant asks for a document to be deleted.
     *
     * Not "deleted", and not silence. The notice names the date and the
     * basis, because the alternative - deleting on request and saying
     * nothing - leaves the tenant believing the document is gone and the
     * authority believing it is retained.
     *
     * @return array{retained: int, until: string|null, basis: list<string>, ar: string, en: string}
     */
    public function retentionNoticeFor(string $entityId, string $subjectType, string $subjectId): array
    {
        $artefacts = DB::connection('tenant')->table('archived_artefacts')
            ->where('entity_id', $entityId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('retain_until')
            ->get();

        if ($artefacts->isEmpty()) {
            return [
                'retained' => 0,
                'until' => null,
                'basis' => [],
                'en' => 'Nothing is archived for this document, so nothing is retained.',
                'ar' => 'لا يوجد أرشيف لهذا المستند، فلا شيء محتفظ به.',
            ];
        }

        $until = (string) $artefacts->first()->retain_until;
        $basis = $artefacts->pluck('retention_basis')->unique()->values()->all();

        return [
            'retained' => $artefacts->count(),
            'until' => $until,
            'basis' => array_map(static fn ($b): string => (string) $b, $basis),
            'en' => sprintf(
                'This document is retained until %s and cannot be deleted before then. %s The '.
                'obligation is the tax authority\'s, so it cannot be waived here.',
                $until,
                implode(' ', array_map(static fn ($b): string => (string) $b, $basis)),
            ),
            'ar' => sprintf(
                'هذا المستند محتفظ به حتى %s ولا يمكن حذفه قبل ذلك. الالتزام قانوني تجاه الجهة الضريبية، فلا يمكن التنازل عنه من هنا.',
                $until,
            ),
        ];
    }

    /** The archive path for an artefact, under the entity's own prefix. */
    public function pathFor(string $entityId, string $kind, string $hash): string
    {
        return sprintf(
            '%s/%s/%s/%s/%s',
            trim((string) config('archive.prefix', 'archive'), '/'),
            $entityId,
            $kind,
            substr($hash, 0, 2),
            $hash,
        );
    }

    /** The retention basis, in words somebody can argue with. */
    private function basisFor(JurisdictionProfile $profile, Carbon $at): string
    {
        return sprintf(
            '%d years from %s under the %s profile, which declares that period.',
            $profile->retentionYears(),
            $at->toDateString(),
            $profile->key(),
        );
    }

    private function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? (string) config('archive.disk', 'archive'));
    }
}
