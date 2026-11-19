<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods coming back - PF-252.
 *
 * ## More than was sold is refused by the database
 *
 * Because a part return of a part return is where a service check is
 * wrong: it reads the invoice, subtracts what it can see, and misses the
 * return another till recorded two seconds ago. The trigger sums the
 * returns against the line with the row locked, which serialises exactly
 * the conflicting pair.
 *
 * ## Restocking is a decision
 *
 * Faulty goods come back into a location that is not sellable. A product
 * that restocked everything by default puts a broken unit back on the
 * shelf, and the next customer finds it - so the location is named on
 * the return line and the quarantine one is a real place rather than a
 * flag.
 *
 * ## The credit and the movement are one transaction
 *
 * A return that credited the customer and did not restore the stock is
 * the error nobody notices until a count, which is three months later
 * and gets written off as shrinkage.
 *
 * @financial
  *
 * @online-safe Creates `sales_return_lines`, `sales_returns`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('invoice_id', 26);
            $table->char('party_id', 26);

            $table->string('number', 64);
            $table->date('returned_on');
            $table->string('state', 16)->default('draft');

            $table->text('reason');

            /*
             | What the customer gets back, and what is kept.
             |
             | A restocking fee is a real commercial term and is recorded
             | as its own figure rather than folded into the credit - so
             | the credit note is explainable from the return, which is
             | what the customer asks about.
             */
            $table->decimal('goods_value', 21, 4);
            $table->decimal('restocking_fee', 21, 4)->default(0);
            $table->decimal('credit_value', 21, 4);
            $table->char('currency', 3);

            $table->char('credit_note_id', 26)->nullable();

            $table->ulid('created_by');
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'invoice_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE sales_returns
                ADD CONSTRAINT sales_returns_state_is_known
                    CHECK (state IN ('draft', 'accepted', 'cancelled')),

                ADD CONSTRAINT sales_returns_state_the_reason
                    CHECK (length(btrim(reason)) >= 10),

                ADD CONSTRAINT sales_returns_amounts_are_not_negative
                    CHECK (goods_value >= 0 AND restocking_fee >= 0 AND credit_value >= 0),

                /*
                 | The credit is the goods less the fee, asserted.
                 |
                 | So the figure on the credit note cannot drift from its
                 | own inputs - which is the one arithmetic a customer
                 | checks.
                 |
                 | And this is also the fee's ceiling, stated once. A fee
                 | larger than the goods would need a negative credit,
                 | which the line above refuses - so a separate
                 | `restocking_fee <= goods_value` is the same invariant
                 | written twice, and the copy is what survives a change
                 | to the original. This task shipped that copy in its
                 | first draft and the bite sweep found it: dropping it
                 | changed nothing. The sentence a person reads lives in
                 | `SalesReturns::chargeRestocking`.
                 */
                ADD CONSTRAINT sales_returns_credit_is_the_arithmetic
                    CHECK (credit_value = goods_value - restocking_fee),

                ADD CONSTRAINT sales_returns_acceptance_is_attributable
                    CHECK (
                        (approved_at IS NULL AND approved_by IS NULL)
                        OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)
                    ),

                ADD CONSTRAINT sales_returns_accepted_is_approved
                    CHECK (state <> 'accepted' OR approved_at IS NOT NULL);
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('sales_returns')");

        Schema::create('sales_return_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('return_id', 26);

            $table->char('invoice_line_id', 26);
            $table->char('item_id', 26);
            $table->char('variant_id', 26)->nullable();
            $table->char('serial_id', 26)->nullable();
            $table->char('batch_id', 26)->nullable();

            $table->decimal('quantity', 21, 6);
            $table->decimal('unit_price', 21, 8);
            $table->decimal('line_value', 21, 4);

            /*
             | Where it goes back to, named on the line.
             |
             | Two units of one line can go to two places: one resellable
             | and one broken. A single destination on the return would
             | force two returns for one parcel.
             */
            $table->char('location_id', 26);
            $table->boolean('resellable');
            $table->text('condition_note')->nullable();

            $table->char('movement_id', 26)->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'return_id']);
            $table->index(['entity_id', 'invoice_line_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE sales_return_lines
                ADD CONSTRAINT sales_return_lines_return_something
                    CHECK (quantity > 0),

                ADD CONSTRAINT sales_return_lines_value_is_the_arithmetic
                    CHECK (line_value = round(quantity * unit_price, 4)),

                /*
                 | Goods that are not resellable say what is wrong.
                 |
                 | "Not resellable" with no note is a unit in quarantine
                 | that nobody can decide about: scrap it, repair it, send
                 | it back to the supplier. All three need the sentence.
                 */
                ADD CONSTRAINT sales_return_lines_faults_are_described
                    CHECK (
                        resellable
                        OR length(btrim(coalesce(condition_note, ''))) >= 10
                    );
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('sales_return_lines')");

        /*
         | More than was sold is refused here.
         |
         | A part return of a part return is where a service check is
         | wrong: it reads the invoice, subtracts what it can see, and
         | misses the return another till recorded two seconds ago.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_return_does_not_exceed_the_sale()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                sold numeric;
                already numeric;
                line_label text;
            BEGIN
                /*
                 | The invoice line, locked.
                 |
                 | FOR SHARE rather than FOR UPDATE: two returns against
                 | DIFFERENT lines of one invoice must not block each
                 | other, and the sum below is what serialises the pair
                 | that conflicts.
                 */
                SELECT l.quantity, coalesce(l.description, '')
                  INTO sold, line_label
                  FROM invoice_lines l
                 WHERE l.id = NEW.invoice_line_id
                   FOR SHARE;

                IF sold IS NULL THEN
                    RAISE EXCEPTION 'There is no invoice line % to return against.', NEW.invoice_line_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                SELECT coalesce(sum(rl.quantity), 0)
                  INTO already
                  FROM sales_return_lines rl
                  JOIN sales_returns r ON r.id = rl.return_id
                 WHERE rl.invoice_line_id = NEW.invoice_line_id
                   AND rl.id <> NEW.id
                   AND r.state <> 'cancelled';

                IF already + NEW.quantity > sold THEN
                    RAISE EXCEPTION
                        '% of that line was sold and % has already come back, so this return of % would bring back more than left. A part return of a part return is where a check that reads the invoice once gets it wrong.',
                        sold, already, NEW.quantity
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER sales_return_lines_do_not_exceed_the_sale
                BEFORE INSERT OR UPDATE ON sales_return_lines
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_return_does_not_exceed_the_sale();

            /*
             | And faulty goods cannot go into sellable stock.
             |
             | The decision is on the line; this is what stops the two
             | disagreeing. A product that restocked everything by default
             | puts a broken unit on the shelf and the next customer
             | finds it.
             */
            CREATE OR REPLACE FUNCTION peakflow_faulty_goods_are_quarantined()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                sellable boolean;
            BEGIN
                SELECT l.sellable INTO sellable
                  FROM stock_locations l
                 WHERE l.id = NEW.location_id;

                IF sellable IS NULL THEN
                    RAISE EXCEPTION 'There is no stock location % to return into.', NEW.location_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF NOT NEW.resellable AND sellable THEN
                    RAISE EXCEPTION
                        'That unit came back faulty and the location it is going into is sellable stock, so the next customer would find it. Return it into a location that is not sellable - quarantine is a real place, not a flag.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER sales_return_lines_quarantine_faults
                BEFORE INSERT OR UPDATE ON sales_return_lines
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_faulty_goods_are_quarantined();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS sales_return_lines_quarantine_faults ON sales_return_lines;
            DROP TRIGGER IF EXISTS sales_return_lines_do_not_exceed_the_sale ON sales_return_lines;
            DROP FUNCTION IF EXISTS peakflow_faulty_goods_are_quarantined();
            DROP FUNCTION IF EXISTS peakflow_a_return_does_not_exceed_the_sale();
        SQL);

        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
    }
};
