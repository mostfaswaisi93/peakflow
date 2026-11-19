<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The immutability trigger could not speak on a journal line - PF-054.
 *
 * ## What it did
 *
 * `refuse_posted_mutation()` guards `journal_entries` and `journal_lines`
 * with one function. For a line it looks the parent entry up and reads
 * `entry.status`, which is right. But the clause that permits the ONE legal
 * transition on a posted entry - marking it reversed - was written as a
 * single condition:
 *
 *     IF TG_TABLE_NAME = 'journal_entries'
 *        AND TG_OP = 'UPDATE'
 *        AND OLD.status = 'posted'
 *        ...
 *
 * plpgsql compiles that whole condition as one SQL expression, so every
 * field reference in it is resolved whatever the first term evaluates to.
 * `OLD` on a `journal_lines` row has no `status`, no `number` and no
 * `journal_id`, so an UPDATE to a line of a posted entry raised:
 *
 *     SQLSTATE[42703] record "old" has no field "status"
 *
 * The row was still refused, which is why this survived: a check asking
 * only "did something throw" sees a guard working. What the operator saw
 * was an error about a missing column - so the one thing they had actually
 * done, edit a posted entry, appeared nowhere in the sentence, and the
 * message sent them to look for a bug in the schema.
 *
 * Measured by strengthening `LedgerEnforcementRules::postedEntriesAreImmutable()`
 * to assert what the refusal SAYS rather than that one happened, and to try
 * the lines as well as the header - it had only ever tried the header, so
 * this branch had never run.
 *
 * ## The fix is the nesting, not the condition
 *
 * The condition itself is correct. Nesting it inside the table-and-operation
 * test means plpgsql compiles the inner expression only when that test
 * passes, and when it passes `OLD` is a `journal_entries` row and every
 * field exists. The same trick the manual says to use, for the same reason.
 *
 * ## What this does NOT change
 *
 * A DELETE still answers `42501 permission denied`, because DELETE is
 * revoked for the application role and the privilege refuses before any
 * trigger runs. That is the stronger layer answering first and it stays:
 * granting DELETE so the trigger could produce a nicer sentence would trade
 * a guarantee for a message. The check asserts that a delete is refused by
 * the PRIVILEGE and an update by the TRIGGER, so each layer is proved to be
 * the one that answered.
 *
 * @online-safe Replaces one function body. No table is touched, no lock is
 *              taken on data, and the triggers that call it are unchanged.
 */
return new class extends Migration
{
    public function up(): void
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
                 |
                 | NESTED, and that is the whole of this migration. plpgsql
                 | compiles a condition as one SQL expression and resolves
                 | every field reference in it regardless of what the first
                 | term evaluates to - so with this flattened into a single
                 | `IF`, `OLD.status` was resolved against a `journal_lines`
                 | row and raised 42703 instead of the message below.
                 */
                IF TG_TABLE_NAME = 'journal_entries' AND TG_OP = 'UPDATE' THEN
                    IF OLD.status = 'posted'
                       AND NEW.status = 'reversed'
                       AND NEW.id = OLD.id
                       AND NEW.entity_id = OLD.entity_id
                       AND NEW.period_id = OLD.period_id
                       AND NEW.journal_id = OLD.journal_id
                       AND NEW.number = OLD.number
                       AND NEW.accounting_date = OLD.accounting_date THEN
                        RETURN NEW;
                    END IF;
                END IF;

                RAISE EXCEPTION
                    'Journal entry % is posted and cannot be % . Correct it with a reversal and a new entry: '
                    'the original was reported on, and a report that no longer reconciles to the ledger it was '
                    'printed from cannot be explained to anyone.',
                    entry.number, lower(TG_OP)
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        /*
         | Deliberately not a revert.
         |
         | Putting the flattened condition back would restore a trigger that
         | raises the wrong error on a journal line, and `down()` exists to
         | undo a change that turned out to be wrong rather than to reinstate
         | a defect. The function this replaces is equivalent in what it
         | REFUSES; only the message differs. Rolling this migration back
         | leaves the corrected function in place, which is the safe
         | direction, and the next `up()` is idempotent.
         */
    }
};
