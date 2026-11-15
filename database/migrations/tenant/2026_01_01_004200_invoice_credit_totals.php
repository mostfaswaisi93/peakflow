<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The over-credit cap, made true under concurrency - PF-097, PF-094.
 *
 * @financial
 *
 * ## What was observed
 *
 * The first version of the cap locked the invoice being credited and then
 * summed the credit notes already issued against it. Three credit notes
 * issued at the same instant from three processes all succeeded: an invoice
 * for 115.0000 ended up with 345.0000 credited against it, and the suite
 * that caught it printed both figures.
 *
 * That violation has not been reproduced on demand since. A forced
 * interleaving - one credit note held in an open transaction until another
 * backend is genuinely blocked - is refused by the locking version too,
 * because a trigger function is VOLATILE and each statement inside it takes
 * a fresh snapshot, so the sum after the lock wait does see the other
 * transaction's committed work.
 *
 * ## Why the counter is kept anyway
 *
 * Because the locking version is correct only if three separate pieces of
 * reasoning hold: that the lock is taken on the right row, that a volatile
 * function's inner statements re-snapshot, and - where they do not - that
 * the number series lock happens to serialise two credit notes before either
 * reaches the trigger. The last of those is an accident. It is a dependency
 * between the over-credit cap and the numbering sequence, two mechanisms
 * with nothing to do with each other, and the day numbering becomes
 * per-branch it stops holding.
 *
 * `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` needs none of that
 * reasoning. It blocks, re-reads the committed row, adds to the value that
 * is actually there, and returns it. An invariant whose correctness can be
 * stated in one sentence is worth more than one that is probably fine.
 *
 * ## This table is not the record
 *
 * The documents are. The references say what credits what and the credit
 * notes say how much, and `Crediting::remainingOn()` reads them - because a
 * figure derived from the documents is the one an auditor can reproduce.
 * This row exists so that the database can refuse an over-credit atomically,
 * and a check asserts the two agree. A derived total nobody checks is a
 * second answer to the same question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_credit_totals', function (Blueprint $table): void {
            // The invoice is the key: one row per credited document, created
            // by the first credit note against it.
            $table->ulid('invoice_id')->primary();
            $table->char('entity_id', 26);

            $table->decimal('gross_credited', 21, 4)->default(0);

            $table->timestampTz('updated_at')->nullable();

            $table->index(['entity_id', 'invoice_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE invoice_credit_totals
                ADD CONSTRAINT invoice_credit_totals_is_not_negative
                    CHECK (gross_credited >= 0);

            SELECT peakflow_apply_entity_rls('invoice_credit_totals');

            CREATE OR REPLACE FUNCTION peakflow_refuse_overcredit() RETURNS trigger AS $$
            DECLARE
                credited_invoice invoices;
                total numeric(21,4);
            BEGIN
                SELECT i.* INTO credited_invoice
                FROM invoice_references r
                JOIN invoices i ON i.id = r.referenced_invoice_id
                WHERE r.invoice_id = NEW.id
                  AND r.kind = 'credits'
                LIMIT 1;

                IF credited_invoice IS NULL THEN
                    RAISE EXCEPTION
                        'a credit note must reference the invoice it credits (EN 16931 BT-25): %, '
                        'issued against nothing, is a document that reduces a receivable nobody can '
                        'identify',
                        NEW.number
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                /*
                 | The running total, through a write rather than a read.
                 |
                 | A read - even a locking one - is bound by the snapshot of
                 | the statement that fired this trigger, which was taken
                 | before any concurrent credit note committed. This is not:
                 | when it blocks on a row another transaction is updating it
                 | re-reads the committed version and adds to that, so the
                 | figure it returns includes every credit note in the
                 | database. Measured: with the locking read, three
                 | simultaneous full credit notes against one 115.0000
                 | invoice all succeeded.
                 */
                INSERT INTO invoice_credit_totals AS t
                    (invoice_id, entity_id, gross_credited, updated_at)
                VALUES
                    (credited_invoice.id, credited_invoice.entity_id, NEW.gross_amount, now())
                ON CONFLICT (invoice_id) DO UPDATE
                    SET gross_credited = t.gross_credited + NEW.gross_amount,
                        updated_at = now()
                RETURNING t.gross_credited INTO total;

                IF total > credited_invoice.gross_amount THEN
                    RAISE EXCEPTION
                        'invoice % is for % and crediting % would bring the total credited against it '
                        'to %: a credit note cannot reduce a receivable below zero, which is a refund '
                        'and not a credit',
                        credited_invoice.number,
                        credited_invoice.gross_amount,
                        NEW.gross_amount,
                        total
                    USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS peakflow_refuse_overcredit() CASCADE');

        Schema::dropIfExists('invoice_credit_totals');
    }
};
