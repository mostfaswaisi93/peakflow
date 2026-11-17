<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payment file, and which rulebook the bank reads - PF-176.
 *
 * ## The scheme is on the account, because the bank accepts the file
 *
 * Not on the entity and not in a global setting. An entity with a euro
 * account in Frankfurt and a riyal account in Riyadh sends SEPA from one
 * and a local file from the other, and a product with one hard-coded
 * format serves whichever customer it was written for.
 *
 * Nullable, with no default. An account whose scheme nobody declared
 * cannot produce a file, and the refusal names the schemes that exist. A
 * default would pick one, and the first time it picked wrong the file
 * would be built to the wrong rulebook, pass every check in this product
 * and be rejected by the bank - which is the failure this task exists to
 * remove.
 *
 * ## A file is a record of what was sent, not a cache
 *
 * It holds its own bytes. A file regenerated from the run later is a
 * different file - the run is frozen, so it would be identical, but
 * "would be identical" is an argument and the bytes are evidence.
 *
 * And it carries the run's `released_hash` as `authorised_hash`, so the
 * question "is this the file for what was approved" is a string
 * comparison rather than a reconstruction. PF-175 hashes the instruction
 * set precisely so that this can be checked.
 *
 * ## Downloadable once, and regeneration is visible
 *
 * A payment file is an instrument. Two copies in circulation is two
 * chances for somebody to upload the second one to the bank portal after
 * the first was already processed, and the bank's duplicate detection is
 * not something to rely on.
 *
 * So the first download is recorded, and a second one needs a new file
 * with a new hash and an audit entry. Nothing is deleted: the superseded
 * file stays, pointing at what replaced it, because "which file did we
 * actually send" is the question an investigation starts with.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->string('payment_scheme', 32)->nullable();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN bank_accounts.payment_scheme IS
                'Which rulebook this bank reads - a key in config/settlement.php. Null means no file can be produced, and the refusal names the schemes that exist: a default would be built to the wrong rulebook the first time it guessed - PF-176.';
        SQL);

        Schema::create('payment_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('run_id', 26);

            $table->string('scheme', 32);
            $table->string('format', 32);

            /*
             | The message id the bank will quote back.
             |
             | Unique per entity, because a bank rejecting a duplicate
             | message id rejects the whole file - and the usual cause is
             | a regeneration reusing the id of the file that was already
             | accepted.
             */
            $table->string('message_id', 35);
            $table->timestamp('created_at_message');

            $table->text('content');
            $table->char('sha256', 64);
            $table->integer('byte_count');

            /*
             | What the release authorised, carried alongside.
             |
             | PF-175 hashes the instruction set so that "is this the file
             | for what was approved" is a string comparison. Copying it
             | here is what makes that comparison possible once the run's
             | own row is one join away.
             */
            $table->char('authorised_hash', 64);

            $table->integer('instruction_count');
            $table->decimal('total', 21, 4);
            $table->char('currency', 3);

            $table->ulid('generated_by');
            $table->timestamp('generated_at');

            $table->timestamp('downloaded_at')->nullable();
            $table->ulid('downloaded_by')->nullable();

            $table->char('superseded_by_id', 26)->nullable();
            $table->text('superseded_reason')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'run_id']);
            $table->unique(['entity_id', 'message_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_files
                ADD CONSTRAINT payment_files_hashes_are_digests
                    CHECK (sha256 ~ '^[0-9a-f]{64}$' AND authorised_hash ~ '^[0-9a-f]{64}$'),

                ADD CONSTRAINT payment_files_are_not_empty
                    CHECK (byte_count > 0 AND instruction_count > 0 AND total > 0),

                /*
                 | A download is attributable, or it did not happen.
                 |
                 | "Downloaded, by nobody" is the state an import or a
                 | repaired row leaves, and it is the one state that makes
                 | the once-only rule unenforceable: the file reads as
                 | spent with nobody to ask about it.
                 */
                ADD CONSTRAINT payment_files_downloads_are_attributable
                    CHECK (
                        (downloaded_at IS NULL AND downloaded_by IS NULL)
                        OR (downloaded_at IS NOT NULL AND downloaded_by IS NOT NULL)
                    ),

                /*
                 | A superseded file says why.
                 |
                 | Regeneration is the thing an investigation asks about
                 | first - a second file exists, so which one went to the
                 | bank and why was there a second. A bare pointer answers
                 | the first half.
                 */
                ADD CONSTRAINT payment_files_supersession_is_explained
                    CHECK (
                        superseded_by_id IS NULL
                        OR (superseded_by_id <> id
                            AND length(btrim(coalesce(superseded_reason, ''))) >= 15)
                    );

            /*
             | One live file per run.
             |
             | A partial unique index rather than a trigger, because the
             | condition is exactly "not superseded" and the database can
             | hold that directly. Two live files for one run is two
             | instruments for one payment, and the bank's duplicate
             | detection is not something to rely on.
             */
            CREATE UNIQUE INDEX payment_files_one_live_file_per_run
                ON payment_files (run_id)
                WHERE superseded_by_id IS NULL;

            COMMENT ON TABLE payment_files IS
                'The bytes that were sent to the bank, with the hash of what the release authorised beside them - so "is this the file for what was approved" is a comparison rather than a reconstruction - PF-176.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('payment_files')");

        /*
         | A file belongs to a released run, enforced.
         |
         | A file for a prepared run is an instrument for money nobody
         | authorised. The state lives on another table, so this is a
         | trigger rather than a check constraint - and it fires on insert
         | only, because a released run cannot move afterwards.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_payment_file_follows_a_release()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                run_state text;
                run_number text;
                run_hash text;
            BEGIN
                SELECT r.state, r.number, r.released_hash
                  INTO run_state, run_number, run_hash
                  FROM payment_runs r
                 WHERE r.id = NEW.run_id;

                IF run_state IS NULL THEN
                    RAISE EXCEPTION
                        'There is no payment run % for this file to be the instruction of.',
                        NEW.run_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF run_state <> 'released' THEN
                    RAISE EXCEPTION
                        'Run % is %, not released, so a payment file for it would be an instruction for money nobody authorised. Release it first: that is where the second person, the factor and the check on what changed all happen.',
                        run_number, run_state
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 | And the file names what the release authorised.
                 |
                 | A file whose `authorised_hash` is not the run's own is a
                 | file built from something else - a stale read, a wrong
                 | id, a race with a regeneration. The comparison costs
                 | nothing here and is impossible to reconstruct later.
                 */
                IF run_hash IS NOT NULL AND NEW.authorised_hash <> run_hash THEN
                    RAISE EXCEPTION
                        'This file says it carries % but run % authorised %. A file built from anything other than the released instruction set is a file nobody approved.',
                        NEW.authorised_hash, run_number, run_hash
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_files_follow_a_release
                BEFORE INSERT ON payment_files
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_payment_file_follows_a_release();
        SQL);

        /*
         | And the bytes are frozen.
         |
         | Everything about a file that was sent is evidence. The download
         | columns and the supersession pointer are the only things that
         | may change afterwards, because they are facts about the file
         | rather than the file itself.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_payment_file_is_evidence()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.content <> OLD.content
                   OR NEW.sha256 <> OLD.sha256
                   OR NEW.authorised_hash <> OLD.authorised_hash
                   OR NEW.message_id <> OLD.message_id
                   OR NEW.run_id <> OLD.run_id
                   OR NEW.total <> OLD.total
                   OR NEW.instruction_count <> OLD.instruction_count THEN
                    RAISE EXCEPTION
                        'Payment file % is the record of what was sent to the bank. Its bytes, its hashes and what it pays cannot be changed - a corrected instruction is a new file, which supersedes this one and says why.',
                        OLD.message_id
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 | And a download happens once.
                 |
                 | Two copies in circulation is two chances for somebody to
                 | upload the second after the first was processed. Clearing
                 | the column to allow another is the shortcut this refuses.
                 */
                IF OLD.downloaded_at IS NOT NULL
                   AND (NEW.downloaded_at IS NULL OR NEW.downloaded_at <> OLD.downloaded_at) THEN
                    RAISE EXCEPTION
                        'Payment file % has already been handed over, on %. A second copy is a second instrument for one payment: generate a new file, which supersedes this one and leaves both on the record.',
                        OLD.message_id, OLD.downloaded_at
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_files_are_evidence
                BEFORE UPDATE ON payment_files
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_payment_file_is_evidence();

            CREATE OR REPLACE FUNCTION peakflow_a_payment_file_is_not_deleted()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'Payment file % cannot be deleted. "Which file did we actually send" is the question an investigation starts with, and a deleted row answers it with silence.',
                    OLD.message_id
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE TRIGGER payment_files_are_not_deleted
                BEFORE DELETE ON payment_files
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_payment_file_is_not_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payment_files_are_not_deleted ON payment_files;
            DROP TRIGGER IF EXISTS payment_files_are_evidence ON payment_files;
            DROP TRIGGER IF EXISTS payment_files_follow_a_release ON payment_files;
            DROP FUNCTION IF EXISTS peakflow_a_payment_file_is_not_deleted();
            DROP FUNCTION IF EXISTS peakflow_a_payment_file_is_evidence();
            DROP FUNCTION IF EXISTS peakflow_a_payment_file_follows_a_release();
        SQL);

        Schema::dropIfExists('payment_files');

        Schema::table('bank_accounts', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a forward-only migration. The column is added by this migration's own up(), so dropping it restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn('payment_scheme');
        });
    }
};
