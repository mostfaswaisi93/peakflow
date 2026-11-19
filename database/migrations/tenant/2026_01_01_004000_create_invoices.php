<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices, and the snapshot an issued one carries - PF-091.
 *
 * @financial
 *
 * ## An issued invoice is a historical statement, not a view
 *
 * It carries copies rather than foreign keys to everything that can change:
 * both parties' legal names, addresses and tax registrations; the entity's own
 * legal identity; every applied rate; the FX rate; the payment terms and the
 * due dates derived from them.
 *
 * The alternative - joining to `parties` at render time - produces an invoice
 * that silently rewrites itself. A customer renames their company in March and
 * every invoice they have ever received now shows the new name, including the
 * ones filed with an authority under the old one. The same joins make a rate
 * change retroactive: reprint a 2019 invoice after a VAT increase and it shows
 * the new rate against the old total.
 *
 * Reprinting an invoice is not an unusual act. It is what happens when a
 * customer disputes one, when an authority asks for one, and when a collection
 * agency needs one - which is to say it happens for the documents that matter
 * most.
 *
 * ## There is no `cancelled` status
 *
 * An issued invoice is immutable, by trigger, in every column. A document that
 * should not have been issued is credited (PF-097) or corrected (PF-098) with
 * another document that references it - which is what every regime requires
 * and what leaves a trail. A status column somebody can set to `cancelled` is
 * a document that disappears from a return it was already in.
 *
 * ## Five tables, because EN 16931 has five groups
 *
 *   invoices                      BG-2 through BG-8, and the totals in BG-22
 *   invoice_lines                 BG-25
 *   invoice_tax_breakdown         BG-23, frozen as computed
 *   invoice_allowances_charges    BG-20 and BG-21, document and line level
 *   invoice_references            BG-3, and the credit/correction links
 *
 * The breakdown is stored rather than recomputed for the same reason as the
 * snapshot: PF-082 computes it from rates that change.
  *
 * @online-safe Creates `invoice_allowances_charges`, `invoice_lines`, `invoice_references`, `invoice_tax_breakdown` and others, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    /** UNCL 1001 document type codes this system issues. */
    private const TYPES = ['380', '381', '383', '384', '386', '389', '326'];

    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | The number is NULL while the document is a draft.
             |
             | Not "0" and not a placeholder: a draft has no number, and a
             | column that holds a fake one is a column somebody will index,
             | display, or allocate from. PF-093 fills it last, inside the
             | issuing transaction.
             */
            $table->string('number', 64)->nullable();
            $table->string('series_key', 32);

            // UNCL 1001, stored as the code. The name is presentation.
            $table->char('document_type', 3)->default('380');

            $table->string('status', 16)->default('draft');

            $table->foreignUlid('party_id')->constrained()->restrictOnDelete();

            $table->date('issue_date');

            /*
             | The tax point, and the return period it falls into - PF-077,
             | PF-085.
             |
             | Stored, not derived. A document issued on the 1st for a supply
             | on the 30th belongs to the earlier return in many
             | jurisdictions, and that decision must be frozen: re-deriving it
             | later reads a configuration that may have changed.
             */
            $table->date('tax_point')->nullable();
            $table->string('return_period_label', 16)->nullable();

            $table->char('currency', 3);

            /*
             | The FX rate as applied, with its direction and source.
             |
             | PF-068's lesson: a functional amount beside a rate that does
             | not produce it is what a wrong rate looks like in the books, so
             | the rate travels with the document rather than being looked up
             | when somebody reprints it.
             */
            $table->decimal('fx_rate', 21, 12)->nullable();
            $table->string('fx_source', 64)->nullable();
            $table->date('fx_rate_date')->nullable();

            // The totals, at the storage scale - EN 16931 BG-22.
            $table->decimal('net_amount', 21, 4)->default('0');
            $table->decimal('tax_amount', 21, 4)->default('0');
            $table->decimal('gross_amount', 21, 4)->default('0');
            $table->decimal('prepaid_amount', 21, 4)->default('0');
            $table->decimal('payable_amount', 21, 4)->default('0');

            /*
             | What the document DISPLAYS, recorded separately.
             |
             | The stored amounts are four decimal places whatever the
             | currency; the exponent is what a reader sees. Recorded on the
             | document because ISO 4217 can change one - it has - and a
             | reprint has to show what was shown.
             */
            $table->smallInteger('presentation_exponent');

            /*
             | The frozen snapshots - the whole point of this table.
             |
             | jsonb rather than a hundred columns: the shape is EN 16931's and
             | changes with the standard, nothing queries inside it except a
             | reprint, and a column per field would make "freeze everything
             | about the supplier" a migration every time the standard adds a
             | term.
             */
            $table->jsonb('supplier_snapshot')->nullable();
            $table->jsonb('customer_snapshot')->nullable();
            $table->jsonb('terms_snapshot')->nullable();
            $table->jsonb('tax_decision_snapshot')->nullable();

            $table->timestampTz('issued_at')->nullable();
            $table->char('issued_by', 26)->nullable();

            /*
             | The canonical form the hash was taken over - PF-096.
             |
             | Stored, not just hashed. A hash tells you that something
             | changed; the canonical form tells you WHAT, which is what the
             | criterion asks for - the divergence report has to name the
             | field, and a verifier holding only a hash can say nothing
             | beyond "this document is not what it was".
             |
             | The same decision as the return filing in PF-086, for the same
             | reason: six years later, the bytes are the evidence.
             */
            $table->text('canonical')->nullable();

            // PF-096 fills these inside the same transaction.
            $table->char('canonical_hash', 64)->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->integer('chain_index')->nullable();

            $table->string('profile', 32)->nullable();
            $table->text('note')->nullable();

            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'series_key', 'number']);
            $table->unique(['entity_id', 'series_key', 'chain_index']);
            $table->index(['entity_id', 'status']);
            $table->index(['entity_id', 'party_id']);
            $table->index(['entity_id', 'tax_point']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();

            $table->integer('line_no');

            /*
             | The item reference AND the line's own description - PF-091.
             |
             | Both, because changing an item's name must not rewrite history.
             | The reference is what a report groups by; the description is
             | what the customer read.
             */
            $table->foreignUlid('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_code', 64)->nullable();
            $table->text('description');

            // UN/CEFACT Rec 20, copied from the item at issue.
            $table->string('unit_code', 8)->default('C62');

            $table->decimal('quantity', 21, 8);
            $table->decimal('unit_price', 21, 8);

            // After line-level allowances and charges - EN 16931 BT-131.
            $table->decimal('net_amount', 21, 4);

            /*
             | Where the credit goes - resolved at draft time, frozen here.
             |
             | The item's per-entity default is the usual source, and a line
             | with no item has no default to read: a free-text line still has
             | to post somewhere, and leaving the posting rule to guess is how
             | revenue ends up in a suspense account.
             */
            $table->foreignUlid('revenue_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->foreignUlid('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            /*
             | `varchar`, not `char`.
             |
             | A UNCL 5305 code is one or two characters - `S`, `AE`, `K` -
             | and `char(3)` blank-pads it to `'S  '`, which then fails to
             | cast back to the enum. Measured: the first invoice issued
             | through the real path died on "S  " is not a valid backing
             | value. The fixed-width column looked tidier and was wrong.
             */
            $table->string('tax_category', 3);
            $table->decimal('tax_rate', 9, 6);
            $table->date('tax_point')->nullable();

            // Where the supply was placed, frozen - PF-078.
            $table->char('place_of_supply', 2)->nullable();
            $table->string('place_of_supply_rule', 64)->nullable();

            $table->string('recognition', 16)->nullable();
            $table->date('service_from')->nullable();
            $table->date('service_until')->nullable();

            $table->char('dimension_1', 26)->nullable();
            $table->char('dimension_2', 26)->nullable();
            $table->char('dimension_3', 26)->nullable();
            $table->char('dimension_4', 26)->nullable();

            $table->timestamps();

            $table->unique(['invoice_id', 'line_no']);
            $table->index(['entity_id', 'item_id']);
        });

        Schema::create('invoice_tax_breakdown', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();

            $table->string('category', 3);
            $table->decimal('rate', 9, 6);

            $table->decimal('taxable_amount', 21, 4);
            $table->decimal('tax_amount', 21, 4);

            // EN 16931 BT-121 and BT-120.
            $table->string('exemption_reason_code', 40)->nullable();
            $table->string('exemption_reason_text', 300)->nullable();

            // Which box of which return this group lands in - PF-085.
            $table->string('report_box', 64)->nullable();

            $table->timestamps();

            $table->unique(['invoice_id', 'category', 'rate']);
        });

        Schema::create('invoice_allowances_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();

            // NULL means document level - EN 16931 BG-20/BG-21 rather than
            // the line-level BT-136/BT-141.
            $table->foreignUlid('invoice_line_id')->nullable()->constrained('invoice_lines')->cascadeOnDelete();

            $table->boolean('is_charge');
            $table->decimal('amount', 21, 4);
            $table->decimal('percentage', 9, 6)->nullable();

            $table->string('reason', 300);
            $table->string('reason_code', 40)->nullable();

            // An allowance carries its own tax treatment where it is applied
            // before tax - EN 16931 BT-95/BT-102.
            $table->string('tax_category', 3)->nullable();
            $table->decimal('tax_rate', 9, 6)->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'invoice_id']);
        });

        Schema::create('invoice_references', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();

            /*
             | What kind of reference, because they are not interchangeable.
             |
             | `credits` and `corrects` carry legal weight - EN 16931 BT-25 -
             | and a credit note without one is not a credit note. `order`,
             | `contract` and `despatch` are commercial and optional.
             */
            $table->string('kind', 24);

            $table->foreignUlid('referenced_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->string('reference', 128)->nullable();
            $table->date('reference_date')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'invoice_id']);
            $table->index(['entity_id', 'referenced_invoice_id']);
        });

        $types = "'".implode("', '", self::TYPES)."'";

        DB::unprepared(<<<SQL
            ALTER TABLE invoices
                ADD CONSTRAINT invoices_status_is_known
                    CHECK (status IN ('draft', 'issued')),

                ADD CONSTRAINT invoices_type_is_uncl_1001
                    CHECK (document_type IN ({$types})),

                /*
                 | A number exists exactly when the document is issued.
                 |
                 | Both halves. A draft with a number has consumed one from a
                 | gapless series and may never be issued; an issued document
                 | without one is a legal document with no identity.
                 */
                ADD CONSTRAINT invoices_number_pairs_with_issue
                    CHECK ((status = 'issued') = (number IS NOT NULL)),

                ADD CONSTRAINT invoices_issued_is_attributable
                    CHECK ((status = 'issued') = (issued_at IS NOT NULL)),

                /*
                 | An issued document carries its snapshots. All of them.
                 |
                 | A draft has none, because there is nothing to freeze until
                 | the moment of issue - freezing earlier means freezing a
                 | party's details as they were when somebody started typing.
                 */
                ADD CONSTRAINT invoices_issued_carries_its_snapshot
                    CHECK (status <> 'issued' OR (
                        supplier_snapshot IS NOT NULL
                        AND customer_snapshot IS NOT NULL
                        AND tax_decision_snapshot IS NOT NULL
                        AND tax_point IS NOT NULL
                    )),

                -- The totals tie to each other, in the database.
                ADD CONSTRAINT invoices_gross_is_net_plus_tax
                    CHECK (gross_amount = net_amount + tax_amount),

                ADD CONSTRAINT invoices_payable_is_gross_less_prepaid
                    CHECK (payable_amount = gross_amount - prepaid_amount),

                ADD CONSTRAINT invoices_prepaid_is_not_negative
                    CHECK (prepaid_amount >= 0),

                ADD CONSTRAINT invoices_exponent_is_plausible
                    CHECK (presentation_exponent BETWEEN 0 AND 4),

                ADD CONSTRAINT invoices_hash_is_a_digest
                    CHECK (canonical_hash IS NULL OR canonical_hash ~ '^[0-9a-f]{64}\$'),

                ADD CONSTRAINT invoices_chain_is_complete
                    CHECK ((canonical_hash IS NULL) = (chain_index IS NULL)
                        AND (canonical_hash IS NULL) = (canonical IS NULL)),

                /*
                 | An FX rate comes with its direction and its source, or not
                 | at all - PF-067.
                 |
                 | A rate with no source is a rate nobody can re-derive, which
                 | is the same as not having one when a translation is
                 | questioned.
                 */
                ADD CONSTRAINT invoices_fx_is_complete
                    CHECK ((fx_rate IS NULL) = (fx_source IS NULL)
                        AND (fx_rate IS NULL) = (fx_rate_date IS NULL)),

                ADD CONSTRAINT invoices_fx_rate_is_positive
                    CHECK (fx_rate IS NULL OR fx_rate > 0);

            ALTER TABLE invoice_lines
                ADD CONSTRAINT invoice_lines_line_no_is_positive
                    CHECK (line_no > 0),

                ADD CONSTRAINT invoice_lines_description_is_present
                    CHECK (length(btrim(description)) > 0),

                ADD CONSTRAINT invoice_lines_quantity_is_not_zero
                    CHECK (quantity <> 0),

                ADD CONSTRAINT invoice_lines_rate_is_a_percentage
                    CHECK (tax_rate >= 0 AND tax_rate <= 100),

                ADD CONSTRAINT invoice_lines_category_is_uncl_5305
                    CHECK (tax_category IN ('S', 'Z', 'E', 'AE', 'O', 'K', 'G', 'L', 'M')),

                /*
                 | A zero category carries no rate, the same rule as the tax
                 | codes - PF-075.
                 |
                 | A Z line at 5% is not a zero rate, and an E line at 5% is a
                 | taxable supply wearing an exempt label. Both produce a
                 | return whose turnover boxes are wrong.
                 */
                ADD CONSTRAINT invoice_lines_zero_categories_carry_zero
                    CHECK (tax_category IN ('S', 'L', 'M') OR tax_rate = 0),

                ADD CONSTRAINT invoice_lines_service_period_is_ordered
                    CHECK (service_from IS NULL OR service_until IS NULL
                        OR service_until >= service_from);

            ALTER TABLE invoice_tax_breakdown
                ADD CONSTRAINT invoice_tax_breakdown_category_is_uncl_5305
                    CHECK (category IN ('S', 'Z', 'E', 'AE', 'O', 'K', 'G', 'L', 'M')),

                ADD CONSTRAINT invoice_tax_breakdown_rate_is_a_percentage
                    CHECK (rate >= 0 AND rate <= 100),

                -- A category that requires a reason carries one - BT-121.
                ADD CONSTRAINT invoice_tax_breakdown_reason_where_required
                    CHECK (category = 'S' OR exemption_reason_code IS NOT NULL);

            ALTER TABLE invoice_allowances_charges
                ADD CONSTRAINT invoice_allowances_charges_amount_is_positive
                    CHECK (amount > 0),

                ADD CONSTRAINT invoice_allowances_charges_reason_is_present
                    CHECK (length(btrim(reason)) > 0),

                ADD CONSTRAINT invoice_allowances_charges_percentage_is_plausible
                    CHECK (percentage IS NULL OR (percentage > 0 AND percentage <= 100));

            ALTER TABLE invoice_references
                ADD CONSTRAINT invoice_references_kind_is_known
                    CHECK (kind IN ('credits', 'corrects', 'prepayment', 'order',
                        'contract', 'despatch', 'receipt', 'project')),

                /*
                 | A legal reference points at a document in this system; a
                 | commercial one may be free text.
                 |
                 | A credit note whose reference is the string "the invoice
                 | from March" is a credit note nobody can match.
                 */
                ADD CONSTRAINT invoice_references_legal_kinds_point_at_a_document
                    CHECK (kind NOT IN ('credits', 'corrects', 'prepayment')
                        OR referenced_invoice_id IS NOT NULL),

                ADD CONSTRAINT invoice_references_commercial_kinds_say_something
                    CHECK (referenced_invoice_id IS NOT NULL
                        OR length(btrim(coalesce(reference, ''))) > 0);
        SQL);

        DB::unprepared(<<<'SQL'
            /*
             | An issued invoice is immutable. Every column.
             |
             | Not "the totals are immutable": an issued invoice whose note or
             | customer snapshot can be edited is an invoice that no longer
             | matches the one the customer holds, the one in the return, and
             | the one the hash was taken over. The hash would catch a change
             | to a legally significant field and say nothing about the rest.
             |
             | In the database rather than the model, because a correction
             | script, a migration and a psql session all write rows the same
             | way - and this is the row that an authority may ask about six
             | years from now.
             */
            CREATE OR REPLACE FUNCTION refuse_to_change_an_issued_invoice() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'issued' THEN
                        RAISE EXCEPTION
                            'Invoice % cannot be deleted. An issued invoice is a historical statement: '
                            'it is credited or corrected by another document that references it, which '
                            'is what leaves a trail.',
                            OLD.number
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.status = 'issued' THEN
                    RAISE EXCEPTION
                        'Invoice % was issued on % and cannot be changed. The customer holds a copy, a '
                        'return may already include it, and the hash was taken over it. A document that '
                        'should not have been issued is credited or corrected, never edited.',
                        OLD.number, OLD.issued_at
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER invoices_are_immutable_once_issued
                BEFORE UPDATE OR DELETE ON invoices
                FOR EACH ROW
                EXECUTE FUNCTION refuse_to_change_an_issued_invoice();

            /*
             | And so is everything hanging off it.
             |
             | A line, a breakdown row or a reference edited after issue
             | changes the document as surely as editing the header does - and
             | it is the easier mistake to make, because the header is
             | obviously sacred and a line looks like data.
             */
            CREATE OR REPLACE FUNCTION refuse_to_change_an_issued_invoices_parts() RETURNS trigger AS $$
            DECLARE
                invoice_status text;
                invoice_number text;
                target uuid;
            BEGIN
                SELECT status, number INTO invoice_status, invoice_number
                  FROM invoices
                 WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);

                IF invoice_status = 'issued' THEN
                    RAISE EXCEPTION
                        'Invoice % is issued, so its %s cannot be changed. A line edited after issue '
                        'changes the document as surely as editing its header does, and it is the '
                        'easier mistake to make because a line looks like data.',
                        invoice_number, TG_TABLE_NAME
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach (['invoice_lines', 'invoice_tax_breakdown', 'invoice_allowances_charges', 'invoice_references'] as $table) {
            DB::unprepared(sprintf(
                'CREATE TRIGGER %1$s_are_immutable_once_issued
                    BEFORE INSERT OR UPDATE OR DELETE ON %1$s
                    FOR EACH ROW
                    EXECUTE FUNCTION refuse_to_change_an_issued_invoices_parts();',
                $table,
            ));
        }

        foreach ([
            'invoices', 'invoice_lines', 'invoice_tax_breakdown',
            'invoice_allowances_charges', 'invoice_references',
        ] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_to_change_an_issued_invoice() CASCADE;');
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_to_change_an_issued_invoices_parts() CASCADE;');

        Schema::dropIfExists('invoice_references');
        Schema::dropIfExists('invoice_allowances_charges');
        Schema::dropIfExists('invoice_tax_breakdown');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
