<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An entry records the state its period was in when it was posted - PF-054.
 *
 * ## Why this column exists, which is a story about a check that was wrong
 *
 * `ledger:verify` is supposed to report an entry that entered a period not
 * accepting postings. Its first version asked whether the entry's period is
 * closed TODAY - which flags every entry in every closed period from the
 * first month-end onwards. Thousands of critical findings, all of them
 * correct behaviour.
 *
 * The second version read the state from the transition log, taking the
 * latest transition at or before the entry's `posted_at`. That is the right
 * question and the data cannot answer it: `now()` in PostgreSQL is the
 * TRANSACTION timestamp, so a close and a posting in the same transaction
 * carry the identical instant, and the ordering between them is arbitrary.
 * Measured: a year-end close and its reversal produced eight transitions and
 * three entries, all stamped the same second.
 *
 * So the state is recorded rather than reconstructed. The period trigger is
 * already a BEFORE trigger that reads the period's state in order to decide
 * whether to refuse - it now writes down what it saw, which costs nothing
 * and turns a guess into a fact.
 *
 * ## And NULL is the signal
 *
 * An entry posted with the trigger disabled gets no value. So "posted and
 * recording no state" is itself the finding - the trace a bypass cannot help
 * leaving, because the column is only ever written by the thing that was
 * bypassed.
 *
 * Entries posted before this migration are also NULL, which is why the
 * verifier separates them by date rather than treating every old entry as a
 * bypass.
 *
 * @online-safe One nullable column and a function replacement. No rewrite, no
 *              backfill: an old entry's period state cannot be recovered and
 *              inventing one would certify a guess.
 *
 * @unsafe-migration-reviewed: the only flagged statement is the `dropColumn`
 * in `down()`, dropping the column this migration adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('period_state_when_posted', 16)->nullable();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN journal_entries.period_state_when_posted IS
                'The period''s state at the instant this entry was posted, written by '
                'assert_period_accepts_postings() - which read it anyway in order to decide whether to '
                'refuse. Recorded rather than reconstructed because now() is the transaction timestamp, so '
                'a close and a posting in one transaction share an instant and cannot be ordered. NULL on '
                'entries posted before this column existed, and on any entry written with the trigger '
                'disabled - which is the trace a bypass cannot avoid leaving.';

            /*
             | The same function, with one assignment added.
             |
             | Replaced rather than wrapped: a second trigger doing the
             | recording would be a second place to keep in step with the
             | refusal logic, and the whole value of the column is that it
             | records what the REFUSAL saw.
             */
            CREATE OR REPLACE FUNCTION assert_period_accepts_postings()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                period record;
            BEGIN
                -- A draft is not a posting. It may be prepared against a
                -- period that is not open yet, which is how next month's
                -- recurring entries are staged.
                IF NEW.status <> 'posted' THEN
                    RETURN NEW;
                END IF;

                SELECT label, state INTO period FROM periods WHERE id = NEW.period_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION
                        'This entry names no period. An accounting date outside every defined period has no '
                        'home, and posting it to nothing would make it invisible in every report.'
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                -- What this trigger saw, written down. Set before every
                -- return path below, so an accepted entry always carries it.
                NEW.period_state_when_posted := period.state;

                IF period.state = 'open' THEN
                    RETURN NEW;
                END IF;

                /*
                 | A soft-closed period still accepts ADJUSTING entries - that
                 | is what the state is for: the operational close has
                 | happened, the audit has not finished, and the adjustments
                 | the auditor asks for still have to land somewhere.
                 |
                 | The application sets `peakflow.posting_rule` to 'adjusting'
                 | only after checking the `period.post_adjusting` permission
                 | and the step-up assertion, so this trusts a value the
                 | application had to earn.
                 */
                IF period.state = 'soft_closed'
                   AND coalesce(current_setting('peakflow.posting_rule', true), '') = 'adjusting' THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION
                    'Period % is %, so it does not accept this posting. A closed period has been reported on; '
                    'an entry arriving in it afterwards changes a figure somebody has already published. Post '
                    'it to an open period, or reopen this one - which is permissioned, audited and appears on '
                    'the compliance report.',
                    period.label, period.state
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        /*
         | The function is NOT restored here.
         |
         | Its only change is an assignment to a column that is about to be
         | dropped, and `CREATE OR REPLACE` of the previous body belongs to
         | the migration that owns it. Dropping the column makes the
         | assignment fail, so the function is replaced with the original
         | text first.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_period_accepts_postings()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                period record;
            BEGIN
                IF NEW.status <> 'posted' THEN
                    RETURN NEW;
                END IF;

                SELECT label, state INTO period FROM periods WHERE id = NEW.period_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'This entry names no period.'
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF period.state = 'open' THEN
                    RETURN NEW;
                END IF;

                IF period.state = 'soft_closed'
                   AND coalesce(current_setting('peakflow.posting_rule', true), '') = 'adjusting' THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'Period % is %, so it does not accept this posting.',
                    period.label, period.state
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
        SQL);

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('period_state_when_posted');
        });
    }
};
