<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paying the same supplier the same amount twice - PF-180.
 *
 * ## Why this has to look ACROSS entities
 *
 * A group of companies shares suppliers. The same invoice arrives at
 * two of them - forwarded by somebody being helpful - and each pays it.
 * Neither run looks wrong from inside its own entity, and the supplier
 * is paid twice from two bank accounts that reconcile perfectly.
 *
 * Every other query in this product is scoped to one entity by row
 * policy, deliberately and for good reasons. This one cannot be: a
 * detector that sees one entity cannot see the duplicate that matters
 * most.
 *
 * ## So the scan is a SECURITY DEFINER function, and a narrow one
 *
 * `peakflow_similar_payments` runs as the owner, which bypasses the row
 * policies - and returns the LEAST it can: the entity's code, the run's
 * number, the amount, the date and how far apart the two are. No
 * invoice numbers, no bank details, no party names beyond the one the
 * caller already named.
 *
 * That is the whole design decision. The alternative - clearing the
 * entity setting in application code and running an ordinary query -
 * would give the caller everything, in a path that is one refactor away
 * from being reused for something else. A function with a fixed
 * projection cannot be.
 *
 * The caller passes the party, so it already knows who it is paying.
 * What it learns is that somebody else is paying them too.
 *
 * ## A warning is acknowledged by a PERSON, with a reason
 *
 * Not dismissed. "Same supplier, same amount, four days apart" is
 * usually a duplicate and sometimes a monthly retainer, and the
 * difference is knowledge nobody can put in a rule. So the release is
 * blocked until somebody says which it is and signs their name to it -
 * and the signature is what an investigation reads six months later
 * when the supplier has been paid twice.
 *
 * @financial
  *
 * @online-safe Creates `payment_duplicate_warnings`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_duplicate_warnings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->char('run_id', 26);
            $table->char('instruction_id', 26);
            $table->char('party_id', 26);

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);

            /*
             | What it looks like, as a reason and a sentence.
             |
             | The reason is the shape - the same amount in the window,
             | the same amount in another entity, an amount already
             | released - and the sentence names the run, the entity and
             | the days apart. A code with no sentence is a warning
             | somebody dismisses because they cannot tell what it is
             | about.
             */
            $table->string('reason', 48);
            $table->text('detail');

            /*
             | Where the other one is, as text and not as a key.
             |
             | It may be in another entity, and a foreign key would
             | either fail the row policy on read or have to be read
             | through the definer function anyway. The identifying
             | facts are copied here at the moment the warning is
             | raised, which is also what makes the warning readable
             | after the other run is archived.
             */
            $table->string('other_entity_code', 32)->nullable();
            $table->string('other_reference', 64)->nullable();
            $table->date('other_date')->nullable();
            $table->integer('days_apart')->nullable();

            $table->timestamp('acknowledged_at')->nullable();
            $table->ulid('acknowledged_by')->nullable();
            $table->text('acknowledgement')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'run_id', 'acknowledged_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_duplicate_warnings
                ADD CONSTRAINT payment_duplicate_warnings_reason_is_known
                    CHECK (reason IN (
                        'same_amount_same_party_in_window',
                        'same_amount_another_entity',
                        'same_amount_already_released',
                        'same_amount_already_paid'
                    )),

                ADD CONSTRAINT payment_duplicate_warnings_say_what_it_looks_like
                    CHECK (length(btrim(detail)) >= 20),

                /*
                 | An acknowledgement is a name AND a sentence.
                 |
                 | "Same supplier, same amount, four days apart" is
                 | usually a duplicate and sometimes a monthly retainer.
                 | The difference is knowledge nobody can put in a rule,
                 | so the person says which it is - and a dismissal with
                 | no sentence is what an investigation finds instead of
                 | an answer.
                 */
                ADD CONSTRAINT payment_duplicate_warnings_acknowledgement_is_attributable
                    CHECK (
                        (acknowledged_at IS NULL AND acknowledged_by IS NULL AND acknowledgement IS NULL)
                        OR (
                            acknowledged_at IS NOT NULL
                            AND acknowledged_by IS NOT NULL
                            AND length(btrim(coalesce(acknowledgement, ''))) >= 15
                        )
                    );

            COMMENT ON TABLE payment_duplicate_warnings IS
                'Paying the same party the same amount twice - across runs AND across the tenant''s entities, because a group shares suppliers and neither run looks wrong from inside its own entity - PF-180.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('payment_duplicate_warnings')");

        /*
         | An acknowledgement is written once.
         |
         | The release reads it, so changing it afterwards would leave a
         | payment that went out against a reason that is no longer
         | there - and the name with it.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_an_acknowledgement_is_final()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.acknowledged_at IS NOT NULL
                   AND (
                       NEW.acknowledged_at IS DISTINCT FROM OLD.acknowledged_at
                       OR NEW.acknowledged_by IS DISTINCT FROM OLD.acknowledged_by
                       OR NEW.acknowledgement IS DISTINCT FROM OLD.acknowledgement
                   ) THEN
                    RAISE EXCEPTION
                        'That warning was accepted by % on %. Changing the acceptance would leave a payment that went out against a reason nobody can read any more - and the name with it.',
                        OLD.acknowledged_by, OLD.acknowledged_at
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payment_duplicate_warnings_are_accepted_once
                BEFORE UPDATE ON payment_duplicate_warnings
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_an_acknowledgement_is_final();
        SQL);

        /*
         | The scan, as a SECURITY DEFINER function.
         |
         | See the class note for why this is the one query in the
         | product that reads past the entity policy, and why it returns
         | a fixed projection rather than rows.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_similar_payments(
                /*
                 | The entity ASKING, which the function cannot work out
                 | for itself.
                 |
                 | The first draft classified a match by comparing the
                 | found run's entity against the found instruction's -
                 | and those are always the same entity, so a sister
                 | company's run came back labelled as one of our own.
                 | The whole point of the scan was lost to a comparison
                 | between two columns that agree by construction.
                 |
                 | `peakflow_current_entity()` is not an answer either: a
                 | definer function reading a session setting would
                 | silently classify everything as foreign when it is
                 | called with none set, which is how a report ends up
                 | warning about every payment.
                 */
                p_entity_id char(26),
                p_party_id char(26),
                p_amount numeric,
                p_currency char(3),
                p_on date,
                p_window_days int,
                p_exclude_run char(26)
            )
            RETURNS TABLE (
                source text,
                other_entity_code text,
                other_reference text,
                other_date date,
                days_apart int
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public
            AS $fn$
                /*
                 | Instructions in runs that are going out or have gone.
                 |
                 | A draft is not a duplicate: nothing has been checked
                 | and nothing can be released, so warning about one
                 | would make every warning mean less. Prepared counts,
                 | because a prepared run is one somebody is about to
                 | release.
                 */
                SELECT
                    CASE
                        WHEN pr.entity_id <> p_entity_id THEN 'same_amount_another_entity'
                        WHEN pr.state = 'released' THEN 'same_amount_already_released'
                        ELSE 'same_amount_same_party_in_window'
                    END AS source,
                    e.code::text,
                    pr.number::text,
                    pr.pay_on,
                    abs(pr.pay_on - p_on)::int
                FROM payment_run_instructions i
                JOIN payment_runs pr ON pr.id = i.run_id
                JOIN entities e ON e.id = pr.entity_id
                WHERE i.party_id = p_party_id
                  AND i.currency = p_currency
                  AND i.amount = p_amount
                  AND pr.state IN ('prepared', 'released')
                  AND pr.id <> coalesce(p_exclude_run, '')
                  AND abs(pr.pay_on - p_on) <= p_window_days

                UNION ALL

                /*
                 | And payments that have already gone out.
                 |
                 | Which is the half a run-only check misses: the first
                 | payment was recorded a week ago, its run is archived
                 | or it was entered by hand, and the second run has
                 | nothing to compare against.
                 */
                SELECT
                    'same_amount_already_paid' AS source,
                    e.code::text,
                    p.number::text,
                    p.value_date,
                    abs(p.value_date - p_on)::int
                FROM payments p
                JOIN entities e ON e.id = p.entity_id
                WHERE p.party_id = p_party_id
                  AND p.currency = p_currency
                  AND p.amount = p_amount
                  AND p.direction = 'disbursement'
                  AND p.reversed_at IS NULL
                  AND abs(p.value_date - p_on) <= p_window_days

                ORDER BY 5, 3
            $fn$;

            COMMENT ON FUNCTION peakflow_similar_payments IS
                'The one query that reads past the entity row policy, deliberately: a group of companies shares suppliers, the same invoice reaches two of them, and neither run looks wrong from inside its own entity. Returns a fixed projection - entity code, run number, date, days apart - and nothing else, so the path cannot be reused to read another entity''s data - PF-180.';
        SQL);

        /*
         | And the application role may call it.
         |
         | Explicitly, because the point of a definer function is that
         | its privileges are the owner's - so who may invoke it is the
         | whole access decision.
         */
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'peakflow_app') THEN
                    EXECUTE 'GRANT EXECUTE ON FUNCTION peakflow_similar_payments(char(26), char(26), numeric, char(3), date, int, char(26)) TO peakflow_app';
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payment_duplicate_warnings_are_accepted_once ON payment_duplicate_warnings;
            DROP FUNCTION IF EXISTS peakflow_an_acknowledgement_is_final();
            DROP FUNCTION IF EXISTS peakflow_similar_payments(char(26), char(26), numeric, char(3), date, int, char(26));
        SQL);

        Schema::dropIfExists('payment_duplicate_warnings');
    }
};
