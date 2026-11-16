<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rates behind a settlement, kept where the difference is realized - PF-170.
 *
 * ## Why the rate is stored and not looked up again
 *
 * A realized gain or loss is the difference between two rates: the one the
 * document was booked at and the one the money arrived at. Both are facts
 * about days that have passed, and a report that re-fetched them would
 * answer a different question every time a rate was corrected.
 *
 * Worse, it would answer it silently. A corrected rate is ordinary - a
 * provider restates, somebody fixes a typo - and a realized figure that
 * moved with it would restate a gain somebody reported last quarter with
 * nothing saying it had changed.
 *
 * ## Both rates, on the allocation
 *
 * Not one. "We used 3.75" cannot be checked; "the invoice was booked at
 * 3.70 and the money arrived at 3.75, so the difference on 1,000 is 50" is
 * arithmetic an auditor re-performs in a line. PF-170 asks for the rate to
 * be recorded on the allocation, and the only useful form of that is both
 * of them with the figure they produced.
 *
 * @financial-not-applicable: this adds columns to `payments` and
 * `allocations`, both of which already carry `entity_id` and have
 * row-level security applied where they are created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            /*
             | The rate the cash was posted at, from the value date.
             |
             | Null when the payment is in the functional currency, and
             | NEVER 1 - the same rule the ledger applies to its own
             | lines. A stored 1 is indistinguishable from a rate somebody
             | fetched, and the difference matters the first time anybody
             | asks which payments were translated.
             */
            $table->decimal('fx_rate', 20, 10)->nullable();
            $table->string('fx_rate_set', 24)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->string('fx_rate_provider', 48)->nullable();
        });

        Schema::table('allocations', function (Blueprint $table): void {
            /*
             | Both rates and the figure they produced.
             |
             | `document_fx_rate` is what the document was booked at,
             | `payment_fx_rate` is what the money arrived at, and
             | `realized_fx` is the difference on the amount allocated -
             | proportional by construction, because it is computed on
             | the allocation rather than on the document.
             */
            $table->decimal('document_fx_rate', 20, 10)->nullable();
            $table->decimal('payment_fx_rate', 20, 10)->nullable();
            $table->date('fx_rate_date')->nullable();
            $table->decimal('realized_fx', 21, 4)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_fx_is_complete_or_absent
                    CHECK (
                        (fx_rate IS NULL AND fx_rate_set IS NULL AND fx_rate_date IS NULL)
                        OR (fx_rate IS NOT NULL AND fx_rate_set IS NOT NULL AND fx_rate_date IS NOT NULL)
                    ),

                /*
                 | A rate of exactly 1 is refused, not stored.
                 |
                 | It is what a functional-currency payment would get if
                 | somebody filled the column in "for completeness", and
                 | it makes "which payments were translated" unanswerable
                 | - the one query somebody writes when a realized figure
                 | looks wrong.
                 */
                ADD CONSTRAINT payments_fx_rate_is_not_one
                    CHECK (fx_rate IS NULL OR fx_rate <> 1);

            ALTER TABLE allocations
                ADD CONSTRAINT allocations_fx_is_complete_or_absent
                    CHECK (
                        (document_fx_rate IS NULL AND payment_fx_rate IS NULL AND realized_fx IS NULL)
                        OR (document_fx_rate IS NOT NULL AND payment_fx_rate IS NOT NULL
                            AND realized_fx IS NOT NULL AND fx_rate_date IS NOT NULL)
                    ),

                /*
                 | Equal rates realize nothing, and the row says so.
                 |
                 | A realized figure of zero against two different rates
                 | is an allocation whose arithmetic nobody did; a
                 | non-zero one against equal rates is a figure from
                 | somewhere else. Both are caught here rather than found
                 | in a reconciliation.
                 */
                ADD CONSTRAINT allocations_equal_rates_realize_nothing
                    CHECK (
                        document_fx_rate IS NULL
                        OR (document_fx_rate = payment_fx_rate) = (realized_fx = 0)
                    );

            COMMENT ON COLUMN allocations.realized_fx IS
                'The difference between the two rates on the amount allocated - proportional by construction, because it is computed on the allocation and not on the document - PF-170.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
                DROP CONSTRAINT IF EXISTS payments_fx_is_complete_or_absent,
                DROP CONSTRAINT IF EXISTS payments_fx_rate_is_not_one;

            ALTER TABLE allocations
                DROP CONSTRAINT IF EXISTS allocations_fx_is_complete_or_absent,
                DROP CONSTRAINT IF EXISTS allocations_equal_rates_realize_nothing;
        SQL);

        // @unsafe-migration-reviewed: the rollback drops exactly the columns
        // this migration added, so nothing that predates it can be reading
        // them. Rolling back to a release that never knew about realized FX
        // is the only situation this runs in, and in that release no code
        // selects these.
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['fx_rate', 'fx_rate_set', 'fx_rate_date', 'fx_rate_provider']);
        });

        Schema::table('allocations', function (Blueprint $table): void {
            $table->dropColumn(['document_fx_rate', 'payment_fx_rate', 'fx_rate_date', 'realized_fx']);
        });
    }
};
