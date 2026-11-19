<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections to a filed return - PF-087.
 *
 * @financial
 *
 * ## An adjustment is a row, not an edit
 *
 * A filed return is a statement the business made. The correction is a
 * separate statement, made later, in the next open period, carrying a
 * reference to the period it corrects - which is what an authority expects to
 * see and what IAS 8 describes for an error corrected in a later period.
 *
 * Editing the filed return instead would destroy the only record of what was
 * submitted, and the business would be unable to answer a query about its own
 * filing. The trigger on `return_filings` makes that impossible; this table is
 * what to do instead.
 *
 * ## Two periods on every row
 *
 * `corrects_period_id` is the filed period the error is in.
 * `reported_in_period_id` is the open period the correction is reported in.
 *
 * Both, because the two questions have different answers and both get asked.
 * The authority's schedule asks "what are you correcting"; the return's own
 * arithmetic asks "what is in this period". A system holding only the second
 * produces a correction nobody can trace to a filing.
  *
 * @online-safe Creates `return_adjustments`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_adjustments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->char('jurisdiction', 2);

            $table->foreignUlid('corrects_period_id')->constrained('return_periods')->restrictOnDelete();
            $table->foreignUlid('reported_in_period_id')->constrained('return_periods')->restrictOnDelete();

            $table->string('box', 64);
            $table->char('currency', 3);

            // Signed: a correction can go either way, and a schedule that
            // only held absolute values would need a separate direction
            // column that somebody would eventually disagree with.
            $table->decimal('net_amount', 21, 4);
            $table->decimal('tax_amount', 21, 4);

            /*
             | A reason, and it is NOT NULL.
             |
             | The schedule is read by an authority, and "adjustment" is not a
             | reason. A correction with no explanation invites the question
             | the schedule exists to answer.
             */
            $table->string('reason', 500);

            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'reported_in_period_id']);
            $table->index(['entity_id', 'corrects_period_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE return_adjustments
                /*
                 | Long enough, and more than one word.
                 |
                 | A length test alone accepted "adjustment" - exactly ten
                 | characters - which is the word this rule exists to refuse.
                 | A single word is never an explanation, whatever its length.
                 */
                ADD CONSTRAINT return_adjustments_reason_is_present
                    CHECK (length(btrim(reason)) >= 20 AND position(' ' in btrim(reason)) > 0),

                -- A correction of nothing is not a correction. Both figures
                -- zero means somebody saved a form they had not filled in,
                -- and it would appear on a schedule an authority reads.
                ADD CONSTRAINT return_adjustments_changes_something
                    CHECK (net_amount <> 0 OR tax_amount <> 0),

                -- The two periods are different periods. An adjustment that
                -- corrects the period it is reported in is an ordinary
                -- document, not an adjustment.
                ADD CONSTRAINT return_adjustments_periods_differ
                    CHECK (corrects_period_id <> reported_in_period_id);

            COMMENT ON COLUMN return_adjustments.corrects_period_id IS
                'The FILED period the error is in - PF-087.';

            COMMENT ON COLUMN return_adjustments.reported_in_period_id IS
                'The OPEN period the correction is reported in - PF-087.';

            /*
             | An adjustment cannot be edited either, and for the same reason.
             |
             | Once it has been reported in a filed return it is part of a
             | statement to an authority. Allowing an update would move a
             | figure inside a return that was already submitted - which is
             | exactly what this table exists to prevent elsewhere.
             */
            CREATE OR REPLACE FUNCTION refuse_to_change_a_reported_adjustment() RETURNS trigger AS $$
            DECLARE
                reported_state text;
            BEGIN
                SELECT state INTO reported_state
                  FROM return_periods
                 WHERE id = OLD.reported_in_period_id;

                IF reported_state = 'filed' THEN
                    RAISE EXCEPTION
                        'This adjustment was reported in a return that has been filed. Changing it now '
                        'would move a figure inside a statement already made to an authority - which is '
                        'the thing adjustments exist to avoid. Correct it with another adjustment in the '
                        'next open period.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER return_adjustments_are_immutable_once_reported
                BEFORE UPDATE OR DELETE ON return_adjustments
                FOR EACH ROW
                EXECUTE FUNCTION refuse_to_change_a_reported_adjustment();
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('return_adjustments');");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS return_adjustments_are_immutable_once_reported ON return_adjustments;
            DROP FUNCTION IF EXISTS refuse_to_change_a_reported_adjustment();
        SQL);

        Schema::dropIfExists('return_adjustments');
    }
};
