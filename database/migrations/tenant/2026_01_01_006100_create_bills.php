<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier bills - PF-133, PF-139.
 *
 * ## Three dates, and they are not interchangeable
 *
 * | | Whose | What it decides |
 * |---|---|---|
 * | `supplier_document_date` | The supplier's | What their statement says, and what a duplicate check compares |
 * | `tax_point` | The jurisdiction's rule | Which return the input tax falls in, and the FX rate |
 * | `posting_date` | Ours | Which accounting period the entry lands in |
 *
 * Collapsing them is the common shortcut and it breaks in both directions: a
 * bill dated the 28th that arrives on the 3rd belongs to the supplier's
 * March and our April, and the input tax belongs to whichever period the tax
 * point names. A system with one date gets two of the three wrong.
 *
 * ## The duplicate constraint is in the database
 *
 * `(entity_id, party_id, supplier_document_number)` is unique, and that is
 * deliberately not a soft check. Paying the same invoice twice is the most
 * common and most expensive error in accounts payable, it is usually
 * discovered by the supplier rather than by the payer, and an application
 * check can be bypassed by an import, a second worker or a retried job.
 *
 * The number is normalised before it is stored - `INV-001`, `inv 001` and
 * `INV001` are the same document from the supplier's point of view - because
 * a constraint on the raw string catches only the lazy half of the problem.
 *
 * What a constraint cannot catch is the same invoice arriving under a
 * different number, which is `bill_duplicate_warnings` and PF-139's
 * near-match search.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            // UNCL 1001: 380 a bill, 381 a supplier credit note, 383 a debit note.
            $table->char('document_type', 3)->default('380');
            $table->string('status', 16)->default('draft');

            $table->foreignUlid('party_id')->constrained()->restrictOnDelete();

            /*
             | The supplier's own number, as they wrote it and as we compare it.
             |
             | Both, because the two have different jobs: the raw form is what
             | goes on a remittance advice and what the supplier recognises,
             | and the normalised form is what the unique constraint and the
             | duplicate search use. Storing only the normalised one would
             | mean a remittance that does not match their records; storing
             | only the raw one would let `INV-001` and `inv 001` both in.
             */
            $table->string('supplier_document_number', 64);
            $table->string('supplier_document_key', 64);
            $table->date('supplier_document_date');

            $table->date('tax_point');
            $table->date('posting_date');
            $table->string('return_period_label', 16)->nullable();

            $table->char('currency', 3);
            $table->decimal('fx_rate', 21, 12)->nullable();
            $table->string('fx_source', 32)->nullable();
            $table->date('fx_rate_date')->nullable();

            $table->decimal('net_amount', 21, 4);
            $table->decimal('tax_amount', 21, 4);
            $table->decimal('gross_amount', 21, 4);

            /*
             | The tax that is NOT recoverable, held separately.
             |
             | It posts to expense rather than to the input account, which is
             | what keeps the input account reconcilable to the return: an
             | input account carrying tax that was never claimed will not tie
             | to any box on any form, and the difference is discovered
             | during a tax audit.
             */
            $table->decimal('recoverable_tax_amount', 21, 4)->default(0);
            $table->decimal('non_recoverable_tax_amount', 21, 4)->default(0);

            $table->decimal('payable_amount', 21, 4);
            $table->decimal('paid_amount', 21, 4)->default(0);

            $table->foreignUlid('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->jsonb('terms_snapshot')->nullable();
            $table->jsonb('supplier_snapshot')->nullable();
            $table->jsonb('tax_decision_snapshot')->nullable();

            $table->string('profile', 32)->nullable();
            $table->text('note')->nullable();

            $table->ulid('created_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->ulid('journal_entry_id')->nullable();

            /*
             | Where it came from, which a reviewer needs.
             |
             | A bill keyed in by hand, one extracted from a PDF by OCR and
             | one parsed from a Peppol document have very different
             | reliability, and the reviewer of an exception needs to know
             | which they are looking at before they decide how hard to look.
             */
            $table->string('source', 24)->default('manual');

            $table->timestamps();

            /*
             | The hard duplicate constraint - PF-139.
             |
             | On the normalised key. Partial, so a draft that somebody is
             | still typing does not collide: the number is frequently the
             | last field entered, and a constraint that fired on an empty
             | one would make the form unusable.
             */
            $table->index(['entity_id', 'party_id', 'posting_date']);
            $table->index(['entity_id', 'status']);
            $table->index(['entity_id', 'tax_point']);
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX bills_one_per_supplier_document
                ON bills (entity_id, party_id, supplier_document_key)
                WHERE length(btrim(supplier_document_key)) > 0;
        SQL);

        Schema::create('bill_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('bill_id')->constrained()->cascadeOnDelete();

            $table->integer('line_no');

            $table->foreignUlid('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_code', 64)->nullable();
            $table->text('description');

            $table->string('unit_code', 8)->default('C62');
            $table->decimal('quantity', 21, 8);
            $table->decimal('unit_price', 21, 8);
            $table->decimal('net_amount', 21, 4);

            /*
             | Expense or asset - the line decides, not the bill.
             |
             | One bill routinely carries a repair (expense) and a machine
             | (asset), and a document-level choice forces the business to
             | split a supplier's invoice for an accounting reason.
             */
            $table->foreignUlid('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->foreignUlid('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->string('tax_category', 3);
            $table->decimal('tax_rate', 9, 6);
            $table->decimal('tax_amount', 21, 4)->default(0);

            /*
             | How much of this line's tax is recoverable.
             |
             | A percentage rather than a boolean, because partial recovery
             | is the normal case for entertainment, fuel and mixed-use
             | assets in most jurisdictions.
             */
            $table->decimal('recoverable_percent', 9, 6)->default(100);

            $table->boolean('reverse_charge')->default(false);

            $table->char('dimension_1', 26)->nullable();
            $table->char('dimension_2', 26)->nullable();
            $table->char('dimension_3', 26)->nullable();
            $table->char('dimension_4', 26)->nullable();

            $table->timestamps();

            $table->unique(['bill_id', 'line_no']);
            $table->index(['entity_id', 'item_id']);
        });

        Schema::create('bill_tax_breakdown', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('bill_id')->constrained()->cascadeOnDelete();

            $table->string('category', 3);
            $table->decimal('rate', 9, 6);

            /*
             | The code the group came from, carried so the posting can.
             |
             | `journal_lines` refuses a tax point with no tax code - the
             | point of a tax point is that it is attributable to a code, and
             | a line carrying one without the other is a figure in a return
             | nobody can trace. The group holds it so the posting rule does
             | not have to go back to the lines to find it.
             */
            $table->foreignUlid('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();

            $table->decimal('taxable_amount', 21, 4);
            $table->decimal('tax_amount', 21, 4);
            $table->decimal('recoverable_amount', 21, 4)->default(0);
            $table->boolean('reverse_charge')->default(false);
            $table->string('report_box', 64)->nullable();

            $table->timestamps();

            $table->unique(['bill_id', 'category', 'rate', 'reverse_charge']);
        });

        /*
         | The near-match warnings a constraint cannot produce - PF-139.
         |
         | The same invoice arriving under a different number, or the same
         | amount and date from the same supplier. A warning is shown with
         | the suspect side by side and requires an explicit acknowledgement,
         | which is recorded - because "I checked and it is not a duplicate"
         | is a decision somebody made and should be answerable for.
         */
        Schema::create('bill_duplicate_warnings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('suspected_bill_id')->constrained('bills')->cascadeOnDelete();

            $table->string('reason', 32);
            $table->text('detail');
            $table->decimal('amount_difference', 21, 4)->nullable();
            $table->integer('days_apart')->nullable();

            $table->timestamp('acknowledged_at')->nullable();
            $table->ulid('acknowledged_by')->nullable();
            $table->text('acknowledgement')->nullable();

            /*
             | When it was raised: at entry, or at payment time.
             |
             | Detection runs twice because the duplicate frequently arrives
             | after the first one was already approved, and by payment time
             | nobody is looking at the entry screen any more.
             */
            $table->string('raised_at_stage', 16)->default('entry');

            $table->timestamps();

            $table->unique(['bill_id', 'suspected_bill_id', 'reason']);
            $table->index(['entity_id', 'acknowledged_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_document_type_is_known
                    CHECK (document_type IN ('380', '381', '383')),

                ADD CONSTRAINT bills_status_is_known
                    CHECK (status IN ('draft', 'awaiting_approval', 'approved', 'posted', 'cancelled')),

                ADD CONSTRAINT bills_source_is_known
                    CHECK (source IN ('manual', 'email', 'ocr', 'peppol', 'import', 'api')),

                ADD CONSTRAINT bills_totals_tie
                    CHECK (gross_amount = net_amount + tax_amount),

                ADD CONSTRAINT bills_tax_splits_tie
                    CHECK (tax_amount = recoverable_tax_amount + non_recoverable_tax_amount),

                ADD CONSTRAINT bills_payable_is_the_gross
                    CHECK (payable_amount = gross_amount),

                ADD CONSTRAINT bills_paid_does_not_exceed_payable
                    CHECK (paid_amount >= 0 AND paid_amount <= abs(payable_amount)),

                /*
                 | The supplier's document number is never blank on a bill
                 | that has been approved.
                 |
                 | A draft may lack it - it is frequently the last field
                 | entered - but a bill with no supplier reference cannot be
                 | matched to their statement, cannot be paid with a
                 | remittance they recognise, and cannot be caught by the
                 | duplicate constraint.
                 */
                ADD CONSTRAINT bills_approved_carries_a_supplier_number
                    CHECK (
                        status IN ('draft', 'cancelled')
                        OR length(btrim(supplier_document_number)) > 0
                    ),

                ADD CONSTRAINT bills_approved_is_attributable
                    CHECK (
                        (approved_at IS NULL AND approved_by IS NULL)
                        OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)
                    ),

                ADD CONSTRAINT bills_posted_carries_its_entry
                    CHECK (
                        (status <> 'posted')
                        OR (posted_at IS NOT NULL AND journal_entry_id IS NOT NULL)
                    ),

                /*
                 | The tax point is not after the posting date.
                 |
                 | A bill posted in April for a supply whose tax point is in
                 | May would claim input tax in a return that has not opened.
                 | The other direction is normal: a March supply posted in
                 | April is exactly why the two dates exist.
                 */
                ADD CONSTRAINT bills_tax_point_is_not_in_the_future
                    CHECK (tax_point <= posting_date);

            ALTER TABLE bill_lines
                ADD CONSTRAINT bill_lines_recoverable_percent_is_a_percentage
                    CHECK (recoverable_percent >= 0 AND recoverable_percent <= 100),

                ADD CONSTRAINT bill_lines_description_is_present
                    CHECK (length(btrim(description)) > 0),

                /*
                 | A reverse-charge line's tax is not the supplier's.
                 |
                 | Under the reverse charge the supplier charges nothing and
                 | the recipient accounts for both sides. A line flagged for
                 | it with a rate of zero would post nothing, which is the
                 | error that leaves the output side unaccounted.
                 */
                ADD CONSTRAINT bill_lines_reverse_charge_carries_a_rate
                    CHECK (reverse_charge = false OR tax_rate > 0);

            ALTER TABLE bill_tax_breakdown
                ADD CONSTRAINT bill_tax_breakdown_recoverable_within_tax
                    CHECK (recoverable_amount >= 0 AND recoverable_amount <= abs(tax_amount));

            ALTER TABLE bill_duplicate_warnings
                ADD CONSTRAINT bill_duplicate_warnings_reason_is_known
                    CHECK (reason IN ('same_amount_and_date', 'similar_amount_in_window', 'same_number_other_supplier', 'same_total_different_number')),

                ADD CONSTRAINT bill_duplicate_warnings_stage_is_known
                    CHECK (raised_at_stage IN ('entry', 'approval', 'payment')),

                ADD CONSTRAINT bill_duplicate_warnings_not_itself
                    CHECK (bill_id <> suspected_bill_id),

                ADD CONSTRAINT bill_duplicate_warnings_acknowledgement_is_attributable
                    CHECK (
                        (acknowledged_at IS NULL AND acknowledged_by IS NULL AND acknowledgement IS NULL)
                        OR (
                            acknowledged_at IS NOT NULL
                            AND acknowledged_by IS NOT NULL
                            AND length(btrim(acknowledgement)) > 10
                        )
                    );
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON INDEX bills_one_per_supplier_document IS
                'PF-139: the hard duplicate constraint, on the NORMALISED number. Paying the same invoice twice is the most expensive AP error and is usually found by the supplier.';
            COMMENT ON COLUMN bills.supplier_document_key IS
                'The normalised supplier number: INV-001, inv 001 and INV001 are one document.';
            COMMENT ON COLUMN bills.non_recoverable_tax_amount IS
                'Posts to expense, not to the input account, so the input account ties to the return - PF-133.';
            COMMENT ON TABLE bill_duplicate_warnings IS
                'What a unique constraint cannot catch: the same invoice under a different number - PF-139.';
        SQL);

        foreach (['bills', 'bill_lines', 'bill_tax_breakdown', 'bill_duplicate_warnings'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_duplicate_warnings');
        Schema::dropIfExists('bill_tax_breakdown');
        Schema::dropIfExists('bill_lines');
        Schema::dropIfExists('bills');
    }
};
