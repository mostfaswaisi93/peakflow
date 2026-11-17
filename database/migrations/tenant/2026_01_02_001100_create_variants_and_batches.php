<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Variants and batches - PF-249, PF-250.
 *
 * ## The axes are data
 *
 * Size, colour, strength, length, finish. A product with a hard-coded
 * size enum is a product that cannot sell shoes, and the next vertical
 * always needs an axis nobody thought of - grind for coffee, dosage for
 * medicine, offset for wheels.
 *
 * ## A combination that does not exist is ABSENT
 *
 * Not present-and-disabled. A matrix that generates every combination of
 * five sizes and six colours produces thirty variants, and a shop that
 * stocks nine of them now has twenty-one rows that need stock records,
 * price lines and a reason for being inactive. Every report has to
 * remember to exclude them.
 *
 * ## A batch is about traceability, and the expiry is the point
 *
 * For food or medicine a batch that cannot be traced is a regulatory
 * gap, not an inconvenience: the question is "who has this batch", and it
 * is asked on a day when the answer matters. So an item under batch
 * control cannot move without naming one, and that is a trigger rather
 * than a hope.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_axes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('code', 32);
            $table->string('name_ar', 120);
            $table->string('name_en', 120);

            /*
             | The order the axes read in on a screen and in a code.
             |
             | "Red / Large" and "Large / Red" are the same variant, and a
             | product that generated both would sell the same shoe twice.
             | The position fixes the order once.
             */
            $table->smallInteger('position');
            $table->timestamps();

            $table->unique(['entity_id', 'code']);
            $table->unique(['entity_id', 'position']);
        });

        DB::unprepared("SELECT peakflow_apply_entity_rls('item_axes')");

        Schema::create('item_axis_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('axis_id', 26);

            $table->string('code', 32);
            $table->string('name_ar', 120);
            $table->string('name_en', 120);
            $table->smallInteger('position');
            $table->timestamps();

            $table->unique(['axis_id', 'code']);
        });

        DB::unprepared("SELECT peakflow_apply_entity_rls('item_axis_values')");

        Schema::create('item_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('item_id', 26);

            /*
             | Its own code, because a variant is what gets scanned.
             |
             | A barcode is on the box of large red ones, not on the
             | notion of the shoe - so the code has to live here and be
             | unique in its own right.
             */
            $table->string('code', 64);
            $table->string('barcode', 64)->nullable();

            /*
             | The combination, as the axis values that make it.
             |
             | A jsonb map of axis code to value code, with a generated
             | signature column that the unique index is on - so two rows
             | cannot describe the same combination whatever order their
             | keys were written in.
             */
            $table->jsonb('axes');

            /*
             | What it overrides. NULL means "whatever the item says".
             |
             | Which is the whole inheritance rule: changing the item's tax
             | code changes forty variants, and changing one variant's
             | price changes one. A variant that copied every field would
             | be forty rows to update and thirty-nine chances to miss one.
             */
            $table->decimal('price_override', 21, 8)->nullable();
            $table->string('unit_code_override', 8)->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'item_id']);
        });

        DB::unprepared(<<<'SQL'
            /*
             | One row per combination, indexed on the jsonb itself.
             |
             | `jsonb` normalises key order on storage and compares by
             | content, so `{"size":"L","colour":"red"}` and
             | `{"colour":"red","size":"L"}` are the SAME value - which is
             | exactly the semantics wanted, and means no signature column
             | is needed.
             |
             | The first attempt built one as a generated column with a
             | `jsonb_each_text` subquery in it, which PostgreSQL refuses:
             | a generation expression cannot contain a subquery. That
             | refusal was useful, because the signature was never needed -
             | the only thing it added over indexing the column was a
             | guard against `{}` colliding, and the check below refuses
             | `{}` outright.
             */
            CREATE UNIQUE INDEX item_variants_one_row_per_combination
                ON item_variants (item_id, axes)
                WHERE deleted_at IS NULL;

            ALTER TABLE item_variants
                ADD CONSTRAINT item_variants_name_a_combination
                    CHECK (jsonb_typeof(axes) = 'object' AND axes <> '{}'::jsonb),

                ADD CONSTRAINT item_variants_overrides_are_positive
                    CHECK (price_override IS NULL OR price_override >= 0);

            COMMENT ON TABLE item_variants IS
                'One row per combination that EXISTS. A matrix of every combination produces stock records for shoes nobody makes, and every report then has to remember to exclude them - PF-249.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('item_variants')");

        Schema::create('stock_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('item_id', 26);

            $table->string('number', 64);
            $table->date('received_on');
            $table->date('expires_on')->nullable();

            /*
             | Where it came from, for the recall that asks.
             |
             | A batch number means nothing without the supplier and their
             | own reference: the recall notice names THEIR lot, and ours
             | has to be findable from it.
             */
            $table->char('supplier_id', 26)->nullable();
            $table->string('supplier_lot', 64)->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'item_id', 'number']);
            $table->index(['entity_id', 'expires_on']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_batches
                ADD CONSTRAINT stock_batches_expire_after_arriving
                    CHECK (expires_on IS NULL OR expires_on >= received_on);

            COMMENT ON TABLE stock_batches IS
                'A lot, its expiry and where it came from. The recall notice names the supplier''s lot, so ours has to be findable from theirs - PF-250.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('stock_batches')");

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->char('variant_id', 26)->nullable();

            /*
             | Who accepted an expired issue, and why.
             |
             | Issuing from an expired batch is sometimes right - a
             | cosmetic best-before, a batch re-tested and released. It is
             | never right silently, so the acknowledgement is a column
             | rather than a log line.
             */
            $table->ulid('expiry_accepted_by')->nullable();
            $table->text('expiry_accepted_reason')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_movements
                ADD CONSTRAINT stock_movements_expiry_override_is_explained
                    CHECK (
                        (expiry_accepted_by IS NULL AND expiry_accepted_reason IS NULL)
                        OR (expiry_accepted_by IS NOT NULL
                            AND length(btrim(coalesce(expiry_accepted_reason, ''))) >= 15)
                    );
        SQL);

        /*
         | An item under batch control cannot move without one.
         |
         | A trigger, because the service is one door and an import is
         | another - and a movement with no batch on a tracked item is a
         | traceability gap that only shows up on the day somebody asks
         | who has the batch.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_tracked_movement_names_its_batch()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                tracked boolean;
                item_code text;
                batch_item char(26);
            BEGIN
                SELECT i.batch_tracked, i.code INTO tracked, item_code
                  FROM items i WHERE i.id = NEW.item_id;

                IF NOT coalesce(tracked, false) THEN
                    RETURN NEW;
                END IF;

                IF NEW.batch_id IS NULL THEN
                    RAISE EXCEPTION
                        '% is under batch control, so every movement names a batch. A movement without one is a traceability gap: "who has this batch" is asked on a day when the answer matters, and for food or medicine it is a regulatory question rather than an inconvenience.',
                        item_code
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT b.item_id INTO batch_item FROM stock_batches b WHERE b.id = NEW.batch_id;

                IF batch_item IS NULL THEN
                    RAISE EXCEPTION 'There is no batch % to move.', NEW.batch_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                /*
                 | And the batch belongs to the item.
                 |
                 | A movement naming another item's batch is how a recall
                 | returns the wrong customers - which is worse than
                 | returning none, because somebody acts on it.
                 */
                IF batch_item <> NEW.item_id THEN
                    RAISE EXCEPTION
                        'That batch belongs to a different item. A movement naming another item''s batch makes a recall return the wrong customers, which is worse than returning none.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER stock_movements_name_their_batch
                BEFORE INSERT ON stock_movements
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_tracked_movement_names_its_batch();
        SQL);

        /*
         | An axis value with history cannot be deleted.
         |
         | Deleting "Large" from the size axis would leave every large
         | variant describing a combination that no longer exists - and
         | the movements against it would be stock of nothing.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_an_axis_value_with_history_stays()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                axis_code text;
                holders integer;
            BEGIN
                SELECT a.code INTO axis_code FROM item_axes a WHERE a.id = OLD.axis_id;

                SELECT count(*) INTO holders
                  FROM item_variants v
                 WHERE v.deleted_at IS NULL
                   AND v.axes ->> axis_code = OLD.code;

                IF holders > 0 THEN
                    RAISE EXCEPTION
                        '% variant(s) are the "%" of the % axis, so removing it would leave them describing a combination that no longer exists - and their stock would be stock of nothing. Deactivate the variants first, or rename the value.',
                        holders, OLD.code, axis_code
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER item_axis_values_with_history_stay
                BEFORE DELETE ON item_axis_values
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_an_axis_value_with_history_stays();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS item_axis_values_with_history_stay ON item_axis_values;
            DROP TRIGGER IF EXISTS stock_movements_name_their_batch ON stock_movements;
            DROP FUNCTION IF EXISTS peakflow_an_axis_value_with_history_stays();
            DROP FUNCTION IF EXISTS peakflow_a_tracked_movement_names_its_batch();
        SQL);

        Schema::table('stock_movements', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a forward-only migration. These three columns are added by this migration's own up(), so dropping them restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn(['variant_id', 'expiry_accepted_by', 'expiry_accepted_reason']);
        });

        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('item_variants');
        Schema::dropIfExists('item_axis_values');
        Schema::dropIfExists('item_axes');
    }
};
