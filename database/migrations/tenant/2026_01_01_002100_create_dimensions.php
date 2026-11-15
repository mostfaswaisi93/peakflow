<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dimension axes and their values - PF-046.
 *
 * @financial
 *
 * ## Eight axes, as columns, already
 *
 * `journal_lines` has `dimension_1` through `dimension_8`. What was missing is
 * what those columns MEAN: which axis is which, what values exist, which
 * accounts demand one, and which combinations are forbidden.
 *
 * Eight and not "as many as you like" because each one is a column and an
 * index: a ninth axis is a migration on a partitioned table with 43
 * partitions, which is a decision rather than a setting. Five are named -
 * cost centre, project, department, branch, fund - and three are free,
 * because every business has one classification nobody else has.
 *
 * ## The axis NUMBER is per entity and permanent
 *
 * `dimension_3` means "project" for this entity and might mean "fund" for
 * another. Which is uncomfortable and is the price of columns: the
 * alternative is a lookup per line per report.
 *
 * So the number is frozen once anything is posted to it. Re-numbering an axis
 * silently re-labels every historical line - a year of cost-centre reporting
 * becomes a year of project reporting, with no error and no clue.
 *
 * ## A required dimension is refused in the database
 *
 * "This account may not be posted to without a cost centre" is the rule that
 * makes dimensional reporting trustworthy, and a rule enforced only in the
 * posting service is a rule an import does not know about. The symptom is a
 * cost-centre report that is short by whatever came in through the import,
 * which is found when a department queries their numbers.
 *
 * ## A value belongs to ONE entity
 *
 * The combination rule that matters most. Posting entity A's cost centre onto
 * entity B's line produces a report where a department appears in a company
 * it does not belong to - and because both ids are valid ULIDs, nothing else
 * notices.
 *
 * @online-safe Creates new tables and a trigger; no rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dimension_axes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            /*
             | Which column on `journal_lines` this axis occupies.
             |
             | 1 to 8. Permanent once anything is posted: re-numbering
             | silently re-labels every historical line, turning a year of
             | cost-centre reporting into a year of project reporting with
             | no error and no clue.
             */
            $table->smallInteger('axis');

            $table->string('key', 32);
            $table->string('name_ar');
            $table->string('name_en');

            /*
             | Whether a posting may name a non-leaf value.
             |
             | Most charts of cost centres are hierarchical and only the
             | leaves are postable - "Operations" is a roll-up and
             | "Operations / Riyadh / Warehouse" is where a cost lands.
             | Allowing a roll-up to be posted to makes the hierarchy's own
             | totals double-count.
             */
            $table->boolean('leaf_only')->default(true);

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['entity_id', 'axis']);
            $table->unique(['entity_id', 'key']);
        });

        Schema::create('dimension_values', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->foreignUlid('dimension_axis_id')->constrained()->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('name_ar');
            $table->string('name_en');

            // Hierarchical, with a materialized path, for the same reason
            // the chart of accounts has one: a report rolls up by prefix
            // rather than by a recursive query per row.
            $table->ulid('parent_id')->nullable();
            $table->string('path', 255);
            $table->smallInteger('depth')->default(0);

            /*
             | A value can be closed without being deleted.
             |
             | A project that finished last year must not appear in a
             | dropdown and must still resolve on last year's reports.
             | Deleting it would orphan a year of lines, and the lines
             | cannot be changed - they are posted.
             */
            $table->boolean('postable')->default(true);
            $table->boolean('active')->default(true);
            $table->date('closed_on')->nullable();

            $table->timestamps();

            $table->unique(['dimension_axis_id', 'code']);
            $table->index(['entity_id', 'dimension_axis_id', 'active']);
            $table->index(['dimension_axis_id', 'path']);
        });

        /*
         | Which accounts demand which axis.
         |
         | Per account rather than per account type, because the requirement
         | is genuinely specific: every expense account needs a cost centre
         | and the bank account does not, and both are postable accounts in
         | the same chart.
         */
        Schema::create('account_dimension_requirements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->ulid('account_id');
            $table->foreignUlid('dimension_axis_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['account_id', 'dimension_axis_id']);
        });

        /*
         | Forbidden combinations - PF-046.
         |
         | Two axes and two values that may not appear together: a project
         | that belongs to one department, a fund that may not be charged to
         | a particular cost centre. Expressed as a denial rather than an
         | allowance, because the allow-list of every valid pair across two
         | hierarchies is thousands of rows that nobody maintains.
         */
        Schema::create('dimension_restrictions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            $table->foreignUlid('axis_a_id')->constrained('dimension_axes')->cascadeOnDelete();
            $table->ulid('value_a_id');

            $table->foreignUlid('axis_b_id')->constrained('dimension_axes')->cascadeOnDelete();

            /*
             | Null means "any value on axis B".
             |
             | "This project may not be charged to ANY fund" is a real rule
             | and is one row; the alternative is a row per fund, which
             | stops being true the day somebody adds a fund.
             */
            $table->ulid('value_b_id')->nullable();

            $table->text('reason');
            $table->timestamps();

            $table->index(['entity_id', 'axis_a_id', 'value_a_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE dimension_axes
                ADD CONSTRAINT dimension_axes_axis_in_range
                    CHECK (axis BETWEEN 1 AND 8);

            ALTER TABLE dimension_values
                ADD CONSTRAINT dimension_values_closed_is_not_postable
                    CHECK (closed_on IS NULL OR postable = false),

                ADD CONSTRAINT dimension_values_parent_is_not_itself
                    CHECK (parent_id IS NULL OR parent_id <> id);

            ALTER TABLE dimension_restrictions
                -- A restriction between an axis and itself forbids a value
                -- from appearing with itself, which is every line it is on.
                ADD CONSTRAINT dimension_restrictions_axes_differ
                    CHECK (axis_a_id <> axis_b_id);
        SQL);

        $this->installAxisFreeze();
        $this->installDimensionValidation();

        foreach ([
            'dimension_axes',
            'dimension_values',
            'account_dimension_requirements',
            'dimension_restrictions',
        ] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN dimension_axes.axis IS
                'Which journal_lines column this axis occupies, 1 to 8. PERMANENT once anything is posted: '
                're-numbering silently re-labels every historical line, turning a year of cost-centre '
                'reporting into a year of project reporting with no error and no clue.';
        SQL);
    }

    /**
     * An axis number cannot move once lines carry it - PF-046.
     *
     * The column IS the meaning. Changing which column an axis occupies
     * re-labels every line already written, and there is no error because
     * both numbers are valid and both columns hold valid ULIDs.
     */
    private function installAxisFreeze(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_renumbering_a_used_axis()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                in_use boolean;
            BEGIN
                IF NEW.axis = OLD.axis THEN
                    RETURN NEW;
                END IF;

                EXECUTE format(
                    'SELECT true FROM journal_lines WHERE entity_id = $1 AND dimension_%s IS NOT NULL LIMIT 1',
                    OLD.axis
                ) INTO in_use USING OLD.entity_id;

                IF in_use THEN
                    RAISE EXCEPTION
                        'Axis "%" is on column dimension_% and lines already carry it, so it cannot move to dimension_%. The column IS the meaning: moving it would re-label every line already posted - a year of % reporting would silently become a year of whatever occupies the new column. Create a new axis instead.',
                        OLD.key, OLD.axis, NEW.axis, OLD.name_en
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER dimension_axes_number_is_frozen
                BEFORE UPDATE ON dimension_axes
                FOR EACH ROW
                EXECUTE FUNCTION refuse_renumbering_a_used_axis();
        SQL);
    }

    /**
     * Every dimension on every line is validated - PF-046.
     *
     * ## In the database, for the usual reason
     *
     * A rule enforced only in the posting service is a rule an import does
     * not know about. The symptom is a cost-centre report short by whatever
     * came in through the import, found when a department queries their
     * numbers - months later, against a closed period.
     *
     * ## Four separate claims
     *
     *   1. a value named on an axis exists, and belongs to THIS entity
     *   2. it is postable - not a closed project, not a roll-up where the
     *      axis is leaf-only
     *   3. an account that requires an axis gets one
     *   4. no forbidden combination is present
     *
     * The first is the one that cannot be caught any other way: both ids are
     * valid ULIDs, so posting entity A's cost centre onto entity B's line is
     * invisible to every other check and produces a report where a
     * department appears in a company it does not belong to.
     */
    private function installDimensionValidation(): void
    {
        DB::unprepared(<<<'SQL'
            /*
             | SECURITY DEFINER, and this one needs its reason recorded.
             |
             | `dimension_values` is entity-scoped, so the policy hides
             | another entity's rows from the application role - which means
             | the lookup below could not SEE the cross-entity value it
             | exists to catch, and refused it as "does not exist". The
             | posting was still refused, so the control held; the message
             | sent the reader looking for a missing value when the real
             | problem was a value from another company.
             |
             | Running as the owner is safe here because every query inside
             | carries its own `entity_id = NEW.entity_id` predicate - the
             | one exception being the value lookup, whose whole job is to
             | notice that the entity differs. The function writes nothing
             | and takes no argument but the row being inserted.
             */
            CREATE OR REPLACE FUNCTION assert_line_dimensions()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                axis_number  smallint;
                value_id     char(26);
                value_row    record;
                axis_row     record;
                required     record;
                restriction  record;
                other_value  char(26);
            BEGIN
                FOR axis_number IN 1..8 LOOP
                    EXECUTE format('SELECT ($1).dimension_%s', axis_number)
                        INTO value_id USING NEW;

                    CONTINUE WHEN value_id IS NULL;

                    SELECT a.* INTO axis_row
                    FROM dimension_axes a
                    WHERE a.entity_id = NEW.entity_id AND a.axis = axis_number;

                    IF axis_row IS NULL THEN
                        RAISE EXCEPTION
                            'Journal line % names a value on dimension %, and this entity has no axis there. The column number IS the meaning, so a value in an undefined column is a classification nobody can read.',
                            NEW.id, axis_number
                            USING ERRCODE = 'foreign_key_violation';
                    END IF;

                    /*
                     | The value must exist AND belong to this entity.
                     |
                     | The entity check is the one that cannot be caught any
                     | other way: both ids are valid ULIDs, so one company's
                     | cost centre on another company's line is invisible
                     | everywhere else.
                     */
                    SELECT v.* INTO value_row
                    FROM dimension_values v
                    WHERE v.id = value_id;

                    IF value_row IS NULL THEN
                        RAISE EXCEPTION
                            'Journal line % names dimension value % on axis "%", which does not exist.',
                            NEW.id, value_id, axis_row.key
                            USING ERRCODE = 'foreign_key_violation';
                    END IF;

                    IF value_row.entity_id <> NEW.entity_id THEN
                        RAISE EXCEPTION
                            'Journal line % is for entity % and names the dimension value "%" belonging to entity %. Both are valid identifiers, so nothing else would notice - and the report it produces shows a department inside a company it is not part of.',
                            NEW.id, NEW.entity_id, value_row.code, value_row.entity_id
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF value_row.dimension_axis_id <> axis_row.id THEN
                        RAISE EXCEPTION
                            'Journal line % puts the value "%" in column dimension_%, which is axis "%" - the value belongs to a different axis. Column and axis are the same statement, so this would file a project under cost centres.',
                            NEW.id, value_row.code, axis_number, axis_row.key
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF NOT value_row.postable THEN
                        RAISE EXCEPTION
                            'Dimension value "%" on axis "%" is not postable%. A closed project must stay resolvable on last year''s reports and must not take new postings.',
                            value_row.code, axis_row.key,
                            CASE WHEN value_row.closed_on IS NULL THEN ''
                                 ELSE ' (closed on ' || value_row.closed_on || ')' END
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    /*
                     | A roll-up is not a posting target where the axis says
                     | leaves only. Posting to "Operations" as well as to
                     | "Operations / Riyadh" makes the hierarchy's own
                     | totals double-count.
                     */
                    IF axis_row.leaf_only AND EXISTS (
                        SELECT 1 FROM dimension_values c WHERE c.parent_id = value_row.id
                    ) THEN
                        RAISE EXCEPTION
                            'Dimension value "%" on axis "%" has children, and that axis takes only leaf values. Posting to a roll-up as well as to its children makes the hierarchy''s own totals double-count.',
                            value_row.code, axis_row.key
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END LOOP;

                /*
                 | A RESERVED journal is exempt from the requirement.
                 |
                 | Not a convenience. A requirement added in June cannot
                 | reach back to January's lines, because posted lines are
                 | immutable - so the year-end close, which mechanically
                 | zeroes whatever is there, would have to post a line
                 | without a dimension for every balance that predates the
                 | rule. Refusing that makes the year unclosable with no way
                 | forward: there is nothing to fix, because the old lines
                 | cannot be changed.
                 |
                 | The same applies to the revaluation and the opening
                 | balance. All three are consequences of what already
                 | exists rather than new assertions about the world, so the
                 | requirement governs what may be POSTED and not what may
                 | be carried.
                 |
                 | The validity checks above still apply: a dimension that
                 | IS supplied must be real, belong to this entity and be
                 | postable. This exempts only the demand for one.
                 */
                IF EXISTS (
                    SELECT 1
                    FROM journal_entries e
                    JOIN journals j ON j.id = e.journal_id
                    WHERE e.id = NEW.journal_entry_id AND j.reserved
                ) THEN
                    RETURN NEW;
                END IF;

                /*
                 | An account that requires an axis gets one.
                 |
                 | This is what makes dimensional reporting trustworthy: a
                 | cost-centre report is only complete if every line that
                 | should carry one does.
                 */
                FOR required IN
                    SELECT a.axis, a.key, a.name_en
                    FROM account_dimension_requirements r
                    JOIN dimension_axes a ON a.id = r.dimension_axis_id
                    WHERE r.account_id = NEW.account_id
                      AND r.entity_id = NEW.entity_id
                LOOP
                    EXECUTE format('SELECT ($1).dimension_%s', required.axis)
                        INTO value_id USING NEW;

                    IF value_id IS NULL THEN
                        RAISE EXCEPTION
                            'This account requires a % ("%"), and journal line % has none. A report grouped by that axis would be short by this line, and the shortfall is found when a department queries their own numbers.',
                            required.name_en, required.key, NEW.id
                            USING ERRCODE = 'not_null_violation';
                    END IF;
                END LOOP;

                -- And no forbidden combination.
                FOR restriction IN
                    SELECT r.*, aa.axis AS axis_a_number, ab.axis AS axis_b_number,
                           va.code AS value_a_code, aa.key AS axis_a_key, ab.key AS axis_b_key
                    FROM dimension_restrictions r
                    JOIN dimension_axes aa ON aa.id = r.axis_a_id
                    JOIN dimension_axes ab ON ab.id = r.axis_b_id
                    JOIN dimension_values va ON va.id = r.value_a_id
                    WHERE r.entity_id = NEW.entity_id
                LOOP
                    EXECUTE format('SELECT ($1).dimension_%s', restriction.axis_a_number)
                        INTO value_id USING NEW;

                    CONTINUE WHEN value_id IS NULL OR value_id <> restriction.value_a_id;

                    EXECUTE format('SELECT ($1).dimension_%s', restriction.axis_b_number)
                        INTO other_value USING NEW;

                    CONTINUE WHEN other_value IS NULL;

                    -- A null value_b_id means "any value on axis B".
                    IF restriction.value_b_id IS NULL OR restriction.value_b_id = other_value THEN
                        RAISE EXCEPTION
                            'Journal line % combines "%" on axis "%" with a value on axis "%", which this entity forbids: %',
                            NEW.id, restriction.value_a_code, restriction.axis_a_key,
                            restriction.axis_b_key, restriction.reason
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER journal_lines_dimensions_are_valid
                BEFORE INSERT OR UPDATE ON journal_lines
                FOR EACH ROW
                EXECUTE FUNCTION assert_line_dimensions();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_dimensions_are_valid ON journal_lines;
            DROP FUNCTION IF EXISTS assert_line_dimensions();

            DROP TRIGGER IF EXISTS dimension_axes_number_is_frozen ON dimension_axes;
            DROP FUNCTION IF EXISTS refuse_renumbering_a_used_axis();
        SQL);

        Schema::dropIfExists('dimension_restrictions');
        Schema::dropIfExists('account_dimension_requirements');
        Schema::dropIfExists('dimension_values');
        Schema::dropIfExists('dimension_axes');
    }
};
