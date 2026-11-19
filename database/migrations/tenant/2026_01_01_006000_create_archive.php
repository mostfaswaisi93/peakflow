<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The archive: the exact bytes that were sent - PF-129.
 *
 * ## A re-rendered document is not an archive copy
 *
 * Most jurisdictions require the original electronic document retained,
 * readable and unaltered, for five to eleven years. "Readable" is the easy
 * half. **Unaltered** means the bytes, and a system that keeps the data and
 * re-renders the PDF on request has kept a report about a document rather
 * than the document: the font changed, the template changed, the rounding
 * changed, and the hash the recipient holds no longer matches anything.
 *
 * So an archived artefact is a row with a content hash and an object in
 * storage, and the row is written at the moment of sending.
 *
 * ## Why retention is a column and not a policy somebody applies
 *
 * `retain_until` is computed when the artefact is archived, from the
 * profile's declared period. A trigger refuses a delete before that date -
 * including from an operator, including from a tenant exercising a right to
 * erasure - because the obligation is the tax authority's and the tenant
 * cannot waive it on the authority's behalf.
 *
 * A system that deleted on request and said nothing would be the worst of
 * the three options: the tenant believes the document is gone, the
 * authority believes it is retained, and nobody finds out until one of them
 * asks.
 *
 * @financial-not-applicable: the archive stores bytes and hashes, not
 * amounts. `entity_id` is declared and RLS applied because an archived
 * document is as entity-scoped as the document it came from.
  *
 * @online-safe Creates `archive_manifests`, `archive_restore_drills`, `archived_artefacts`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archived_artefacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            // What it belongs to, and which part of it this is.
            $table->string('subject_type', 64);
            $table->char('subject_id', 26);
            $table->string('kind', 24);

            $table->string('profile', 32);
            $table->string('period_label', 16);

            $table->char('content_hash', 64);
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->bigInteger('byte_size');
            $table->string('content_type', 128);

            $table->timestamp('archived_at');
            $table->date('retain_until');

            /*
             | The retention basis, in words.
             |
             | "Six years from 2026-05-18 under the Saudi VAT Implementing
             | Regulations" is what somebody reads when they ask why a
             | document cannot be deleted. A date with no reason is a date
             | somebody will argue with.
             */
            $table->string('retention_basis', 200);

            $table->timestamps();

            /*
             | One artefact of a kind per subject.
             |
             | A document has one sent XML, one receipt and one PDF. A second
             | row for the same kind would mean two candidates for "the bytes
             | that were sent", and nothing could say which the recipient
             | holds.
             */
            $table->unique(['subject_type', 'subject_id', 'kind']);
            $table->index(['entity_id', 'period_label']);
            $table->index(['entity_id', 'retain_until']);
        });

        Schema::create('archive_manifests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->string('period_label', 16);

            $table->integer('artefact_count');
            $table->bigInteger('byte_size');

            /*
             | The manifest hash covers the LIST, not the files.
             |
             | Each artefact's own hash proves its bytes. This proves the set:
             | an artefact removed from the archive and from the table
             | together would leave every remaining hash correct, and only a
             | hash over the list notices that something is gone.
             */
            $table->char('manifest_hash', 64);
            $table->text('manifest');

            $table->timestamp('sealed_at');
            $table->ulid('sealed_by')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'period_label']);
        });

        Schema::create('archive_restore_drills', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->timestamp('ran_at');
            $table->integer('sampled');
            $table->integer('restored');
            $table->integer('diverged');
            $table->integer('missing');
            $table->string('outcome', 16);
            $table->text('detail')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'ran_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE archived_artefacts
                ADD CONSTRAINT archived_artefacts_hash_is_hex
                    CHECK (content_hash ~ '^[0-9a-f]{64}$'),

                ADD CONSTRAINT archived_artefacts_size_is_positive
                    CHECK (byte_size > 0),

                ADD CONSTRAINT archived_artefacts_kind_is_known
                    CHECK (kind IN ('sent_xml', 'cleared_xml', 'signature', 'receipt', 'pdf', 'qr')),

                ADD CONSTRAINT archived_artefacts_retention_is_after_archiving
                    CHECK (retain_until > archived_at::date),

                ADD CONSTRAINT archived_artefacts_retention_basis_is_stated
                    CHECK (length(btrim(retention_basis)) > 20);

            ALTER TABLE archive_manifests
                ADD CONSTRAINT archive_manifests_hash_is_hex
                    CHECK (manifest_hash ~ '^[0-9a-f]{64}$');

            ALTER TABLE archive_restore_drills
                ADD CONSTRAINT archive_restore_drills_outcome_is_known
                    CHECK (outcome IN ('passed', 'failed', 'empty'));
        SQL);

        /*
         | WORM, in the database - PF-129.
         |
         | Object-lock is the storage layer's half and is a bucket setting
         | this environment cannot make. This is the half that can be
         | enforced here, and it is the half an operator would otherwise
         | bypass: a row deleted before its retention expires takes the only
         | record of what the bytes were, whatever the bucket says.
         |
         | An UPDATE to the bytes is refused too. An archive copy that can be
         | corrected is not one.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_refuse_archive_tampering()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.retain_until >= CURRENT_DATE THEN
                        RAISE EXCEPTION
                            'Archived artefact % (%) must be retained until %: %. The obligation is the '
                            'tax authority''s and a tenant cannot waive it on the authority''s behalf.',
                            OLD.id, OLD.kind, OLD.retain_until, OLD.retention_basis
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN OLD;
                END IF;

                IF NEW.content_hash <> OLD.content_hash
                    OR NEW.path <> OLD.path
                    OR NEW.byte_size <> OLD.byte_size
                    OR NEW.subject_id <> OLD.subject_id
                    OR NEW.kind <> OLD.kind THEN
                    RAISE EXCEPTION
                        'An archived artefact cannot be changed. An archive copy that can be corrected '
                        'is not an archive copy, and the recipient holds a hash of the original.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.retain_until < OLD.retain_until THEN
                    RAISE EXCEPTION
                        'Retention cannot be shortened: % was %, and moving it to % would make the '
                        'document deletable before the period it was archived under.',
                        OLD.id, OLD.retain_until, NEW.retain_until
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER archived_artefacts_are_worm
                BEFORE UPDATE OR DELETE ON archived_artefacts
                FOR EACH ROW EXECUTE FUNCTION peakflow_refuse_archive_tampering();
        SQL);

        /*
         | A sealed manifest is likewise immutable.
         |
         | It is the evidence that a period's archive was complete when it
         | was sealed. A manifest that can be resealed is one that always
         | matches whatever is there now, which proves nothing.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_refuse_manifest_tampering()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'Archive manifest % for % is sealed. A manifest that can be resealed always matches '
                    'whatever is in the archive now, which proves nothing about what was there.',
                    OLD.id, OLD.period_label
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE TRIGGER archive_manifests_are_sealed
                BEFORE UPDATE OR DELETE ON archive_manifests
                FOR EACH ROW EXECUTE FUNCTION peakflow_refuse_manifest_tampering();
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE archived_artefacts IS
                'The exact bytes that were sent. A re-rendered PDF is not an archive copy - PF-129.';
            COMMENT ON TABLE archive_manifests IS
                'A hash over the LIST of a period''s artefacts, so a removal is detectable - PF-129.';
            COMMENT ON TABLE archive_restore_drills IS
                'A restore drill is a scheduled task, not a hope - PF-129.';
        SQL);

        foreach (['archived_artefacts', 'archive_manifests', 'archive_restore_drills'] as $table) {
            DB::unprepared(sprintf('SELECT peakflow_apply_entity_rls(%s)', "'".$table."'"));
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS archive_manifests_are_sealed ON archive_manifests');
        DB::unprepared('DROP TRIGGER IF EXISTS archived_artefacts_are_worm ON archived_artefacts');
        DB::unprepared('DROP FUNCTION IF EXISTS peakflow_refuse_manifest_tampering()');
        DB::unprepared('DROP FUNCTION IF EXISTS peakflow_refuse_archive_tampering()');

        Schema::dropIfExists('archive_restore_drills');
        Schema::dropIfExists('archive_manifests');
        Schema::dropIfExists('archived_artefacts');
    }
};
