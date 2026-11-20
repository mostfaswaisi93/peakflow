<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An open balance counts what the payer withheld - PF-083, PF-120.
 *
 * ## The term that was missing
 *
 * 009300 defines an open balance as the gross less five things, and 001400
 * put the two credit terms into the bill branch that had been left out of
 * it. This adds a sixth to both branches: what a withholding certificate
 * relieved.
 *
 * **An invoice total that does not equal the cash received is correct when
 * withholding applies.** The payer keeps part of the invoice back and pays
 * it to the authority on the payee's behalf, against a certificate the
 * payee claims the credit with. The document is settled; the shortfall is
 * an asset.
 *
 * Without the term the deduction stays open on the document for ever. The
 * ageing counts it, the collections report chases a customer who paid
 * everything it owed, and the only way anybody finds out why is by reading
 * the certificate - which, until `withholding_reliefs` existed, named its
 * document in free text and could not be joined to at all.
 *
 * ## Why not a write-off, which already has a term here
 *
 * Because a write-off closes the document and **destroys the credit**. The
 * certificate stays in a drawer, the money leaves the books as a cost, and
 * nobody claims it against the liability it was paid towards. The two are
 * one keystroke apart in a user interface and opposite in substance, which
 * is why they are separate tables rather than one with a `reason` column.
 *
 * @financial-not-applicable: redefines a read-only STABLE function. No
 * table is created, no column altered and no financial row written.
 * @online-safe Replacing a function takes no lock on any table and
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
                             /*
                              | And what the customer withheld - PF-083.
                              |
                              | The sixth term. An invoice total that does
                              | not equal the cash received is CORRECT when
                              | withholding applies: the payer keeps part of
                              | it back and pays it to the authority on the
                              | payee's behalf, against a certificate the
                              | payee claims the credit with.
                              |
                              | Without this term the deduction stays open on
                              | the document for ever. That is not untidy:
                              | the ageing counts it, the collections report
                              | chases a customer who paid everything it
                              | owed, and the only way anybody discovers why
                              | is by reading the certificate.
                              |
                              | NOT a write-off, which is the tempting place
                              | to put it. A write-off closes the document
                              | and destroys the credit - the certificate
                              | stays in a drawer, the money leaves the books
                              | as a cost, and nobody claims it.
                              */
                             - coalesce((
                                 SELECT sum(wr.amount)
                                 FROM withholding_reliefs wr
                                 WHERE wr.document_type = 'invoice'
                                   AND wr.document_id = i.id
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
                             /*
                              | And what WE withheld from the supplier.
                              |
                              | The mirror, and a real case: paying a
                              | non-resident contractor means keeping part of
                              | the bill back and paying it to the authority
                              | ourselves. The supplier is owed the whole
                              | bill, of which part goes to the authority on
                              | their behalf - so the bill is settled and the
                              | difference is a liability to the authority,
                              | not a balance the supplier chases.
                              |
                              | Carried although the posting rule for it does
                              | not exist yet: `postingRuleKey()` refuses for
                              | a bill, by name, rather than returning the
                              | receivable rule. The term costs nothing while
                              | no rows exist, and would otherwise be the
                              | thing nobody remembers when the rule lands.
                              */
                             - coalesce((
                                 SELECT sum(wr.amount)
                                 FROM withholding_reliefs wr
                                 WHERE wr.document_type = 'bill'
                                   AND wr.document_id = b.id
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
         | Restoring the previous definition would put back a document that
         | stays open for a deduction the customer legitimately withheld,
         | and the ageing built on it would chase somebody who paid in
         | full. `down()` on a corrected money definition is a way to
         | reintroduce the defect.
         */
    }
};
