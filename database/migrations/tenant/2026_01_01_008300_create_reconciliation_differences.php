<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Differences, classified before they are written off - PF-164.
 *
 * ## Why a row per difference rather than a journal line
 *
 * The posting is the consequence; this is the decision. A journal line
 * records that 15.00 went to bank charges, and that is all it can say. The
 * questions somebody asks a year later are different ones: how often does
 * this customer short-pay, is the bank-charge line rising, how much
 * withholding did we recognise and how much of it has a certificate - and
 * none of those are answerable from a general ledger account that several
 * flows post into.
 *
 * PF-164 asks for a standing report of differences by type and by period.
 * A report is only as good as the record behind it, and the record has to
 * carry the party, the type, the document and who decided.
 *
 * ## The tolerance that applied is stored, not recomputed
 *
 * The same reasoning as the suspense sign-off. What made this acceptable
 * was the tolerance as it stood at the time; recomputing it later answers a
 * different question, and a tenant who raises a tolerance would silently
 * make every past difference look as though it had always been within it.
 *
 * ## Approved differences carry their approver
 *
 * Nullable, because most differences are below tolerance and nobody
 * approves them. A difference above tolerance without an approver is
 * refused outright - which is the whole control - so the column being null
 * and the amount being above tolerance is a state that cannot exist.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_differences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('type', 24);

            /*
             | What it arose against, by type and id.
             |
             | A bank line for a charge the bank took, a document for a
             | payment that fell short. Not two nullable foreign keys: the
             | shapes are different tables and a column that is always
             | null for half the rows is one a query forgets.
             */
            $table->string('subject_type', 24);
            $table->char('subject_id', 26);
            $table->char('party_id', 26)->nullable();

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);

            /*
             | The tolerance as it stood, and whether this was inside it.
             |
             | Both, because "it was within tolerance" is the claim and the
             | figure is the evidence. Storing only the boolean leaves a
             | later reader unable to tell a difference that was genuinely
             | small from one that was large under a tolerance somebody
             | had raised that week.
             */
            $table->decimal('tolerance', 21, 4);
            $table->boolean('within_tolerance');

            $table->date('arose_on');
            $table->char('period_id', 26)->nullable();

            $table->text('reason');
            $table->ulid('recorded_by');
            $table->timestamp('recorded_at');

            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->ulid('journal_entry_id')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'type', 'arose_on']);
            $table->index(['entity_id', 'party_id']);
            $table->index(['entity_id', 'period_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE reconciliation_differences
                ADD CONSTRAINT reconciliation_differences_type_is_known
                    CHECK (type IN ('bank_charge', 'fx_difference', 'short_payment',
                                    'overpayment', 'rounding', 'withholding')),

                ADD CONSTRAINT reconciliation_differences_are_not_zero
                    CHECK (amount <> 0),

                ADD CONSTRAINT reconciliation_differences_state_a_reason
                    CHECK (length(btrim(reason)) > 10),

                /*
                 | Above tolerance, somebody's name is on it.
                 |
                 | This is the control PF-164 asks for, written where it
                 | cannot be skipped. A service can be bypassed by an
                 | import or a correction script; a row that records a
                 | difference as outside tolerance with nobody having
                 | approved it is a state the table refuses to hold.
                 */
                ADD CONSTRAINT reconciliation_differences_above_tolerance_are_approved
                    CHECK (within_tolerance OR approved_by IS NOT NULL);
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE reconciliation_differences IS
                'Why a payment and a document did not agree, classified before anything was written off - PF-164.';
            COMMENT ON CONSTRAINT reconciliation_differences_above_tolerance_are_approved ON reconciliation_differences IS
                'A difference beyond what a tenant said is acceptable is a decision, and a decision has an author.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('reconciliation_differences')");
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_differences');
    }
};
