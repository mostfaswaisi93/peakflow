<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets by account, dimension and period, in versions - PF-193.
 *
 * @financial
 *
 * ## Why a version is a row and not a column
 *
 * "The budget" is three or four documents by March: what was approved in
 * December, the reforecast after the first quarter, and whatever somebody
 * is working on now. A table with one amount per account and period can
 * hold exactly one of those, so the reforecast overwrites the approved
 * figure - and every variance report printed before that afternoon
 * becomes unreproducible, with nothing on it saying so.
 *
 * ## The approved version is LOCKED, and that is enforced here
 *
 * A budget is what a department was told it may spend. If the approved
 * figures can be edited, the variance report is a report against whatever
 * the budget was most recently made to say, which is a report nobody can
 * be held to - and the edit leaves no trace, because the only evidence
 * would have been the figure it replaced.
 *
 * So `locked_at` is set when a version is approved, and a trigger refuses
 * to insert, change or delete a line belonging to a locked version. The
 * next forecast is a NEW version that supersedes it, which is a document
 * somebody approved rather than an edit somebody made.
 *
 * ## The approver is not the author
 *
 * In the table, not only in the service. A budget somebody set for
 * themselves and approved for themselves is the control not having
 * operated at all, and a column pair catches the import that sets both to
 * one id - which is the case the service's own guard cannot see.
 *
 * ## The dimensions are the same eight columns the journal carries
 *
 * Not a JSON blob, and not a narrower set: a budget line for "cost centre
 * Riyadh, project Atlas" has to be comparable to the journal lines that
 * spend against it, and the comparison is only sound if the two are
 * keyed the same way. The axis NUMBER means the same thing here as it
 * does on `journal_lines` - it is the entity's own `dimension_axes.axis`
 * - so a report may join them without a translation table nobody would
 * keep in step.
 *
 * @online-safe Creates two new tables and one trigger; nothing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | A version belongs to a fiscal year, not to a date range.
             |
             | A budget is set against the calendar the accounts are kept
             | on, so that every period in it has a row to compare
             | against. A range somebody typed would let a budget cover
             | eleven months of one year and one of the next, and the
             | variance report would then be comparing a thirteen-month
             | actual to a twelve-month budget with nothing saying so.
             */
            $table->char('fiscal_year_id', 26);

            $table->string('name', 64);
            $table->integer('version_no');

            /*
             | The currency is the entity's functional currency, named.
             |
             | A budget in a currency the books are not kept in cannot be
             | compared to an actual without a rate, and a rate on a
             | budget is a figure nobody can reproduce six months later.
             | The service refuses any other currency; the column exists
             | so the report can state it rather than assume it.
             */
            $table->char('currency', 3);

            $table->string('status', 16)->default('draft');

            $table->ulid('created_by');
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            /*
             | Superseded, not deleted - and this column is WRITTEN.
             |
             | `Budgets::approve()` points the version it replaces at the
             | new one, so "what did we approve before this, and when did
             | it stop being the budget" is one query. A chain column that
             | nothing ever sets is worse than no column: it reads as an
             | answer and returns null for every row.
             */
            $table->char('superseded_by_id', 26)->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'fiscal_year_id', 'version_no']);
            $table->index(['entity_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE budget_versions
                ADD CONSTRAINT budget_versions_status_is_known
                    CHECK (status IN ('draft', 'approved', 'superseded')),

                ADD CONSTRAINT budget_versions_are_numbered_from_one
                    CHECK (version_no >= 1),

                /*
                 | An approved version is attributable and locked, as one
                 | fact.
                 |
                 | The three columns move together, so "approved by
                 | nobody" and "approved and still editable" are not
                 | states this table can be in - which is what makes the
                 | lock something a report may rely on rather than
                 | something a service remembered to do.
                 */
                ADD CONSTRAINT budget_versions_approval_is_attributable
                    CHECK (
                        status <> 'approved'
                        OR (approved_by IS NOT NULL AND approved_at IS NOT NULL AND locked_at IS NOT NULL)
                    ),

                ADD CONSTRAINT budget_versions_are_not_self_approved
                    CHECK (approved_by IS NULL OR approved_by <> created_by),

                /*
                 | A superseded version was approved once and says what
                 | replaced it.
                 |
                 | A budget that stopped being the budget without naming
                 | its successor is a gap in the record somebody will read
                 | as "there was no budget then".
                 */
                ADD CONSTRAINT budget_versions_supersession_is_explained
                    CHECK (
                        (status <> 'superseded' AND superseded_by_id IS NULL AND superseded_at IS NULL)
                        OR (status = 'superseded' AND superseded_by_id IS NOT NULL
                            AND superseded_at IS NOT NULL AND approved_at IS NOT NULL)
                    ),

                ADD CONSTRAINT budget_versions_do_not_supersede_themselves
                    CHECK (superseded_by_id IS NULL OR superseded_by_id <> id);

            /*
             | One approved version per fiscal year.
             |
             | A partial index, so a superseded one does not block the
             | version that replaced it. Without it, two approved budgets
             | exist for one year and nothing says which the variance
             | report should read - and the report would pick whichever
             | sorted first.
             */
            CREATE UNIQUE INDEX budget_versions_one_approved_per_year
                ON budget_versions (entity_id, fiscal_year_id)
                WHERE status = 'approved';

            COMMENT ON TABLE budget_versions IS
                'What a department was told it may spend, as a document somebody approved: versioned, attributable, and locked once approved - PF-193.';
            COMMENT ON CONSTRAINT budget_versions_are_not_self_approved ON budget_versions IS
                'A budget somebody set for themselves and approved for themselves is the control not having operated. The column pair catches the import that sets both to one id, which the service cannot see.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('budget_versions')");

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('budget_version_id', 26);

            /*
             | By PERIOD, which is the grain a variance is read at.
             |
             | An annual figure divided by twelve on the way out is a
             | monthly budget nobody set: seasonal businesses budget
             | nothing in one month and three times the average in
             | another, and a report that apportioned would call the first
             | an underspend and the second a disaster.
             */
            $table->char('period_id', 26);
            $table->char('account_id', 26);

            /*
             | The journal's own eight axes, at the journal's own numbers.
             |
             | Nullable, because most budget lines are set at the account
             | level and a null means "this account, whatever dimensions
             | it is spent on". A report that treated null as a value
             | would show every dimensional actual as unbudgeted.
             */
            $table->char('dimension_1', 26)->nullable();
            $table->char('dimension_2', 26)->nullable();
            $table->char('dimension_3', 26)->nullable();
            $table->char('dimension_4', 26)->nullable();
            $table->char('dimension_5', 26)->nullable();
            $table->char('dimension_6', 26)->nullable();
            $table->char('dimension_7', 26)->nullable();
            $table->char('dimension_8', 26)->nullable();

            /*
             | Signed the way the ledger signs it, and zero is allowed.
             |
             | "This cost centre may spend nothing this month" is a
             | budget, and it is not the same statement as having no
             | budget line at all - the first is a limit and the second is
             | an omission. A constraint forbidding zero would make the
             | two indistinguishable, and the report could not say which
             | it was looking at.
             */
            $table->decimal('amount', 21, 4);

            $table->timestamps();

            $table->index(['entity_id', 'budget_version_id', 'period_id']);
            $table->index(['budget_version_id', 'account_id']);
        });

        DB::unprepared(<<<'SQL'
            /*
             | One line per version, period, account and dimension set.
             |
             | An expression index over `coalesce(dimension_n, '')`,
             | because PostgreSQL treats two NULLs as distinct in a plain
             | unique index - so the obvious declaration would permit any
             | number of duplicate account-level lines, and the variance
             | report would silently double a budget somebody entered
             | twice.
             */
            CREATE UNIQUE INDEX budget_lines_one_per_dimension_set
                ON budget_lines (
                    budget_version_id,
                    period_id,
                    account_id,
                    coalesce(dimension_1, ''),
                    coalesce(dimension_2, ''),
                    coalesce(dimension_3, ''),
                    coalesce(dimension_4, ''),
                    coalesce(dimension_5, ''),
                    coalesce(dimension_6, ''),
                    coalesce(dimension_7, ''),
                    coalesce(dimension_8, '')
                );

            COMMENT ON TABLE budget_lines IS
                'One budgeted amount per version, period, account and dimension set, keyed the way journal_lines is keyed so the two can be compared - PF-193.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('budget_lines')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_locked_budget_is_not_edited()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                target_version char(26);
                version_row record;
            BEGIN
                target_version := coalesce(NEW.budget_version_id, OLD.budget_version_id);

                SELECT status, locked_at, name INTO version_row
                FROM budget_versions
                WHERE id = target_version;

                IF version_row IS NULL THEN
                    RAISE EXCEPTION
                        'A budget line must belong to a version, and no version answers to %. A line '
                        'with no document behind it is a figure a department is held to that nobody '
                        'approved.',
                        target_version
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF version_row.locked_at IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Budget version "%" was approved and is locked, so its lines cannot be %. A '
                        'budget is what a department was told it may spend: an approved figure that '
                        'can still be edited makes every variance report a report against whatever '
                        'the budget was most recently made to say. Draft a new version and approve '
                        'it, which supersedes this one and leaves both in the record.',
                        version_row.name,
                        CASE TG_OP WHEN 'INSERT' THEN 'added to' WHEN 'DELETE' THEN 'deleted' ELSE 'changed' END
                        USING ERRCODE = 'check_violation';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER budget_lines_respect_the_lock
                BEFORE INSERT OR UPDATE OR DELETE ON budget_lines
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_locked_budget_is_not_edited();

            COMMENT ON FUNCTION peakflow_a_locked_budget_is_not_edited() IS
                'An approved budget version is immutable. Enforced here rather than in the service because an import, a console session and a repair script do not go through the service - and the edit it would make leaves no trace, since the only evidence would have been the figure it replaced - PF-193.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS budget_lines_respect_the_lock ON budget_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS peakflow_a_locked_budget_is_not_edited()');

        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budget_versions');
    }
};
