<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Debit notes and corrected invoices - PF-098.
 *
 * ## One correction per invoice, enforced by a primary key
 *
 * A corrected invoice replaces the document it references. Two replacements
 * for one invoice is a question with no answer - which one is the invoice? -
 * and the regimes that use this mechanism expect a chain: correct the
 * correction, not the original twice.
 *
 * So the trigger inserts a row keyed by the corrected document, and the
 * primary key is the enforcement. No counter, no sum, no reasoning about
 * snapshots: two transactions inserting the same key cannot both succeed,
 * and the second one waits and then fails. That is the whole guarantee.
 *
 * ## And a corrected invoice cannot also be credited
 *
 * The replacement is the live document. Crediting the original after it has
 * been replaced reduces a receivable that the replacement already restated,
 * which double-counts the correction - and the two documents would each be
 * defensible on their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_corrections', function (Blueprint $table): void {
            // The corrected document is the key: one replacement, ever.
            $table->ulid('corrected_invoice_id')->primary();
            $table->char('entity_id', 26);
            $table->ulid('correction_invoice_id');

            $table->timestampTz('created_at')->nullable();

            $table->index(['entity_id', 'corrected_invoice_id']);
        });

        DB::unprepared(<<<'SQL'
            SELECT peakflow_apply_entity_rls('invoice_corrections');

            /*
             | One function for every document that must reference another.
             |
             | Three types do - 381 credits, 383 charges, 384 corrects - and
             | each is refused for the same underlying reason when it does
             | not: a document that adjusts another one, and cannot say which
             | one, adjusts a balance nobody can trace to a supply.
             |
             | They were going to be three triggers. One function and one
             | trigger, because the alternative is three WHEN clauses that
             | have to stay mutually exclusive, and the day a fourth type
             | arrives it is added to two of them.
             */
            CREATE OR REPLACE FUNCTION peakflow_check_adjusting_document() RETURNS trigger AS $$
            DECLARE
                reference_kind text;
                adjusted invoices;
                total numeric(21,4);
            BEGIN
                reference_kind := CASE NEW.document_type
                    WHEN '381' THEN 'credits'
                    WHEN '383' THEN 'charges'
                    WHEN '384' THEN 'corrects'
                END;

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

                /*
                 | A replaced invoice is closed to further adjustment.
                 |
                 | The replacement restated it in full. Crediting the
                 | original afterwards reduces a receivable the replacement
                 | already dealt with, and both documents would look correct
                 | on their own.
                 */
                IF EXISTS (SELECT 1 FROM invoice_corrections WHERE corrected_invoice_id = adjusted.id) THEN
                    RAISE EXCEPTION
                        'invoice % has been replaced by a corrected invoice, which is now the live '
                        'document: adjusting the original as well would count the correction twice',
                        adjusted.number
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF NEW.document_type = '384' THEN
                    /*
                     | The primary key IS the rule.
                     |
                     | Two replacements for one invoice is a question with no
                     | answer. A unique key cannot both succeed, the second
                     | transaction waits and then fails, and nothing about
                     | snapshots needs to be true for that to hold.
                     */
                    INSERT INTO invoice_corrections
                        (corrected_invoice_id, entity_id, correction_invoice_id, created_at)
                    VALUES
                        (adjusted.id, adjusted.entity_id, NEW.id, now());

                    RETURN NEW;
                END IF;

                IF NEW.document_type = '383' THEN
                    -- A debit note adds to what is owed, so there is no cap:
                    -- the customer can be charged more than once for
                    -- different things against the same invoice.
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

            DROP TRIGGER IF EXISTS credit_notes_cannot_exceed_the_invoice ON invoices;

            CREATE TRIGGER adjusting_documents_reference_what_they_adjust
                BEFORE UPDATE ON invoices
                FOR EACH ROW
                WHEN (
                    NEW.status = 'issued'
                    AND OLD.status = 'draft'
                    AND NEW.document_type IN ('381', '383', '384')
                )
                EXECUTE FUNCTION peakflow_check_adjusting_document();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS adjusting_documents_reference_what_they_adjust ON invoices;
            DROP FUNCTION IF EXISTS peakflow_check_adjusting_document();
        SQL);

        Schema::dropIfExists('invoice_corrections');
    }
};
