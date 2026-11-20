<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a withholding certificate relieved, and from which document - PF-083, PF-120.
 *
 * ## Why a table and not the certificate's `payment_reference`
 *
 * `withholding_certificates.payment_reference` is a `string(300)` of free
 * text, and PF-120's note in the task sheet says exactly what that costs:
 * "free text until it adds the payment key beside it". A certificate that
 * names its document in prose cannot be joined to, so nothing can answer
 * "how much of this invoice was relieved by withholding" - and that is the
 * question the open balance has to ask.
 *
 * Without the answer, the invoice stays open for the deduction for ever.
 * That is what PF-083's last criterion is about: a 1,150 invoice settled by
 * a 1,050 transfer and a 100 certificate is **settled**, and a part-open
 * document is chased by the collections report.
 *
 * ## Why not a column on the certificate instead
 *
 * Because one deduction can relieve more than one document. A customer
 * paying three invoices in one transfer withholds once, on the total, and
 * the certificate the authority sees is one certificate - so document and
 * certificate are many-to-many, and the amount belongs on the join rather
 * than on either side.
 *
 * The reverse is also true and is why `amount` is here rather than derived
 * from the certificate: a partial relief is legitimate when part of a
 * document was settled in an earlier period.
 *
 * ## Why the journal entry is on it
 *
 * The relief is an accounting act - the receivable goes down and an asset
 * appears - and the row that records it has to name the entry, or the two
 * can drift. Reporting a relief with no entry would close a document in a
 * report and not in the books.
 *
 * @online-safe Creates one table. No existing table is read or rewritten,
 * and nothing is backfilled: there are no reliefs before this, because
 * there was no way to record one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_reliefs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('document_type', 32);
            $table->char('document_id', 26);

            $table->foreignUlid('certificate_id')->constrained('withholding_certificates')->restrictOnDelete();
            $table->char('journal_entry_id', 26);

            $table->decimal('amount', 19, 4);
            $table->char('currency', 3);

            $table->char('party_id', 26);
            $table->date('relieved_on');
            $table->char('relieved_by', 26);
            $table->timestamps();

            /*
             | One relief per certificate per document.
             |
             | A certificate relieving the same invoice twice would claim
             | the credit twice and close the invoice past zero. The
             | service refuses before it gets here - the open balance has
             | to equal what the rate withholds - and this is the half of
             | that which survives a service being bypassed.
             */
            $table->unique(['certificate_id', 'document_type', 'document_id'], 'withholding_reliefs_once_per_document');

            $table->index(['entity_id', 'document_type', 'document_id'], 'withholding_reliefs_by_document');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE withholding_reliefs
                ADD CONSTRAINT withholding_reliefs_amount_is_positive
                CHECK (amount > 0);

            COMMENT ON TABLE withholding_reliefs IS
                'What a withholding certificate relieved, per document - PF-083, PF-120. The join the open balance reads, because a certificate that names its document in free text cannot be joined to and the document stays open for the deduction for ever.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('withholding_reliefs')");
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_reliefs');
    }
};
