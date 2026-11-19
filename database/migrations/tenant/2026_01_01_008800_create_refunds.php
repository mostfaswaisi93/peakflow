<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A refund is its own document - PF-169.
 *
 * ## Why not a negative receipt
 *
 * Because a negative receipt is untraceable. It nets against the
 * customer's cash in every report that sums payments, so "how much have we
 * refunded this year" becomes a query nobody can write - and the figure it
 * hides is the one a finance director asks about, usually after a
 * chargeback.
 *
 * It is also the shape fraud takes. A negative receipt looks like a
 * correction; a refund document has an amount, a reason, an approver and a
 * payment behind it, and the approver is a person whose name is on a row.
 *
 * ## The credit it consumes is ordinary unapplied cash
 *
 * An overpayment is not a special kind of object. The customer sent more
 * than their invoices came to, the excess sits unallocated against them,
 * and that is a credit they can use on their next document or ask to have
 * back. Modelling it as something else would mean two ways to hold a
 * customer's money and a reconciliation between them.
 *
 * So a refund is a **disbursement** that releases the held liability, and
 * this table is the decision behind it.
 *
 * @financial
  *
 * @online-safe Creates `refunds`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 32);
            $table->char('party_id', 26);

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);
            $table->date('refunded_on');

            $table->text('reason');

            /*
             | The payment that actually moved the money.
             |
             | Nullable only for the instant between the row and the
             | posting, inside one transaction. A refund without a payment
             | is a decision nobody acted on, and a payment without a
             | refund is money out with no reason beside it.
             */
            $table->char('payment_id', 26)->nullable();

            $table->ulid('approved_by');
            $table->timestamp('approved_at');

            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'party_id', 'refunded_on']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE refunds
                ADD CONSTRAINT refunds_amount_is_positive
                    CHECK (amount > 0),

                /*
                 | A reason, at the length of a sentence.
                 |
                 | Read when somebody asks why money went back to a
                 | customer. "Refund" is not an answer, and the question
                 | is usually asked by a person who has just seen the
                 | figure on a bank statement.
                 */
                ADD CONSTRAINT refunds_state_a_reason
                    CHECK (length(btrim(reason)) >= 20);

            COMMENT ON TABLE refunds IS
                'Money given back, as a document with an amount, a reason, an approver and a payment - never as a negative receipt - PF-169.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('refunds')");
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
