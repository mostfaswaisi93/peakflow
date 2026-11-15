<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reserved journal accepts postings only from the rule that owns it - PF-040, PF-052.
 *
 * Not `@financial`: this creates no table and no entity-scoped row, so the
 * marker's requirements - an entity_id column and a row-level security
 * policy - do not apply to it. The architecture rule said so, correctly, on
 * the first run.
 *
 * ## `reserved` was a comment
 *
 * `journals.reserved` has been there since the journals migration, with the
 * comment "these accept postings only from the process that owns them". It
 * was read by nothing. Any rule could post into the opening-balances journal,
 * and an opening balance typed by hand six months into the year is
 * indistinguishable from the real one - found a year later by whoever
 * compares this year's opening to last year's closing.
 *
 * ## Declared on the journal, not hard-coded in the trigger
 *
 * The journal carries the key of the rule that may post into it. A trigger
 * with a hard-coded list would have to be migrated every time a reserved
 * process is added, and a tenant whose chart template differs would be
 * enforcing somebody else's list.
 *
 * ## Why not just check the rule is non-null
 *
 * Because every posting has a rule - `assertBalanced` refuses one without
 * it. The question is not whether a rule posted but WHICH, and the answer
 * has to be a specific key or the control is "any automated process", which
 * includes every automated process.
 *
 * @online-safe Adds one nullable column and one trigger.
 *
 * @unsafe-migration-reviewed: the backfill UPDATEs are bounded to the
 * journals table - a handful of rows per entity, tens per tenant - and the
 * `dropColumn` is in `down()`, dropping the column this migration adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            /*
             | Which rule may post here, when the journal is reserved.
             |
             | Null on an ordinary journal, which takes postings from any
             | rule - a sale, a receipt, a manual entry.
             */
            $table->string('posting_rule', 64)->nullable();
        });

        /*
         | The three reserved journals the chart template creates.
         |
         | By journal TYPE rather than by code, because the code is the
         | tenant's and the type is the template's - a tenant who renames
         | `CLS` to `YEC` keeps the behaviour.
         */
        DB::table('journals')->where('type', 'opening')->whereNull('posting_rule')
            ->update(['posting_rule' => 'ledger.opening_balance', 'updated_at' => now()]);

        DB::table('journals')->where('type', 'closing')->whereNull('posting_rule')
            ->update(['posting_rule' => 'ledger.year_end_close', 'updated_at' => now()]);

        DB::table('journals')->where('type', 'revaluation')->whereNull('posting_rule')
            ->update(['posting_rule' => 'ledger.fx_revaluation', 'updated_at' => now()]);

        DB::unprepared(<<<'SQL'
            -- A reserved journal with no rule reserves nothing, and an
            -- ordinary journal with one is a reservation nobody declared.
            ALTER TABLE journals
                ADD CONSTRAINT journals_reservation_is_complete
                    CHECK ((reserved = false AND posting_rule IS NULL)
                           OR (reserved = true AND posting_rule IS NOT NULL))
                    NOT VALID;

            CREATE OR REPLACE FUNCTION assert_journal_accepts_rule()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                journal record;
            BEGIN
                SELECT code, name_en, reserved, posting_rule INTO journal
                FROM journals
                WHERE id = NEW.journal_id;

                IF journal IS NULL THEN
                    RAISE EXCEPTION
                        'Journal entry % names journal %, which does not exist.',
                        NEW.number, NEW.journal_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF NOT journal.reserved THEN
                    RETURN NEW;
                END IF;

                /*
                 | A REVERSAL is always allowed in a reserved journal.
                 |
                 | This was missing and it mattered: without it, reversing
                 | an opening balance or a year-end close was refused, which
                 | would have made PF-052's own requirement - that a close
                 | is reversible - unachievable. The guard caught it on the
                 | first run of the suite.
                 |
                 | Allowed because a reversal is not a new assertion about
                 | the world: it mirrors an entry that is already there,
                 | line for line, and the ledger's reverser is the only
                 | thing that writes one. Refusing it would mean a reserved
                 | entry could never be corrected at all.
                 */
                IF NEW.posting_rule = 'ledger.reversal' THEN
                    RETURN NEW;
                END IF;

                IF NEW.posting_rule IS DISTINCT FROM journal.posting_rule THEN
                    RAISE EXCEPTION
                        'The % journal (%) accepts postings only from "%", and entry % came from %. An opening balance or a closing entry typed by hand is indistinguishable from the real one, and is found a year later by whoever compares this year''s opening to last year''s closing.',
                        journal.name_en, journal.code, journal.posting_rule, NEW.number,
                        coalesce('"' || NEW.posting_rule || '"', 'no rule at all')
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER journal_entries_journal_accepts_rule
                BEFORE INSERT OR UPDATE ON journal_entries
                FOR EACH ROW
                EXECUTE FUNCTION assert_journal_accepts_rule();

            COMMENT ON COLUMN journals.posting_rule IS
                'The one rule that may post into this journal, when it is reserved. Null on an ordinary '
                'journal. Declared here rather than hard-coded in the trigger so a tenant whose chart '
                'template differs is not enforcing somebody else''s list.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_entries_journal_accepts_rule ON journal_entries;
            DROP FUNCTION IF EXISTS assert_journal_accepts_rule();

            ALTER TABLE journals DROP CONSTRAINT IF EXISTS journals_reservation_is_complete;
        SQL);

        Schema::table('journals', function (Blueprint $table): void {
            $table->dropColumn('posting_rule');
        });
    }
};
