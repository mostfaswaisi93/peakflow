<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales orders, and the balance left to invoice on each line - PF-090.
 *
 * @financial
 *
 * ## Why the balance is tracked per LINE
 *
 * Because that is the question people ask: "have we invoiced the installation
 * yet?", not "how much of this order is left". An order for three things
 * invoiced to 60% could be any combination of them, and a balance held only
 * at the order level cannot say which - so the second invoice duplicates a
 * line and nobody notices until the customer does.
 *
 * It is a column maintained by a trigger rather than a sum computed on
 * demand, for the same reason the credit-note total is: the over-invoicing
 * cap has to hold under concurrency, and `INSERT ... ON CONFLICT DO UPDATE`
 * re-reads the committed row after waiting while a `SELECT sum(...)` inside
 * a trigger does not.
 *
 * ## An order is not an accounting document
 *
 * No posting, ever. A confirmed order is a commitment - it shows against the
 * customer's credit exposure and, where a tenant wants it, in a budget - and
 * a commitment is not a liability or a receivable. A check asserts that no
 * journal entry is ever written with an order as its source.
 */
return new class extends Migration
{
    private const STATES = ['draft', 'confirmed', 'completed', 'cancelled'];

    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            // A reference, not a legal number - the same reasoning as a
            // quote's.
            $table->string('reference', 64);

            $table->foreignUlid('party_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');

            $table->date('order_date');
            $table->date('requested_delivery')->nullable();
            $table->string('customer_reference', 128)->nullable();

            $table->char('currency', 3);
            $table->decimal('net_amount', 21, 4)->default(0);
            $table->decimal('tax_amount', 21, 4)->default(0);
            $table->decimal('gross_amount', 21, 4)->default(0);
            $table->unsignedTinyInteger('presentation_exponent')->default(2);

            $table->string('profile', 32)->nullable();
            $table->text('terms')->nullable();
            $table->text('note')->nullable();

            // The quote it came from, where it came from one.
            $table->ulid('quote_id')->nullable();

            $table->timestampTz('confirmed_at')->nullable();
            $table->char('confirmed_by', 26)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancelled_reason')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'reference']);
            $table->index(['entity_id', 'status']);
            $table->index(['entity_id', 'party_id', 'status']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('sales_order_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_no');
            $table->ulid('item_id')->nullable();
            $table->string('item_code', 64)->nullable();
            $table->text('description');
            $table->char('unit_code', 8)->default('C62');

            $table->decimal('quantity', 21, 8);
            $table->decimal('unit_price', 21, 8);
            $table->decimal('net_amount', 21, 4);

            /*
             | What has been invoiced against this line, maintained by the
             | trigger when an invoice is issued.
             |
             | Quantity AND net, because either can be the thing somebody
             | invoices: a service line is invoiced by value and a goods line
             | by quantity, and a cap on only one of them refuses legitimate
             | documents in the other case.
             */
            $table->decimal('invoiced_quantity', 21, 8)->default(0);
            $table->decimal('invoiced_net', 21, 4)->default(0);

            $table->ulid('revenue_account_id')->nullable();
            $table->ulid('tax_code_id')->nullable();
            $table->string('tax_category', 3)->nullable();
            $table->decimal('tax_rate', 9, 6)->nullable();

            $table->timestamps();

            $table->unique(['sales_order_id', 'line_no']);
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            /*
             | @unsafe-migration-reviewed: the column is created in the same
             | statement as its constraint, so every existing row holds NULL
             | and the validation scan has nothing to fail on. The table is
             | new in this release and has no long-running readers.
             */
            $table->foreignUlid('order_line_id')->nullable()
                ->constrained('sales_order_lines')->nullOnDelete();
        });

        $states = "'".implode("', '", self::STATES)."'";

        DB::unprepared(<<<SQL
            ALTER TABLE sales_orders
                ADD CONSTRAINT sales_orders_status_is_known
                    CHECK (status IN ({$states})),

                ADD CONSTRAINT sales_orders_gross_is_net_plus_tax
                    CHECK (gross_amount = net_amount + tax_amount),

                ADD CONSTRAINT sales_orders_confirmed_is_attributable
                    CHECK ((status IN ('confirmed', 'completed')) = (confirmed_at IS NOT NULL)),

                ADD CONSTRAINT sales_orders_cancelled_is_dated
                    CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL)),

                /*
                 | A cancellation says why.
                 |
                 | An order cancelled with no reason is a conversation nobody
                 | can reconstruct, and the customer's version of it is the
                 | one that survives.
                 */
                ADD CONSTRAINT sales_orders_cancellation_has_a_reason
                    CHECK (cancelled_at IS NULL OR length(btrim(coalesce(cancelled_reason, ''))) >= 10);

            ALTER TABLE sales_order_lines
                ADD CONSTRAINT sales_order_lines_quantity_is_positive
                    CHECK (quantity > 0),

                ADD CONSTRAINT sales_order_lines_invoiced_is_not_negative
                    CHECK (invoiced_quantity >= 0 AND invoiced_net >= 0),

                /*
                 | The cap, as a constraint on the row itself.
                 |
                 | The trigger adds to these columns and this refuses the
                 | result, which means the cap is enforced by the database
                 | rather than by the code that updates it: an UPDATE that
                 | tried to invoice more than was ordered cannot commit,
                 | whoever wrote it.
                 */
                ADD CONSTRAINT sales_order_lines_not_over_invoiced
                    CHECK (invoiced_quantity <= quantity AND invoiced_net <= net_amount);

            SELECT peakflow_apply_entity_rls('sales_orders');
            SELECT peakflow_apply_entity_rls('sales_order_lines');
        SQL);
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the DOWN path of the migration that
            // added the column, so the only code reading it is code
            // introduced with it.
            $table->dropConstrainedForeignId('order_line_id');
        });

        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
