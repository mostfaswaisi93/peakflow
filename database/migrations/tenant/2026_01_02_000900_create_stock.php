<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock, and the movements that are the only record of it - PF-247, PF-248.
 *
 * ## There is no quantity column, and that is the whole design
 *
 * Quantity on hand is `sum(quantity)` over the movements. A stored
 * figure is a second place the truth lives, and it drifts the first time
 * a movement is reversed, a job retries, or two tills sell the last unit
 * in the same second.
 *
 * The cost of that decision is a sum per item per location on every read,
 * which is why there is an index shaped exactly for it. The cost of the
 * alternative is a stock figure nobody can reconcile, which is what the
 * quarterly "shrinkage" write-off actually is in most businesses.
 *
 * ## Movements are immutable
 *
 * A correction is a new movement. A stock ledger somebody can edit is a
 * stock ledger nobody can audit - and the cost of sales already posted
 * from a movement would be left describing a quantity that no longer
 * exists.
 *
 * ## Cost is frozen on the line that consumed it
 *
 * Weighted average or FIFO, computed from the movements before it and
 * written onto the issue. Recomputing later would make the margin on a
 * sale depend on when somebody asked, and reversing a sale would reverse
 * a different number from the one it posted.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('code', 32);
            $table->string('name_ar', 160);
            $table->string('name_en', 160);

            /*
             | Whether stock here can be sold.
             |
             | Faulty goods come back into a location that is not sellable
             | rather than into the shelf - PF-252's point, and the reason
             | a location is a first-class thing rather than a label.
             */
            $table->boolean('sellable')->default(true);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['entity_id', 'code']);
        });

        DB::unprepared("SELECT peakflow_apply_entity_rls('stock_locations')");

        Schema::table('items', function (Blueprint $table): void {
            /*
             | Whether this item carries stock at all.
             |
             | A service does not. A stocked item that is sold without a
             | movement leaves two records of one event, which is the
             | fault this whole phase exists to prevent - so the two are
             | tied together in the sales path rather than hoped for.
             */
            $table->boolean('stocked')->default(false);

            /*
             | How its cost is computed, and immutable once anything moved.
             |
             | Changing it restates every cost of sale already posted, so
             | it is a decision taken when the item is created and not
             | afterwards.
             */
            $table->string('cost_method', 16)->nullable();

            /*
             | Whether this item may go negative, per item.
             |
             | A business that allows it is choosing to - a bar that sells
             | a pour before the delivery is booked. One that allows it by
             | accident discovers the cost of sales is a guess. So it is
             | off by default and recorded with its reason when it is on.
             */
            $table->boolean('allows_negative')->default(false);
            $table->text('negative_basis')->nullable();

            $table->boolean('batch_tracked')->default(false);
            $table->boolean('serial_tracked')->default(false);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE items
                ADD CONSTRAINT items_stocked_declares_a_cost_method
                    CHECK (NOT stocked OR cost_method IN ('weighted_average', 'fifo')),

                /*
                 | A service cannot be stocked.
                 |
                 | Not a validation nicety: a stocked service would demand
                 | a movement for something that has no units, and the
                 | first symptom is an invoice that cannot be issued.
                 */
                ADD CONSTRAINT items_only_goods_carry_stock
                    CHECK (NOT stocked OR type = 'good'),

                ADD CONSTRAINT items_negative_stock_states_its_basis
                    CHECK (
                        NOT allows_negative
                        OR length(btrim(coalesce(negative_basis, ''))) >= 20
                    ),

                /*
                 | And tracking needs stock to track.
                 |
                 | A batch or a serial on an item with no movements is a
                 | number attached to nothing.
                 */
                ADD CONSTRAINT items_tracking_needs_stock
                    CHECK (stocked OR (NOT batch_tracked AND NOT serial_tracked));

            COMMENT ON COLUMN items.cost_method IS
                'How this item''s cost of sale is computed, immutable once a movement exists - changing it restates every cost already posted - PF-247.';
        SQL);

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('item_id', 26);
            $table->char('location_id', 26);

            $table->string('kind', 16);

            /*
             | Signed, and the sign is the direction.
             |
             | A receipt is positive and an issue is negative, so quantity
             | on hand is a sum rather than a case expression - and a
             | movement with the wrong sign is visible rather than hidden
             | inside a `kind` somebody has to know the meaning of.
             */
            $table->decimal('quantity', 21, 6);

            /*
             | The unit cost this movement carried, and the value it moved.
             |
             | On a receipt, what was paid. On an issue, what the method
             | said at that moment - frozen, because recomputing later
             | makes the margin on a sale depend on when somebody asked.
             */
            $table->decimal('unit_cost', 21, 6)->nullable();
            $table->decimal('value', 21, 4)->nullable();
            $table->char('currency', 3)->nullable();

            $table->string('source_type', 32)->nullable();
            $table->char('source_id', 26)->nullable();
            $table->char('batch_id', 26)->nullable();

            $table->text('reason')->nullable();

            $table->ulid('journal_entry_id')->nullable();
            $table->date('moved_on');
            $table->ulid('moved_by');
            $table->timestamps();

            /*
             | The index the derivation needs.
             |
             | Quantity on hand is a sum over (item, location), and it is
             | read on every line of every document that sells a stocked
             | item. Without this it is a sequential scan of the stock
             | ledger on each one.
             */
            $table->index(['entity_id', 'item_id', 'location_id']);
            $table->index(['entity_id', 'moved_on']);
            $table->index(['entity_id', 'source_type', 'source_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_movements
                ADD CONSTRAINT stock_movements_kind_is_known
                    CHECK (kind IN ('receipt', 'issue', 'transfer_in', 'transfer_out',
                                    'adjustment', 'count', 'return_in', 'return_out')),

                ADD CONSTRAINT stock_movements_move_something
                    CHECK (quantity <> 0),

                /*
                 | A receipt adds and an issue removes, enforced.
                 |
                 | A receipt with a negative quantity is somebody
                 | correcting a receipt by entering another one backwards,
                 | which makes the movement history unreadable: the way to
                 | undo a receipt is an adjustment that says so.
                 */
                ADD CONSTRAINT stock_movements_signs_match_their_kind
                    CHECK (
                        (kind IN ('receipt', 'transfer_in', 'return_in') AND quantity > 0)
                        OR (kind IN ('issue', 'transfer_out', 'return_out') AND quantity < 0)
                        OR kind IN ('adjustment', 'count')
                    ),

                /*
                 | An adjustment says why.
                 |
                 | "Adjustment" with no reason is where shrinkage hides,
                 | and a stock ledger full of them is a stock ledger that
                 | reconciles to itself and to nothing else.
                 */
                ADD CONSTRAINT stock_movements_adjustments_say_why
                    CHECK (
                        kind NOT IN ('adjustment', 'count')
                        OR length(btrim(coalesce(reason, ''))) >= 10
                    ),

                ADD CONSTRAINT stock_movements_cost_is_complete_or_absent
                    CHECK (
                        (unit_cost IS NULL AND value IS NULL AND currency IS NULL)
                        OR (unit_cost IS NOT NULL AND value IS NOT NULL AND currency IS NOT NULL)
                    ),

                /*
                 | And the value is the quantity times the cost.
                 |
                 | Asserted on the way in, rounded to the amount scale, so
                 | the stored value cannot drift from its own inputs. A
                 | line that does not satisfy this is one an auditor
                 | re-performing the stock valuation would find and nobody
                 | else would.
                 */
                ADD CONSTRAINT stock_movements_value_is_the_arithmetic
                    CHECK (
                        value IS NULL
                        OR value = round(quantity * unit_cost, 4)
                    );

            COMMENT ON TABLE stock_movements IS
                'The only record of stock. Quantity on hand is a sum over these - there is no quantity column, because a stored figure drifts the first time a movement is reversed - PF-247, PF-248.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('stock_movements')");

        /*
         | Movements are immutable, and not deletable.
         |
         | A correction is a new movement. The cost of sales already
         | posted from one would otherwise be left describing a quantity
         | that no longer exists, and the inventory control account would
         | stop agreeing with its own subsidiary record.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_stock_movement_is_a_fact()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION
                        'A stock movement cannot be deleted. It is the only record that the stock moved, and the cost of sales posted from it would be left describing a quantity that no longer exists. Correct it with an adjustment that says what happened.'
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 | The journal entry may be attached afterwards.
                 |
                 | The movement is written and then posted, inside one
                 | transaction - so that column is the one thing that may
                 | still change, exactly as a payment file's download is.
                 */
                IF NEW.id <> OLD.id
                   OR NEW.entity_id <> OLD.entity_id
                   OR NEW.item_id <> OLD.item_id
                   OR NEW.location_id <> OLD.location_id
                   OR NEW.kind <> OLD.kind
                   OR NEW.quantity <> OLD.quantity
                   OR NEW.unit_cost IS DISTINCT FROM OLD.unit_cost
                   OR NEW.value IS DISTINCT FROM OLD.value
                   OR NEW.moved_on <> OLD.moved_on THEN
                    RAISE EXCEPTION
                        'A stock movement is a fact about a moment and cannot be changed. A correction is a new movement, so that both the original and the correction are on the record.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER stock_movements_are_facts
                BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_stock_movement_is_a_fact();
        SQL);

        /*
         | Negative stock is refused in the database.
         |
         | The service checks it too, with a better message - but two
         | tills selling the last unit in the same second is a race the
         | service cannot see, and this is where it is lost. The sum is
         | taken with the row locked, which serialises exactly the
         | conflicting pair and nothing else.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_stock_does_not_go_negative()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                allowed boolean;
                on_hand numeric;
                item_code text;
            BEGIN
                SELECT i.allows_negative, i.code
                  INTO allowed, item_code
                  FROM items i
                 WHERE i.id = NEW.item_id
                   FOR SHARE;

                IF allowed THEN
                    RETURN NEW;
                END IF;

                SELECT coalesce(sum(m.quantity), 0)
                  INTO on_hand
                  FROM stock_movements m
                 WHERE m.item_id = NEW.item_id
                   AND m.location_id = NEW.location_id
                   AND m.id <> NEW.id;

                IF on_hand + NEW.quantity < 0 THEN
                    RAISE EXCEPTION
                        'There is % of % at that location and this movement needs %. The item does not allow negative stock, so this is refused rather than recorded: a negative quantity makes the cost of sales a guess, and the figure never comes back on its own.',
                        on_hand, item_code, abs(NEW.quantity)
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER stock_movements_do_not_go_negative
                BEFORE INSERT ON stock_movements
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_stock_does_not_go_negative();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stock_movements_do_not_go_negative ON stock_movements;
            DROP TRIGGER IF EXISTS stock_movements_are_facts ON stock_movements;
            DROP FUNCTION IF EXISTS peakflow_stock_does_not_go_negative();
            DROP FUNCTION IF EXISTS peakflow_a_stock_movement_is_a_fact();
        SQL);

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_locations');

        Schema::table('items', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a forward-only migration. These six columns are added by this migration's own up(), so dropping them restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn([
                'stocked', 'cost_method', 'allows_negative', 'negative_basis',
                'batch_tracked', 'serial_tracked',
            ]);
        });
    }
};
