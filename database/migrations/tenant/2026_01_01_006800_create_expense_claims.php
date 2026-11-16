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
             | How much of the input tax this category may recover, as a
             | percentage. Zero means none.
             |
             | ONE column, deliberately. The obvious design is a boolean
             | `tax_recoverable` beside the percentage, and it carries no
             | information the percentage does not: "not recoverable" is
             | zero per cent. The pair needs a CHECK to stop them
             | disagreeing, and a pair kept consistent by a constraint is a
             | pair somebody will eventually read only one half of.
             |
             | Measured: with both columns, a bite that removed the service's
             | boolean check changed nothing, because the percentage was
             | already zero. Two columns answering one question also means
             | two places a check can pass while the other is wrong.
             |
             | Partial recovery - entertainment at 50% in several
             | jurisdictions - is the reason it is a percentage rather than
             | a flag in the first place.
             */
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
                    );
        SQL);

        /*
         | A policy exception is explained before the claim leaves draft.
         |
         | Not at insert. The flag is raised by the system - the line is over
         | a ceiling, or no policy covers the category - and the explanation
         | comes from the claimant afterwards, so a column-level CHECK would
         | refuse the very line it is trying to annotate.
         |
         | The moment that matters is submission: a flag with no text is a
         | line somebody approves without knowing what was wrong with it, and
         | the approver is the person the policy exists to inform. So the
         | guarantee is a trigger on the claim's status, which is also the
         | only place it can be expressed - the condition spans the claim and
         | its lines.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_refuse_an_unexplained_breach()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                offending RECORD;
            BEGIN
                IF NEW.status = OLD.status OR OLD.status <> 'draft' THEN
                    RETURN NEW;
                END IF;

                IF NEW.status IN ('cancelled', 'rejected') THEN
                    RETURN NEW;
                END IF;

                SELECT line_no, category, policy_exception
                INTO offending
                FROM expense_claim_lines
                WHERE expense_claim_id = NEW.id
                  AND policy_exception IS NOT NULL
                  AND length(btrim(coalesce(policy_explanation, ''))) <= 5
                ORDER BY line_no
                LIMIT 1;

                IF offending IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Line % breaches the % policy (%) and carries no explanation. The approver is '
                        'the person the policy exists to inform, and a flag with no text is a line '
                        'somebody approves without knowing what is wrong with it.',
                        offending.line_no, offending.category, offending.policy_exception
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER expense_claims_breaches_are_explained_before_submission
                BEFORE UPDATE ON expense_claims
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_refuse_an_unexplained_breach();
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE expense_claims IS
                'The payable is to the EMPLOYEE, in a control account of its own - an employee is not a supplier - PF-143.';
            COMMENT ON CONSTRAINT expense_claim_lines_recovery_needs_a_receipt ON expense_claim_lines IS
                'Input tax claimed on an employee word is a return whose input box cannot be supported.';
            COMMENT ON FUNCTION peakflow_refuse_an_unexplained_breach() IS
                'The flag is raised at entry by the system and explained afterwards by a person, so the moment that matters is submission - PF-143.';
            COMMENT ON COLUMN expense_claim_lines.rate IS
                'FROZEN from the policy on the date incurred. A claim re-derived at today rate pays the wrong amount.';
        SQL);

        foreach (['expense_policies', 'expense_claims', 'expense_claim_lines'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS expense_claims_breaches_are_explained_before_submission ON expense_claims;
            DROP FUNCTION IF EXISTS peakflow_refuse_an_unexplained_breach();
        SQL);

        Schema::dropIfExists('expense_claim_lines');
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('expense_policies');
    }
};
