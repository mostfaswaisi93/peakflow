<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax codes and their effective-dated rates - PF-075, PF-076.
 *
 * @financial
 *
 * ## Two tables, because a category is not an attribute of a rate
 *
 * `tax_codes` carries what the code IS - its UNCL 5305 category, its
 * jurisdiction, its labels, its exemption reason, its return boxes.
 * `tax_rates` carries what it WAS, version by version.
 *
 * The first version of this migration put everything in one versioned table,
 * which made the category an attribute of each rate version - and that meant a
 * rate change could silently also change the category, so the service had to
 * refuse it in application code. A category is what a code MEANS: zero-rated
 * and exempt are different supplies with different return treatment, and
 * changing it mid-history makes every historical document's treatment
 * unreadable. Structure that cannot express the mistake beats a refusal that
 * has to remember to.
 *
 * `docs/domain/tax-engine.md` specified this shape before the code existed. The
 * one-table version was a divergence nobody had decided on.
 *
 * ## A rate version is a date RANGE, not two columns
 *
 * `validity daterange` with `[)` bounds - inclusive start, exclusive end - so
 * "in force on a date" is `validity @> date`, one containment test that the
 * query and the exclusion constraint share. Two nullable date columns need a
 * COALESCE in both places, and the day they are written with `<` in one and
 * `<=` in the other is the day a document resolves two rates.
 *
 * `upper_inf(validity)` is the open-ended current rate.
 *
 * ## Overlapping validity is refused by the database
 *
 * An exclusion constraint, not application validation - a rate seeded by a
 * migration bypasses the latter. Two rows covering the same day means a
 * document has two possible rates and which one it gets depends on row order,
 * so the same invoice computed twice can produce two different tax amounts.
 *
 * ## The zero-rate invariant is also the database's
 *
 * `Z`, `E`, `AE`, `O`, `K` and `G` must carry a rate of zero. A `Z` row at 5%
 * is not a zero rate; an `E` row at 5% is a taxable supply wearing an exempt
 * label. Both produce a return whose output tax looks plausible and whose
 * turnover boxes are wrong, which is the shape of error that survives every
 * check a business does on itself.
 *
 * Enforced on `tax_rates` with a trigger rather than a CHECK, because the
 * category lives on the parent row and a CHECK cannot read another table.
 *
 * @online-safe Creates new tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | The tenant's own identifier for a rate, and what a document
             | stores. NOT the category: one jurisdiction can have several
             | standard-rate codes - a general rate and a reduced rate are
             | both `S` - and a document needs to say which.
             */
            $table->string('code', 32);

            // UNCL 5305. The category drives behaviour; the label is
            // presentation. See App\Tenant\Tax\Enums\TaxCategory.
            $table->string('category', 2);

            // ISO 3166-1 alpha-2. Which authority's tax this is, which is not
            // always the entity's own country - a seller registered in two
            // places carries codes for both.
            $table->char('jurisdiction', 2);

            $table->string('label_ar', 120);
            $table->string('label_en', 120);

            /*
             | The return boxes this code lands in, per return.
             |
             | `{"sa-vat": {"output": "1a", "input": "7"}}` - keyed by the
             | return's own identifier, because one code appears on more than
             | one return in a jurisdiction that has both a VAT return and a
             | reverse-charge schedule. JSONB rather than a join table because
             | the shape differs per authority, and a normalised version would
             | be a key-value table with a type column - the same thing with
             | more joins.
             */
            $table->jsonb('reporting_boxes')->default('{}');

            /*
             | The default exemption reason - EN 16931 `BT-121` coded and
             | `BT-120` text.
             |
             | Mandatory for every category but `S`. A document may override
             | it with something more specific; what it may not do is omit it,
             | and the way to guarantee that is for the code to carry one. An
             | invoice that says 0% with no reason is an invoice that cannot
             | be defended.
             */
            $table->string('exemption_reason_code', 40)->nullable();
            $table->string('exemption_reason_ar', 300)->nullable();
            $table->string('exemption_reason_en', 300)->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'jurisdiction', 'category']);
        });

        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('tax_code_id')->constrained('tax_codes')->cascadeOnDelete();

            /*
             | The rate as a PERCENTAGE, at six decimal places.
             |
             | 15.000000 is fifteen percent. Not a fraction: an invoice says
             | "15%", an authority's publication says "15%", and a column
             | holding 0.15 means every reader has to remember which it is.
             | The one written the way everybody says it is the one that gets
             | checked - and the division happens in exactly one place, so
             | multiplying a net amount by 15 cannot happen by accident.
             */
            $table->decimal('rate', 9, 6);

            /*
             | Where the rate came from.
             |
             | Not nullable and not defaulted: the question asked six months
             | later is "where did 15% come from", and a default would be the
             | string everybody leaves in place. A rate with no citation is a
             | rate nobody will dare change and nobody can defend.
             */
            $table->string('source', 300);

            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            -- The validity range, added raw because the Blueprint has no
            -- daterange column type. `[)` is inclusive start, exclusive end:
            -- a rate effective 2020-07-01 with no end is [2020-07-01,), and a
            -- predecessor ending the day before is [2018-01-01,2020-07-01).
            -- They abut without overlapping and leave no gap, which two
            -- nullable date columns can only approximate.
            ALTER TABLE tax_rates ADD COLUMN validity daterange NOT NULL;

            ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_no_overlap
                EXCLUDE USING gist (tax_code_id WITH =, validity WITH &&);

            -- An empty range is in force on no date at all, which makes a
            -- code with only that version unresolvable - and the failure
            -- arrives at the first invoice rather than at the insert.
            ALTER TABLE tax_rates
                ADD CONSTRAINT tax_rates_validity_is_not_empty
                    CHECK (NOT isempty(validity)),

                -- A range with no lower bound is in force before the tax
                -- existed, which is how a backdated document picks up a rate
                -- that was not law.
                ADD CONSTRAINT tax_rates_validity_has_a_start
                    CHECK (NOT lower_inf(validity)),

                ADD CONSTRAINT tax_rates_rate_is_a_percentage
                    CHECK (rate >= 0 AND rate <= 100),

                ADD CONSTRAINT tax_rates_source_is_present
                    CHECK (length(btrim(source)) > 0);

            ALTER TABLE tax_codes
                ADD CONSTRAINT tax_codes_category_is_uncl_5305
                    CHECK (category IN ('S', 'Z', 'E', 'AE', 'O', 'K', 'G', 'L', 'M')),

                -- EN 16931 BT-120/BT-121.
                ADD CONSTRAINT tax_codes_non_standard_carries_a_reason
                    CHECK (
                        category = 'S'
                        OR (exemption_reason_code IS NOT NULL
                            AND exemption_reason_ar IS NOT NULL
                            AND exemption_reason_en IS NOT NULL)
                    ),

                ADD CONSTRAINT tax_codes_labels_are_present
                    CHECK (length(btrim(label_ar)) > 0 AND length(btrim(label_en)) > 0);
        SQL);

        /*
         | The zero-rate invariant, as a trigger.
         |
         | A CHECK cannot read another table, and the category lives on the
         | parent row - so this is the one invariant here that needs a
         | trigger. It fires on the rate and on a category change, because
         | either side can break it: a `Z` code gaining a 5% rate, or an `S`
         | code at 5% being changed to `Z`.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_a_rate_a_zero_category_cannot_have()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                offending record;
            BEGIN
                SELECT c.code, c.category, r.rate
                INTO offending
                FROM tax_rates r
                JOIN tax_codes c ON c.id = r.tax_code_id
                WHERE c.category NOT IN ('S', 'L', 'M')
                  AND r.rate <> 0
                  AND (
                        (TG_TABLE_NAME = 'tax_rates' AND r.id = NEW.id)
                     OR (TG_TABLE_NAME = 'tax_codes' AND c.id = NEW.id)
                  )
                LIMIT 1;

                IF offending.code IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Tax code % is category % and cannot carry a rate of %. A Z row at 5%% is not a zero rate, and an E row at 5%% is a taxable supply wearing an exempt label - both produce a return whose output tax looks plausible and whose turnover boxes are wrong.',
                        offending.code, offending.category, offending.rate
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER tax_rates_respect_the_category
                AFTER INSERT OR UPDATE ON tax_rates
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW
                EXECUTE FUNCTION refuse_a_rate_a_zero_category_cannot_have();

            CREATE CONSTRAINT TRIGGER tax_codes_respect_their_rates
                AFTER UPDATE OF category ON tax_codes
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW
                EXECUTE FUNCTION refuse_a_rate_a_zero_category_cannot_have();
        SQL);

        foreach (['tax_codes', 'tax_rates'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_a_rate_a_zero_category_cannot_have() CASCADE;');

        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_codes');
    }
};
