<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self-billing agreements, and the document type they unlock - PF-100.
 *
 * @financial
 *
 * ## Why the agreement is a row and not a checkbox
 *
 * Self-billing is the buyer issuing the supplier's invoice on their behalf.
 * Every regime that allows it requires a prior agreement between the two
 * parties, and most require that it be produced on request - so it has a
 * reference, a date it was signed, and a validity period, because an
 * agreement that lapsed is not an agreement.
 *
 * A checkbox on the party would record that somebody once said yes. What an
 * auditor asks for is the agreement that was in force on the date of the
 * document, which is a different question and needs a date range to answer.
 *
 * ## Enforced in the database, not only in the service
 *
 * The trigger refuses a `389` whose supplier has no agreement covering its
 * tax point. A second issuing path - an import, an API, a correction script -
 * would otherwise be able to issue a document the regime does not recognise,
 * and the party it names would be the one asked to explain it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_self_billing_agreements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();

            // The reference the parties gave it, which is what goes on the
            // document and what an auditor asks for by name.
            $table->string('reference', 64);
            $table->date('signed_on');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'party_id', 'reference']);
        });

        DB::unprepared(<<<'SQL'
            /*
             | A half-open range, the same shape as every other validity in
             | this schema - PF-073. An agreement that lapsed is not an
             | agreement, and an open upper bound means "still in force".
             */
            ALTER TABLE party_self_billing_agreements
                ADD COLUMN validity daterange NOT NULL DEFAULT daterange(CURRENT_DATE, NULL, '[)');

            ALTER TABLE party_self_billing_agreements
                ADD CONSTRAINT party_self_billing_agreements_validity_is_half_open
                    CHECK (lower_inc(validity) AND NOT upper_inc(validity)),

                ADD CONSTRAINT party_self_billing_agreements_signed_before_it_starts
                    CHECK (signed_on <= lower(validity)),

                ADD CONSTRAINT party_self_billing_agreements_reference_is_not_blank
                    CHECK (length(btrim(reference)) > 0);

            /*
             | No two agreements with the same party may overlap.
             |
             | Two agreements in force at once is two answers to "which
             | agreement is this document under", and the document carries
             | one reference.
             */
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            ALTER TABLE party_self_billing_agreements
                ADD CONSTRAINT party_self_billing_agreements_do_not_overlap
                    EXCLUDE USING gist (entity_id WITH =, party_id WITH =, validity WITH &&);

            SELECT peakflow_apply_entity_rls('party_self_billing_agreements');
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_check_self_billed_document() RETURNS trigger AS $$
            DECLARE
                agreement party_self_billing_agreements;
            BEGIN
                SELECT * INTO agreement
                FROM party_self_billing_agreements
                WHERE entity_id = NEW.entity_id
                  AND party_id = NEW.party_id
                  AND validity @> NEW.tax_point
                LIMIT 1;

                IF agreement IS NULL THEN
                    RAISE EXCEPTION
                        'no self-billing agreement with this party covers %: the buyer issuing the '
                        'supplier''s invoice needs a prior agreement between them, and an agreement '
                        'that lapsed is not an agreement',
                        NEW.tax_point
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER self_billed_documents_need_an_agreement
                BEFORE UPDATE ON invoices
                FOR EACH ROW
                WHEN (
                    NEW.status = 'issued'
                    AND OLD.status = 'draft'
                    AND NEW.document_type = '389'
                )
                EXECUTE FUNCTION peakflow_check_self_billed_document();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS self_billed_documents_need_an_agreement ON invoices;
            DROP FUNCTION IF EXISTS peakflow_check_self_billed_document();
        SQL);

        Schema::dropIfExists('party_self_billing_agreements');
    }
};
