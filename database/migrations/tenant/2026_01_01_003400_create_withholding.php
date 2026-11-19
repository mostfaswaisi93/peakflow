<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Withholding tax: the rates, and the certificates - PF-083.
 *
 * @financial
 *
 * ## Withholding is not a tax on the document
 *
 * It is a deduction the PAYER makes from the payment, on behalf of the payee,
 * and remits to the authority itself. The invoice is for 1,000; the payer
 * sends 950 and a certificate for the 50 it withheld; the payee's receivable
 * is settled in full and it claims the 50 as a credit against its own
 * liability.
 *
 * That is why nothing here touches a document total, and why an invoice total
 * that does not equal the cash received is CORRECT when withholding applies.
 * Allocation logic that treats the shortfall as a short payment either leaves
 * the invoice open forever or writes the difference off as bad debt. Both are
 * wrong, and both look like housekeeping.
 *
 * ## The rate's identity is four columns, three of them optional
 *
 * A rate applies to a jurisdiction, and may narrow to a party, to an item
 * type, or to both. "Professional services, any supplier, 5%" and "this
 * supplier, any item, 10%" are both ordinary rows, and a payment that matches
 * both has to resolve to one of them by a rule rather than by whichever the
 * index returned first - see App\Tenant\Tax\Services\Withholding.
 *
 * ## The overlap constraint uses COALESCE, and casts to text
 *
 * NULL is not equal to NULL under `=`, so an exclusion constraint over the
 * nullable columns directly would let two "any party, any item" rows cover the
 * same dates. The COALESCE collapses the optional columns to a sentinel, and
 * the cast to text avoids `char(26)`'s blank padding making the sentinel a
 * different value from itself.
  *
 * @online-safe Creates `withholding_certificates`, `withholding_rates`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->char('jurisdiction', 2);

            // Both optional: a row may apply to every party, every item type,
            // or narrow to either.
            $table->foreignUlid('party_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_type', 32)->nullable();

            $table->decimal('rate', 9, 6);

            // `net` excludes the VAT from the base; `gross` does not. Which
            // one a regime uses is a fact about the regime, and getting it
            // wrong is a 15% error on the withheld amount rather than a
            // rounding difference.
            $table->string('base', 8);

            $table->string('source', 300);
            $table->timestamps();

            $table->index(['entity_id', 'jurisdiction']);
        });

        Schema::create('withholding_certificates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 64);
            $table->foreignUlid('party_id')->constrained()->restrictOnDelete();
            $table->char('jurisdiction', 2);

            $table->date('issued_on');

            // The period the withholding belongs to, as a month: the payee
            // claims the credit in the period the deduction was made, not the
            // period the invoice was raised.
            $table->char('period', 7);

            $table->char('currency', 3);
            $table->decimal('base_amount', 21, 4);
            $table->decimal('withheld_amount', 21, 4);
            $table->decimal('rate', 9, 6);

            /*
             | A free-text reference until Phase 09.
             |
             | The certificate is evidence about a PAYMENT, and payments
             | arrive with settlement. A nullable foreign key to a table that
             | does not exist yet would be a column nobody fills; a reference
             | string is what a payer actually gives you - a transfer
             | reference, a cheque number - and PF-120 adds the key beside it
             | rather than replacing it.
             */
            $table->string('payment_reference', 128)->nullable();

            $table->string('status', 16)->default('issued');
            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'party_id', 'period']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE withholding_rates ADD COLUMN validity daterange NOT NULL;

            ALTER TABLE withholding_rates
                ADD CONSTRAINT withholding_rates_validity_is_not_empty
                    CHECK (NOT isempty(validity)),

                ADD CONSTRAINT withholding_rates_validity_has_a_start
                    CHECK (lower(validity) IS NOT NULL),

                -- A withholding rate is a percentage. 0 is legal: a row that
                -- says "this party, nothing withheld" is how an exemption
                -- certificate is recorded, and it has to beat a broader row.
                ADD CONSTRAINT withholding_rates_rate_is_a_percentage
                    CHECK (rate >= 0 AND rate <= 100),

                ADD CONSTRAINT withholding_rates_base_is_known
                    CHECK (base IN ('net', 'gross')),

                ADD CONSTRAINT withholding_rates_source_is_present
                    CHECK (length(btrim(source)) > 0),

                ADD CONSTRAINT withholding_rates_no_overlap
                    EXCLUDE USING gist (
                        entity_id WITH =,
                        jurisdiction WITH =,
                        (COALESCE(party_id::text, '-')) WITH =,
                        (COALESCE(item_type, '-')) WITH =,
                        validity WITH &&
                    );

            ALTER TABLE withholding_certificates
                ADD CONSTRAINT withholding_certificates_amounts_are_positive
                    CHECK (base_amount > 0 AND withheld_amount >= 0),

                -- Withholding cannot exceed what it is withheld from. A
                -- certificate for more than the payment is either a
                -- misplaced decimal point or a rate stored as 15 where the
                -- regime meant 0.15, and the payee claims a credit it is not
                -- owed.
                ADD CONSTRAINT withholding_certificates_withheld_fits
                    CHECK (withheld_amount <= base_amount),

                ADD CONSTRAINT withholding_certificates_status_is_known
                    CHECK (status IN ('issued', 'void')),

                ADD CONSTRAINT withholding_certificates_period_is_a_month
                    CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$');

            COMMENT ON COLUMN withholding_certificates.payment_reference IS
                'Free text until PF-120 adds the payment key beside it - PF-083.';
        SQL);

        foreach (['withholding_rates', 'withholding_certificates'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_certificates');
        Schema::dropIfExists('withholding_rates');
    }
};
