<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allocations, and the two invariants that keep AR honest - PF-168.
 *
 * ## The invariant, and why it lives here
 *
 * An allocation may not exceed the payment's unallocated amount, and it
 * may not exceed the document's open balance. Both are sums across rows,
 * so both are triggers rather than CHECK constraints - and both are in the
 * database rather than in a service because this is **the** invariant that
 * keeps the receivables subledger equal to its control account.
 *
 * Break the first and cash is applied twice: two invoices report settled
 * by one payment, the control account moves by twice what arrived, and the
 * only symptom is a bank reconciliation that will not balance. Break the
 * second and a document goes negative - a customer owing minus 400, which
 * is a credit nobody granted.
 *
 * A service can be bypassed. An import, a correction script, a bulk tool
 * written next year, a half-finished migration: each reaches this table
 * without passing one, and "the service is careful" is not an invariant.
 *
 * ## Reversal is a row, not a delete
 *
 * And re-allocation afterwards is ordinary: somebody allocated to the
 * wrong invoice, undid it, and allocated correctly. A table that deleted
 * could not answer "it was against 4471 and somebody changed it", which is
 * the question asked when a customer disputes a statement.
 *
 * Reversed rows do not count toward either sum, or a corrected allocation
 * could never be redone - which is the one moment somebody needs it.
 *
 * @financial
  *
 * @online-safe Creates `allocations`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->char('payment_id', 26);

            /*
             | What it was applied to, as a type and an id.
             |
             | The same shape as `matches`, for the same reason: an
             | allocation lands on an invoice, a bill or a credit note,
             | and four nullable foreign keys of which three are always
             | null is a row shape that invites a query to forget one.
             */
            $table->string('document_type', 24);
            $table->char('document_id', 26);
            $table->char('party_id', 26)->nullable();

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);
            $table->date('allocated_on');

            /*
             | How it was decided, and by whom.
             |
             | `strategy` is the automatic rule that chose it, or
             | `explicit` where a person did. PF-168 asks for strategies
             | that are inspectable and overridable, and the first part of
             | being inspectable is that the row says which one ran.
             */
            $table->string('strategy', 24);
            $table->text('reason')->nullable();

            $table->ulid('journal_entry_id')->nullable();
            $table->ulid('allocated_by');
            $table->timestamp('allocated_at');

            $table->timestamp('reversed_at')->nullable();
            $table->ulid('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'payment_id']);
            $table->index(['entity_id', 'document_type', 'document_id']);
            $table->index(['entity_id', 'party_id', 'allocated_on']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE allocations
                ADD CONSTRAINT allocations_document_type_is_known
                    CHECK (document_type IN ('invoice', 'bill', 'credit_note', 'supplier_credit')),

                ADD CONSTRAINT allocations_amount_is_positive
                    CHECK (amount > 0),

                ADD CONSTRAINT allocations_strategy_is_known
                    CHECK (strategy IN ('explicit', 'oldest_first', 'by_reference', 'instructed')),

                ADD CONSTRAINT allocations_reversal_is_explained
                    CHECK (
                        (reversed_at IS NULL AND reversed_by IS NULL)
                        OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL
                            AND length(btrim(coalesce(reversal_reason, ''))) > 10)
                    );
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_an_allocation_fits()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                payment_amount numeric(21,4);
                payment_currency char(3);
                payment_reversed timestamptz;
                allocated numeric(21,4);
                document_gross numeric(21,4);
                document_allocated numeric(21,4);
            BEGIN
                /*
                 | A reversal only ever removes from both sums.
                 |
                 | Re-checking on the update that reverses a row would
                 | read the row as still live under some isolation levels
                 | and refuse a correction for exceeding a total the
                 | correction is reducing.
                 */
                IF TG_OP = 'UPDATE' AND NEW.reversed_at IS NOT NULL THEN
                    RETURN NEW;
                END IF;

                SELECT p.amount, p.currency, p.reversed_at
                INTO payment_amount, payment_currency, payment_reversed
                FROM payments p
                WHERE p.id = NEW.payment_id;

                IF payment_amount IS NULL THEN
                    RAISE EXCEPTION
                        'This allocation names payment %, and there is no such payment. Money that '
                        'was never recorded cannot settle anything.',
                        NEW.payment_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF payment_reversed IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Payment % was reversed. Allocating it would report a document settled by '
                        'money that was taken back.',
                        NEW.payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.currency <> payment_currency THEN
                    RAISE EXCEPTION
                        'This allocation is in % and the payment is in %. An allocation in another '
                        'currency is arithmetic between two different units.',
                        NEW.currency, payment_currency
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT coalesce(sum(a.amount), 0)
                INTO allocated
                FROM allocations a
                WHERE a.payment_id = NEW.payment_id
                  AND a.reversed_at IS NULL
                  AND a.id <> NEW.id;

                IF allocated + NEW.amount > payment_amount THEN
                    RAISE EXCEPTION
                        'This payment is %, % of it is already allocated, and this would allocate '
                        'another % - which applies more cash than arrived. Two documents would '
                        'report settled by one payment and the bank reconciliation would be the '
                        'only thing that noticed.',
                        payment_amount, allocated, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 | The document's open balance, from its own table.
                 |
                 | Gross less credit notes less what is already allocated.
                 | Computed here rather than read from a column for the
                 | same reason the matching engine computes it: a stored
                 | balance is wrong between the credit note and the job
                 | that would have updated it, and that window is exactly
                 | when somebody is allocating.
                 */
                IF NEW.document_type = 'invoice' THEN
                    SELECT i.gross_amount
                      - coalesce((
                          SELECT sum(c.gross_amount)
                          FROM invoices c
                          JOIN invoice_references r ON r.invoice_id = c.id
                          WHERE r.referenced_invoice_id = i.id
                            AND c.document_type = '381'
                            AND c.status = 'issued'
                        ), 0)
                    INTO document_gross
                    FROM invoices i
                    WHERE i.id = NEW.document_id;
                ELSIF NEW.document_type = 'bill' THEN
                    SELECT b.gross_amount INTO document_gross FROM bills b WHERE b.id = NEW.document_id;
                ELSE
                    document_gross := NULL;
                END IF;

                IF document_gross IS NULL THEN
                    RAISE EXCEPTION
                        'This allocation names a % that does not exist, or one whose open balance '
                        'this rule does not know how to compute. An allocation to nothing settles '
                        'nothing and leaves the cash reported as applied.',
                        NEW.document_type
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                SELECT coalesce(sum(a.amount), 0)
                INTO document_allocated
                FROM allocations a
                WHERE a.document_type = NEW.document_type
                  AND a.document_id = NEW.document_id
                  AND a.reversed_at IS NULL
                  AND a.id <> NEW.id;

                IF document_allocated + NEW.amount > document_gross THEN
                    RAISE EXCEPTION
                        'This document is worth %, % is already allocated to it, and this would '
                        'allocate another % - which would take it below zero. A customer owing a '
                        'negative figure is a credit nobody granted.',
                        document_gross, document_allocated, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER allocations_fit_both_sides
                BEFORE INSERT OR UPDATE ON allocations
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_an_allocation_fits();

            COMMENT ON FUNCTION peakflow_an_allocation_fits() IS
                'An allocation exceeds neither the payment nor the document - PF-168. This is the invariant that keeps the receivables subledger equal to its control account.';

            COMMENT ON TABLE allocations IS
                'What a payment settled, and how that was decided - PF-168. Reversal is a row; re-allocation afterwards is ordinary.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('allocations')");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS allocations_fit_both_sides ON allocations;
            DROP FUNCTION IF EXISTS peakflow_an_allocation_fits();
        SQL);

        Schema::dropIfExists('allocations');
    }
};
