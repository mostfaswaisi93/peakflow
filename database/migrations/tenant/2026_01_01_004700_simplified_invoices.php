<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The simplified form, as a fact about the document - PF-101.
 *
 * ## Why a column and not a profile lookup
 *
 * Whether a document was issued in the simplified form is part of what it
 * says, and an issued document does not change. Deriving it later from the
 * profile and the customer would re-read a configuration that may have moved
 * and a party that may have been merged - the same reason the snapshots
 * exist.
 *
 * ## What the database can and cannot enforce
 *
 * It can enforce which document types may be simplified at all: an invoice
 * and a credit note, because those are the forms the regimes define. It
 * cannot enforce the value threshold, which is a figure per regime in a
 * currency, read from configuration the database does not see - so that one
 * is the service's, and the service refuses rather than issuing a document
 * under a form that does not apply to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('simplified')->default(false);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE invoices
                ADD CONSTRAINT invoices_only_some_types_are_simplified
                    CHECK (NOT simplified OR document_type IN ('380', '381'));

            COMMENT ON COLUMN invoices.simplified IS
                'The reduced mandatory field set a jurisdiction permits - PF-101. The permitted fields and the value threshold are in config/jurisdictions.php; this column records which form the document was issued in.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_only_some_types_are_simplified');

        Schema::table('invoices', function (Blueprint $table): void {
            /*
             | @unsafe-migration-reviewed: this is the DOWN path of the
             | migration that added the column, so the only code that reads
             | it is code introduced with it. A rollback here runs against a
             | deployment being taken back to before the column existed,
             | which is the one case where dropping it immediately is the
             | point rather than the hazard.
             */
            $table->dropColumn('simplified');
        });
    }
};
