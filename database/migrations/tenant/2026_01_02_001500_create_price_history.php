<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A price is its history - PF-254.
 *
 * ## Why the price moves off `price_list_lines`
 *
 * "What did this cost in March" is asked by a customer holding an
 * invoice, and a column that is overwritten cannot answer it. PF-065
 * gave a LIST a validity window, which answers "which list applied in
 * March"; it did not answer "what was the price inside that list in
 * March", because changing a price meant updating the row.
 *
 * So `price_list_lines` keeps the identity of a tier - this list, this
 * item, from this quantity - and the price itself becomes a row in
 * `price_points` with the date it takes effect, the person and the
 * reason. The price in force on a date is the latest point at or before
 * it.
 *
 * The old columns are dropped rather than left as a denormalised
 * "current price". Two places holding one truth is how they come to
 * disagree, and the resolver reading one while history reads the other
 * is the exact failure this task exists to remove.
 *
 * ## A scheduled change needs nobody to run anything
 *
 * It is a point with a future `effective_from`. On the day it arrives it
 * becomes the latest point at or before the document date, and that is
 * the whole mechanism - no job, no flag to flip, nothing to forget on a
 * public holiday.
 *
 * And it is visible before it arrives, because it is a row somebody can
 * list. A product where tomorrow's price is invisible until tomorrow is
 * one where a rise is discovered by a customer.
 *
 * ## A list may be DERIVED from another, and the rule is re-run
 *
 * `derived_from_id` with a kind and a percentage. A wholesale list that
 * is retail less fifteen per cent is not a copy of retail with the
 * arithmetic already done: a copy stops agreeing with its base the
 * first time the base moves, and nobody notices because both numbers
 * look deliberate.
 *
 * Re-run at resolution means the derived price is correct on every date,
 * including dates in the past: the base's point for that date is read,
 * and the rule applied to it.
 *
 * ## The snapshot goes on the invoice line
 *
 * Which the sales side already does for tax - `tax_code_id`, `tax_rate`
 * and `tax_category` are on the line, not looked up when somebody opens
 * the document. Price was computing the same provenance and throwing it
 * away: `RecurringSchedules` built a derivation sentence for a preview
 * array and nothing persisted it.
 *
 * @financial-not-applicable: prices are shared across the tenant's entities, the same decision `items` records - the same item is sold by every company in the group and its price is not a property of one of them. `price_points` and the derivation columns follow their parents, and `invoice_lines` is already entity-scoped with RLS forced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_points', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('price_list_line_id', 26);

            /*
             | One of the two, never both and never neither.
             |
             | A point is either a price or a discount off whatever the
             | next list says - the same two shapes the line used to
             | carry, moved here so that a tier can change from one to
             | the other over time without losing what it was.
             */
            $table->decimal('unit_price', 21, 8)->nullable();
            $table->decimal('discount_percent', 9, 6)->nullable();

            $table->date('effective_from');

            /*
             | Why it changed, in a sentence.
             |
             | A supplier increase, a promotion, a correction of a typo.
             | The three are read differently when a customer disputes an
             | invoice, and "price updated" tells nobody which it was.
             */
            $table->text('reason');

            $table->ulid('changed_by');
            $table->timestamps();

            /*
             | One price per tier per day.
             |
             | Two points on one date is an ambiguity with no tiebreak a
             | person could defend - and the symptom is an invoice whose
             | price depends on which row the planner happened to read.
             */
            $table->unique(['price_list_line_id', 'effective_from']);
            $table->index(['price_list_line_id', 'effective_from']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE price_points
                ADD CONSTRAINT price_points_are_a_price_or_a_discount
                    CHECK (
                        (unit_price IS NOT NULL AND discount_percent IS NULL)
                        OR (unit_price IS NULL AND discount_percent IS NOT NULL)
                    ),

                ADD CONSTRAINT price_points_are_not_negative
                    CHECK (unit_price IS NULL OR unit_price >= 0),

                /*
                 | A discount is a discount, not a reversal.
                 |
                 | Over 100 per cent means paying the customer to take
                 | the goods, which is not a price list. The usual cause
                 | is a figure entered as 1500 meaning 15.00.
                 */
                ADD CONSTRAINT price_points_discounts_are_percentages
                    CHECK (discount_percent IS NULL OR (discount_percent > 0 AND discount_percent <= 100)),

                ADD CONSTRAINT price_points_say_why
                    CHECK (length(btrim(reason)) >= 10);

            COMMENT ON TABLE price_points IS
                'A price with the date it takes effect, the person and the reason - the price IS its history, because "what did this cost in March" is asked by a customer holding an invoice - PF-254.';
        SQL);

        /*
         | Every existing price becomes its first point.
         |
         | Attributed to the no-session actor and dated from the list's
         | own `valid_from`, which is the earliest date the price could
         | have applied. Backfilling to a later date would make the
         | resolver find no price for documents the tenant has already
         | issued.
         */
        DB::unprepared(<<<'SQL'
            INSERT INTO price_points (
                id, price_list_line_id, unit_price, discount_percent,
                effective_from, reason, changed_by, created_at, updated_at
            )
            SELECT
                upper(lpad(to_hex((row_number() OVER (ORDER BY l.id))::int), 26, '0')),
                l.id,
                l.unit_price,
                l.discount_percent,
                coalesce(pl.valid_from, DATE '2000-01-01'),
                'the price this tier carried before prices kept their history',
                repeat('0', 26),
                now(),
                now()
            FROM price_list_lines l
            JOIN price_lists pl ON pl.id = l.price_list_id;
        SQL);

        /*
         | A point is a fact.
         |
         | Editing one rewrites what a customer was charged, and the
         | invoice that quoted it does not change with it. A correction
         | is a new point saying so.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_price_point_is_a_fact()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'A price point records what a price was from a date. Changing or removing one rewrites what a customer was charged, and the invoice that quoted it does not change with it - record a new point from today instead, with the reason.'
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE TRIGGER price_points_are_facts
                BEFORE UPDATE OR DELETE ON price_points
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_price_point_is_a_fact();
        SQL);

        /*
         | And the price leaves the line, so there is one place.
         */
        Schema::table('price_list_lines', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the point of the task. These two columns ARE the second place a price lives, and leaving them behind as a denormalised "current price" is the failure PF-254 exists to remove - the resolver would read one while history read the other. Every row is copied into `price_points` by the backfill above, in the same transaction, before they go.
            $table->dropColumn(['unit_price', 'discount_percent']);
        });

        /*
         | A list may be derived from another by a rule.
         */
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->char('derived_from_id', 26)->nullable();
            $table->string('derivation_kind', 16)->nullable();
            $table->decimal('derivation_percent', 9, 6)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE price_lists
                ADD CONSTRAINT price_lists_derivation_is_complete_or_absent
                    CHECK (
                        (derived_from_id IS NULL AND derivation_kind IS NULL AND derivation_percent IS NULL)
                        OR (derived_from_id IS NOT NULL AND derivation_kind IS NOT NULL AND derivation_percent IS NOT NULL)
                    ),

                ADD CONSTRAINT price_lists_derivation_kind_is_known
                    CHECK (derivation_kind IS NULL OR derivation_kind IN ('margin', 'markup', 'discount')),

                /*
                 | A discount cannot exceed the base; a margin cannot
                 | reach it.
                 |
                 | A margin is expressed on the SELLING price - "we make
                 | 35% on it" - so 100% would require an infinite price.
                 | A markup is on the cost and has no ceiling. The three
                 | are different arithmetic and operators use all three
                 | words interchangeably, which is why the kind is stored
                 | rather than inferred from the number.
                 */
                ADD CONSTRAINT price_lists_derivation_is_a_percentage
                    CHECK (
                        derivation_percent IS NULL
                        OR (
                            derivation_percent > 0
                            AND (derivation_kind = 'markup' OR derivation_percent < 100)
                        )
                    ),

                /*
                 | A list is not derived from itself, which would make
                 | resolution recurse until the stack gives out - and
                 | the error names a stack, not a price list.
                 |
                 | There is deliberately NO rule that a derived list
                 | carries no lines of its own. "Retail less fifteen per
                 | cent, except these three at a fixed price" is a real
                 | arrangement, and forbidding it would send operators to
                 | build the exception as a second list with a higher
                 | priority - the same thing, spelled so that nobody can
                 | see it is an exception.
                 */
                ADD CONSTRAINT price_lists_are_not_their_own_base
                    CHECK (derived_from_id IS NULL OR derived_from_id <> id);

            COMMENT ON COLUMN price_lists.derived_from_id IS
                'The list this one is computed FROM, re-run at resolution rather than copied - a copy stops agreeing with its base the first time the base moves, and nobody notices because both numbers look deliberate - PF-254.';
        SQL);

        /*
         | And the snapshot goes on the invoice line.
         |
         | Nullable, because a price typed by hand is a real thing and
         | says so by carrying no list. What matters is that a price
         | which CAME from a list says which one, and under what rule.
         */
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->char('price_list_id', 26)->nullable();
            $table->text('price_basis')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE invoice_lines
                /*
                 | A list on the line means a sentence on the line.
                 |
                 | "Resolved from RETAIL at 94.50" is the difference
                 | between a question and a dispute. A list id with no
                 | sentence is a foreign key somebody has to go and
                 | interpret, a year later, from lists that have changed.
                 */
                ADD CONSTRAINT invoice_lines_a_priced_line_explains_itself
                    CHECK (
                        price_list_id IS NULL
                        OR length(btrim(coalesce(price_basis, ''))) >= 10
                    );

            COMMENT ON COLUMN invoice_lines.price_basis IS
                'Why this unit price - the list, the tier and any derivation, snapshotted like the tax treatment beside it - PF-254.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS price_points_are_facts ON price_points;
            DROP FUNCTION IF EXISTS peakflow_a_price_point_is_a_fact();
        SQL);

        Schema::table('price_list_lines', function (Blueprint $table): void {
            $table->decimal('unit_price', 21, 8)->nullable();
            $table->decimal('discount_percent', 9, 6)->nullable();
        });

        DB::unprepared(<<<'SQL'
            UPDATE price_list_lines l
               SET unit_price = p.unit_price,
                   discount_percent = p.discount_percent
              FROM (
                SELECT DISTINCT ON (price_list_line_id)
                       price_list_line_id, unit_price, discount_percent
                  FROM price_points
                 ORDER BY price_list_line_id, effective_from DESC, id DESC
              ) p
             WHERE p.price_list_line_id = l.id;
        SQL);

        Schema::table('invoice_lines', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a forward-only migration. Both columns are added by this migration's own up(), so dropping them restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn(['price_list_id', 'price_basis']);
        });

        Schema::table('price_lists', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a forward-only migration. All three columns are added by this migration's own up(), so dropping them restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn(['derived_from_id', 'derivation_kind', 'derivation_percent']);
        });

        Schema::dropIfExists('price_points');
    }
};
