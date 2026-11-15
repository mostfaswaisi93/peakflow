<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of every period state change - PF-050.
 *
 * @financial
 *
 * ## A state column cannot answer the question that gets asked
 *
 * `periods.state` says what a period is now. The question is "who reopened
 * February, when, and what did they say", and that is the first question in
 * any review of a restated figure.
 *
 * `closed_at` and `closed_by_user_id` on the period answer it for the most
 * recent close and lose it on the next one. A period closed in March,
 * reopened in April and closed again in May has one set of columns and three
 * events, and the two that are overwritten are the interesting ones.
 *
 * ## And the state cannot move without a record
 *
 * Enforced by a trigger, for the usual reason: a state change through a
 * console session or a support script is exactly the one somebody will want
 * explained later. Without the trigger the log is a convention, and a
 * convention is a log with gaps in it.
 *
 * ## What the pre-flight flags
 *
 * A unique index on `periods` built without CONCURRENTLY, and a `dropColumn`
 * in `down()`.
 *
 * @unsafe-migration-reviewed: the unique index is on `periods`, which holds
 * one row per month per entity - tens of rows, built instantly. Building it
 * concurrently would need its own transactionless migration for an index
 * that cannot take a meaningful lock. The `dropColumn` is in `down()`, which
 * runs on a deliberate rollback, and it drops the column this migration
 * adds: leaving it behind would leave the state machine writing a column
 * whose guard no longer exists, which is worse than dropping it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_transitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->foreignUlid('period_id')->constrained('periods')->cascadeOnDelete();

            $table->string('from_state', 16);
            $table->string('to_state', 16);

            /*
             | Flagged rather than derived from the pair.
             |
             | `closed -> open` is a reopening and `future -> open` is a
             | period starting, and the compliance report wants the first
             | and not the second. Deriving it means every reader of this
             | table reimplements the distinction, and one of them will get
             | it wrong in the direction that hides a reopening.
             */
            $table->boolean('is_reopening')->default(false);

            $table->text('reason');
            $table->ulid('by_user_id')->nullable();
            $table->timestamp('at');

            $table->timestamps();

            $table->index(['entity_id', 'period_id', 'at']);
            $table->index(['entity_id', 'is_reopening', 'at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE period_transitions
                ADD CONSTRAINT period_transitions_states_known
                    CHECK (from_state IN ('future', 'open', 'soft_closed', 'closed', 'locked')
                           AND to_state IN ('future', 'open', 'soft_closed', 'closed', 'locked')),

                -- A transition to the state it came from records that
                -- something happened when nothing did.
                ADD CONSTRAINT period_transitions_states_differ
                    CHECK (from_state <> to_state),

                /*
                 | Nothing comes back from locked.
                 |
                 | The period trigger already refuses it; this says the same
                 | thing about the LOG, so a row claiming it happened cannot
                 | be written either - which is what somebody faking a trail
                 | would try after finding the trigger.
                 */
                ADD CONSTRAINT period_transitions_locked_is_terminal
                    CHECK (from_state <> 'locked'),

                ADD CONSTRAINT period_transitions_reason_is_not_empty
                    CHECK (length(btrim(reason)) > 0),

                /*
                 | A reopening is a move TO open from a state that had
                 | closed it. `future -> open` is a period starting.
                 */
                ADD CONSTRAINT period_transitions_reopening_is_one
                    CHECK (is_reopening = false
                           OR (to_state = 'open' AND from_state IN ('soft_closed', 'closed')));
        SQL);

        /*
         | The period points AT its latest transition.
         |
         | The first version of the guard looked for a transition row with
         | a matching from/to inside the last second, and that was too
         | loose: a check moved a period open to soft_closed through the
         | machine, and a bare UPDATE doing the same transition moments
         | later was accepted because the machine's own row satisfied it.
         |
         | A support script repeating a transition the application had just
         | made would have slipped through the same way.
         |
         | So a transition row is CONSUMED. The period carries the id of the
         | one that moved it, the column is unique, and the row must match
         | the states being moved between - which means each row can be used
         | once, by one period, for one transition.
         */
        Schema::table('periods', function (Blueprint $table): void {
            $table->ulid('last_transition_id')->nullable();
            $table->unique('last_transition_id');
        });

        $this->installTransitionRequirement();

        DB::unprepared("SELECT peakflow_apply_entity_rls('period_transitions');");

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE period_transitions IS
                'peakflow:append-only - who moved a period, when, and why. periods.closed_at answers that for '
                'the most recent close and loses it on the next one; a period closed, reopened and closed '
                'again has one set of columns and three events, and the overwritten ones are the interesting '
                'ones.';
        SQL);
    }

    /**
     * A period's state cannot move without a transition row - PF-050.
     *
     * ## Why a trigger
     *
     * The state machine in the application writes both together. A console
     * session, a support script or a migration does not - and that change is
     * exactly the one somebody will want explained later. Without this the
     * log is a convention, and a convention is a log with gaps in it.
     *
     * ## How it finds the row
     *
     * The state machine inserts the transition first, so a legitimate change
     * always has a matching row already visible in the same transaction. A
     * bare UPDATE has none. The same shape as the credit-hold guard, for the
     * same reason.
     */
    private function installTransitionRequirement(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_period_change_is_recorded()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                recorded boolean;
            BEGIN
                IF NEW.state IS NOT DISTINCT FROM OLD.state THEN
                    RETURN NEW;
                END IF;

                /*
                 | The row is consumed, not merely found.
                 |
                 | `last_transition_id` is unique and must change, and the
                 | row it names must describe exactly the move being made.
                 | So a transition row works once - a bare UPDATE has no id
                 | to offer, and one that borrows the id of the row the
                 | application just used is refused because that id is
                 | already on the period.
                 */
                IF NEW.last_transition_id IS NULL
                   OR NEW.last_transition_id IS NOT DISTINCT FROM OLD.last_transition_id THEN
                    RAISE EXCEPTION
                        'Period % cannot move from % to % without a recorded transition of its own. "Who reopened this period, when, and what did they say" is the first question in any review of a restated figure, and the state column cannot answer it. Go through PeriodStateMachine.',
                        NEW.label, OLD.state, NEW.state
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT true INTO recorded
                FROM period_transitions
                WHERE id = NEW.last_transition_id
                  AND period_id = NEW.id
                  AND from_state = OLD.state
                  AND to_state = NEW.state
                LIMIT 1;

                IF recorded IS NULL THEN
                    RAISE EXCEPTION
                        'Period % names transition % for a move from % to %, and that row does not describe this move for this period. A transition record is the explanation for a state change; one that says something else explains nothing.',
                        NEW.label, NEW.last_transition_id, OLD.state, NEW.state
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            /*
             | AFTER the existing locked-is-terminal trigger.
             |
             | Triggers of the same type fire in alphabetical order, and
             | `periods_locked_is_terminal` sorts before
             | `periods_state_is_recorded` - so an attempt to move a locked
             | period is refused for being locked rather than for being
             | unrecorded, which is the more useful message.
             */
            CREATE TRIGGER periods_state_is_recorded
                BEFORE UPDATE ON periods
                FOR EACH ROW
                EXECUTE FUNCTION assert_period_change_is_recorded();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS periods_state_is_recorded ON periods;
            DROP FUNCTION IF EXISTS assert_period_change_is_recorded();
        SQL);

        Schema::table('periods', function (Blueprint $table): void {
            $table->dropUnique(['last_transition_id']);
            $table->dropColumn('last_transition_id');
        });

        Schema::dropIfExists('period_transitions');
    }
};
