<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numbering series: format, branch, and the gap record - PF-071.
 *
 * ## What was already here, and what was missing
 *
 * `number_series` existed with a prefix and a padding width, which can
 * express `INV-00001` and nothing else. Real requirements are not like that:
 * several jurisdictions mandate the year or the issuer inside the number, and
 * a prefix column ends up holding a date somebody has to remember to change
 * each January.
 *
 * So the series carries a format TEMPLATE, and the three things an auditor
 * actually asks for: the first number issued, the last, and whether there is
 * a gap between them.
 *
 * ## The format is frozen once the series has issued
 *
 * This is the part worth enforcing in the database.
 *
 * A series' promise is that its numbers are consecutive. Change the shape
 * mid-year - `INV-{seq:4}` to `INV-{YYYY}-{seq:4}` - and the sequence is
 * still consecutive in the counter and no longer looks it on the documents:
 * `INV-0087` is followed by `INV-2026-0088`, and nobody reading the file can
 * tell whether 88 documents are missing or none are.
 *
 * The counter itself must keep moving, so the trigger has to allow the one
 * column that changes constantly and refuse the five that must not.
 *
 * ## Why a branch is part of the key
 *
 * Where numbering is per branch, two branches issuing from one series
 * contend on one row - every invoice in the company waits behind every
 * other. Where it is not, a branch column left null keeps one series. The
 * unique key therefore includes it, and `coalesce` makes null a real value
 * for uniqueness rather than a wildcard that permits duplicates.
 *
 * @unsafe-migration-reviewed: adds columns to `number_series` and replaces
 * its unique index. The table holds one row per entity, document type and
 * year - tens of rows, not millions - so the index rebuild is instant. The
 * backfill is bounded by the same count. `dropColumn` appears only in
 * `down()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('number_series', function (Blueprint $table): void {
            /*
             | The format template, nullable for one statement only - it is
             | backfilled from the prefix and padding below and then made
             | NOT NULL, because a series with no format cannot issue.
             */
            $table->string('format', 64)->nullable();

            // Null means "this entity does not number per branch".
            $table->string('branch', 16)->nullable();

            /*
             | What an auditor asks for, in three columns.
             |
             | `next_number` alone cannot answer any of it: it says where the
             | series will go next, not where it started or whether anything
             | was skipped. A series whose counter is at 400 having issued
             | 250 documents is the question, and without `first_number` and
             | `issued_count` there is nothing to compare.
             */
            $table->bigInteger('first_number')->default(1);
            $table->bigInteger('last_issued_number')->nullable();
            $table->bigInteger('issued_count')->default(0);
            $table->timestamp('last_issued_at')->nullable();

            $table->timestamp('gap_checked_at')->nullable();
            $table->string('gap_status', 16)->nullable();
            $table->text('gap_detail')->nullable();
        });

        /*
         | Backfill the format from what the old columns meant.
         |
         | The allocator rendered `{prefix}{year}/{seq padded}`, so that is
         | the format those series already have. Deriving it rather than
         | defaulting everything to one template is what keeps numbers
         | already issued consistent with the numbers issued next.
         */
        DB::unprepared(<<<'SQL'
            UPDATE number_series
            SET format = prefix || '{YYYY}/{seq:' || padding::text || '}'
            WHERE format IS NULL;

            ALTER TABLE number_series
                ALTER COLUMN format SET NOT NULL;

            /*
             | The issuance record, derived from the counter.
             |
             | `next_number` is one past the last number allocated, so the
             | last issued number is a fact and not a guess. The count is
             | derived from the same counter, which means for these rows the
             | two legs of the gap check share a source.
             |
             | That does NOT make the check a tautology, because the third
             | leg does not: the gap report compares both against the number
             | of documents that actually exist, and that count comes from
             | the documents. A pre-migration crash between allocating a
             | number and writing its row still shows up, as a document
             | count short of the range.
             |
             | What is lost is detecting a counter that was itself wound
             | back before this migration. Nothing can recover that, and the
             | trigger below prevents it from here on.
             */
            UPDATE number_series
            SET last_issued_number = next_number - 1,
                issued_count = next_number - first_number
            WHERE next_number > 1
              AND last_issued_number IS NULL;
        SQL);

        DB::unprepared(<<<'SQL'
            /*
             | The key now includes the branch.
             |
             | `coalesce(branch, '')` because NULL is not equal to NULL in a
             | unique index: two series with a null branch would both be
             | permitted, which is precisely the duplicate this index exists
             | to prevent.
             */
            ALTER TABLE number_series
                DROP CONSTRAINT IF EXISTS number_series_entity_id_scope_key_year_unique;

            CREATE UNIQUE INDEX number_series_one_per_scope
                ON number_series (entity_id, scope, key, coalesce(year, 0), coalesce(branch, ''));

            ALTER TABLE number_series
                /*
                 | `untracked` is a status, not an omission.
                 |
                 | A series issues numbers for documents the gap report may
                 | not be able to count yet - only the journal is tracked so
                 | far. Reporting those as `gapped` was the first behaviour
                 | and it made the report 100% false positives on real data,
                 | which is how a report stops being run. Saying "a gap can
                 | neither be confirmed nor ruled out here" is the honest
                 | answer and is actionable in a way that a wrong one is not.
                 */
                ADD CONSTRAINT number_series_gap_status_known
                    CHECK (gap_status IS NULL
                           OR gap_status IN ('clean', 'gapped', 'unchecked', 'untracked')),

                ADD CONSTRAINT number_series_first_is_positive
                    CHECK (first_number >= 1),

                -- A counter that has fallen behind the last number issued
                -- means the next document reuses a number already on a
                -- customer's invoice.
                ADD CONSTRAINT number_series_counter_is_ahead
                    CHECK (last_issued_number IS NULL OR next_number > last_issued_number),

                ADD CONSTRAINT number_series_count_matches_issuance
                    CHECK ((last_issued_number IS NULL AND issued_count = 0)
                           OR (last_issued_number IS NOT NULL AND issued_count >= 1));
        SQL);

        $this->installFormatFreeze();

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN number_series.format IS
                'The template, e.g. INV-{entity}-{YYYY}-{seq:6}. FROZEN once the series has issued its first '
                'document: changing the shape mid-sequence makes consecutive numbers look like a gap, and '
                'nobody reading the file can tell which it is. See App\Support\Numbering\NumberFormat.';

            COMMENT ON COLUMN number_series.issued_count IS
                'How many documents this series has actually issued. Compared with last_issued_number minus '
                'first_number plus one to detect a gap - which is the one question an auditor always asks.';
        SQL);
    }

    /**
     * The format cannot change once the series has issued - PF-071.
     *
     * In the database, not in a form validator, because the series row is
     * reachable from a console session and a support script - and a format
     * changed there is discovered from a customer's invoice.
     */
    private function installFormatFreeze(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_reshaping_an_issued_series()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.last_issued_number IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.format IS DISTINCT FROM OLD.format THEN
                    RAISE EXCEPTION
                        'Series %/% has already issued up to %, so its format cannot change from "%" to "%". The numbers would stay consecutive in the counter and stop looking consecutive on the documents, and nobody reading the file could tell whether documents are missing. Start a new series instead.',
                        OLD.scope, OLD.key, OLD.last_issued_number, OLD.format, NEW.format
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.resets_annually IS DISTINCT FROM OLD.resets_annually THEN
                    RAISE EXCEPTION
                        'Series %/% has already issued, so its reset policy cannot change. Turning an annual reset on duplicates numbers already issued; turning it off leaves next year continuing a sequence the jurisdiction requires to restart.',
                        OLD.scope, OLD.key
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.first_number IS DISTINCT FROM OLD.first_number THEN
                    RAISE EXCEPTION
                        'Series %/% has already issued, so the number it started from is history and cannot be edited. The gap check compares against it.',
                        OLD.scope, OLD.key
                        USING ERRCODE = 'restrict_violation';
                END IF;

                /*
                 | The counter may only move FORWARD.
                 |
                 | Winding it back is the one edit that silently reissues a
                 | number already printed on a customer's invoice - two
                 | documents with one number, which is the failure the whole
                 | gapless-numbering mechanism exists to prevent.
                 */
                IF NEW.next_number < OLD.next_number THEN
                    RAISE EXCEPTION
                        'Series %/% cannot be wound back from % to %: the numbers in between are on documents that have been issued, and reissuing one of them puts two documents in the file with the same number.',
                        OLD.scope, OLD.key, OLD.next_number, NEW.next_number
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER number_series_shape_is_frozen
                BEFORE UPDATE ON number_series
                FOR EACH ROW
                EXECUTE FUNCTION refuse_reshaping_an_issued_series();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS number_series_shape_is_frozen ON number_series;
            DROP FUNCTION IF EXISTS refuse_reshaping_an_issued_series();

            DROP INDEX IF EXISTS number_series_one_per_scope;

            ALTER TABLE number_series
                DROP CONSTRAINT IF EXISTS number_series_gap_status_known,
                DROP CONSTRAINT IF EXISTS number_series_first_is_positive,
                DROP CONSTRAINT IF EXISTS number_series_counter_is_ahead,
                DROP CONSTRAINT IF EXISTS number_series_count_matches_issuance;

            ALTER TABLE number_series
                ADD CONSTRAINT number_series_entity_id_scope_key_year_unique
                    UNIQUE (entity_id, scope, key, year);
        SQL);

        Schema::table('number_series', function (Blueprint $table): void {
            $table->dropColumn([
                'format', 'branch', 'first_number', 'last_issued_number',
                'issued_count', 'last_issued_at', 'gap_checked_at', 'gap_status', 'gap_detail',
            ]);
        });
    }
};
