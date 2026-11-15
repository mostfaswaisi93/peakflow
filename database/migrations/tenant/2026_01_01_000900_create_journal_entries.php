<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal entries and their lines - PF-041, PF-042, PF-046.
 *
 * @financial
 *
 * ## One signed amount, not a debit column and a credit column
 *
 * `amount` is signed: positive is a debit, negative is a credit. One
 * invariant - the lines of an entry sum to zero - instead of two, and no
 * "which column did this go in" bug, which is the single most common defect
 * in hand-written ledgers. The UI splits the sign for display; the storage
 * does not.
 *
 * `CHECK (amount <> 0)` because a zero line is not a posting. It is either a
 * rounding artefact that should have been allocated, or a placeholder someone
 * meant to fill in.
 *
 * ## `accounting_date` is a DATE, deliberately
 *
 * Never a timestamp. An accounting date that carries a time can be
 * timezone-shifted, and a posting that moves from 2026-03-31T23:00+03:00 to
 * 2026-03-31T20:00Z is still March - but one at 2026-04-01T01:00+03:00
 * becomes 2026-03-31T22:00Z and silently changes which period it belongs to,
 * which quarter it is reported in, and which VAT return it appears on.
 *
 * ## The date is on the LINE as well as the entry
 *
 * Denormalized, because declarative partitioning requires the partition key
 * in the partitioned table, and the lines are what grow. It is kept equal to
 * the entry's date by a trigger rather than by the application, so a line
 * cannot be filed in a different month from the entry it belongs to.
 *
 * ## Dimensions are COLUMNS
 *
 * Eight axes, as columns rather than JSON or an EAV side table. Every
 * financial report filters and groups by them, and an index on a JSON path is
 * not a substitute for an index on a column - the planner can use the second
 * for a range scan and a grouping, and the first mostly cannot.
 *
 * Eight is a limit, and limits in a schema are honest. A ninth axis is a
 * migration someone has to justify, which is the correct cost.
 */
return new class extends Migration
{
    /** How many months of partitions to create up front. */
    private const MONTHS_AHEAD = 18;

    /** How many months back, so historical opening balances have a home. */
    private const MONTHS_BACK = 24;

    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->foreignUlid('journal_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('period_id')->constrained('periods')->restrictOnDelete();

            $table->string('number', 32);
            $table->date('accounting_date');

            // When it was ENTERED, as distinct from the date it is accounted
            // for. The difference between the two is what an auditor looks at
            // when asking whether a period was closed on time.
            $table->timestamp('entered_at')->nullable();

            $table->text('narration')->nullable();

            // What caused this posting. The ledger does not know what an
            // invoice is; it records that something of this type and id
            // produced these lines, which is what makes a balance explainable
            // back to its source document.
            $table->string('source_type', 64)->nullable();
            $table->ulid('source_id')->nullable();
            $table->string('posting_rule', 64)->nullable();

            $table->string('status', 16)->default('draft');

            $table->ulid('created_by_user_id')->nullable();
            $table->ulid('posted_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();

            // A reversal points at what it reverses. Posted entries are never
            // edited, so a correction is a new entry that says what it undoes.
            $table->ulid('reverses_entry_id')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'journal_id', 'number']);
            $table->index(['entity_id', 'accounting_date']);
            $table->index(['entity_id', 'period_id', 'status']);
            $table->index(['entity_id', 'source_type', 'source_id']);
            $table->index(['entity_id', 'status']);
        });

        /*
         | `journal_lines`, partitioned by month on the accounting date.
         |
         | Raw SQL because Laravel's schema builder cannot express
         | PARTITION BY, and because the primary key MUST include the
         | partition key - PostgreSQL requires every unique constraint on a
         | partitioned table to contain it. `(id, accounting_date)` rather
         | than `(id)` is therefore not a modelling choice.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE journal_lines (
                id                 char(26)      NOT NULL,
                entity_id          char(26)      NOT NULL,
                journal_entry_id   char(26)      NOT NULL,
                accounting_date    date          NOT NULL,
                period_id          char(26)      NOT NULL,

                account_id         char(26)      NOT NULL,

                -- Signed. Positive is a debit. See the class note.
                amount             numeric(21,4) NOT NULL,
                currency           char(3)       NOT NULL,

                -- The rate used, and the result of applying it. Both are
                -- stored: recomputing a historical amount from a rate that
                -- has since been corrected would silently restate the books.
                fx_rate            numeric(21,12),
                functional_amount  numeric(21,4) NOT NULL,

                party_id           char(26),
                tax_code_id        char(26),
                narration          text,

                dimension_1        char(26),
                dimension_2        char(26),
                dimension_3        char(26),
                dimension_4        char(26),
                dimension_5        char(26),
                dimension_6        char(26),
                dimension_7        char(26),
                dimension_8        char(26),

                created_at         timestamp,
                updated_at         timestamp,

                PRIMARY KEY (id, accounting_date),

                CONSTRAINT journal_lines_amount_not_zero CHECK (amount <> 0),

                -- A functional amount of zero on a non-zero amount means a
                -- rate of zero was applied, which turns a real posting into
                -- nothing and still balances.
                CONSTRAINT journal_lines_functional_not_zero CHECK (functional_amount <> 0),

                CONSTRAINT journal_lines_rate_positive CHECK (fx_rate IS NULL OR fx_rate > 0)
            ) PARTITION BY RANGE (accounting_date);
        SQL);

        $this->createPartitions();

        DB::unprepared(<<<'SQL'
            /*
             | The trial balance index.
             |
             | Leading with entity and period because every balance query is
             | bounded by both, then account because that is what it groups
             | by. `functional_amount` is INCLUDEd rather than keyed, so the
             | sum is served from the index without touching the heap - which
             | on a year of lines is the difference between reading an index
             | and reading the table.
             */
            CREATE INDEX journal_lines_trial_balance
                ON journal_lines (entity_id, period_id, account_id)
                INCLUDE (functional_amount);

            -- The general-ledger detail report: one account, in date order.
            CREATE INDEX journal_lines_account_detail
                ON journal_lines (entity_id, account_id, accounting_date);

            -- Fetching an entry's own lines, which happens on every posting.
            CREATE INDEX journal_lines_by_entry ON journal_lines (journal_entry_id);

            -- Aging reports and party statements.
            CREATE INDEX journal_lines_by_party
                ON journal_lines (entity_id, party_id, accounting_date)
                WHERE party_id IS NOT NULL;
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE journal_entries
                ADD CONSTRAINT journal_entries_status_known
                    CHECK (status IN ('draft', 'posted', 'reversed'));
        SQL);

        /*
         | The line's accounting date and period follow the ENTRY's.
         |
         | Denormalized for partitioning, so they must not be settable
         | independently - a line filed in a different month from its entry
         | would be invisible to every report that bounds by the entry's
         | period and present in every report that bounds by the line's.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_line_to_entry()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                entry record;
            BEGIN
                SELECT accounting_date, period_id, entity_id
                INTO entry
                FROM journal_entries
                WHERE id = NEW.journal_entry_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Journal entry % does not exist.', NEW.journal_entry_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                -- Taken from the entry rather than validated against it: the
                -- caller has nothing useful to say about these, and copying
                -- them is how they cannot diverge.
                NEW.accounting_date := entry.accounting_date;
                NEW.period_id       := entry.period_id;
                NEW.entity_id       := entry.entity_id;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER journal_lines_follow_entry
                BEFORE INSERT OR UPDATE ON journal_lines
                FOR EACH ROW
                EXECUTE FUNCTION sync_line_to_entry();

            -- Installed by the chart migration; attached here because the
            -- table it guards did not exist then.
            CREATE TRIGGER journal_lines_account_is_postable
                BEFORE INSERT OR UPDATE ON journal_lines
                FOR EACH ROW
                EXECUTE FUNCTION assert_account_is_postable();
        SQL);

        /*
         | Applied AFTER the partitions exist, so the helper reaches them.
         | A partition created later - by the scheduled task that extends the
         | range - gets its policy from the same helper, which is why
         | TenantPartitionExtender calls it rather than issuing CREATE TABLE
         | on its own.
         */
        foreach (['journal_entries', 'journal_lines'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }
    }

    /**
     * Partitions created AHEAD of time, never on demand.
     *
     * A partition created during a request is a DDL lock taken while someone
     * is posting, and the first posting of a new month is exactly when the
     * month-end rush happens. A scheduled task extends the range; this seeds
     * enough that a new tenant can post immediately and that historical
     * opening balances have somewhere to land.
     */
    private function createPartitions(): void
    {
        $start = now()->startOfMonth()->subMonths(self::MONTHS_BACK);

        for ($i = 0; $i < self::MONTHS_BACK + self::MONTHS_AHEAD; $i++) {
            $from = $start->copy()->addMonths($i);
            $to = $from->copy()->addMonth();

            DB::unprepared(sprintf(
                'CREATE TABLE journal_lines_%s PARTITION OF journal_lines FOR VALUES FROM (%s) TO (%s);',
                $from->format('Y_m'),
                "'".$from->format('Y-m-d')."'",
                "'".$to->format('Y-m-d')."'",
            ));
        }

        /*
         | A default partition, so a date outside the range is never REJECTED.
         |
         | Losing a posting because nobody extended the partitions would be
         | far worse than storing it somewhere the planner cannot prune. The
         | default partition being non-empty is itself the alert that the
         | scheduled task has stopped running.
         */
        DB::unprepared('CREATE TABLE journal_lines_default PARTITION OF journal_lines DEFAULT;');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_follow_entry ON journal_lines;
            DROP TRIGGER IF EXISTS journal_lines_account_is_postable ON journal_lines;
            DROP FUNCTION IF EXISTS sync_line_to_entry();
            DROP TABLE IF EXISTS journal_lines;
        SQL);

        Schema::dropIfExists('journal_entries');
    }
};
