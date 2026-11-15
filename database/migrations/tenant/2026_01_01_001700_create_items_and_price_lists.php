<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billable items and the prices they resolve to - PF-064, PF-065.
 *
 * @financial
 *
 * ## Items are shared; their default ACCOUNTS are not
 *
 * The same item is sold by every company in the group. Which revenue account
 * it lands in is a property of each company's chart, and the charts differ -
 * a subsidiary in another jurisdiction has different statutory codes.
 *
 * So `items` carries no entity_id and `item_entity_defaults` does, the same
 * split as parties and their per-entity settings.
 *
 * ## The item type is not a category
 *
 * `good`, `service`, `digital_service`. The distinction drives **place of
 * supply**, which decides which country's VAT applies and at what rate - so
 * it is a first-class field with a CHECK on it, not a tag somebody can
 * rename. A digital service sold to a consumer in another member state is
 * taxed where the consumer is; the same sale of a physical good is not.
 *
 * Getting it wrong does not produce an error. It produces a return filed in
 * the wrong country.
 *
 * ## Classification is a CODE AND ITS SCHEME, always both
 *
 * EN 16931 requires the scheme identifier beside the code (BT-158-1 beside
 * BT-158). `43232408` means one thing in UNSPSC and something else in CPV,
 * and a bare code on an invoice is unroutable - a receiving system cannot
 * resolve it and rejects the document.
 *
 * ## Prices resolve by the DOCUMENT's date, not today
 *
 * A credit note issued in April against a March invoice must use March's
 * price. Effective dating is therefore on the list, and every lookup takes a
 * date. A resolver that reads "the current price list" reprices history
 * every time somebody edits a list, and the first sign of it is a credit
 * note that does not match the invoice it credits.
 *
 * ## Unit prices keep eight decimals; extended amounts keep four
 *
 * A price of 0.0725 per unit is ordinary in utilities, telecoms and fuel, and
 * rounding it to the currency's own exponent before multiplying by 10,000
 * units is a 250-unit error. So `unit_price` is at the UnitPrice scale and
 * the rounding to the currency's exponent happens once, on the line total.
 *
 * @online-safe Creates new tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // No entity_id: see the class note. Shared across the tenant.
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            /*
             | Drives place of supply, so it is checked rather than free.
             | See the class note.
             */
            $table->string('type', 16);

            // UN/CEFACT Rec 20. Validated against App\Support\Iso\UnitRegistry.
            $table->string('unit_code', 8)->default('C62');

            /*
             | Revenue recognition default - IFRS 15, feeding PF-107.
             |
             | A default on the ITEM rather than a decision at invoice time,
             | because whether a thing is earned on delivery or over a period
             | is a property of the thing. A twelve-month licence recognised
             | immediately overstates this year and understates next, and the
             | restatement is a prior-period adjustment.
             */
            $table->string('recognition', 16)->default('immediate');
            $table->smallInteger('recognition_months')->nullable();

            $table->boolean('sellable')->default(true);
            $table->boolean('purchasable')->default(false);
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code']);
            $table->index(['type', 'active']);
            $table->index(['name_en']);
        });

        /*
         | What an item means to ONE entity: which accounts, which tax code,
         | which dimensions. Per entity because the chart differs.
         */
        Schema::create('item_entity_defaults', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->foreignUlid('item_id')->constrained()->cascadeOnDelete();

            $table->ulid('revenue_account_id')->nullable();
            $table->ulid('expense_account_id')->nullable();
            $table->ulid('tax_code_id')->nullable();

            // The eight dimension axes, matching journal_lines. Null means
            // "the posting decides", which is the common case.
            for ($axis = 1; $axis <= 8; $axis++) {
                $table->ulid('dimension_'.$axis)->nullable();
            }

            $table->timestamps();

            $table->unique(['entity_id', 'item_id']);
        });

        /*
         | Classification codes, as coded PAIRS.
         |
         | The scheme is not optional and not derivable: 43232408 means one
         | thing in UNSPSC and another in CPV, and a bare code on an invoice
         | cannot be resolved by the receiving system.
         */
        Schema::create('item_classifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('item_id')->constrained()->cascadeOnDelete();

            $table->string('scheme', 16);
            $table->string('code', 64);

            // The scheme's version, where it has one. UNSPSC is revised and
            // a code can be retired; an invoice issued under version 23 is a
            // statement about version 23.
            $table->string('scheme_version', 16)->nullable();

            $table->timestamps();

            $table->unique(['item_id', 'scheme', 'code']);
            $table->index(['scheme', 'code']);
        });

        /*
         | Price lists - PF-065.
         |
         | Four dimensions of specificity, resolved in a declared order:
         | a list for one party beats a list for their group, which beats a
         | general list. The order is data (`priority`) plus a rule, and the
         | resolver reports which list won - because "why is this price
         | 94.50" is asked every week and guessing is not an answer.
         */
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('code', 32);
            $table->string('name_ar');
            $table->string('name_en');

            $table->char('currency', 3);

            // Null on both means a general list. A party list beats a group
            // list beats a general one, regardless of priority.
            $table->ulid('party_id')->nullable();
            $table->string('party_group', 32)->nullable();

            /*
             | Effective dating, with an open end.
             |
             | `valid_to` null means "still in force", which is the normal
             | state of a current price list. A sentinel far-future date
             | would work and would then appear on screens as 9999-12-31.
             */
            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            // Tie-break between lists of equal specificity. Higher wins.
            $table->smallInteger('priority')->default(0);

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['code']);
            $table->index(['currency', 'active']);
            $table->index(['party_id']);
            $table->index(['party_group']);
        });

        Schema::create('price_list_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained()->cascadeOnDelete();

            /*
             | Quantity tiers.
             |
             | `min_quantity` is the floor at which this price applies, and
             | the resolver takes the highest floor at or below the quantity
             | ordered. Tiers as separate rows rather than a break table
             | because a tier is a price with a condition, and the condition
             | is one number.
             */
            $table->decimal('min_quantity', 21, 8)->default('0');

            /*
             | The UnitPrice scale - 8 decimals. A price of 0.0725 per unit
             | is ordinary in utilities, telecoms and fuel.
             |
             | Nullable, because a line is EITHER a price or a discount off
             | the next less specific list. "10% off list" is a real
             | commercial agreement and cannot be stored as a number: the
             | number changes whenever the underlying list does, and a copy
             | that has to be kept in step will not be.
             */
            $table->decimal('unit_price', 21, 8)->nullable();
            $table->decimal('discount_percent', 21, 12)->nullable();

            $table->timestamps();

            $table->unique(['price_list_id', 'item_id', 'min_quantity'], 'price_list_lines_one_per_tier');
            $table->index(['item_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE items
                -- Drives place of supply. A category somebody can rename
                -- would quietly change which country's VAT applies.
                ADD CONSTRAINT items_type_known
                    CHECK (type IN ('good', 'service', 'digital_service')),

                ADD CONSTRAINT items_recognition_known
                    CHECK (recognition IN ('immediate', 'over_period', 'on_milestone')),

                -- A period recognition with no period is a schedule nobody
                -- can compute, and it fails at the moment of invoicing.
                ADD CONSTRAINT items_recognition_period_is_complete
                    CHECK (recognition <> 'over_period'
                           OR (recognition_months IS NOT NULL AND recognition_months >= 1)),

                -- An item that can be neither sold nor purchased cannot
                -- appear on any document, so it is configuration nobody can
                -- use and everybody can see in a list.
                ADD CONSTRAINT items_is_usable
                    CHECK (sellable OR purchasable);

            ALTER TABLE item_classifications
                ADD CONSTRAINT item_classifications_scheme_known
                    CHECK (scheme IN ('UNSPSC', 'GPC', 'CPV', 'eCl@ss', 'HS', 'TARIC', 'SITC', 'local'));

            ALTER TABLE price_lists
                -- A list whose window is inverted matches nothing, which
                -- presents as "the price is missing" rather than as a bad
                -- date, and sends somebody looking in the wrong place.
                ADD CONSTRAINT price_lists_window_is_ordered
                    CHECK (valid_to IS NULL OR valid_to >= valid_from),

                -- Both set is a list that is specific to a party AND to a
                -- group, which has no resolution order.
                ADD CONSTRAINT price_lists_scope_is_one_thing
                    CHECK (party_id IS NULL OR party_group IS NULL);

            ALTER TABLE price_list_lines
                ADD CONSTRAINT price_list_lines_quantity_is_not_negative
                    CHECK (min_quantity >= 0),

                -- A negative unit price is a credit disguised as a sale, and
                -- it is representable as a credit note instead.
                ADD CONSTRAINT price_list_lines_price_is_not_negative
                    CHECK (unit_price >= 0),

                ADD CONSTRAINT price_list_lines_discount_is_a_discount
                    CHECK (discount_percent IS NULL
                           OR (discount_percent > 0 AND discount_percent <= 100)),

                -- Exactly one answer per line. A price AND a discount is two
                -- answers to one question, and whichever the resolver reads
                -- first becomes the rule nobody wrote down.
                ADD CONSTRAINT price_list_lines_is_a_price_or_a_discount
                    CHECK ((unit_price IS NOT NULL AND discount_percent IS NULL)
                           OR (unit_price IS NULL AND discount_percent IS NOT NULL));
        SQL);

        $this->installOverlapGuard();

        DB::unprepared("SELECT peakflow_apply_entity_rls('item_entity_defaults');");

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE items IS
                'peakflow:tenant-scoped - the same item is sold by every company in the group; which revenue '
                'account it lands in is a property of each chart and lives in item_entity_defaults.';

            COMMENT ON TABLE price_lists IS
                'peakflow:tenant-scoped - a price list is a commercial agreement with a customer, not a '
                'property of one of your companies.';

            COMMENT ON COLUMN items.type IS
                'good | service | digital_service. Drives PLACE OF SUPPLY, which decides which country''s VAT '
                'applies - not a category. Getting it wrong files a return in the wrong country.';
        SQL);
    }

    /**
     * Two lists may not make one item's price ambiguous - PF-065.
     *
     * ## What the rule actually is
     *
     * The requirement is that the resolution order is explicit and
     * inspectable. Two lists of identical specificity AND priority covering
     * the same day make it neither: the winner depends on row order, so the
     * same invoice priced twice can come out differently, and the screen
     * that explains the price would have to say "one of these two".
     *
     * ## Why this is not an EXCLUDE constraint on `price_lists`
     *
     * That was the first attempt and it was wrong in a way worth recording:
     * it forbade two general lists in one currency at one priority
     * regardless of what was ON them - so a utility tariff list could not
     * coexist with a general goods list, which is an ordinary setup. It
     * failed on the first probe that tried it.
     *
     * The ambiguity is per ITEM, and the items are in the child table, so it
     * cannot be expressed as an exclusion on the parent at all. It is a
     * trigger on the LINE, where the item is known.
     *
     * Priority is part of the comparison, so the legitimate case - a
     * replacement list at a higher priority overlapping the one it
     * supersedes - stays allowed.
     */
    private function installOverlapGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_price_is_unambiguous()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                mine     record;
                clashing record;
            BEGIN
                SELECT currency, party_id, party_group, priority, valid_from, valid_to, code, active
                INTO mine
                FROM price_lists
                WHERE id = NEW.price_list_id;

                IF NOT mine.active THEN
                    -- An inactive list prices nothing, so it cannot clash.
                    RETURN NEW;
                END IF;

                SELECT pl.code, pl.valid_from, pl.valid_to
                INTO clashing
                FROM price_list_lines pll
                JOIN price_lists pl ON pl.id = pll.price_list_id
                WHERE pll.item_id = NEW.item_id
                  AND pll.price_list_id <> NEW.price_list_id
                  AND pl.active
                  AND pl.currency = mine.currency
                  AND pl.priority = mine.priority
                  AND coalesce(pl.party_id::text, '-') = coalesce(mine.party_id::text, '-')
                  AND coalesce(pl.party_group, '-') = coalesce(mine.party_group, '-')
                  AND daterange(pl.valid_from, pl.valid_to, '[]')
                      && daterange(mine.valid_from, mine.valid_to, '[]')
                LIMIT 1;

                IF clashing.code IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Lists "%" and "%" both price this item in %, for the same customers, at priority %, over overlapping dates. Which one wins would depend on row order, so the same invoice priced twice could come out differently and no screen could explain either answer. Give one of them a different priority, or narrow its dates.',
                        mine.code, clashing.code, mine.currency, mine.priority
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER price_list_lines_are_unambiguous
                BEFORE INSERT OR UPDATE ON price_list_lines
                FOR EACH ROW
                EXECUTE FUNCTION assert_price_is_unambiguous();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS price_list_lines_are_unambiguous ON price_list_lines;
            DROP FUNCTION IF EXISTS assert_price_is_unambiguous();
        SQL);

        Schema::dropIfExists('price_list_lines');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('item_classifications');
        Schema::dropIfExists('item_entity_defaults');
        Schema::dropIfExists('items');
    }
};
