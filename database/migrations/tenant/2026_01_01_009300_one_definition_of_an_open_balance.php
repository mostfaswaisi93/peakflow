<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What a document still owes, defined once - PF-172.
 *
 * ## Three copies was the warning; four is the bug
 *
 * The open balance of a document is its gross, less credit notes, less
 * what has been allocated to it, less what a difference relieved, less
 * what has been written off. Each of those terms arrived with a task, and
 * by PF-171 the formula existed three times: in the trigger that refuses
 * an over-allocation, in the service that reports a balance, and in the
 * query that lists open documents for a strategy.
 *
 * They were kept in step by hand. PF-171 showed what that costs: the
 * difference term reached two of the three, and the one it missed reported
 * a penny still owed on an invoice the ledger had settled.
 *
 * So it is a function, and the three callers ask it. A term added later is
 * a one-line change in one place, which is the only arrangement under
 * which "they agree" is a property rather than a hope.
 *
 * ## Why in SQL rather than in PHP
 *
 * Because one of the three callers is a trigger, and a trigger cannot call
 * PHP. The other direction - PHP as the single definition, the trigger
 * trusting it - is the arrangement that failed: a service is one code
 * path and an import is another.
 *
 * `STABLE`, so the planner may call it once per row and cache within the
 * statement. Not `IMMUTABLE`: it reads tables, and declaring otherwise
 * would let the planner fold it to a constant across a statement that
 * changes what it reads.
 *
 * @financial-not-applicable: this creates a read-only function and
 * redefines a trigger function on `allocations`, which already carries
 * `entity_id` and has row-level security applied where it is created. No
 * table is created and no financial row is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_document_open_balance(
                doc_type text,
                doc_id char(26)
            )
            RETURNS numeric
            LANGUAGE sql
            STABLE
            AS $$
                /*
                 | The five terms, in one place.
                 |
                 | Credit notes apply to invoices only - a bill is credited
                 | by a supplier credit, which settles through its own
                 | allocation rather than by reference - so the invoice
                 | branch carries that term and the bill branch does not.
                 */
                SELECT CASE doc_type
                    WHEN 'invoice' THEN (
                        SELECT i.gross_amount
                             - coalesce((
                                 SELECT sum(c.gross_amount)
                                 FROM invoices c
                                 JOIN invoice_references r ON r.invoice_id = c.id
                                 WHERE r.referenced_invoice_id = i.id
                                   AND c.document_type = '381'
                                   AND c.status = 'issued'
                               ), 0)
                             - coalesce((
                                 SELECT sum(a.amount)
                                 FROM allocations a
                                 WHERE a.document_type = 'invoice'
                                   AND a.document_id = i.id
                                   AND a.reversed_at IS NULL
                               ), 0)
                             - coalesce((
                                 SELECT sum(d.amount)
                                 FROM reconciliation_differences d
                                 WHERE d.subject_type = 'invoice'
                                   AND d.subject_id = i.id
                               ), 0)
                             - coalesce((
                                 SELECT sum(w.amount)
                                 FROM write_offs w
                                 WHERE w.document_type = 'invoice'
                                   AND w.document_id = i.id
                               ), 0)
                        FROM invoices i
                        WHERE i.id = doc_id
                    )
                    WHEN 'bill' THEN (
                        SELECT b.gross_amount
                             - coalesce((
                                 SELECT sum(a.amount)
                                 FROM allocations a
                                 WHERE a.document_type = 'bill'
                                   AND a.document_id = b.id
                                   AND a.reversed_at IS NULL
                               ), 0)
                             - coalesce((
                                 SELECT sum(d.amount)
                                 FROM reconciliation_differences d
                                 WHERE d.subject_type = 'bill'
                                   AND d.subject_id = b.id
                               ), 0)
                             - coalesce((
                                 SELECT sum(w.amount)
                                 FROM write_offs w
                                 WHERE w.document_type = 'bill'
                                   AND w.document_id = b.id
                               ), 0)
                        FROM bills b
                        WHERE b.id = doc_id
                    )
                    /*
                     | NULL for a type this does not know.
                     |
                     | Not zero. A caller reading zero would conclude the
                     | document is settled; one reading null has to decide
                     | what to do, and every caller here refuses.
                     */
                    ELSE NULL
                END;
            $$;

            COMMENT ON FUNCTION peakflow_document_open_balance(text, char) IS
                'Gross, less credit notes, less allocations, less what a difference relieved, less what was written off - PF-172. One definition, because three copies kept in step by hand had already drifted once.';
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
                document_open numeric(21,4);
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
                 | The shared definition, asked rather than restated.
                 */
                document_open := peakflow_document_open_balance(NEW.document_type, NEW.document_id);

                IF document_open IS NULL THEN
                    RAISE EXCEPTION
                        'This allocation names a % that does not exist, or one whose open balance '
                        'this rule does not know how to compute. An allocation to nothing settles '
                        'nothing and leaves the cash reported as applied.',
                        NEW.document_type
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                /*
                 | This row's own current contribution, added back.
                 |
                 | On INSERT the row is not in the table yet, so the open
                 | balance already excludes it and this is zero. On an
                 | UPDATE that changes the amount, the balance has
                 | subtracted the OLD figure - and comparing a new amount
                 | against a balance that already assumes the old one
                 | would refuse any increase, however small.
                 */
                SELECT coalesce(sum(a.amount), 0)
                INTO document_allocated
                FROM allocations a
                WHERE a.id = NEW.id
                  AND a.reversed_at IS NULL;

                IF NEW.amount > document_open + document_allocated THEN
                    RAISE EXCEPTION
                        'This document has % left on it and this would allocate % - which would '
                        'take it below zero. A customer owing a negative figure is a credit nobody '
                        'granted.',
                        document_open + document_allocated, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        // @unsafe-migration-reviewed: dropping a function nothing but this
        // migration's own trigger definition calls. The rollback restores
        // neither - a release that predates this one has its own trigger
        // body, which its own migration installs.
        DB::unprepared('DROP FUNCTION IF EXISTS peakflow_document_open_balance(text, char);');
    }
};
