<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quote and an order carry the supply type forward - PF-078.
 *
 * ## Why these two tables and not only the invoice line
 *
 * Because a document invoiced from an order is not drafted by a person: the
 * line comes from the order, which came from a quote. 001900 put
 * `supply_type` on `invoice_lines` and the invoice path fills it from the
 * catalogue item - and an order line with a free-text description has no
 * item, so the invoice raised from it has nothing to be placed by and
 * refuses at issue.
 *
 * That refusal is correct and lands in the wrong place. Whoever priced the
 * quote knows whether they are selling a good or a service; whoever presses
 * "invoice this order" three weeks later does not, and the product would be
 * asking them.
 *
 * So the fact is captured where it is known and travels with the line:
 * quote to order to invoice, each copying the one before it.
 *
 * ## The same three words, again
 *
 * `items.type`, `invoice_lines.supply_type` and now these two use one
 * vocabulary. Four spellings of the same fact would mean three mappings,
 * and a mapping is where a supply acquires the wrong type on the way
 * through.
 *
 * @financial-not-applicable: adds two nullable columns to pre-financial
 * documents - a quote and an order post nothing. No existing value is
 * rewritten.
 * @online-safe Two nullable column additions with no default, which
 * PostgreSQL does as a catalogue change, and two constraints added NOT
 * VALID and validated separately so neither takes ACCESS EXCLUSIVE for the
 * length of a scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->string('supply_type', 16)->nullable()->after('item_code');
        });

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->string('supply_type', 16)->nullable()->after('item_code');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE quote_lines
                ADD CONSTRAINT quote_lines_supply_type_known
                CHECK (supply_type IS NULL
                       OR supply_type IN ('good', 'service', 'digital_service'))
                NOT VALID;

            ALTER TABLE quote_lines VALIDATE CONSTRAINT quote_lines_supply_type_known;

            ALTER TABLE sales_order_lines
                ADD CONSTRAINT sales_order_lines_supply_type_known
                CHECK (supply_type IS NULL
                       OR supply_type IN ('good', 'service', 'digital_service'))
                NOT VALID;

            ALTER TABLE sales_order_lines VALIDATE CONSTRAINT sales_order_lines_supply_type_known;

            COMMENT ON COLUMN quote_lines.supply_type IS
                'What kind of supply this line is - PF-078. Captured where it is known, so the invoice eventually raised from it can be placed by the rule book without asking whoever pressed the button.';

            COMMENT ON COLUMN sales_order_lines.supply_type IS
                'What kind of supply this line is - PF-078. Copied from the quote line, and copied on to the invoice line.';
        SQL);
    }

    /**
     * @unsafe-migration-reviewed: the drops roll back columns this same
     * migration added, in the release that added them, so the code reading
     * them is the code being withdrawn with them.
     */
    public function down(): void
    {
        DB::unprepared('ALTER TABLE quote_lines DROP CONSTRAINT IF EXISTS quote_lines_supply_type_known');
        DB::unprepared('ALTER TABLE sales_order_lines DROP CONSTRAINT IF EXISTS sales_order_lines_supply_type_known');

        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->dropColumn('supply_type');
        });

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropColumn('supply_type');
        });
    }
};
