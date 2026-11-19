<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A bill's open balance was its gross, for ever - PF-281, PF-172.
 *
 * ## What was wrong
 *
 * 009300 defines the open balance as "gross, less credit notes, less what
 * has been allocated to it, less what a difference relieved, less what has
 * been written off", and says so in its own docblock. The bill branch
 * implemented three of those five: it left out both credit terms, on the
 * reasoning that "credit notes apply to invoices only - a bill is credited
 * by allocation rather than by reference".
 *
 * A bill is credited both ways. `Bill::effectiveCredit()` sums the credit
 * notes that name it through `credits_bill_id`, and
 * `Bill::allocatedCredit()` sums `bill_credit_allocations`. Neither
 * reached the function, so the function and the model disagreed about what
 * a supplier still owed us - in opposite directions, because the model in
 * turn left out the allocations the function had.
 *
 * ## How the fixture found it
 *
 * `PayablesAging` asked the model rather than the function, and the model
 * subtracts `bills.paid_amount` - a column **nothing writes**. So every
 * posted bill aged at its full gross for ever, however much had been paid.
 * The showcase books paid 41 of 56 bills and the ageing still reported all
 * 56 in full: 367,683.75 against a control account of 105,253.75.
 *
 * That is the dangerous shape of this bug rather than the obvious one. The
 * screens read the function and the ageing read the model, so "which bills
 * are still open" had two answers, and `BillListController`'s own docblock
 * had already written down what that costs: "this list and the payment run
 * that asks the same question disagree about which bills are still open,
 * and both look right." A payment run selecting from the wrong one pays a
 * supplier twice.
 *
 * ## The invoice branch is untouched
 *
 * It already carried all five terms, which is why receivables tied while
 * payables did not.
 *
 * @financial-not-applicable: redefines a read-only STABLE function. No
 * table is created, no column altered and no financial row written.
 * @online-safe CREATE OR REPLACE FUNCTION takes no lock on any table and
 * rewrites nothing. Statements already planned keep the old definition
 * until they finish, and both definitions return a numeric.
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
                             /*
                              | The two credit terms this branch was missing.
                              |
                              | A supplier credit note reaches a bill either
                              | by naming it - `credits_bill_id` - or by being
                              | allocated to it. `Bill::effectiveCredit()` and
                              | `Bill::allocatedCredit()` sum exactly these
                              | two, and leaving them out of the function made
                              | the function and the model disagree about the
                              | same supplier.
                              |
                              | Cancelled credit notes are excluded, matching
                              | the model, which counts only `approved` and
                              | `posted`. A draft credit note has not been
                              | agreed with anybody yet and must not reduce
                              | what we show as owed.
                              */
                             - coalesce((
                                 SELECT sum(n.gross_amount)
                                 FROM bills n
                                 WHERE n.credits_bill_id = b.id
                                   AND n.document_type = '381'
                                   AND n.status IN ('approved', 'posted')
                               ), 0)
                             - coalesce((
                                 SELECT sum(ca.amount)
                                 FROM bill_credit_allocations ca
                                 WHERE ca.bill_id = b.id
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
        SQL);
    }

    public function down(): void
    {
        /*
         | Deliberately not reversed.
         |
         | Restoring the previous definition would put back a bill whose
         | open balance ignores its credit notes. `down()` on a corrected
         | money definition is a way to reintroduce the defect, and the
         | migration that follows this one would then disagree with the
         | function again.
         */
    }
};
