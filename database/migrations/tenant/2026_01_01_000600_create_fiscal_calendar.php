<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal years and periods - PF-049, PF-050, PF-051.
 *
 * @financial
 *
 * ## A fiscal year is not a calendar year
 *
 * It may start in April, in July, or on the Saturday nearest 31 January. A
 * subsidiary may differ from its parent, which is why these belong to the
 * ENTITY and not to the tenant. A system that assumes January to December
 * works for perhaps half the world and fails silently for the rest: the
 * postings land, in the wrong year, and nobody notices until the auditors do.
 *
 * ## Periods carry a state, and the state is enforced here
 *
 * `future`      nothing may post - the year exists so that nobody is blocked
 *               in January, not so that December can be posted into early
 * `open`        normal posting
 * `soft_closed` only `period.post_adjusting`, for the gap between the
 *               operational close and the audit sign-off
 * `closed`      nothing; reopening needs a permission, step-up auth, a reason
 * `locked`      never again, by anyone, irreversibly - set after a statutory
 *               filing, because amending a filed period is a different legal
 *               act from editing a book
 *
 * `locked` has no application path back ON PURPOSE. A product that can
 * un-lock a filed period will be asked to, at 6pm, by someone senior.
 *
 * ## The adjusting period carries no calendar days
 *
 * Period 13 exists so that audit adjustments land AFTER the last month
 * without being re-dated into it. Its `starts_on` and `ends_on` equal the
 * year's last day, and `is_adjusting` is what distinguishes it - so a date
 * lookup never lands there by accident and an adjustment must be posted to it
 * deliberately.
  *
 * @online-safe Creates `fiscal_years`, `periods`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            // A label rather than a number: "2026", "FY26/27", "1447H". The
            // year a fiscal year is CALLED is a local convention and not
            // derivable from its dates.
            $table->string('label', 32);
            $table->date('starts_on');
            $table->date('ends_on');

            /*
             | How the year is divided. The calendar shape is a property of
             | the YEAR, not of the entity, because an entity that switches
             | from monthly to 4-4-5 does so at a year boundary and its old
             | years keep their old shape.
             */
            $table->string('calendar', 16)->default('monthly');

            $table->boolean('closed')->default(false);
            $table->timestamps();

            $table->unique(['entity_id', 'label']);
            $table->index(['entity_id', 'starts_on']);
        });

        Schema::create('periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->foreignUlid('fiscal_year_id')->constrained()->cascadeOnDelete();

            $table->smallInteger('ordinal');
            $table->string('label', 32);
            $table->date('starts_on');
            $table->date('ends_on');

            // See the class note: period 13 carries the year's last day and
            // is reached only deliberately.
            $table->boolean('is_adjusting')->default(false);

            $table->string('state', 16)->default('future');
            $table->timestamp('closed_at')->nullable();
            $table->ulid('closed_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->ulid('locked_by_user_id')->nullable();

            $table->timestamps();

            $table->unique(['fiscal_year_id', 'ordinal']);

            /*
             | The index the date lookup uses. Leading with the entity and
             | then the date range means "which period is 2026-03-14 in" is
             | one index scan rather than a scan of the entity's periods.
             */
            $table->index(['entity_id', 'starts_on', 'ends_on']);
            $table->index(['entity_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fiscal_years
                ADD CONSTRAINT fiscal_years_dates_ordered CHECK (ends_on >= starts_on),
                ADD CONSTRAINT fiscal_years_calendar_known
                    CHECK (calendar IN ('monthly', 'four_weekly', 'retail_454'));

            ALTER TABLE periods
                ADD CONSTRAINT periods_dates_ordered CHECK (ends_on >= starts_on),
                ADD CONSTRAINT periods_state_known
                    CHECK (state IN ('future', 'open', 'soft_closed', 'closed', 'locked'));
        SQL);

        /*
         | Overlapping periods are refused BY THE DATABASE, with an exclusion
         | constraint rather than application validation.
         |
         | Two periods covering the same day means a posting has two possible
         | homes, and which one it lands in depends on row order - so the same
         | invoice posted twice can appear in two different months. The
         | adjusting period is excluded from the check because it deliberately
         | shares its days with the last month.
         |
         | btree_gist is what lets a range exclusion also match on equality of
         | a plain column, which is how the constraint stays per entity.
         */
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            ALTER TABLE periods ADD CONSTRAINT periods_do_not_overlap
                EXCLUDE USING gist (
                    entity_id WITH =,
                    daterange(starts_on, ends_on, '[]') WITH &&
                ) WHERE (NOT is_adjusting);
        SQL);

        /*
         | `locked` is terminal, enforced here rather than in the application.
         |
         | The application can refuse the transition and a future maintainer
         | can write an UPDATE. A period locked after a statutory filing must
         | not be reopenable by anything short of a migration someone has to
         | write, review and explain.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_unlocking_period()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.state = 'locked' AND NEW.state <> 'locked' THEN
                    RAISE EXCEPTION
                        'Period % is locked. A locked period has been filed with an authority, so changing it is '
                        'an amendment to a statutory return rather than an edit to a book - there is deliberately '
                        'no way to do it from the application.', OLD.label
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER periods_locked_is_terminal
                BEFORE UPDATE ON periods
                FOR EACH ROW
                EXECUTE FUNCTION refuse_unlocking_period();
        SQL);

        foreach (['fiscal_years', 'periods'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS periods_locked_is_terminal ON periods;
            DROP FUNCTION IF EXISTS refuse_unlocking_period();
        SQL);

        Schema::dropIfExists('periods');
        Schema::dropIfExists('fiscal_years');
    }
};
