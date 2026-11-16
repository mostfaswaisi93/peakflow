<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders, receipts and the three-way match - PF-131, PF-132, PF-135.
 *
 * ## Everything is tracked PER LINE
 *
 * `quantity_received` and `quantity_billed` live on the order line, not on
 * the order. An order for ten items of which six arrived and four were
 * billed is the normal case, and an order-level figure cannot express it:
 * "60% received" says nothing about which six, and the three-way match needs
 * to know.
 *
 * ## A commitment is never a posting
 *
 * The remaining commitment on a line is `(ordered - billed) x price`, and it
 * is computed rather than stored as a journal entry. An order is not a
 * transaction: nothing has happened yet, nobody is owed anything, and a
 * ledger that carried commitments would have a balance sheet that does not
 * reconcile to anything. Budget visibility is a report, not an entry.
 *
 * ## The accrual is what makes a cutoff honest
 *
 * Goods arrive on the 29th and the invoice arrives on the 4th. Without an
 * accrual the expense lands in the wrong month and the balance sheet omits a
 * liability that exists. `goods_received_not_invoiced` is a control account
 * so that "what have we received and not been billed for" is a balance
 * rather than a note - and the bill that matches reverses it, so a balance
 * that does not clear is a receipt nobody was ever billed for.
 *
 * ## A mismatch names the discrepancy
 *
 * `three_way_match_exceptions` carries the expected and the actual figure,
 * so the queue says "billed 120 units, received 100" rather than "on hold".
 * A generic hold makes somebody open three documents to find out what is
 * wrong, and the person who opens them is not the person who knows.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 64)->nullable();
            $table->string('series_key', 32)->default('PO');
            $table->string('status', 16)->default('draft');

            $table->foreignUlid('party_id')->constrained()->restrictOnDelete();

            $table->date('ordered_on');
            $table->date('expected_on')->nullable();

            $table->char('currency', 3);
            $table->decimal('net_amount', 21, 4)->default(0);
            $table->decimal('tax_amount', 21, 4)->default(0);
            $table->decimal('gross_amount', 21, 4)->default(0);

            $table->foreignUlid('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();

            $table->ulid('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->ulid('confirmed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->ulid('closed_by')->nullable();
            $table->text('closed_reason')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'party_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('purchase_order_id')->constrained()->cascadeOnDelete();

            $table->integer('line_no');

            $table->foreignUlid('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_code', 64)->nullable();
            $table->text('description');

            /*
             | Goods or a service, because the match differs.
             |
             | A service has no quantity to count on a loading bay: the
             | receipt is a confirmation that the work was done, which is
             | what a three-way match needs instead.
             */
            $table->string('line_type', 16)->default('goods');

            $table->string('unit_code', 8)->default('C62');
            $table->decimal('quantity_ordered', 21, 8);
            $table->decimal('unit_price', 21, 8);
            $table->decimal('net_amount', 21, 4);

            /*
             | Per line, and maintained as receipts and bills arrive.
             |
             | An order of ten of which six arrived and four were billed is
             | the normal case, and an order-level figure cannot say which
             | six.
             */
            $table->decimal('quantity_received', 21, 8)->default(0);
            $table->decimal('quantity_billed', 21, 8)->default(0);

            $table->foreignUlid('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignUlid('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->string('tax_category', 3)->nullable();
            $table->decimal('tax_rate', 9, 6)->default(0);

            $table->date('expected_on')->nullable();

            $table->char('dimension_1', 26)->nullable();
            $table->char('dimension_2', 26)->nullable();
            $table->char('dimension_3', 26)->nullable();
            $table->char('dimension_4', 26)->nullable();

            $table->timestamps();

            $table->unique(['purchase_order_id', 'line_no']);
            $table->index(['entity_id', 'item_id']);
        });

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 64)->nullable();
            $table->foreignUlid('purchase_order_id')->constrained()->restrictOnDelete();

            $table->date('received_on');
            $table->ulid('received_by')->nullable();
            $table->string('delivery_note', 64)->nullable();

            /*
             | The accrual this receipt posted, if it posted one.
             |
             | Optional per entity: a tenant that bills within days of
             | receiving does not need it, and one that closes a period
             | between the two does.
             */
            $table->ulid('journal_entry_id')->nullable();
            $table->ulid('reversal_entry_id')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'purchase_order_id']);
            $table->index(['entity_id', 'received_on']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();

            $table->decimal('quantity', 21, 8);
            $table->decimal('quantity_rejected', 21, 8)->default(0);
            $table->text('rejection_reason')->nullable();

            /*
             | A service line's receipt is a confirmation of performance.
             |
             | Who confirmed it and when, because that is the evidence a
             | three-way match has for a service: there is nothing on a
             | loading bay to count.
             */
            $table->ulid('confirmed_by')->nullable();
            $table->text('confirmation')->nullable();

            $table->timestamps();

            $table->unique(['goods_receipt_id', 'purchase_order_line_id']);
        });

        /*
         | Tolerances, per supplier, with a platform ceiling - PF-135.
         |
         | Both absolute and percentage, because neither alone works: 2% of a
         | 50-unit order is one unit, and 2% of a 50,000-unit order is a
         | thousand. The ceiling exists because a tenant who sets a 50%
         | tolerance has turned the match off, and the thing a three-way
         | match is for is the invoice that is quietly 20% too high.
         */
        Schema::create('supplier_match_tolerances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('party_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('line_type', 16)->nullable();

            $table->decimal('quantity_percent', 9, 6)->default(0);
            $table->decimal('quantity_absolute', 21, 8)->default(0);
            $table->decimal('price_percent', 9, 6)->default(0);
            $table->decimal('price_absolute', 21, 4)->default(0);

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['entity_id', 'party_id', 'line_type']);
        });

        Schema::create('three_way_match_exceptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bill_line_id')->nullable()->constrained('bill_lines')->cascadeOnDelete();
            $table->foreignUlid('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();

            $table->string('kind', 32);

            /*
             | The figures, so the queue can say what is wrong.
             |
             | "Billed 120 units, received 100" rather than "on hold". A
             | generic hold makes somebody open three documents to find out,
             | and the person who opens them is not the person who knows.
             */
            $table->decimal('expected', 21, 8)->nullable();
            $table->decimal('actual', 21, 8)->nullable();
            $table->decimal('tolerance', 21, 8)->nullable();
            $table->text('detail');

            $table->timestamp('resolved_at')->nullable();
            $table->ulid('resolved_by')->nullable();
            $table->text('resolution')->nullable();

            $table->timestamps();

            $table->unique(['bill_line_id', 'kind']);
            $table->index(['entity_id', 'resolved_at']);
        });

        /*
         | What a bill line matched against - PF-135.
         |
         | On the bill line, because the match is a property of the line: one
         | bill routinely spans several orders, and a bill-level pointer
         | could not say which line came from which.
         */
        Schema::table('bill_lines', function (Blueprint $table): void {
            $table->foreignUlid('purchase_order_line_id')->nullable()
                ->constrained('purchase_order_lines')->nullOnDelete();
            $table->foreignUlid('goods_receipt_line_id')->nullable()
                ->constrained('goods_receipt_lines')->nullOnDelete();
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->string('match_status', 16)->default('unmatched');
            $table->timestamp('matched_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE purchase_orders
                ADD CONSTRAINT purchase_orders_status_is_known
                    CHECK (status IN ('draft', 'awaiting_approval', 'confirmed', 'closed', 'cancelled')),

                ADD CONSTRAINT purchase_orders_confirmation_is_dated
                    CHECK (
                        (confirmed_at IS NULL AND confirmed_by IS NULL)
                        OR (confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL)
                    ),

                /*
                 | A closed order says why.
                 |
                 | "Closed" with no reason is the state somebody finds in a
                 | year while asking why nineteen units were never delivered.
                 */
                ADD CONSTRAINT purchase_orders_closure_is_explained
                    CHECK (
                        status <> 'closed'
                        OR (closed_at IS NOT NULL AND closed_by IS NOT NULL AND length(btrim(coalesce(closed_reason, ''))) > 5)
                    );

            ALTER TABLE purchase_order_lines
                ADD CONSTRAINT purchase_order_lines_type_is_known
                    CHECK (line_type IN ('goods', 'service')),

                ADD CONSTRAINT purchase_order_lines_quantity_is_positive
                    CHECK (quantity_ordered > 0),

                ADD CONSTRAINT purchase_order_lines_received_is_not_negative
                    CHECK (quantity_received >= 0),

                ADD CONSTRAINT purchase_order_lines_billed_is_not_negative
                    CHECK (quantity_billed >= 0),

                ADD CONSTRAINT purchase_order_lines_description_is_present
                    CHECK (length(btrim(description)) > 0);

            ALTER TABLE goods_receipt_lines
                ADD CONSTRAINT goods_receipt_lines_quantity_is_positive
                    CHECK (quantity > 0),

                ADD CONSTRAINT goods_receipt_lines_rejection_is_explained
                    CHECK (
                        quantity_rejected = 0
                        OR length(btrim(coalesce(rejection_reason, ''))) > 5
                    );

            ALTER TABLE supplier_match_tolerances
                ADD CONSTRAINT supplier_match_tolerances_line_type_is_known
                    CHECK (line_type IS NULL OR line_type IN ('goods', 'service')),

                ADD CONSTRAINT supplier_match_tolerances_are_not_negative
                    CHECK (
                        quantity_percent >= 0 AND quantity_absolute >= 0
                        AND price_percent >= 0 AND price_absolute >= 0
                    ),

                /*
                 | The platform ceiling - PF-135.
                 |
                 | A tenant who sets a 50% tolerance has turned the match
                 | off, and the thing a three-way match is for is the
                 | invoice that is quietly 20% too high. Ten per cent is the
                 | most the platform permits, and a business that needs more
                 | needs a conversation rather than a setting.
                 */
                ADD CONSTRAINT supplier_match_tolerances_within_the_platform_ceiling
                    CHECK (quantity_percent <= 10 AND price_percent <= 10);

            ALTER TABLE three_way_match_exceptions
                ADD CONSTRAINT three_way_match_exceptions_kind_is_known
                    CHECK (kind IN (
                        'no_order', 'no_receipt', 'quantity_over_received', 'quantity_over_ordered',
                        'price_above_order', 'order_closed', 'wrong_supplier', 'service_not_confirmed'
                    )),

                ADD CONSTRAINT three_way_match_exceptions_detail_is_stated
                    CHECK (length(btrim(detail)) > 10),

                ADD CONSTRAINT three_way_match_exceptions_resolution_is_attributable
                    CHECK (
                        (resolved_at IS NULL AND resolved_by IS NULL)
                        OR (resolved_at IS NOT NULL AND resolved_by IS NOT NULL
                            AND length(btrim(coalesce(resolution, ''))) > 5)
                    );

            ALTER TABLE bills
                ADD CONSTRAINT bills_match_status_is_known
                    CHECK (match_status IN ('unmatched', 'matched', 'exception', 'not_applicable'));
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN purchase_order_lines.quantity_received IS
                'Per LINE. An order of ten of which six arrived cannot be expressed at order level - PF-131.';
            COMMENT ON TABLE three_way_match_exceptions IS
                'A mismatch names the figures: "billed 120, received 100" rather than "on hold" - PF-135.';
            COMMENT ON CONSTRAINT supplier_match_tolerances_within_the_platform_ceiling ON supplier_match_tolerances IS
                'A 50% tolerance is the match turned off, and what the match is for is the invoice that is quietly 20% too high.';
        SQL);

        foreach ([
            'purchase_orders', 'purchase_order_lines', 'goods_receipts', 'goods_receipt_lines',
            'supplier_match_tolerances', 'three_way_match_exceptions',
        ] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of columns added in
            // this same migration, so nothing deployed before it reads them.
            $table->dropColumn(['match_status', 'matched_at']);
        });

        Schema::table('bill_lines', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: as above.
            $table->dropColumn(['purchase_order_line_id', 'goods_receipt_line_id']);
        });

        Schema::dropIfExists('three_way_match_exceptions');
        Schema::dropIfExists('supplier_match_tolerances');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
