<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a credit note adds to the document tables - PF-097.
 *
 * Not tagged `@financial`: that tag asserts a new table carries `entity_id`
 * and has row-level security applied, and this migration creates no table. It
 * adds one column to `invoice_lines`, a trigger to `invoices` and an index -
 * all three on tables that already carry the column and the policy, applied
 * where they were created.
 *
 * ## Why the cap is a trigger and not a CHECK
 *
 * "A credit note may not credit more than the invoice's remaining uncredited
 * value" is a statement about rows in two tables, and a CHECK constraint can
 * only see the row in front of it. The alternative - a `credited_amount`
 * column on `invoices`, maintained by a trigger, with a CHECK against
 * `gross_amount` - cannot work here at all: an issued invoice is immutable by
 * trigger in every column, so the maintenance UPDATE would be refused by the
 * guarantee it sits next to.
 *
 * So the sum is computed when a credit note is issued, under a lock on the
 * invoice it credits, and refused there. The locking is what makes it true
 * under concurrency: without it two credit notes for the full amount, issued
 * at the same instant, both see nothing credited and both succeed.
 *
 * ## Why it fires on the transition and not on the reference
 *
 * References are written while a document is still a draft, and two drafts
 * may each credit the whole invoice while only one is ever issued. Refusing
 * at draft time would stop somebody preparing an alternative; refusing at
 * issue time refuses the thing that has legal effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table): void {
            /*
             | Which line of the original this line credits - PF-097.
             |
             | Nullable, because a credit note may be for an amount rather
             | than for lines: a negotiated settlement credits "1,000 against
             | invoice 41" and has no line of the original behind it. When it
             | IS line-selected, this is what lets the original show which of
             | its lines have been credited and by how much.
             */
            /*
             | @unsafe-migration-reviewed: the column is created in the same
             | statement as the constraint, so every existing row holds NULL
             | and the validation scan has nothing it can fail on. The lock is
             | taken on `invoice_lines` against itself - a self-reference -
             | and the table is new in this release, so there is no
             | long-running reader for it to block. The NOT VALID / VALIDATE
             | pair is the right shape for adding a constraint to a column
             | that already holds data, which this is not.
             */
            $table->foreignUlid('credits_line_id')->nullable()
                ->constrained('invoice_lines')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_refuse_overcredit() RETURNS trigger AS $$
            DECLARE
                credited_invoice invoices;
                already numeric(21,4);
                room numeric(21,4);
            BEGIN
                /*
                 | The invoice this document credits, locked.
                 |
                 | FOR UPDATE, so two credit notes issued at the same instant
                 | cannot both read "nothing credited yet". The lock is held
                 | for the remainder of the issuing transaction, which is one
                 | invoice's worth of contention and the correct scope: two
                 | credit notes against DIFFERENT invoices never wait.
                 */
                SELECT i.* INTO credited_invoice
                FROM invoice_references r
                JOIN invoices i ON i.id = r.referenced_invoice_id
                WHERE r.invoice_id = NEW.id
                  AND r.kind = 'credits'
                LIMIT 1
                FOR UPDATE OF i;

                IF credited_invoice IS NULL THEN
                    RAISE EXCEPTION
                        'a credit note must reference the invoice it credits (EN 16931 BT-25): %, '
                        'issued against nothing, is a document that reduces a receivable nobody can '
                        'identify',
                        NEW.number
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                SELECT coalesce(sum(c.gross_amount), 0) INTO already
                FROM invoice_references r
                JOIN invoices c ON c.id = r.invoice_id
                WHERE r.referenced_invoice_id = credited_invoice.id
                  AND r.kind = 'credits'
                  AND c.status = 'issued'
                  AND c.id <> NEW.id;

                room := credited_invoice.gross_amount - already;

                IF NEW.gross_amount > room THEN
                    RAISE EXCEPTION
                        'invoice % is for % and % has already been credited, so only % remains: a '
                        'credit note for % would reduce a receivable below zero, which is a refund '
                        'and not a credit',
                        credited_invoice.number,
                        credited_invoice.gross_amount,
                        already,
                        room,
                        NEW.gross_amount
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER credit_notes_cannot_exceed_the_invoice
                BEFORE UPDATE ON invoices
                FOR EACH ROW
                WHEN (
                    NEW.status = 'issued'
                    AND OLD.status = 'draft'
                    AND NEW.document_type = '381'
                )
                EXECUTE FUNCTION peakflow_refuse_overcredit();
        SQL);

        /*
         | The index the sum reads.
         |
         | Without it the cap is a sequential scan of every reference in the
         | tenant, held under the invoice's lock, on every credit note - which
         | turns a correctness guarantee into a contention problem.
         */
        DB::unprepared(<<<'SQL'
            CREATE INDEX IF NOT EXISTS invoice_references_credits_of
                ON invoice_references (referenced_invoice_id, kind)
                WHERE kind = 'credits';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS credit_notes_cannot_exceed_the_invoice ON invoices;
            DROP FUNCTION IF EXISTS peakflow_refuse_overcredit();
            DROP INDEX IF EXISTS invoice_references_credits_of;
        SQL);

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('credits_line_id');
        });
    }
};
