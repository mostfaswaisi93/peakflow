<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opening balances may be posted only before anything else is - PF-038.
 *
 * ## The one hole in the control-account rule, closed to a window
 *
 * A control account may be reached only by a registered posting rule: that is
 * what keeps a subledger equal to its control account, and it is the invariant
 * that cannot be restored once a human has posted into one by hand.
 *
 * Opening balances are the exception, because no document produces them.
 * Somebody has to type what the receivables were on the day the books opened.
 * `ledger.opening_balance` is a registered rule, so the database already lets
 * it through - which means the exception, as it stood, was permanent: the rule
 * could be used in June to adjust a receivable, and the entry would be
 * indistinguishable from the real opening balance.
 *
 * So the exception becomes a WINDOW. It is open while the entity has no other
 * posting and closed forever after the first one. An opening balance typed six
 * months into the year is the thing this prevents, and it is found a year
 * later by whoever compares this opening to last year's closing.
 *
 * ## Enforced here, not only in the service
 *
 * The service checks the permission, writes the audit entry and refuses with
 * an explanation - all of which a backfill script, a psql session and a future
 * bug go around. This trigger is what they cannot.
 *
 * ## A reversal is exempt
 *
 * Opening balances get typed wrong, and the correction is a reversal and a new
 * entry. Refusing the reversal would mean the only way to fix a mistyped
 * opening balance is to edit a posted entry - which is the thing the whole
 * module refuses. The reversal is not a new assertion about the world: it
 * mirrors an entry that is already there, line for line.
 *
 * @online-safe One trigger. No table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_opening_balances_are_first()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                blocker record;
            BEGIN
                IF NEW.status <> 'posted' OR NEW.posting_rule IS DISTINCT FROM 'ledger.opening_balance' THEN
                    RETURN NEW;
                END IF;

                /*
                 | A reversal of an opening balance is still an opening
                 | balance posting by rule, and it is allowed whenever: see
                 | the migration note.
                 */
                IF NEW.reverses_entry_id IS NOT NULL THEN
                    RETURN NEW;
                END IF;

                SELECT e.number, e.accounting_date, e.posting_rule
                INTO blocker
                FROM journal_entries e
                WHERE e.entity_id = NEW.entity_id
                  AND e.id <> NEW.id
                  AND e.status IN ('posted', 'reversed')
                  AND coalesce(e.posting_rule, '') <> 'ledger.opening_balance'
                ORDER BY e.accounting_date, e.number
                LIMIT 1;

                IF blocker IS NULL THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION
                    'Opening balances cannot be posted now: entry % of % (%) is already in this entity''s '
                    'ledger. The opening-balance rule is the one way a control account can be reached by '
                    'hand, so it is open only before the books have anything in them - an opening balance '
                    'typed six months into the year is indistinguishable from the real one, and is found a '
                    'year later by whoever compares this opening to last year''s closing. Correct an '
                    'opening balance with a reversal and a new one.',
                    blocker.number, blocker.accounting_date, coalesce(blocker.posting_rule, 'manual')
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER journal_entries_opening_balances_are_first
                BEFORE INSERT ON journal_entries
                FOR EACH ROW
                EXECUTE FUNCTION assert_opening_balances_are_first();

            COMMENT ON FUNCTION assert_opening_balances_are_first() IS
                'The opening-balance window: open while the entity has no other posting, closed forever '
                'after the first. SECURITY DEFINER so it can see the entity''s other entries under '
                'row-level security. A reversal is exempt, because the alternative to reversing a '
                'mistyped opening balance is editing a posted entry.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_entries_opening_balances_are_first ON journal_entries;
            DROP FUNCTION IF EXISTS assert_opening_balances_are_first();
        SQL);
    }
};
