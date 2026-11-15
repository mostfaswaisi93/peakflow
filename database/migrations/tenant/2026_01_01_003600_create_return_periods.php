<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Return periods, per registration rather than per calendar - PF-085.
 *
 * @financial
 *
 * ## A return period is not an accounting period
 *
 * Monthly VAT on an entity that closes quarterly is normal, and so is the
 * reverse. Deriving one from the other makes a return unfileable in a way that
 * looks fine: the figures are right and the window is wrong, so the return
 * either includes a month belonging to the next one or omits one that belonged
 * to the last.
 *
 * So this is its own table, keyed by entity and jurisdiction, with its own
 * state machine - and `periods` (the accounting calendar from PF-035) is left
 * alone.
 *
 * ## `filed` is a one-way door
 *
 * A trigger refuses any change to a filed period's figures or window, and
 * refuses reopening it. Amending a filed return is a different legal act from
 * editing a draft one: in most regimes it is a new submission with its own
 * reference, and PF-087 is where that flow lives. A system that let the window
 * move would make the filed figures unexplainable - they would no longer
 * correspond to any period.
 *
 * ## The exclusion constraint is what makes "the period for this tax point"
 * ## a question with one answer
 *
 * Overlapping windows for one registration would make a document fall into
 * two returns, and the second one filed would double-count it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->char('jurisdiction', 2);
            $table->string('frequency', 16);

            // `2026-05`, `2026-Q2`, `2026` - what an authority's portal calls
            // the period, and what a person looks for.
            $table->string('label', 16);

            $table->string('state', 16)->default('open');

            $table->date('due_on')->nullable();
            $table->timestampTz('filed_at')->nullable();
            $table->char('filed_by', 26)->nullable();

            // The authority's own reference for the submission, which is what
            // a query from them quotes back.
            $table->string('submission_reference', 128)->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'jurisdiction', 'label']);
            $table->index(['entity_id', 'jurisdiction', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE return_periods ADD COLUMN period daterange NOT NULL;

            ALTER TABLE return_periods
                ADD CONSTRAINT return_periods_period_is_not_empty
                    CHECK (NOT isempty(period)),

                ADD CONSTRAINT return_periods_period_is_bounded
                    CHECK (lower(period) IS NOT NULL AND upper(period) IS NOT NULL),

                ADD CONSTRAINT return_periods_frequency_is_known
                    CHECK (frequency IN ('monthly', 'quarterly', 'annual')),

                ADD CONSTRAINT return_periods_state_is_known
                    CHECK (state IN ('open', 'filed')),

                -- Filed means filed: a timestamp and whoever did it. A filed
                -- period with no filer is a period nobody can be asked about.
                ADD CONSTRAINT return_periods_filed_is_attributable
                    CHECK ((state = 'filed') = (filed_at IS NOT NULL AND filed_by IS NOT NULL)),

                ADD CONSTRAINT return_periods_no_overlap
                    EXCLUDE USING gist (
                        entity_id WITH =,
                        jurisdiction WITH =,
                        period WITH &&
                    );

            COMMENT ON COLUMN return_periods.period IS
                'Half-open [from,until). The window a document falls into by its TAX POINT, not by its accounting date - PF-085.';

            /*
             | A filed period is immutable, enforced here.
             |
             | Not in the application: a correction script, a migration or a
             | psql session all write rows the same way, and this is the only
             | thing standing between them and a filed return whose window has
             | moved under it.
             */
            CREATE OR REPLACE FUNCTION refuse_to_change_a_filed_return() RETURNS trigger AS $$
            BEGIN
                IF OLD.state = 'filed' THEN
                    IF NEW.state <> 'filed' THEN
                        RAISE EXCEPTION
                            'Return period % for % was filed on %. Reopening it is not a correction - '
                            'amending a filed return is a new submission with its own reference, which '
                            'is PF-087.',
                            OLD.label, OLD.jurisdiction, OLD.filed_at
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.period <> OLD.period OR NEW.label <> OLD.label
                        OR NEW.frequency <> OLD.frequency OR NEW.jurisdiction <> OLD.jurisdiction THEN
                        RAISE EXCEPTION
                            'Return period % is filed and its window cannot move. The figures that were '
                            'filed would no longer correspond to any period, so nobody could explain '
                            'them.',
                            OLD.label
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER return_periods_are_immutable_once_filed
                BEFORE UPDATE ON return_periods
                FOR EACH ROW
                EXECUTE FUNCTION refuse_to_change_a_filed_return();
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('return_periods');");

        /*
         | And the registration says how often it files - PF-085.
         |
         | Nullable: a registration whose frequency nobody has recorded is an
         | ordinary state while an entity is being set up, and a default of
         | monthly would quietly generate eleven periods nobody asked for.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE tax_registrations ADD COLUMN filing_frequency varchar(16);

            ALTER TABLE tax_registrations
                ADD CONSTRAINT tax_registrations_frequency_is_known
                    CHECK (filing_frequency IS NULL
                        OR filing_frequency IN ('monthly', 'quarterly', 'annual'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS return_periods_are_immutable_once_filed ON return_periods;
            DROP FUNCTION IF EXISTS refuse_to_change_a_filed_return();

            ALTER TABLE tax_registrations
                DROP CONSTRAINT IF EXISTS tax_registrations_frequency_is_known,
                DROP COLUMN IF EXISTS filing_frequency;
        SQL);

        Schema::dropIfExists('return_periods');
    }
};
