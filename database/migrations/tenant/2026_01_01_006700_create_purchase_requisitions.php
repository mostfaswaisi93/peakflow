<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase requisitions - PF-130.
 *
 * ## A request, before anything is committed
 *
 * A requisition is an internal document: somebody asks for something, a
 * budget holder agrees, and only then does an order go to a supplier. It
 * posts nothing and commits nothing, like the order it becomes - but one step
 * further back, because at this point the business has not even chosen who to
 * buy from.
 *
 * ## The justification is required, and it is the whole point
 *
 * A requisition with a quantity, a price and no reason is a purchase order
 * with extra clicks. What makes the step worth having is that somebody wrote
 * down why, and somebody else read it - which is the only thing a budget
 * holder can act on three months later when asked why the department spent
 * what it did.
 *
 * ## It routes through the bill approval engine
 *
 * PF-130's second criterion: "one approval mechanism and not two". The engine
 * is already generic over `subject_type`, so a requisition is a subject like
 * any other - the same limits, the same delegation, the same fingerprint
 * check that returns an edited document to the start of the chain, and the
 * same segregation-of-duties conflict when the requester tries to approve
 * their own request.
 *
 * A second workflow would mean a second set of limits to keep in step, and
 * the one that drifts is the one nobody is looking at.
 *
 * @financial-not-applicable: requisitions post nothing - they are an internal
 * request before any commitment exists, let alone an obligation. The RLS
 * policy is applied below because they are tenant data, but there is no
 * financial posting for `entity_id` to scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 64)->nullable();
            $table->string('status', 24)->default('draft');

            $table->ulid('requested_by');
            $table->date('requested_on');
            $table->date('needed_by')->nullable();

            /*
             | The justification, and a length the database insists on.
             |
             | "Needed" is what somebody types to get past a required field,
             | and it is what a budget holder reads three months later while
             | being asked why the department spent what it did.
             */
            $table->text('justification');

            $table->char('currency', 3);
            $table->decimal('estimated_amount', 21, 4)->default(0);

            /*
             | The cost centre, as a dimension rather than a column of its
             | own.
             |
             | Which is what carries forward to the order and then to the
             | posting, so the expense lands against the budget that
             | approved it. A `cost_centre` column would be a fifth
             | dimension that the ledger does not know about.
             */
            $table->char('dimension_1', 26)->nullable();
            $table->char('dimension_2', 26)->nullable();
            $table->char('dimension_3', 26)->nullable();
            $table->char('dimension_4', 26)->nullable();

            $table->foreignUlid('preferred_party_id')->nullable()->constrained('parties')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->ulid('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('converted_at')->nullable();
            $table->ulid('purchase_order_id')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'status']);
            $table->index(['entity_id', 'requested_by']);
        });

        Schema::create('purchase_requisition_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('purchase_requisition_id')->constrained()->cascadeOnDelete();

            $table->integer('line_no');

            $table->foreignUlid('item_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->string('line_type', 16)->default('goods');

            $table->string('unit_code', 8)->default('C62');
            $table->decimal('quantity', 21, 8);

            /*
             | An ESTIMATED price, named as one.
             |
             | The supplier has not quoted yet, and a column called
             | `unit_price` would be read as a price somebody agreed - which
             | is how an estimate ends up on a purchase order unchanged.
             */
            $table->decimal('estimated_unit_price', 21, 8)->default(0);
            $table->decimal('estimated_amount', 21, 4)->default(0);

            $table->foreignUlid('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->date('needed_by')->nullable();

            $table->char('dimension_1', 26)->nullable();
            $table->char('dimension_2', 26)->nullable();
            $table->char('dimension_3', 26)->nullable();
            $table->char('dimension_4', 26)->nullable();

            $table->timestamps();

            $table->unique(['purchase_requisition_id', 'line_no']);
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignUlid('purchase_requisition_id')->nullable()
                ->constrained('purchase_requisitions')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE purchase_requisitions
                ADD CONSTRAINT purchase_requisitions_status_is_known
                    CHECK (status IN ('draft', 'awaiting_approval', 'approved', 'rejected', 'converted', 'cancelled')),

                ADD CONSTRAINT purchase_requisitions_justification_says_something
                    CHECK (length(btrim(justification)) > 10),

                ADD CONSTRAINT purchase_requisitions_estimate_is_not_negative
                    CHECK (estimated_amount >= 0),

                ADD CONSTRAINT purchase_requisitions_approval_is_attributable
                    CHECK (
                        (approved_at IS NULL AND approved_by IS NULL)
                        OR (approved_at IS NOT NULL AND approved_by IS NOT NULL)
                    ),

                /*
                 | A rejection says why.
                 |
                 | The requester is going to ask, and "rejected" with
                 | nothing behind it is what makes them re-submit the same
                 | request with a different title.
                 */
                ADD CONSTRAINT purchase_requisitions_rejection_is_explained
                    CHECK (
                        status <> 'rejected'
                        OR (rejected_at IS NOT NULL AND rejected_by IS NOT NULL
                            AND length(btrim(coalesce(rejection_reason, ''))) > 5)
                    ),

                /*
                 | A converted requisition names its order.
                 |
                 | Otherwise "what came of my request" is answered by
                 | searching the orders for something that looks similar,
                 | which is how two orders get raised for one request.
                 */
                ADD CONSTRAINT purchase_requisitions_conversion_names_its_order
                    CHECK (
                        status <> 'converted'
                        OR (converted_at IS NOT NULL AND purchase_order_id IS NOT NULL)
                    ),

                ADD CONSTRAINT purchase_requisitions_approval_precedes_conversion
                    CHECK (status <> 'converted' OR approved_at IS NOT NULL);

            ALTER TABLE purchase_requisition_lines
                ADD CONSTRAINT purchase_requisition_lines_type_is_known
                    CHECK (line_type IN ('goods', 'service')),

                ADD CONSTRAINT purchase_requisition_lines_quantity_is_positive
                    CHECK (quantity > 0),

                ADD CONSTRAINT purchase_requisition_lines_description_is_present
                    CHECK (length(btrim(description)) > 0),

                ADD CONSTRAINT purchase_requisition_lines_estimate_is_not_negative
                    CHECK (estimated_amount >= 0 AND estimated_unit_price >= 0);
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN purchase_requisition_lines.estimated_unit_price IS
                'An ESTIMATE. The supplier has not quoted, and a column called unit_price reads as a price somebody agreed - PF-130.';
            COMMENT ON CONSTRAINT purchase_requisitions_justification_says_something ON purchase_requisitions IS
                'A requisition with a quantity, a price and no reason is a purchase order with extra clicks.';
        SQL);

        foreach (['purchase_requisitions', 'purchase_requisition_lines'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a column added in
            // this same migration, so nothing deployed before it reads it.
            $table->dropConstrainedForeignId('purchase_requisition_id');
        });

        Schema::dropIfExists('purchase_requisition_lines');
        Schema::dropIfExists('purchase_requisitions');
    }
};
