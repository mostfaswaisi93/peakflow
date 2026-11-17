<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payment run - PF-174.
 *
 * ## Three states, and exactly three transitions
 *
 *   draft → prepared     somebody selected what to pay
 *   prepared → draft     the selection was wrong, and is being redone
 *   prepared → released  somebody else sent it
 *
 * Nothing else. In particular there is no transition out of `released`:
 * a mistake in a released run is corrected by a **new run and a recall
 * request**, never by editing. The money has left; a ledger that let the
 * instruction be edited afterwards would describe a payment the bank
 * never made.
 *
 * The backward one earns its place. A prepared run that turns out wrong
 * has to go somewhere, and the alternatives are worse: releasing it and
 * recalling, or editing it in place. Unpreparing invalidates the
 * preparation, which is exactly what PF-175 needs - the releaser must be
 * shown a preparation nobody has touched since.
 *
 * ## Why the state machine is in the database
 *
 * Because this is the table where money leaves the business. A service
 * can be bypassed by an import, a correction script or a bulk tool; a
 * trigger cannot, and "released without ever being prepared" is a state
 * that must not be representable rather than merely unreachable by the
 * code anybody has read.
 *
 * ## Items and instructions are different things
 *
 * An **item** is a payable selected into the run. An **instruction** is a
 * payment that will actually be made. They are not one-to-one: several
 * invoices to one supplier become one instruction where the beneficiary
 * accepts it, and one large instruction becomes several where a limit
 * requires splitting.
 *
 * Collapsing them would make either the selection or the file wrong. A
 * run whose items are its instructions cannot group; one whose
 * instructions are its items cannot say which invoices a payment settled,
 * which is the first thing a supplier asks.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 32);
            $table->string('state', 16)->default('draft');

            $table->char('bank_account_id', 26);
            $table->char('currency', 3);
            $table->date('pay_on');

            /*
             | What was asked for, kept beside what came out.
             |
             | The selection criteria as given: the due-date horizon, the
             | suppliers, whether discounts were being chased. A run whose
             | criteria are not recorded cannot be explained - "why is
             | this supplier not in it" is the question, and re-deriving
             | the answer from the result is guesswork.
             */
            $table->jsonb('criteria');

            $table->decimal('gross_total', 21, 4)->default(0);
            $table->decimal('discount_total', 21, 4)->default(0);
            $table->decimal('net_total', 21, 4)->default(0);
            $table->integer('item_count')->default(0);
            $table->integer('instruction_count')->default(0);

            $table->ulid('prepared_by')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->ulid('released_by')->nullable();
            $table->timestamp('released_at')->nullable();

            /*
             | The hash of what was released - PF-175 fills it.
             |
             | Declared here because it belongs to the released state
             | rather than to the file format, and a column added later
             | would be nullable on every run that predates it - which is
             | every run anybody would want to check.
             */
            $table->string('released_hash', 64)->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'state', 'pay_on']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_runs
                ADD CONSTRAINT payment_runs_state_is_known
                    CHECK (state IN ('draft', 'prepared', 'released')),

                ADD CONSTRAINT payment_runs_totals_are_not_negative
                    CHECK (gross_total >= 0 AND discount_total >= 0 AND net_total >= 0),

                /*
                 | The net is the gross less the discounts taken.
                 |
                 | Asserted rather than displayed. A run whose totals do
                 | not tie is one where somebody will pay the gross and
                 | the ledger will expect the net, and the difference
                 | turns up as an unexplained bank line a month later.
                 */
                ADD CONSTRAINT payment_runs_net_is_gross_less_discount
                    CHECK (net_total = gross_total - discount_total),

                /*
                 | A prepared run carries who prepared it; a released one
                 | carries both.
                 |
                 | The state and the attribution move together, so
                 | "released by nobody" is not representable - which is
                 | the state an import would otherwise leave behind.
                 */
                ADD CONSTRAINT payment_runs_prepared_is_attributable
                    CHECK (
                        state = 'draft'
                        OR (prepared_by IS NOT NULL AND prepared_at IS NOT NULL)
                    ),

                ADD CONSTRAINT payment_runs_released_is_attributable
                    CHECK (
                        state <> 'released'
                        OR (released_by IS NOT NULL AND released_at IS NOT NULL)
                    ),

                /*
                 | And the releaser is not the preparer.
                 |
                 | Maker-checker on outgoing funds, in the table. PF-175
                 | enforces it against the audit trail as well, because a
                 | column pair catches the same person and not the same
                 | person using two accounts - but a column pair catches
                 | the import that sets both to the same id, and the audit
                 | trail does not see that at all.
                 */
                ADD CONSTRAINT payment_runs_are_not_self_released
                    CHECK (released_by IS NULL OR released_by <> prepared_by);
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_payment_run_moves_lawfully()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.state = OLD.state THEN
                    /*
                     | Not a transition: an ordinary update.
                     |
                     | Allowed only while the run is not released. A
                     | released run is frozen below, including its totals
                     | and its lines.
                     */
                    IF OLD.state = 'released' THEN
                        RAISE EXCEPTION
                            'Payment run % has been released and cannot be changed. The money has '
                            'left; editing the instruction afterwards would describe a payment the '
                            'bank never made. Correct it with a new run and a recall request.',
                            OLD.number
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                /*
                 | The three transitions, and nothing else.
                 |
                 | Listed rather than computed, so the set is a diff
                 | somebody can read. A state machine whose allowed moves
                 | are derived from two booleans is one nobody can
                 | enumerate.
                 */
                IF NOT (
                    (OLD.state = 'draft' AND NEW.state = 'prepared')
                    OR (OLD.state = 'prepared' AND NEW.state = 'draft')
                    OR (OLD.state = 'prepared' AND NEW.state = 'released')
                ) THEN
                    RAISE EXCEPTION
                        'Payment run % cannot go from % to %. A run is prepared, then released; a '
                        'wrong preparation goes back to draft and is redone, and a released run is '
                        'corrected by a new run and a recall request rather than by moving '
                        'backwards.',
                        OLD.number, OLD.state, NEW.state
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_runs_move_lawfully
                BEFORE UPDATE ON payment_runs
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_payment_run_moves_lawfully();

            COMMENT ON TABLE payment_runs IS
                'Three states and exactly three transitions, enforced in the database because this is the table where money leaves the business - PF-174.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('payment_runs')");

        Schema::create('payment_run_instructions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('run_id', 26);

            $table->char('party_id', 26);
            $table->char('party_bank_account_id', 26)->nullable();

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);

            /*
             | Which of several instructions to one beneficiary this is.
             |
             | `1 of 1` for the common case, `2 of 3` where a limit forced
             | a split. Stored rather than derived, because a supplier
             | ringing about "the second of three" needs the system to
             | know what they mean.
             */
            $table->integer('sequence')->default(1);
            $table->integer('of')->default(1);
            $table->string('split_reason', 64)->nullable();

            $table->timestamps();

            $table->index(['run_id']);
            $table->index(['entity_id', 'party_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_run_instructions
                ADD CONSTRAINT payment_run_instructions_amount_is_positive
                    CHECK (amount > 0),

                ADD CONSTRAINT payment_run_instructions_sequence_is_within_its_set
                    CHECK (sequence >= 1 AND sequence <= "of"),

                /*
                 | A split says why it was split.
                 |
                 | One instruction of one needs no reason. Several do: a
                 | supplier asking why they received three transfers
                 | instead of one is asking a question the run should be
                 | able to answer without somebody reading the code.
                 */
                ADD CONSTRAINT payment_run_instructions_splits_say_why
                    CHECK ("of" = 1 OR split_reason IS NOT NULL);

            COMMENT ON TABLE payment_run_instructions IS
                'A payment that will actually be made: one per beneficiary where they accept grouping, several where a limit requires splitting - PF-174.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('payment_run_instructions')");

        Schema::create('payment_run_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('run_id', 26);
            $table->char('instruction_id', 26)->nullable();

            $table->char('bill_id', 26);
            $table->char('party_id', 26);

            $table->decimal('gross_amount', 21, 4);
            $table->decimal('discount_amount', 21, 4)->default(0);
            $table->decimal('net_amount', 21, 4);
            $table->char('currency', 3);

            $table->date('due_on')->nullable();

            /*
             | The discount as it was evaluated, with its deadline.
             |
             | Taking early-settlement discounts is usually the run's
             | whole purpose, so the figures are kept rather than
             | recomputed: a run prepared on Tuesday and released on
             | Thursday must pay what Tuesday's evaluation said, and a
             | recomputation on release would quietly change the amount
             | the releaser approved.
             */
            $table->decimal('discount_percent', 9, 6)->nullable();
            $table->date('discount_until')->nullable();

            $table->timestamps();

            $table->unique(['run_id', 'bill_id']);
            $table->index(['entity_id', 'bill_id']);
            $table->index(['instruction_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_run_items
                ADD CONSTRAINT payment_run_items_amounts_are_not_negative
                    CHECK (gross_amount > 0 AND discount_amount >= 0 AND net_amount > 0),

                ADD CONSTRAINT payment_run_items_net_is_gross_less_discount
                    CHECK (net_amount = gross_amount - discount_amount),

                /*
                 | A discount taken names the terms that offered it.
                 |
                 | Without the percent and the deadline, a discount is a
                 | figure somebody deducted - and the supplier disputing
                 | it has nothing to be shown.
                 */
                ADD CONSTRAINT payment_run_items_discounts_name_their_terms
                    CHECK (
                        discount_amount = 0
                        OR (discount_percent IS NOT NULL AND discount_until IS NOT NULL)
                    );

            COMMENT ON TABLE payment_run_items IS
                'A payable selected into a run, with the discount as it was evaluated at preparation - recomputing on release would change the amount the releaser approved - PF-174.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('payment_run_items')");

        /*
         | A released run's items and instructions are frozen too.
         |
         | Freezing only the header would leave the amounts editable under
         | a released instruction, which is the same failure wearing a
         | different hat: the run says 40,000 and the lines say something
         | else, and the file has already gone.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_released_run_is_frozen()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                run_state text;
                run_number text;
            BEGIN
                SELECT r.state, r.number
                INTO run_state, run_number
                FROM payment_runs r
                WHERE r.id = coalesce(NEW.run_id, OLD.run_id);

                IF run_state = 'released' THEN
                    RAISE EXCEPTION
                        'Payment run % has been released, so its lines cannot be changed. The run '
                        'would say one figure and its lines another, and the file has already gone.',
                        run_number
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN coalesce(NEW, OLD);
            END;
            $$;

            CREATE TRIGGER payment_run_items_are_frozen_once_released
                BEFORE INSERT OR UPDATE OR DELETE ON payment_run_items
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_released_run_is_frozen();

            CREATE TRIGGER payment_run_instructions_are_frozen_once_released
                BEFORE INSERT OR UPDATE OR DELETE ON payment_run_instructions
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_released_run_is_frozen();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payment_run_items_are_frozen_once_released ON payment_run_items;
            DROP TRIGGER IF EXISTS payment_run_instructions_are_frozen_once_released ON payment_run_instructions;
            DROP FUNCTION IF EXISTS peakflow_a_released_run_is_frozen();
            DROP TRIGGER IF EXISTS payment_runs_move_lawfully ON payment_runs;
            DROP FUNCTION IF EXISTS peakflow_a_payment_run_moves_lawfully();
        SQL);

        Schema::dropIfExists('payment_run_items');
        Schema::dropIfExists('payment_run_instructions');
        Schema::dropIfExists('payment_runs');
    }
};
