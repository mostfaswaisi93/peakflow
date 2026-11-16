<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee expense claims - PF-143.
 *
 * ## The payable is to the EMPLOYEE, in a control account of its own
 *
 * Not trade payables. An employee is not a supplier: the balance is reported
 * differently in most frameworks, it is frequently settled through payroll
 * rather than through the payment run, and an aging report that mixed the two
 * would show a colleague's taxi fare alongside a supplier whose terms are
 * thirty days.
 *
 * It also matters for the fraud this module is about. "Who do we owe money
 * to" with employees mixed into it is a list nobody reads closely, and the
 * expense claim is the cheapest place in a business to take money out of it.
 *
 * ## Input tax follows the receipt, not the claim
 *
 * Tax is recoverable only where a receipt is attached **and** the category
 * permits it. Both conditions, because they fail differently: no receipt
 * means no evidence, and a category like client entertainment means no
 * entitlement however good the evidence is.
 *
 * A system that claimed input tax on an employee's word produces a return
 * whose input box cannot be supported, and the authority asks for the
 * receipts rather than the spreadsheet.
 *
 * ## Per-diem and mileage rates are frozen onto the line
 *
 * A rate changes - annually in most jurisdictions - and a claim for last
 * November re-derived at today's rate pays the wrong amount and cannot be
 * reconciled to what was approved. So the rate that applied on the date the
 * expense was incurred is copied onto the line, like a payment term's due
 * dates are frozen onto a bill.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_policies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('category', 24);
            $table->char('currency', 3);

            /*
             | A rate, a ceiling, or both.
             |
             | Mileage and per diem have a rate and no ceiling; a meal has a
             | ceiling and no rate; a hotel has both in some businesses. A
             | table that forced one shape on all of them would make the
             | other categories carry a column that means nothing, and a
             | column that means nothing is a column somebody fills in.
             */
            $table->decimal('rate', 21, 8)->nullable();
            $table->string('rate_unit', 8)->nullable();
            $table->decimal('ceiling_per_line', 21, 4)->nullable();
            $table->decimal('ceiling_per_claim', 21, 4)->nullable();

            /*
             | The amount above which a receipt is required.
             |
             | Zero means always, which is the honest default: a business
             | that wants to waive receipts under a small figure is making a
             | decision, and it should be visible as one.
             */
            $table->decimal('receipt_required_above', 21, 4)->default(0);

            /*
             | Whether the category permits recovering input tax at all.
             |
             | Separate from whether a receipt is attached, because they
             | fail differently: no receipt is no evidence, and client
             | entertainment is no entitlement however good the evidence.
             */
            $table->boolean('tax_recoverable')->default(true);
            $table->decimal('recoverable_percent', 9, 6)->default(100);

            $table->foreignUlid('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('active')->default(true);

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'category', 'effective_from']);
        });

        Schema::create('expense_claims', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 64)->nullable();
            $table->string('status', 24)->default('draft');

            /*
             | The claimant is a user, not a party.
             |
             | An employee is not a supplier, and modelling them as one puts
             | a colleague in the list a payment run reads and the aging
             | report ages. The payable is to a person and it sits in a
             | control account of its own.
             */
            $table->ulid('claimant_user_id');

            $table->date('claimed_on');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            $table->char('currency', 3);
            $table->decimal('net_amount', 21, 4)->default(0);
            $table->decimal('tax_amount', 21, 4)->default(0);
            $table->decimal('gross_amount', 21, 4)->default(0);
            $table->decimal('recoverable_tax_amount', 21, 4)->default(0);
            $table->decimal('non_recoverable_tax_amount', 21, 4)->default(0);
            $table->decimal('paid_amount', 21, 4)->default(0);

            $table->date('posting_date')->nullable();
            $table->ulid('journal_entry_id')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->ulid('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'claimant_user_id', 'status']);
        });

        Schema::create('expense_claim_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('expense_claim_id')->constrained()->cascadeOnDelete();

            $table->integer('line_no');
            $table->string('category', 24);
            $table->date('incurred_on');
            $table->text('description');

            /*
             | A quantity and a frozen rate, for the categories that have
             | them.
             |
             | 240 kilometres at the rate that applied in November, not at
             | today's. A claim re-derived at the current rate pays the
             | wrong amount and cannot be reconciled to what was approved.
             */
            $table->decimal('quantity', 21, 8)->nullable();
            $table->decimal('rate', 21, 8)->nullable();
            $table->string('rate_unit', 8)->nullable();
            $table->ulid('policy_id')->nullable();

            $table->decimal('net_amount', 21, 4);
            $table->foreignUlid('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->string('tax_category', 3)->nullable();
            $table->decimal('tax_rate', 9, 6)->default(0);
            $table->decimal('tax_amount', 21, 4)->default(0);
            $table->decimal('recoverable_percent', 9, 6)->default(0);

            $table->foreignUlid('expense_account_id')->constrained('accounts')->restrictOnDelete();

            /*
             | The receipt, and why the tax decision went the way it did.
             |
             | Stored rather than re-derived, because the policy changes and
             | the return has already been filed: "why was this claimed"
             | has to be answerable from the line a year later.
             */
            $table->ulid('receipt_attachment_id')->nullable();
            $table->text('tax_decision')->nullable();
            $table->text('policy_exception')->nullable();
            $table->text('policy_explanation')->nullable();

            $table->char('dimension_1', 26)->nullable();
            $table->char('dimension_2', 26)->nullable();
            $table->char('dimension_3', 26)->nullable();
            $table->char('dimension_4', 26)->nullable();

            $table->timestamps();

            $table->unique(['expense_claim_id', 'line_no']);
            $table->index(['entity_id', 'category', 'incurred_on']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE expense_policies
                ADD CONSTRAINT expense_policies_category_is_known
                    CHECK (category IN ('travel', 'accommodation', 'meals', 'mileage', 'per_diem',
                                        'entertainment', 'supplies', 'other')),

                ADD CONSTRAINT expense_policies_rate_has_a_unit
                    CHECK ((rate IS NULL) = (rate_unit IS NULL)),

                ADD CONSTRAINT expense_policies_figures_are_not_negative
                    CHECK (
                        coalesce(rate, 0) >= 0
                        AND coalesce(ceiling_per_line, 0) >= 0
                        AND coalesce(ceiling_per_claim, 0) >= 0
                        AND receipt_required_above >= 0
                    ),

                ADD CONSTRAINT expense_policies_recoverable_percent_is_a_percentage
                    CHECK (recoverable_percent >= 0 AND recoverable_percent <= 100),

                /*
                 | A category that recovers no tax recovers none of it.
                 |
                 | Both columns exist because partial recovery is real, and
                 | the pair "not recoverable, 50%" is two answers to one
                 | question - which is the state somebody finds while
                 | explaining a return to an authority.
                 */
                ADD CONSTRAINT expense_policies_recovery_is_consistent
                    CHECK (tax_recoverable OR recoverable_percent = 0),

                ADD CONSTRAINT expense_policies_window_is_ordered
                    CHECK (effective_to IS NULL OR effective_to >= effective_from);

            ALTER TABLE expense_claims
                ADD CONSTRAINT expense_claims_status_is_known
                    CHECK (status IN ('draft', 'awaiting_approval', 'approved', 'rejected',
                                      'posted', 'cancelled')),

                ADD CONSTRAINT expense_claims_approval_is_attributable
                    CHECK (
                        (approved_at IS NULL AND approved_by IS NULL)
                        OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)
                    ),

                ADD CONSTRAINT expense_claims_rejection_is_explained
                    CHECK (
                        status <> 'rejected'
                        OR (rejected_at IS NOT NULL AND rejected_by IS NOT NULL
                            AND length(btrim(coalesce(rejection_reason, ''))) > 5)
                    ),

                /*
                 | A posted claim was approved first, and names its entry.
                 |
                 | Posting an unapproved claim pays somebody for expenses
                 | nobody agreed to, and a posted claim with no entry is a
                 | balance in the employee payable account that no document
                 | explains.
                 */
                ADD CONSTRAINT expense_claims_posting_follows_approval
                    CHECK (
                        status <> 'posted'
                        OR (approved_at IS NOT NULL AND journal_entry_id IS NOT NULL
                            AND posting_date IS NOT NULL)
                    ),

                ADD CONSTRAINT expense_claims_period_is_ordered
                    CHECK (period_to IS NULL OR period_from IS NULL OR period_to >= period_from),

                ADD CONSTRAINT expense_claims_tax_splits_tie
                    CHECK (recoverable_tax_amount + non_recoverable_tax_amount = tax_amount),

                ADD CONSTRAINT expense_claims_gross_ties
                    CHECK (gross_amount = net_amount + tax_amount);

            ALTER TABLE expense_claim_lines
                ADD CONSTRAINT expense_claim_lines_category_is_known
                    CHECK (category IN ('travel', 'accommodation', 'meals', 'mileage', 'per_diem',
                                        'entertainment', 'supplies', 'other')),

                ADD CONSTRAINT expense_claim_lines_description_is_present
                    CHECK (length(btrim(description)) > 0),

                ADD CONSTRAINT expense_claim_lines_amounts_are_not_negative
                    CHECK (net_amount >= 0 AND tax_amount >= 0),

                ADD CONSTRAINT expense_claim_lines_quantity_pairs_with_a_rate
                    CHECK ((quantity IS NULL) = (rate IS NULL)),

                ADD CONSTRAINT expense_claim_lines_recoverable_percent_is_a_percentage
                    CHECK (recoverable_percent >= 0 AND recoverable_percent <= 100),

                /*
                 | Recovering input tax needs a receipt AND a reason.
                 |
                 | The reason is the stored tax decision: "why was this
                 | claimed" has to be answerable from the line a year later,
                 | when the policy has changed and the return has been
                 | filed. A claim with recovery and no receipt is exactly
                 | what an authority asks about.
                 */
                ADD CONSTRAINT expense_claim_lines_recovery_needs_a_receipt
                    CHECK (
                        recoverable_percent = 0
                        OR (receipt_attachment_id IS NOT NULL
                            AND length(btrim(coalesce(tax_decision, ''))) > 5)
                    ),

                /*
                 | A policy exception is explained by a person.
                 |
                 | A flag with no text is a line somebody approved without
                 | knowing what was wrong with it, and the approver is the
                 | person the policy exists to inform.
                 */
                ADD CONSTRAINT expense_claim_lines_exception_is_explained
                    CHECK (
                        policy_exception IS NULL
                        OR length(btrim(coalesce(policy_explanation, ''))) > 5
                    );
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE expense_claims IS
                'The payable is to the EMPLOYEE, in a control account of its own - an employee is not a supplier - PF-143.';
            COMMENT ON CONSTRAINT expense_claim_lines_recovery_needs_a_receipt ON expense_claim_lines IS
                'Input tax claimed on an employee word is a return whose input box cannot be supported.';
            COMMENT ON COLUMN expense_claim_lines.rate IS
                'FROZEN from the policy on the date incurred. A claim re-derived at today rate pays the wrong amount.';
        SQL);

        foreach (['expense_policies', 'expense_claims', 'expense_claim_lines'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_lines');
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('expense_policies');
    }
};
