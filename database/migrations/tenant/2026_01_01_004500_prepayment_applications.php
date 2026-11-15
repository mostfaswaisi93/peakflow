<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A prepayment is applied to exactly one invoice - PF-099.
 *
 * @financial
 *
 * ## Why a primary key rather than a balance
 *
 * Splitting one prepayment across several invoices is a real thing people do,
 * and it is deliberately not modelled: it needs a balance per prepayment,
 * ordering rules for when two invoices compete for the remainder, and a
 * report of what is left - which is an allocation ledger, not a column, and
 * allocation is Phase 09's subject. Returning part of a prepayment is a
 * credit note, which exists.
 *
 * So the rule is one application, and the enforcement is the cheapest correct
 * one there is: a key on the prepayment. Two transactions applying the same
 * prepayment cannot both succeed, and nothing about snapshots has to be true
 * for that to hold.
 *
 * ## Why the trigger now fires on an ordinary invoice too
 *
 * A final invoice is a `380`, and whether it carries a prepayment is a fact
 * about its references rather than its type - which a trigger's `WHEN` clause
 * cannot see. So the condition widens and the function checks: an invoice
 * with no prepayment reference does one indexed lookup and returns, which is
 * the cost of the guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_prepayment_applications', function (Blueprint $table): void {
            $table->ulid('prepayment_invoice_id')->primary();
            $table->char('entity_id', 26);
            $table->ulid('final_invoice_id');
            $table->decimal('gross_applied', 21, 4);

            $table->timestampTz('created_at')->nullable();

            $table->index(['entity_id', 'final_invoice_id']);
        });

        DB::unprepared(<<<'SQL'
            SELECT peakflow_apply_entity_rls('invoice_prepayment_applications');

            CREATE OR REPLACE FUNCTION peakflow_check_adjusting_document() RETURNS trigger AS $$
            DECLARE
                reference_kind text;
                adjusted invoices;
                total numeric(21,4);
            BEGIN
                /*
                 | A final invoice applying a prepayment.
                 |
                 | Handled first and separately, because a `380` is an
                 | ordinary invoice that may or may not carry one: the
                 | reference decides, and most invoices have none.
                 */
                IF NEW.document_type = '380' THEN
                    SELECT i.* INTO adjusted
                    FROM invoice_references r
                    JOIN invoices i ON i.id = r.referenced_invoice_id
                    WHERE r.invoice_id = NEW.id
                      AND r.kind = 'prepayment'
                    LIMIT 1;

                    IF adjusted IS NULL THEN
                        RETURN NEW;
                    END IF;

                    IF adjusted.status <> 'issued' OR adjusted.document_type <> '386' THEN
                        RAISE EXCEPTION
                            'the prepayment applied to % is not an issued prepayment invoice: there is '
                            'no liability to release and nothing the customer has been asked to pay',
                            NEW.number
                        USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF NEW.prepaid_amount <> adjusted.gross_amount THEN
                        RAISE EXCEPTION
                            'invoice % deducts % and prepayment % is for %: a deduction that does not '
                            'match the document it cites leaves the difference in a liability nobody '
                            'will clear',
                            NEW.number,
                            NEW.prepaid_amount,
                            adjusted.number,
                            adjusted.gross_amount
                        USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    /*
                     | One application, by primary key.
                     |
                     | Two invoices deducting one prepayment would release
                     | the same liability twice and collect the balance from
                     | the customer twice.
                     */
                    INSERT INTO invoice_prepayment_applications
                        (prepayment_invoice_id, entity_id, final_invoice_id, gross_applied, created_at)
                    VALUES
                        (adjusted.id, adjusted.entity_id, NEW.id, NEW.prepaid_amount, now());

                    RETURN NEW;
                END IF;

                reference_kind := CASE NEW.document_type
                    WHEN '381' THEN 'credits'
                    WHEN '383' THEN 'charges'
                    WHEN '384' THEN 'corrects'
                END;

                IF reference_kind IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT i.* INTO adjusted
                FROM invoice_references r
                JOIN invoices i ON i.id = r.referenced_invoice_id
                WHERE r.invoice_id = NEW.id
                  AND r.kind = reference_kind
                LIMIT 1;

                IF adjusted IS NULL THEN
                    RAISE EXCEPTION
                        'a document of type % must reference the invoice it adjusts (EN 16931 BT-25): '
                        '%, issued against nothing, adjusts a balance nobody can trace to a supply',
                        NEW.document_type,
                        NEW.number
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF EXISTS (SELECT 1 FROM invoice_corrections WHERE corrected_invoice_id = adjusted.id) THEN
                    RAISE EXCEPTION
                        'invoice % has been replaced by a corrected invoice, which is now the live '
                        'document: adjusting the original as well would count the correction twice',
                        adjusted.number
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF NEW.document_type = '384' THEN
                    INSERT INTO invoice_corrections
                        (corrected_invoice_id, entity_id, correction_invoice_id, created_at)
                    VALUES
                        (adjusted.id, adjusted.entity_id, NEW.id, now());

                    RETURN NEW;
                END IF;

                IF NEW.document_type = '383' THEN
                    RETURN NEW;
                END IF;

                INSERT INTO invoice_credit_totals AS t
                    (invoice_id, entity_id, gross_credited, updated_at)
                VALUES
                    (adjusted.id, adjusted.entity_id, NEW.gross_amount, now())
                ON CONFLICT (invoice_id) DO UPDATE
                    SET gross_credited = t.gross_credited + NEW.gross_amount,
                        updated_at = now()
                RETURNING t.gross_credited INTO total;

                IF total > adjusted.gross_amount THEN
                    RAISE EXCEPTION
                        'invoice % is for % and crediting % would bring the total credited against it '
                        'to %: a credit note cannot reduce a receivable below zero, which is a refund '
                        'and not a credit',
                        adjusted.number,
                        adjusted.gross_amount,
                        NEW.gross_amount,
                        total
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS adjusting_documents_reference_what_they_adjust ON invoices;

            CREATE TRIGGER adjusting_documents_reference_what_they_adjust
                BEFORE UPDATE ON invoices
                FOR EACH ROW
                WHEN (
                    NEW.status = 'issued'
                    AND OLD.status = 'draft'
                    AND NEW.document_type IN ('380', '381', '383', '384')
                )
                EXECUTE FUNCTION peakflow_check_adjusting_document();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_prepayment_applications');
    }
};
