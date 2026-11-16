<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use App\Support\Audit\AuditTrail;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Store a file, once, and let a document claim it - PF-073.
 *
 * ## The order of operations is the security property
 *
 * 1. Write the upload to a temporary path.
 * 2. **Sniff** the content type from the bytes.
 * 3. Refuse a type that is not permitted, or a name that disagrees with the
 *    content.
 * 4. Refuse anything over the cap.
 * 5. Hash the bytes. If the tenant already holds them, take a reference and
 *    delete the temporary file.
 * 6. Otherwise **scan**, store, and record the verdict.
 *
 * Scanning before the file is readable, rather than after it is stored, is
 * the whole arrangement: a scan that runs on a schedule tells you what you
 * have already been serving.
 *
 * ## Deduplication is per tenant, deliberately
 *
 * Two tenants uploading the same PDF get two objects. Sharing one would save
 * a few megabytes and let a tenant learn that another holds a particular
 * file by uploading it and watching the reference count - and the file a
 * tenant attaches is frequently one somebody else sent them.
 *
 * ## The path is derived from the hash, never from the name
 *
 * A declared name is user input. `../../.env` is a name, and a path built
 * from one is a path traversal. The name is kept for the download and the
 * path is `<prefix>/<hash[0..1]>/<hash>`, which also makes the directory
 * fan out instead of holding a million entries.
 */
final class AttachmentStore
{
    public function __construct(
        private readonly ContentTypeSniffer $sniffer,
        private readonly VirusScanner $scanner,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Take a local file into the store and attach it to a document.
     *
     * @param  string  $sourcePath  a local path; it is consumed
     *
     * @throws AttachmentRefused
     */
    public function attach(
        string $entityId,
        string $attachableType,
        string $attachableId,
        string $sourcePath,
        string $declaredName,
        ?string $declaredType = null,
        ?string $uploadedBy = null,
        ?string $note = null,
    ): array {
        if (! is_file($sourcePath)) {
            throw AttachmentRefused::because(sprintf('%s is not a readable file.', $sourcePath));
        }

        $this->assertTheNameIsAName($declaredName);

        $bytes = (int) filesize($sourcePath);
        $cap = (int) config('attachments.max_bytes', 25 * 1024 * 1024);

        if ($bytes <= 0) {
            throw AttachmentRefused::because('The file is empty, so there is nothing to attach.');
        }

        if ($bytes > $cap) {
            throw AttachmentRefused::tooLarge($bytes, $cap);
        }

        $sniffed = $this->sniffer->sniff($sourcePath);

        if (! $this->sniffer->isPermitted($sniffed)) {
            throw AttachmentRefused::typeNotPermitted($sniffed, $declaredType);
        }

        $mismatch = $this->sniffer->mismatch($declaredName, $sniffed);

        if ($mismatch !== null) {
            throw AttachmentRefused::because($mismatch);
        }

        $hash = hash_file('sha256', $sourcePath);

        if ($hash === false) {
            throw AttachmentRefused::because('The file could not be read to hash it.');
        }

        return DB::connection('tenant')->transaction(function () use (
            $entityId, $attachableType, $attachableId, $sourcePath, $declaredName,
            $declaredType, $uploadedBy, $note, $bytes, $sniffed, $hash,
        ): array {
            $blob = $this->blobFor($entityId, $hash, $sourcePath, $bytes, $sniffed);

            $attachment = [
                'id' => (string) Str::ulid(),
                'entity_id' => $entityId,
                'attachment_blob_id' => $blob['id'],
                'attachable_type' => $attachableType,
                'attachable_id' => $attachableId,
                'declared_name' => trim($declaredName),
                'declared_type' => $declaredType,
                'uploaded_by' => $uploadedBy,
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::connection('tenant')->table('attachments')->insert($attachment);

            DB::connection('tenant')->table('attachment_blobs')
                ->where('id', $blob['id'])
                ->increment('reference_count');

            $this->audit->record(
                category: 'documents',
                action: 'attachment.stored',
                subjectType: $attachableType,
                subjectId: $attachableId,
                subjectLabel: trim($declaredName),
                after: [
                    'content_hash' => $hash,
                    'content_type' => $sniffed,
                    'declared_type' => $declaredType,
                    'bytes' => $bytes,
                    'scan_result' => $blob['scan_result'],
                    'scanner' => $blob['scanner'],
                ],
                entityId: $entityId,
            );

            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }

            return [...$attachment, 'blob' => $blob];
        });
    }

    /**
     * The blob for these bytes: an existing one, or a newly scanned and
     * stored one.
     *
     * @return array<string, mixed>
     */
    private function blobFor(
        string $entityId,
        string $hash,
        string $sourcePath,
        int $bytes,
        string $sniffed,
    ): array {
        $existing = DB::connection('tenant')->table('attachment_blobs')
            ->where('entity_id', $entityId)
            ->where('content_hash', $hash)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            /*
             | The same bytes, already here and already scanned.
             |
             | Not rescanned: the verdict belongs to the bytes. Rescanning
             | would also mean the second attachment of a file could get a
             | different answer from the first, and nobody could say which
             | was right.
             */
            return (array) $existing;
        }

        $verdict = $this->scanner->scan($sourcePath);
        $path = $this->pathFor($entityId, $hash);

        $this->disk()->put($path, (string) file_get_contents($sourcePath));

        $blob = [
            'id' => (string) Str::ulid(),
            'entity_id' => $entityId,
            'content_hash' => $hash,
            'disk' => (string) config('attachments.disk', 'attachments'),
            'path' => $path,
            'byte_size' => $bytes,
            'content_type' => $sniffed,
            'scan_result' => $verdict->result,
            'scanned_at' => now(),
            'scanner' => $verdict->scanner,
            'scan_detail' => $verdict->detail,
            'reference_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('tenant')->table('attachment_blobs')->insert($blob);

        return $blob;
    }

    /**
     * Read a stored attachment's bytes.
     *
     * @throws AttachmentRefused if the file has not been found clean
     */
    public function read(string $entityId, string $attachmentId): string
    {
        $row = $this->blobOf($entityId, $attachmentId);

        if ((string) $row->scan_result !== 'clean') {
            throw AttachmentRefused::notClean((string) $row->scan_result, (string) ($row->scan_detail ?? ''));
        }

        /*
         | `exists()` first, because the disk is configured to THROW.
         |
         | `throw => true` is the right setting - a silent null from a read
         | is how a missing object becomes an empty file served to somebody -
         | and it means the caller has to ask first rather than treat the
         | return value as the answer.
         */
        $disk = $this->disk((string) $row->disk);

        if (! $disk->exists((string) $row->path)) {
            throw AttachmentRefused::because(
                'The stored object is missing. The row says it is there and the storage does not have '.
                'it, which is a divergence `attachments:verify` reports.',
            );
        }

        $contents = (string) $disk->get((string) $row->path);

        /*
         | The hash, recomputed on every read.
         |
         | Cheap against the cost of serving a file that is not the one that
         | was scanned. Storage corrupts, a restore puts back the wrong
         | version, and a scan verdict applies to bytes rather than to a
         | path.
         */
        $actual = hash('sha256', $contents);

        if ($actual !== (string) $row->content_hash) {
            throw AttachmentRefused::because(sprintf(
                'The stored object hashes to %s and the row says %s. The scan verdict applies to the '.
                'bytes that were scanned, and these are not them.',
                $actual,
                (string) $row->content_hash,
            ));
        }

        return $contents;
    }

    /**
     * Detach a document's claim, and remove the object when nothing holds it.
     *
     * The reference count is what makes one tenant's delete safe: four bills
     * carrying the same contract leave three claims and the object stays.
     */
    public function detach(string $entityId, string $attachmentId): bool
    {
        return (bool) DB::connection('tenant')->transaction(function () use ($entityId, $attachmentId): bool {
            $attachment = DB::connection('tenant')->table('attachments')
                ->where('entity_id', $entityId)
                ->where('id', $attachmentId)
                ->first();

            if ($attachment === null) {
                return false;
            }

            $blob = DB::connection('tenant')->table('attachment_blobs')
                ->where('id', (string) $attachment->attachment_blob_id)
                ->lockForUpdate()
                ->first();

            DB::connection('tenant')->table('attachments')->where('id', $attachmentId)->delete();

            if ($blob === null) {
                return true;
            }

            $remaining = max(0, (int) $blob->reference_count - 1);

            DB::connection('tenant')->table('attachment_blobs')
                ->where('id', (string) $blob->id)
                ->update(['reference_count' => $remaining, 'updated_at' => now()]);

            if ($remaining === 0) {
                $this->disk((string) $blob->disk)->delete((string) $blob->path);

                DB::connection('tenant')->table('attachment_blobs')
                    ->where('id', (string) $blob->id)
                    ->delete();
            }

            $this->audit->record(
                category: 'documents',
                action: 'attachment.detached',
                subjectType: (string) $attachment->attachable_type,
                subjectId: (string) $attachment->attachable_id,
                subjectLabel: (string) $attachment->declared_name,
                after: ['content_hash' => (string) $blob->content_hash, 'references_left' => $remaining],
                entityId: $entityId,
            );

            return true;
        });
    }

    /**
     * A short-lived signed URL for a download.
     *
     * ## Why the URL expires and why it carries a signature
     *
     * An attachment is a tax document: a bank statement, a contract, a
     * supplier's invoice. A permanent URL is one that ends up in a browser
     * history, a chat message and a support ticket, and the file stays
     * readable from all three for as long as it exists.
     *
     * The route serves it with `Content-Disposition: attachment` from a
     * separate origin, so a stored file cannot execute against the
     * application's own origin and reach a session cookie.
     */
    public function signedUrl(
        string $entityId,
        string $attachmentId,
        ?int $seconds = null,
        ?string $tenantSlug = null,
    ): string {
        $row = $this->blobOf($entityId, $attachmentId);

        if ((string) $row->scan_result !== 'clean') {
            throw AttachmentRefused::notClean((string) $row->scan_result, (string) ($row->scan_detail ?? ''));
        }

        $seconds ??= (int) config('attachments.url_ttl_seconds', 300);

        /*
         | The tenant and the entity are in the path, inside the signature.
         |
         | The holder of the link has no session, and row-level security
         | reads nothing without an entity - so both travel in the URL, and
         | both are covered by the signature so neither can be edited.
         */
        $tenant = $tenantSlug ?? (string) (
            app()->bound('currentTenant')
                ? (app('currentTenant')->slug ?? app('currentTenant')->getKey())
                : ''
        );

        $path = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'attachments.download',
            now()->addSeconds($seconds),
            [
                'tenant' => $tenant,
                'entity' => $entityId,
                'attachment' => $attachmentId,
            ],
        );

        $origin = (string) config('attachments.origin', '');

        if ($origin === '') {
            return $path;
        }

        /*
         | Re-homed onto the download origin.
         |
         | The signature covers the path and the query, not the host, so
         | moving the host is safe - and serving a tenant's uploaded file
         | from the application's own origin is how a stored SVG reads a
         | session cookie.
         */
        $parts = parse_url($path);

        return rtrim($origin, '/').($parts['path'] ?? '/').
            (isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Re-hash every stored object against its row - `attachments:verify`.
     *
     * @return array{checked: int, missing: list<string>, diverged: list<string>, unscanned: list<string>}
     */
    public function verify(string $entityId): array
    {
        $report = ['checked' => 0, 'missing' => [], 'diverged' => [], 'unscanned' => []];

        $blobs = DB::connection('tenant')->table('attachment_blobs')
            ->where('entity_id', $entityId)
            ->orderBy('content_hash')
            ->get();

        foreach ($blobs as $blob) {
            $report['checked']++;

            if ((string) $blob->scan_result !== 'clean') {
                $report['unscanned'][] = (string) $blob->content_hash;
            }

            $disk = $this->disk((string) $blob->disk);

            if (! $disk->exists((string) $blob->path)) {
                $report['missing'][] = (string) $blob->content_hash;

                continue;
            }

            $contents = (string) $disk->get((string) $blob->path);

            if (hash('sha256', $contents) !== (string) $blob->content_hash) {
                $report['diverged'][] = (string) $blob->content_hash;
            }
        }

        return $report;
    }

    /** The storage path for a hash, under this tenant's own prefix. */
    public function pathFor(string $entityId, string $hash): string
    {
        return sprintf(
            '%s/%s/%s/%s',
            trim((string) config('attachments.prefix', 'attachments'), '/'),
            $entityId,
            substr($hash, 0, 2),
            $hash,
        );
    }

    private function blobOf(string $entityId, string $attachmentId): object
    {
        $row = DB::connection('tenant')->table('attachments as a')
            ->join('attachment_blobs as b', 'b.id', '=', 'a.attachment_blob_id')
            ->where('a.entity_id', $entityId)
            ->where('a.id', $attachmentId)
            ->select([
                'b.disk', 'b.path', 'b.content_hash', 'b.content_type',
                'b.scan_result', 'b.scan_detail', 'a.declared_name',
            ])
            ->first();

        if ($row === null) {
            throw AttachmentRefused::because(sprintf('No attachment %s exists for this entity.', $attachmentId));
        }

        return $row;
    }

    private function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? (string) config('attachments.disk', 'attachments'));
    }

    /** @throws AttachmentRefused */
    private function assertTheNameIsAName(string $declaredName): void
    {
        $name = trim($declaredName);

        if ($name === '' || $name === '.' || $name === '..') {
            throw AttachmentRefused::because('The file has no usable name.');
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw AttachmentRefused::because(sprintf(
                'The name "%s" contains a path separator. A name is user input, and a path built from '.
                'user input is a path traversal - which is why the stored path is derived from the '.
                'content hash and never from the name.',
                $name,
            ));
        }
    }
}
