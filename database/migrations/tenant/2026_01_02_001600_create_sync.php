<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a device sent, once - PF-255.
 *
 * ## Idempotency is the contract, not a nicety
 *
 * A network that drops a RESPONSE is indistinguishable from one that
 * drops a REQUEST. The till cannot tell them apart and must retry, so
 * the server has to make retrying safe - and the only way is for the
 * client to name the write.
 *
 * `client_id` is generated on the device, before the first attempt, and
 * is unique per tenant. The second arrival of the same id does not
 * create a second record: it returns what the first one produced. That
 * is why `outcome` is stored and not only the fact of receipt - a retry
 * has to be answerable with the same answer, or the till shows a
 * different total from the server.
 *
 * ## Ordering is by the SERVER's receipt
 *
 * `received_seq` is a bigint sequence assigned here, on arrival. A
 * till's clock is wrong often: a tablet that has been in a drawer for a
 * month comes back with last month's date, and one whose battery died
 * comes back with the epoch.
 *
 * So `occurred_at` - the device's own clock - is recorded and never
 * used to order anything or to date a posting. A till with the wrong
 * date must not be able to post into a closed period, and the way that
 * happens is a product that trusts `occurred_at` because it is more
 * convenient than explaining the difference.
 *
 * ## Conflicts are surfaced, never merged
 *
 * Two tills selling the last unit is a FACT somebody has to decide
 * about. A last-write-wins rule decides it by latency - which is to say
 * by which shop has better wifi - and the decision is invisible.
 *
 * So a submission that cannot be applied becomes a row in
 * `sync_conflicts` with both sides on it, and somebody resolves it. The
 * cost is a queue a person has to work; the alternative is stock that
 * is quietly wrong and a customer who paid for something that is not
 * there.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_submissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | The device's own id for this write, generated BEFORE the
             | first attempt.
             |
             | A server-generated key cannot do this job: the till needs
             | the id to retry with, and it has to have it before it
             | knows whether the first attempt arrived.
             */
            /*
             | `varchar`, not `char`.
             |
             | `char` is blank-padded in PostgreSQL: a 20-character id
             | comes back as 20 characters and 44 spaces. Every
             | comparison in application code then fails, and an API
             | that echoes the id back returns it padded - so the till
             | cannot match our answer to its own queue entry, which is
             | the one thing this column exists for. Found by a check
             | comparing the log's order against the ids it sent.
             */
            $table->string('client_id', 64);
            $table->ulid('device_id');

            $table->string('kind', 48);
            $table->jsonb('payload');

            /*
             | The device's clock, recorded and never trusted.
             |
             | A tablet that has been in a drawer for a month comes back
             | with last month's date. This column exists so the skew is
             | VISIBLE - "the till says 400 and the server says 380" is
             | otherwise unanswerable - and nothing orders or dates
             | anything by it.
             */
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');

            /*
             | The order things actually happened in, as far as anybody
             | can know: the order they arrived.
             |
             | A bigint from a sequence, not a timestamp. Two
             | submissions in the same microsecond are possible and a
             | total order is what replay needs.
             */
            $table->bigIncrements('received_seq');

            $table->string('state', 16)->default('accepted');
            $table->text('outcome')->nullable();
            $table->jsonb('result')->nullable();

            $table->timestamps();

            /*
             | THE contract: one write per client id, per tenant.
             |
             | Not per device - a device that is re-registered keeps its
             | queue, and a replay from the new registration must not
             | create a second record of the same sale.
             */
            $table->unique('client_id');
            $table->index(['entity_id', 'device_id', 'received_seq']);
            $table->index(['entity_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE sync_submissions
                ADD CONSTRAINT sync_submissions_state_is_known
                    CHECK (state IN ('accepted', 'applied', 'conflicted', 'rejected')),

                /*
                 | A finished submission says what it produced.
                 |
                 | Because a retry has to be answerable with the SAME
                 | answer. A row that recorded only "we got this" sends
                 | the till a different total on the second attempt, and
                 | the till is the thing the customer is looking at.
                 */
                ADD CONSTRAINT sync_submissions_a_finished_one_has_an_outcome
                    CHECK (
                        state = 'accepted'
                        OR length(btrim(coalesce(outcome, ''))) >= 10
                    );

            COMMENT ON TABLE sync_submissions IS
                'Every write a device sent, with the order it ARRIVED in - a network that drops a response is indistinguishable from one that drops a request, so the client names the write - PF-255.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('sync_submissions')");

        /*
         | A submission is a fact. Its outcome is written once.
         |
         | Re-applying one would create a second record of a sale that
         | happened once, which is the exact failure the client id
         | exists to prevent - so the transition is guarded here rather
         | than only in the service that performs it.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_submission_settles_once()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.state <> 'accepted' AND NEW.state <> OLD.state THEN
                    RAISE EXCEPTION
                        'Submission % is already %. Settling it again would make a second record of something that happened once, which is what the client id exists to prevent - a retry has to be answered with the SAME answer, not a new one.',
                        OLD.client_id, OLD.state
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.client_id <> OLD.client_id
                   OR NEW.payload <> OLD.payload
                   OR NEW.occurred_at <> OLD.occurred_at
                   OR NEW.received_seq <> OLD.received_seq THEN
                    RAISE EXCEPTION
                        'What a device sent, and when it arrived, is a fact. Changing it rewrites the answer to "the till says 400 and the server says 380".'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER sync_submissions_settle_once
                BEFORE UPDATE ON sync_submissions
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_submission_settles_once();

            CREATE OR REPLACE FUNCTION peakflow_a_submission_is_not_deleted()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'A sync submission is the record of what a device sent. Removing one makes the difference between the till and the server unexplainable, which is the question this log exists to answer.'
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE TRIGGER sync_submissions_are_not_deleted
                BEFORE DELETE ON sync_submissions
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_submission_is_not_deleted();
        SQL);

        Schema::create('sync_conflicts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('submission_id', 26);

            $table->string('kind', 48);

            /*
             | Both sides, on the row.
             |
             | "The till sold 3 and the shelf had 1" is a decision
             | somebody can make. A conflict that recorded only "refused"
             | sends them to two screens and a clock.
             */
            $table->text('what_the_device_said');
            $table->text('what_the_server_says');

            $table->string('state', 16)->default('open');
            $table->text('decision')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->unique('submission_id');
            $table->index(['entity_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE sync_conflicts
                ADD CONSTRAINT sync_conflicts_state_is_known
                    CHECK (state IN ('open', 'resolved', 'abandoned')),

                ADD CONSTRAINT sync_conflicts_name_both_sides
                    CHECK (
                        length(btrim(what_the_device_said)) >= 5
                        AND length(btrim(what_the_server_says)) >= 5
                    ),

                /*
                 | A closed conflict is attributable and explained.
                 |
                 | This is a human decision about money or stock -
                 | "we honoured the sale and wrote the unit off" - and it
                 | is the only record that it was made.
                 */
                ADD CONSTRAINT sync_conflicts_a_decision_has_a_name
                    CHECK (
                        state = 'open'
                        OR (
                            decided_by IS NOT NULL
                            AND decided_at IS NOT NULL
                            AND length(btrim(coalesce(decision, ''))) >= 10
                        )
                    );

            COMMENT ON TABLE sync_conflicts IS
                'Two tills selling the last unit is a fact somebody has to decide about - a last-write-wins rule decides it by which shop has better wifi - PF-255.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('sync_conflicts')");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS sync_submissions_are_not_deleted ON sync_submissions;
            DROP TRIGGER IF EXISTS sync_submissions_settle_once ON sync_submissions;
            DROP FUNCTION IF EXISTS peakflow_a_submission_is_not_deleted();
            DROP FUNCTION IF EXISTS peakflow_a_submission_settles_once();
        SQL);

        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_submissions');
    }
};
