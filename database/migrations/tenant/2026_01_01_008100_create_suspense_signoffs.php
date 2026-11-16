<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Signing off a suspense balance at the close - PF-163.
 *
 * ## Why a table rather than a flag on the period
 *
 * Because the question is not "was this period allowed to close". It is
 * "who said this unexplained money was acceptable, how much was it, and what
 * did they say about it" - and that is a record with an author, not a
 * boolean. A flag answers the first question and loses the other three,
 * which are the ones asked during an audit.
 *
 * ## The balance is stored, not recomputed
 *
 * Deliberately. Recomputing it later gives today's figure, and the thing
 * being signed off is the figure at the moment of signing. If the two differ
 * afterwards, that difference is itself information - somebody cleared or
 * added to suspense after the close - and a table that recomputes cannot
 * show it.
 *
 * ## One sign-off per period
 *
 * Unique on the period. A period closed twice has been reopened in between,
 * and the second close needs its own decision about whatever is in suspense
 * by then - so the row is replaced through an explicit path rather than a
 * second row appearing beside the first with nothing saying which applied.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspense_signoffs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->char('period_id', 26);
            $table->string('period_label', 32);

            /*
             | What was in suspense when somebody signed it off.
             |
             | And the count of items, because "17,400 across 3 items" and
             | "17,400 across 240 items" are different situations and only
             | one of them is a sign-off somebody should have given.
             */
            $table->decimal('balance', 21, 4);
            $table->char('currency', 3);
            $table->integer('items');

            $table->text('reason');
            $table->ulid('signed_by');
            $table->timestamp('signed_at');

            $table->timestamps();

            $table->unique(['entity_id', 'period_id']);
            $table->index(['entity_id', 'signed_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE suspense_signoffs
                /*
                 | A real reason, at the length of a sentence.
                 |
                 | This one is read during an audit of a period that closed
                 | with money nobody could explain. "ok" and "agreed" are
                 | the answers that make that conversation worse, and forty
                 | characters is about one sentence - enough to say which
                 | items and why they were acceptable.
                 */
                ADD CONSTRAINT suspense_signoffs_reason_is_a_sentence
                    CHECK (length(btrim(reason)) >= 40),

                /*
                 | Signing off nothing is not a sign-off.
                 |
                 | A zero balance needs no decision, and a row saying one
                 | was made would let a period close later on the strength
                 | of a sign-off given when there was nothing to sign.
                 */
                ADD CONSTRAINT suspense_signoffs_have_something_to_sign
                    CHECK (balance <> 0 AND items > 0);
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE suspense_signoffs IS
                'Who accepted an unexplained balance at a period close, how much it was, and what they said - PF-163.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('suspense_signoffs')");
    }

    public function down(): void
    {
        Schema::dropIfExists('suspense_signoffs');
    }
};
