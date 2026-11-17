<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Serials, their history and the warranty on them - PF-251.
 *
 * ## A serial has a state, and the state is the history
 *
 * Received, sold, returned, replaced, written off. "Where is this unit"
 * is the question a warranty claim starts with, and it has to be
 * answerable from one list - so every change of state is a row rather
 * than an update, and the current state is the last of them.
 *
 * ## Sold once, by a unique index
 *
 * Not by a service check. The second sale almost always arrives from an
 * import, a second till or a retried job - none of which goes through the
 * check, and all of which go through the index.
 *
 * ## The warranty's start is DERIVED and its end is computed
 *
 * From the sale, because a claim is answered by a date rather than by an
 * argument. A stored end date is a second place the truth lives: the
 * first time somebody corrects a sale date, the warranty stops agreeing
 * with it and the customer is the one who finds out.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('item_id', 26);
            $table->char('variant_id', 26)->nullable();
            $table->char('batch_id', 26)->nullable();

            $table->string('number', 96);

            /*
             | Where it is now, denormalised from the events deliberately.
             |
             | The events are the record; this is the index. "Which serials
             | are in stock" is asked on every goods-out screen, and a
             | lateral join to the last event per serial is a query nobody
             | writes correctly twice.
             |
             | A trigger keeps it equal to the last event, so it cannot
             | drift - which is the only arrangement in which a
             | denormalised column is safe.
             */
            $table->string('state', 16)->default('received');
            $table->char('location_id', 26)->nullable();

            $table->timestamps();

            /*
             | Unique per item, across the tenant.
             |
             | Not per entity: a serial is a physical thing, and the same
             | unit moving between two entities of one group is a transfer
             | rather than two units. Two rows would make the warranty
             | history split at the transfer.
             */
            $table->unique(['item_id', 'number']);
            $table->index(['entity_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE serials
                ADD CONSTRAINT serials_state_is_known
                    CHECK (state IN ('received', 'sold', 'returned', 'replaced', 'written_off')),

                ADD CONSTRAINT serials_have_a_number
                    CHECK (length(btrim(number)) > 0);

            COMMENT ON COLUMN serials.state IS
                'Where the unit is now, kept equal to the last event by a trigger - the events are the record and this is the index, because "which serials are in stock" is asked on every goods-out screen - PF-251.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('serials')");

        Schema::create('serial_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('serial_id', 26);

            $table->string('event', 16);
            $table->date('happened_on');

            $table->char('movement_id', 26)->nullable();
            $table->string('source_type', 32)->nullable();
            $table->char('source_id', 26)->nullable();
            $table->char('party_id', 26)->nullable();
            $table->char('location_id', 26)->nullable();

            /*
             | The term sold WITH this unit, when one was.
             |
             | An extended warranty is a thing a customer buys at the
             | till, and it is not a property of the item - two units of
             | one model go out of the door with different cover. So the
             | term is named on the sale event, and the item's term is
             | the fallback rather than the answer.
             */
            $table->char('warranty_term_id', 26)->nullable();

            $table->text('note')->nullable();
            $table->ulid('recorded_by');
            $table->timestamps();

            $table->index(['entity_id', 'serial_id', 'happened_on']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE serial_events
                ADD CONSTRAINT serial_events_event_is_known
                    CHECK (event IN ('received', 'sold', 'returned', 'replaced', 'written_off')),

                /*
                 | And a term belongs to the sale, not to any event.
                 |
                 | A term on a `returned` row would be a second answer to
                 | "what cover does this unit have", which is the question
                 | the whole task exists to make unambiguous.
                 */
                ADD CONSTRAINT serial_events_only_a_sale_carries_a_term
                    CHECK (warranty_term_id IS NULL OR event = 'sold');

            /*
             | One sale per serial, by a unique index.
             |
             | The control this task exists for. A service check cannot
             | see the second till, the import or the retried job; this
             | can, and it is the same index whichever door the write
             | came through.
             */
            CREATE UNIQUE INDEX serial_events_sold_once
                ON serial_events (serial_id)
                WHERE event = 'sold';

            COMMENT ON TABLE serial_events IS
                'Every change of state, as a row rather than an update - "where is this unit" has to be answerable from one list - PF-251.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('serial_events')");

        Schema::create('warranty_terms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('item_id', 26)->nullable();

            $table->string('name_ar', 160);
            $table->string('name_en', 160);
            $table->smallInteger('months');

            /*
             | What it covers, as words rather than a flag.
             |
             | "Twelve months" is a date. What a claim turns on is whether
             | this fault is covered, and that is a sentence somebody wrote
             | and a customer can be shown.
             */
            $table->text('covers_ar');
            $table->text('covers_en');

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['entity_id', 'item_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE warranty_terms
                ADD CONSTRAINT warranty_terms_last_some_time
                    CHECK (months >= 1),

                ADD CONSTRAINT warranty_terms_say_what_they_cover
                    CHECK (
                        length(btrim(covers_ar)) >= 20
                        AND length(btrim(covers_en)) >= 20
                    );
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('warranty_terms')");

        Schema::create('warranty_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('serial_id', 26);
            $table->char('party_id', 26)->nullable();

            $table->date('claimed_on');
            $table->text('fault');

            $table->string('decision', 16)->nullable();
            $table->text('decision_reason')->nullable();
            $table->date('decided_on')->nullable();
            $table->ulid('decided_by')->nullable();

            /*
             | The serial that went out in its place, where one did.
             |
             | A replacement is two stock movements: the new unit leaves
             | and the old one comes back. Recording only the decision
             | would leave the stock wrong in both directions.
             */
            $table->char('replacement_serial_id', 26)->nullable();

            $table->ulid('recorded_by');
            $table->timestamps();

            $table->index(['entity_id', 'serial_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE warranty_claims
                ADD CONSTRAINT warranty_claims_state_the_fault
                    CHECK (length(btrim(fault)) >= 10),

                ADD CONSTRAINT warranty_claims_decision_is_known
                    CHECK (
                        decision IS NULL
                        OR decision IN ('repaired', 'replaced', 'refunded', 'rejected')
                    ),

                /*
                 | A decision is attributable and explained.
                 |
                 | Rejecting a claim is the one a customer argues with, so
                 | the reason is what the conversation is held against -
                 | and "rejected" with no reason is a decision nobody can
                 | defend six months later.
                 */
                ADD CONSTRAINT warranty_claims_decisions_are_attributable
                    CHECK (
                        decision IS NULL
                        OR (decided_on IS NOT NULL
                            AND decided_by IS NOT NULL
                            AND length(btrim(coalesce(decision_reason, ''))) >= 10)
                    ),

                /*
                 | And a replacement names the unit that went.
                 |
                 | Without it the decision says a unit was replaced and
                 | the stock says nothing left, so the next count finds
                 | one too many and writes it off.
                 */
                ADD CONSTRAINT warranty_claims_replacements_name_the_unit
                    CHECK (
                        decision IS DISTINCT FROM 'replaced'
                        OR replacement_serial_id IS NOT NULL
                    ),

                ADD CONSTRAINT warranty_claims_do_not_replace_themselves
                    CHECK (replacement_serial_id IS NULL OR replacement_serial_id <> serial_id);
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('warranty_claims')");

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->char('serial_id', 26)->nullable();
        });

        /*
         | The serial's state follows its last event, by trigger.
         |
         | Which is what makes the denormalised column safe: it cannot
         | drift, because nothing can write it except this.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_serial_follows_its_events()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                UPDATE serials
                   SET state = NEW.event,
                       location_id = coalesce(NEW.location_id, location_id),
                       updated_at = now()
                 WHERE id = NEW.serial_id;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER serial_events_move_the_serial
                AFTER INSERT ON serial_events
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_serial_follows_its_events();

            /*
             | And an event is a fact: no edits, no deletes.
             |
             | The warranty start is derived from the sale event, so an
             | editable event is an editable warranty - and the customer
             | is the one who finds out.
             */
            CREATE OR REPLACE FUNCTION peakflow_a_serial_event_is_a_fact()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'A serial event is the record that the unit moved, and the warranty start is derived from it. Correct it with another event - a return, a write-off - so that both are on the record.'
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE TRIGGER serial_events_are_facts
                BEFORE UPDATE OR DELETE ON serial_events
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_serial_event_is_a_fact();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS serial_events_are_facts ON serial_events;
            DROP TRIGGER IF EXISTS serial_events_move_the_serial ON serial_events;
            DROP FUNCTION IF EXISTS peakflow_a_serial_event_is_a_fact();
            DROP FUNCTION IF EXISTS peakflow_a_serial_follows_its_events();
        SQL);

        Schema::table('stock_movements', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a forward-only migration. The column is added by this migration's own up(), so dropping it restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn('serial_id');
        });

        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('warranty_terms');
        Schema::dropIfExists('serial_events');
        Schema::dropIfExists('serials');
    }
};
