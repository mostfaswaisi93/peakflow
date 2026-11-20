<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two facts the place-of-supply engine needs and could not read - PF-078.
 *
 * ## What was wrong, and it is not a missing column
 *
 * `PlaceOfSupply::decide()` - the eight-point rule engine of PF-078, with
 * sixteen asserted cases, a vendored bloc register and a dated UK departure
 * - **was never called by the application**. Seven places in `Sales` write
 * `'place_of_supply_rule' => 'domestic'` as a literal, and
 * `InvoiceApiController` hands `['rule' => 'domestic']` to the issuer. So
 * every document ever issued records a domestic supply, whatever the
 * customer's country, and the engine exists only in its own test suite.
 *
 * An export of goods therefore reads as a domestic sale on the document,
 * and PF-078's open criterion - "the decision and its reasoning are visible
 * on the invoice's tax panel, because an accountant must be able to check
 * it" - could not be met by adding a panel: the panel would display a
 * decision nobody made.
 *
 * ## Why the engine could not be called
 *
 * It needs five facts and the document carried three. The supplier's
 * country is on the entity, the customer's is on the party, and their
 * registration status is derivable from `party_tax_registrations`. The
 * other two were nowhere:
 *
 * - **What kind of supply the line is.** `items.type` has it - `good`,
 *   `service`, `digital_service`, with a comment saying it "drives place of
 *   supply" - but a line may have no item at all: a free-text description
 *   is a legitimate invoice line and carries no type. So the line gets its
 *   own column, which the item fills in when there is one.
 * - **Where the goods went.** The highest-precedence rule in the book -
 *   `goods-delivered-in-the-supplier-state` - tests the delivery location
 *   against the supplier's country, because a French customer collecting
 *   goods from a Saudi warehouse is a Saudi supply. With no delivery
 *   location the engine raises `NeedsTheDeliveryLocation` rather than
 *   falling through, which is PF-078's "refuses rather than guesses".
 *
 * ## Both nullable, and that is the decision
 *
 * A `NOT NULL` with a default would be the defect again in a new place: the
 * default would decide the place of supply for every existing document and
 * every line somebody leaves blank. Null means **not stated**, the decider
 * records the supply as undecided with the reason, and the panel says so in
 * words. An accountant reading "the place of supply was not decided: line 1
 * states no supply type" is better served than one reading a confident
 * `domestic`.
 *
 * @financial-not-applicable: adds two nullable columns. No existing value
 * is rewritten, no figure is derived from them yet, and no financial row
 * is touched.
 * @online-safe Two nullable column additions with no default, which
 * PostgreSQL does as a catalogue change. No table is rewritten and no
 * backfill runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->string('supply_type', 16)->nullable()->after('item_code');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->char('delivery_country', 2)->nullable()->after('tax_point');
        });

        DB::unprepared(<<<'SQL'
            /*
             | The same three words as `items_type_known`, deliberately.
             |
             | A line's type and an item's type are the same fact about the
             | same supply, and two vocabularies for it would mean a
             | mapping at the point where the item fills the line in - and
             | a mapping is a place for a supply to acquire the wrong type
             | on the way through.
             */
            /*
             | NOT VALID, then validated - the inspector's own advice.
             |
             | A plain ADD CONSTRAINT scans every existing invoice line
             | under ACCESS EXCLUSIVE, so reads and writes both wait for a
             | full scan of the largest table in the product. NOT VALID
             | takes the lock only to write the catalogue row, and the
             | separate VALIDATE takes SHARE UPDATE EXCLUSIVE, which
             | blocks neither.
             |
             | Existing rows satisfy it trivially: the column was added in
             | this same migration and every row has NULL in it. The
             | validation is therefore a formality, and running it anyway
             | is what stops the constraint being marked `NOT VALID` for
             | ever - an unvalidated constraint is not enforced for rows
             | that were already there, and nobody notices which rows
             | those are.
             */
            ALTER TABLE invoice_lines
                ADD CONSTRAINT invoice_lines_supply_type_known
                CHECK (supply_type IS NULL
                       OR supply_type IN ('good', 'service', 'digital_service'))
                NOT VALID;

            ALTER TABLE invoice_lines VALIDATE CONSTRAINT invoice_lines_supply_type_known;

            COMMENT ON COLUMN invoice_lines.supply_type IS
                'What kind of supply this line is - PF-078. Filled from items.type where the line has an item; stated directly on a free-text line. NULL means not stated, and the place of supply is then recorded as undecided with that reason rather than defaulted to domestic.';

            COMMENT ON COLUMN invoices.delivery_country IS
                'Where the goods went - PF-078, ISO 3166-1 alpha-2. The highest-precedence rule in the book tests this against the supplier country, because a customer collecting goods from the supplier state is a supply in that state. NULL means not stated: the engine refuses rather than falling through to a lower-precedence rule that would answer a question a higher one had a claim on.';
        SQL);
    }

    /**
     * @unsafe-migration-reviewed: the two drops are a rollback of columns
     * this same migration added, and a rollback runs where the deploy that
     * added them is being withdrawn - so the code that selects them is the
     * code being withdrawn with it. Dropping a column somebody else's
     * running release still reads is the hazard the inspector names, and it
     * cannot arise from undoing an addition in the same release.
     */
    public function down(): void
    {
        DB::unprepared('ALTER TABLE invoice_lines DROP CONSTRAINT IF EXISTS invoice_lines_supply_type_known');

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropColumn('supply_type');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('delivery_country');
        });
    }
};
