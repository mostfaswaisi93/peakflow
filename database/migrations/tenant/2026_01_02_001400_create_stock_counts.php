<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Counting a warehouse that is still working - PF-253.
 *
 * ## The expected quantity is hidden until the count is entered
 *
 * Because counting against a figure you can see is not counting. A
 * counter who knows the system says 40 writes 40, and the count becomes
 * a transcription of the thing it was meant to check. It is not
 * dishonesty: finding 38 and writing 40 feels like correcting a
 * miscount, and the shelf is never recounted.
 *
 * Two constraints hold it. `expected_quantity` cannot exist on a line
 * that has not been counted, so the figure cannot be put there in
 * advance; and `counted_quantity` is write-once, so a counter cannot
 * enter a number, see what was expected and then revise it. Both are
 * the database's, because the person doing this at six in the evening is
 * not going through a service method.
 *
 * ## A count freezes nothing
 *
 * Stopping a business to count is how counts stop happening. So goods
 * move while counters walk, and every movement recorded while a count is
 * open is tagged with it - `stock_movements.count_id`.
 *
 * The tag is written at insert time rather than derived from dates
 * afterwards, and that is the whole reason it exists. `moved_on` is a
 * DATE: a movement dated inside the count window but keyed in two days
 * later cannot be told apart by date from one keyed in at the time, and
 * a count that guessed would explain its variance with a movement that
 * had nothing to do with it.
 *
 * ## Each line's expected figure is as of the moment it was ENTERED
 *
 * Not as of when the count opened. A line counted at nine and keyed in
 * at eleven is measured against what the system believed at eleven, so
 * the two deliveries that arrived at ten are already in the expected
 * figure and there is nothing to reconcile by hand.
 *
 * What is left over - a variance on a line whose goods moved between the
 * counter looking and the keying - is why the movements are listed
 * against the count. That residue is real and no arithmetic removes it;
 * the product's job is to put the movements in front of the person
 * deciding, not to pretend the warehouse held still.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 64);
            $table->char('location_id', 26);
            $table->string('state', 16)->default('draft');

            /*
             | Why this count is happening, in a sentence.
             |
             | A cycle count, a year-end count, a count because the
             | margin on one line looks wrong. The three are read
             | differently a year later, and "count" tells nobody which
             | it was.
             */
            $table->text('reason');

            $table->timestamp('opened_at');
            $table->ulid('opened_by');

            $table->timestamp('counted_at')->nullable();
            $table->ulid('counted_by')->nullable();

            /*
             | A second person where the variance is MATERIAL.
             |
             | Not on every count: requiring two signatures on a cycle
             | count of one shelf is how the second signature becomes a
             | rubber stamp, and then it is worth nothing on the count
             | that matters.
             |
             | The threshold is stored on the count, not read from
             | configuration at approval time. A count approved under
             | last year's threshold has to stay explainable under it.
             */
            $table->decimal('materiality', 21, 4);
            $table->decimal('variance_value', 21, 4)->nullable();
            $table->char('currency', 3);

            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->char('journal_entry_id', 26)->nullable();

            $table->text('cancelled_reason')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'location_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_counts
                ADD CONSTRAINT stock_counts_state_is_known
                    CHECK (state IN ('draft', 'counting', 'counted', 'posted', 'cancelled')),

                ADD CONSTRAINT stock_counts_say_why
                    CHECK (length(btrim(reason)) >= 10),

                ADD CONSTRAINT stock_counts_materiality_is_a_figure
                    CHECK (materiality >= 0),

                ADD CONSTRAINT stock_counts_counting_is_attributable
                    CHECK (
                        (counted_at IS NULL AND counted_by IS NULL)
                        OR (counted_at IS NOT NULL AND counted_by IS NOT NULL)
                    ),

                ADD CONSTRAINT stock_counts_approval_is_attributable
                    CHECK (
                        (approved_at IS NULL AND approved_by IS NULL)
                        OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)
                    ),

                /*
                 | The person who counted cannot be the person who
                 | approves it.
                 |
                 | Stated here and not only in the duty rules, because
                 | this is the one that writes off the difference. A
                 | counter who can approve their own variance can make
                 | missing stock disappear from the books by typing a
                 | reason.
                 */
                ADD CONSTRAINT stock_counts_counting_is_not_approving
                    CHECK (approved_by IS NULL OR approved_by <> counted_by),

                /*
                 | A material variance needs the second name; an
                 | immaterial one does not.
                 |
                 | Asserted on the posted state, because that is the
                 | moment the books change.
                 */
                ADD CONSTRAINT stock_counts_material_variance_is_approved
                    CHECK (
                        state <> 'posted'
                        OR abs(coalesce(variance_value, 0)) <= materiality
                        OR approved_at IS NOT NULL
                    ),

                ADD CONSTRAINT stock_counts_posted_is_counted
                    CHECK (state <> 'posted' OR (posted_at IS NOT NULL AND counted_at IS NOT NULL)),

                ADD CONSTRAINT stock_counts_cancelling_says_why
                    CHECK (state <> 'cancelled' OR length(btrim(coalesce(cancelled_reason, ''))) >= 10);

            COMMENT ON TABLE stock_counts IS
                'A count is a document with a scope, a state and two people where the variance is material - PF-253.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('stock_counts')");

        /*
         | Only one count open per location at a time.
         |
         | Two open counts over one shelf produce two expected figures
         | for the same goods and two adjustments for one difference -
         | the stock ends up wrong by exactly the variance, in the
         | direction nobody predicts.
         */
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX stock_counts_one_open_per_location
                ON stock_counts (entity_id, location_id)
                WHERE state IN ('draft', 'counting', 'counted');
        SQL);

        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('count_id', 26);

            $table->char('item_id', 26);
            $table->char('variant_id', 26)->nullable();
            $table->char('batch_id', 26)->nullable();

            /*
             | What the counter found, and what the system believed.
             |
             | Both nullable, and in that order: a line exists on the
             | sheet before anybody has counted it, `counted_quantity`
             | arrives when they do, and `expected_quantity` is written
             | in the same statement - never before it.
             */
            $table->decimal('counted_quantity', 21, 6)->nullable();
            $table->decimal('expected_quantity', 21, 6)->nullable();

            $table->decimal('variance_quantity', 21, 6)->nullable();
            $table->decimal('unit_cost', 21, 8)->nullable();
            $table->decimal('variance_value', 21, 4)->nullable();

            /*
             | Per line, because one total write-off tells nobody which
             | items go missing.
             |
             | "Shrinkage, 4,200" is a figure somebody approves once a
             | quarter and nobody acts on. Four lines each naming an item
             | and a reason is a list somebody can do something about.
             */
            $table->text('reason')->nullable();

            $table->timestamp('counted_at')->nullable();
            $table->ulid('counted_by')->nullable();

            $table->char('movement_id', 26)->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'count_id']);
            $table->unique(['count_id', 'item_id', 'batch_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_count_lines
                /*
                 | The expected figure cannot exist on an uncounted line.
                 |
                 | THE control for "hidden until the count is entered". A
                 | sheet printed with the expected quantity on it is not
                 | a count sheet, and the way that happens is a query
                 | that fills this column in advance.
                 */
                ADD CONSTRAINT stock_count_lines_expected_follows_counted
                    CHECK (expected_quantity IS NULL OR counted_quantity IS NOT NULL),

                ADD CONSTRAINT stock_count_lines_counting_is_attributable
                    CHECK (
                        (counted_quantity IS NULL AND counted_at IS NULL AND counted_by IS NULL)
                        OR (counted_quantity IS NOT NULL AND counted_at IS NOT NULL AND counted_by IS NOT NULL)
                    ),

                /*
                 | Nothing is negative on a shelf.
                 |
                 | A negative COUNT is a typo - the variance may be
                 | negative, and is, which is the whole point.
                 */
                ADD CONSTRAINT stock_count_lines_a_shelf_holds_no_negatives
                    CHECK (counted_quantity IS NULL OR counted_quantity >= 0),

                ADD CONSTRAINT stock_count_lines_variance_is_the_arithmetic
                    CHECK (
                        variance_quantity IS NULL
                        OR variance_quantity = counted_quantity - expected_quantity
                    ),

                ADD CONSTRAINT stock_count_lines_value_is_the_arithmetic
                    CHECK (
                        variance_value IS NULL
                        OR variance_value = round(variance_quantity * unit_cost, 4)
                    ),

                /*
                 | A variance says why; agreement does not need to.
                 |
                 | Asking for a reason on every line of a count that
                 | agreed is how a count of two hundred lines gets
                 | "ok" typed two hundred times - and then the four that
                 | mattered say "ok" too.
                 */
                ADD CONSTRAINT stock_count_lines_a_difference_says_why
                    CHECK (
                        coalesce(variance_quantity, 0) = 0
                        OR length(btrim(coalesce(reason, ''))) >= 10
                    );
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('stock_count_lines')");

        /*
         | A counted quantity is written once.
         |
         | Which closes the door the first constraint leaves open: enter
         | a number, read the expected figure it reveals, then "correct"
         | the number. A recount is a new count, with its own reason and
         | its own name against it.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_count_is_entered_once()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.counted_quantity IS NOT NULL
                   AND NEW.counted_quantity IS DISTINCT FROM OLD.counted_quantity THEN
                    RAISE EXCEPTION
                        'Line % was counted as % already. Changing it now would let a counter enter a figure, read the expected quantity it reveals and then revise it - which is the one thing a count exists to prevent. Recount the shelf as a new count.',
                        OLD.id, OLD.counted_quantity
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER stock_count_lines_are_entered_once
                BEFORE UPDATE ON stock_count_lines
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_count_is_entered_once();
        SQL);

        /*
         | And a posted count is history.
         |
         | Its lines are what the adjustments were posted from, so
         | editing one leaves a journal entry describing a difference
         | nobody can recompute.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_posted_count_is_history()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                current_state text;
            BEGIN
                SELECT c.state INTO current_state
                  FROM stock_counts c
                 WHERE c.id = coalesce(NEW.count_id, OLD.count_id);

                IF current_state = 'posted' THEN
                    RAISE EXCEPTION
                        'That count is posted, so its lines are what the adjustments in the ledger were computed from. Editing one would leave a journal entry describing a difference nobody can recompute.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN coalesce(NEW, OLD);
            END;
            $$;

            CREATE TRIGGER stock_count_lines_stay_put_once_posted
                BEFORE UPDATE OR DELETE ON stock_count_lines
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_posted_count_is_history();
        SQL);

        /*
         | Movements recorded while a count is open are tagged with it.
         |
         | Written at insert time, never derived afterwards - see the
         | class docblock for why `moved_on` cannot do this job.
         */
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->char('count_id', 26)->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE INDEX stock_movements_by_count
                ON stock_movements (count_id)
                WHERE count_id IS NOT NULL;

            COMMENT ON COLUMN stock_movements.count_id IS
                'The count that was open over this location when the movement was recorded - PF-253. A count freezes nothing, so this is how the variance on a line whose goods moved mid-count is explained.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stock_count_lines_stay_put_once_posted ON stock_count_lines;
            DROP TRIGGER IF EXISTS stock_count_lines_are_entered_once ON stock_count_lines;
            DROP FUNCTION IF EXISTS peakflow_a_posted_count_is_history();
            DROP FUNCTION IF EXISTS peakflow_a_count_is_entered_once();
            DROP INDEX IF EXISTS stock_movements_by_count;
        SQL);

        Schema::table('stock_movements', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a forward-only migration. `count_id` is added by this migration's own up(), so dropping it restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn('count_id');
        });

        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
