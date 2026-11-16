<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments, deduplicated by content hash - PF-073.
 *
 * ## Two tables, because a file and a reference to it are different things
 *
 * `attachment_blobs` is the stored object: one row per distinct content
 * hash, with the bytes' size, the sniffed content type, the scan result and
 * a reference count. `attachments` is a document's claim on one: the same
 * contract PDF attached to four bills is one object and four rows.
 *
 * Deduplication is **within a tenant**, which is the whole reason the
 * reference count is in the tenant database and the storage prefix carries
 * the tenant. Sharing one object across tenants would save a few megabytes
 * and let one tenant learn that another holds a particular file by uploading
 * it and watching the reference count - and the file a tenant uploads is
 * frequently one they received from somebody else.
 *
 * ## The scan result is on the BLOB
 *
 * A file is scanned once, when it arrives, and the verdict belongs to the
 * bytes rather than to whoever attached them. The alternative - a verdict
 * per attachment - would mean the same bytes could be clean on one document
 * and unscanned on another.
 *
 * ## A file is not readable until it has been scanned
 *
 * `scan_result` starts as `pending` and the read path refuses anything that
 * is not `clean`. That ordering is the point: a scan that runs after the
 * file is downloadable is a scan that tells you what you already served.
 *
 * @financial-not-applicable: attachments carry no amounts. `entity_id` is
 * declared and RLS is applied because a document's attachment is as
 * entity-scoped as the document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_blobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | The hash is the identity.
             |
             | SHA-256 of the bytes, hex. It is what deduplication keys on,
             | what the integrity check recomputes, and what the storage
             | path is derived from - so a file whose bytes change has a
             | different identity rather than a corrupted one.
             */
            $table->char('content_hash', 64);

            $table->string('disk', 32);
            $table->string('path', 512);
            $table->bigInteger('byte_size');

            /*
             | SNIFFED, not declared - PF-073.
             |
             | The browser's content type is whatever the uploader's client
             | said, and an uploader who renames `payload.html` to
             | `invoice.pdf` gets `application/pdf` from every client there
             | is. This column holds what the bytes are.
             */
            $table->string('content_type', 128);

            $table->string('scan_result', 16)->default('pending');
            $table->timestamp('scanned_at')->nullable();
            $table->string('scanner', 32)->nullable();
            $table->text('scan_detail')->nullable();

            $table->integer('reference_count')->default(0);

            $table->timestamps();

            $table->unique(['entity_id', 'content_hash']);
            $table->index(['entity_id', 'scan_result']);
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('attachment_blob_id')->constrained('attachment_blobs')->restrictOnDelete();

            // What it is attached to: a polymorphic claim, because an
            // attachment belongs to an invoice, a bill, a party or a journal
            // entry and each of those is a different table.
            $table->string('attachable_type', 64);
            $table->char('attachable_id', 26);

            /*
             | The name the uploader gave, kept and never used as a path.
             |
             | It is what the download is called and what a person
             | recognises. It is not where the file is: a name is user input
             | and a path built from user input is a path traversal.
             */
            $table->string('declared_name', 255);
            $table->string('declared_type', 128)->nullable();

            $table->ulid('uploaded_by')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'attachable_type', 'attachable_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE attachment_blobs
                ADD CONSTRAINT attachment_blobs_hash_is_hex
                    CHECK (content_hash ~ '^[0-9a-f]{64}$'),

                ADD CONSTRAINT attachment_blobs_size_is_positive
                    CHECK (byte_size > 0),

                ADD CONSTRAINT attachment_blobs_scan_result_is_known
                    CHECK (scan_result IN ('pending', 'clean', 'infected', 'failed')),

                ADD CONSTRAINT attachment_blobs_scanned_is_attributable
                    CHECK (
                        (scan_result = 'pending' AND scanned_at IS NULL AND scanner IS NULL)
                        OR (scan_result <> 'pending' AND scanned_at IS NOT NULL AND scanner IS NOT NULL)
                    ),

                ADD CONSTRAINT attachment_blobs_reference_count_is_not_negative
                    CHECK (reference_count >= 0);

            ALTER TABLE attachments
                ADD CONSTRAINT attachments_declared_name_is_present
                    CHECK (length(btrim(declared_name)) > 0),

                ADD CONSTRAINT attachments_declared_name_has_no_path
                    CHECK (declared_name NOT LIKE '%/%' AND declared_name NOT LIKE '%\\%' AND declared_name <> '..');
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE attachment_blobs IS
                'One stored object per content hash, per entity. Deduplicated within a tenant only - PF-073.';
            COMMENT ON TABLE attachments IS
                'A document''s claim on a stored object. The declared name is what a download is called, never where the file is.';
            COMMENT ON CONSTRAINT attachments_declared_name_has_no_path ON attachments IS
                'A name is user input, and a path built from user input is a path traversal.';
        SQL);

        DB::unprepared('SELECT peakflow_apply_entity_rls(\'attachment_blobs\')');
        DB::unprepared('SELECT peakflow_apply_entity_rls(\'attachments\')');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('attachment_blobs');
    }
};
