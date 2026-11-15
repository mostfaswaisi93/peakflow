<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The plan for releasing deferred revenue, and what has been released - PF-107.
 *
 * @financial
 *
 * ## Why the plan is rows and not a formula
 *
 * "Straight-line over twelve months from March" is computable, and computing
 * it at every reporting run is how two runs disagree: a rate change, a credit
 * note, a leap year, a period that was reopened. The plan is written once,
 * when the document is issued, and the run reads it.
 *
 * It also makes the schedule something a person can look at and change,
 * which is what an auditor asks for - "show me how you arrived at this
 * month's revenue" is a question about a list, not about an algorithm.
 *
 * ## Idempotency, again by unique index
 *
 * `(invoice_line_id, recognise_on)` is unique, and a released row carries the
 * journal entry that released it. `revenue:recognize` run twice posts once,
 * and the second run reports what it skipped. Same reasoning as the
 * recurring occurrences: a reporting command that double-posts is worse than
 * one that fails, because the figures look plausible.
 *
 * ## Why the amounts are allocated, not divided
 *
 * 1,000.00 over three months is 333.33, 333.33 and 333.34. `Money::allocate`
 * is what makes the parts add back to the whole; dividing and rounding each
 * month independently leaves a cent in the deferral that nobody ever
 * releases, and a control account that never quite reaches zero is a control
 * account people stop reconciling.
 */
return new class extends Migration
{
    private const STATES = ['planned', 'recognised', 'cancelled'];

    public function up(): void
    {
        Schema::create('revenue_recognition_plan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_line_id')->constrained('invoice_lines')->cascadeOnDelete();

            // The date the revenue is earned. A period is derived from it,
            // because a plan written against a period id cannot survive the
            // fiscal calendar being regenerated.
            $table->date('recognise_on');

            $table->char('currency', 3);
            $table->decimal('amount', 21, 4);
            $table->string('status', 16)->default('planned');

            $table->ulid('revenue_account_id')->nullable();
            $table->ulid('journal_entry_id')->nullable();
            $table->timestampTz('recognised_at')->nullable();

            // What this row is: "month 3 of 12, 2026-05" reads better than a
            // sequence number on a report an auditor is holding.
            $table->string('note', 200)->nullable();

            $table->timestamps();

            /*
             | One planned release per line per date - PF-107.
             |
             | The run is re-runnable because of this: a second run finds the
             | rows already recognised and skips them, and a straight-line
             | plan regenerated after an edit cannot produce two releases for
             | one month.
             */
            $table->unique(['invoice_line_id', 'recognise_on']);
            $table->index(['entity_id', 'status', 'recognise_on']);
            $table->index(['entity_id', 'invoice_id']);
        });

        $states = "'".implode("', '", self::STATES)."'";

        DB::unprepared(<<<SQL
            ALTER TABLE revenue_recognition_plan
                ADD CONSTRAINT revenue_recognition_plan_status_is_known
                    CHECK (status IN ({$states})),

                ADD CONSTRAINT revenue_recognition_plan_amount_is_positive
                    CHECK (amount > 0),

                /*
                 | A recognised row carries the entry that recognised it.
                 |
                 | Both halves: a row marked recognised with no entry is
                 | revenue nobody can trace to a posting, and an entry on a
                 | planned row is a posting the next run would make again.
                 */
                ADD CONSTRAINT revenue_recognition_plan_recognised_is_posted
                    CHECK ((status = 'recognised') = (journal_entry_id IS NOT NULL AND recognised_at IS NOT NULL));

            SELECT peakflow_apply_entity_rls('revenue_recognition_plan');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_recognition_plan');
    }
};
