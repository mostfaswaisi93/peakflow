<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invariants that make this a ledger - PF-043, PF-044, PF-045.
 *
 * Deliberately NOT marked `@financial`: that marker means "this migration
 * creates financial tables", and the architecture rule reading it then
 * requires an `entity_id` column and a row-level security policy. This
 * migration creates no tables at all - it installs triggers on tables that
 * already carry both.
 *
 * Every rule here is expressed in PostgreSQL. The application checks them too,
 * but only so the error message is useful. An application can be bypassed by a
 * console command, a migration, a REPL session or a bug in an ORM upgrade; a
 * deferred constraint trigger cannot.
  *
 * @online-safe Creates functions and the triggers that call them - the balanced-entry
 *              constraint trigger, the period gate and the posted-mutation refusal.
 *              `CREATE OR REPLACE FUNCTION` rewrites a catalog row and takes no lock
 *              on any table's data; the triggers are added to tables this release
 *              creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->balancedEntry();
        $this->closedPeriods();
        $this->immutability();
    }

    /**
     * An entry's lines sum to zero in the FUNCTIONAL currency - PF-043.
     */
    private function balancedEntry(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_entry_balanced()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                entry_id  char(26);
                total     numeric(21,4);
                line_count int;
                entry_number text;
            BEGIN
                entry_id := coalesce(NEW.journal_entry_id, OLD.journal_entry_id);

                SELECT coalesce(sum(functional_amount), 0), count(*)
                INTO total, line_count
                FROM journal_lines
                WHERE journal_entry_id = entry_id;

                -- Every line of the entry was deleted. That is a draft being
                -- discarded, not an unbalanced entry.
                IF line_count = 0 THEN
                    RETURN NULL;
                END IF;

                IF total <> 0 THEN
                    SELECT number INTO entry_number FROM journal_entries WHERE id = entry_id;

                    RAISE EXCEPTION
                        'Journal entry % does not balance: its lines sum to % in the functional currency, and '
                        'must sum to zero. If this entry records a transaction in another currency, the '
                        'difference belongs on an explicit exchange-difference line rather than being left '
                        'implicit.', coalesce(entry_number, entry_id), total
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$;

            /*
             | DEFERRABLE INITIALLY DEFERRED, and the deferral IS the mechanism.
             |
             | An entry is transiently unbalanced while its lines are being
             | inserted - after the first line and before the last, the sum is
             | never zero. A trigger that fired per statement would therefore
             | reject every multi-line entry ever written, which is all of
             | them.
             |
             | Firing at COMMIT is what lets the invariant be absolute while
             | still permitting the only way an entry can physically be
             | written. Do not "fix" this to fire earlier: the test suite will
             | pass, because a test that inserts a balanced entry in one
             | statement is the one case that works, and every real posting
             | will fail.
             |
             | A CONSTRAINT TRIGGER must be FOR EACH ROW, so this runs once per
             | line at commit. The check is a single indexed aggregate over the
             | entry's own lines, which is why that is affordable.
             */
            CREATE CONSTRAINT TRIGGER journal_lines_entry_balances
                AFTER INSERT OR UPDATE OR DELETE ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION assert_entry_balanced();
        SQL);
    }

    /**
     * A posting lands only in a period whose state accepts it - PF-044.
     */
    private function closedPeriods(): void
    {
        DB::unprepared(<<<'SQL'
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
                    'posting into it after the fact changes a figure somebody has already acted upon.',
                    period.label, period.state
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER journal_entries_period_accepts
                BEFORE INSERT OR UPDATE ON journal_entries
                FOR EACH ROW
                EXECUTE FUNCTION assert_period_accepts_postings();
        SQL);
    }

    /**
     * A posted entry is never edited - PF-045.
     *
     * Not "should not be": cannot be. A correction to a posted entry is a
     * REVERSAL plus a new entry, because the original was reported on and the
     * report has to stay explicable. An edited posting leaves a trial balance
     * that no longer reconciles to the statements printed from it, and no
     * record of why.
     */
    private function immutability(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_posted_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                entry record;
            BEGIN
                IF TG_TABLE_NAME = 'journal_entries' THEN
                    entry := OLD;
                ELSE
                    SELECT * INTO entry FROM journal_entries WHERE id = OLD.journal_entry_id;

                    -- The entry is going too, in the same statement. Deleting
                    -- a draft takes its lines with it.
                    IF NOT FOUND THEN
                        RETURN OLD;
                    END IF;
                END IF;

                IF entry.status = 'draft' THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;

                /*
                 | The one permitted transition on a posted entry: marking it
                 | reversed. The reversal itself is a separate entry, and this
                 | only records that it happened.
                 */
                IF TG_TABLE_NAME = 'journal_entries'
                   AND TG_OP = 'UPDATE'
                   AND OLD.status = 'posted'
                   AND NEW.status = 'reversed'
                   AND NEW.id = OLD.id
                   AND NEW.entity_id = OLD.entity_id
                   AND NEW.period_id = OLD.period_id
                   AND NEW.journal_id = OLD.journal_id
                   AND NEW.number = OLD.number
                   AND NEW.accounting_date = OLD.accounting_date THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION
                    'Journal entry % is posted and cannot be % . Correct it with a reversal and a new entry: '
                    'the original was reported on, and a report that no longer reconciles to the ledger it was '
                    'printed from cannot be explained to anyone.',
                    entry.number, lower(TG_OP)
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER journal_entries_posted_immutable
                BEFORE UPDATE OR DELETE ON journal_entries
                FOR EACH ROW
                EXECUTE FUNCTION refuse_posted_mutation();

            CREATE TRIGGER journal_lines_posted_immutable
                BEFORE UPDATE OR DELETE ON journal_lines
                FOR EACH ROW
                EXECUTE FUNCTION refuse_posted_mutation();
        SQL);

        /*
         | The second layer: DELETE revoked for the application role.
         |
         | Declared with a marker rather than a hardcoded role name, because
         | the role is generated per tenant - the same lesson the audit log
         | taught, where a revoke naming `peakflow_app` was a well-formed
         | no-op against a role that never connects.
         |
         | UPDATE is NOT revoked: a draft is edited before it is posted, and
         | the trigger above is what distinguishes the two.
         */
        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE journal_entries IS
                'peakflow:no-delete - a posted entry is corrected by reversal, never removed. DELETE is revoked '
                'from the application role by TenantProvisioner::harden().';

            COMMENT ON TABLE journal_lines IS
                'peakflow:no-delete - see journal_entries. DELETE is revoked from the application role.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_posted_immutable ON journal_lines;
            DROP TRIGGER IF EXISTS journal_entries_posted_immutable ON journal_entries;
            DROP TRIGGER IF EXISTS journal_entries_period_accepts ON journal_entries;
            DROP TRIGGER IF EXISTS journal_lines_entry_balances ON journal_lines;

            DROP FUNCTION IF EXISTS refuse_posted_mutation();
            DROP FUNCTION IF EXISTS assert_period_accepts_postings();
            DROP FUNCTION IF EXISTS assert_entry_balanced();
        SQL);
    }
};
