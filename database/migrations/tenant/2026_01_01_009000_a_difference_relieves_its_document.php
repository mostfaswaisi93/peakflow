<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A recorded difference relieves the document it was recorded against - PF-171.
 *
 * ## The divergence this closes
 *
 * PF-164 posts a difference - a rounding unit, a short payment written
 * off, tax withheld at source - against a document, and the entry relieves
 * the receivable control. The subledger did not know: a document's open
 * balance was gross, less credit notes, less allocations, and a difference
 * is none of those.
 *
 * So the ledger said an invoice was settled and the customer ledger said a
 * penny was still owed. That is the exact shape of divergence this phase
 * exists to prevent, and it was found by a check asserting the document
 * closes rather than one asserting the posting happened - which is the
 * difference between checking the consequence and checking the act.
 *
 * ## And the allocation guard has to agree
 *
 * The trigger that refuses an over-allocation computed the open balance
 * the old way. Left alone, a document part-relieved by a write-off could
 * still absorb an allocation for its full gross - which is the over-
 * application the trigger exists to refuse, reached by a different route.
 *
 * @financial-not-applicable: this redefines a trigger function on
 * `allocations`, which already carries `entity_id` and has row-level
 * security applied where it is created. No table is created and no
 * financial row is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared($this->fits(withDifferences: true));
    }

    public function down(): void
    {
        DB::unprepared($this->fits(withDifferences: false));
    }

    private function fits(bool $withDifferences): string
    {
        $differences = $withDifferences
            ? "- coalesce((SELECT sum(d.amount) FROM reconciliation_differences d ".
              "WHERE d.subject_type = NEW.document_type AND d.subject_id = NEW.document_id), 0)"
            : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION peakflow_an_allocation_fits()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                payment_amount numeric(21,4);
                payment_currency char(3);
                payment_reversed timestamptz;
                allocated numeric(21,4);
                document_gross numeric(21,4);
                document_allocated numeric(21,4);
            BEGIN
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
                 | Gross, less credit notes, less what a difference already
                 | relieved.
                 |
                 | The last term is what PF-171 added. A document part-
                 | relieved by a rounding unit or a written-off shortfall
                 | can absorb less than its gross, and a guard that did not
                 | know would let an allocation take it below zero by a
                 | route the guard exists to refuse.
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
                      {$differences}
                    INTO document_gross
                    FROM invoices i
                    WHERE i.id = NEW.document_id;
                ELSIF NEW.document_type = 'bill' THEN
                    SELECT b.gross_amount
                      {$differences}
                    INTO document_gross
                    FROM bills b
                    WHERE b.id = NEW.document_id;
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
            \$\$;
        SQL;
    }
};
