<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seller's electronic address scheme - PF-120, EN 16931 BT-34.
 *
 * ## Why this column exists, and what it proves
 *
 * The Peppol profile needs `BT-34`, the seller's electronic address with its
 * EAS scheme code: it is the participant identifier the network resolves to
 * an access point, and `PEPPOL-EN16931-R020` refuses a document without one.
 *
 * `party_tax_registrations` has carried `eas_code` since PF-065 - the buyer
 * side was always there - and the entity's own registrations never did. So
 * every document PeakFlow could build would have failed R020, and the first
 * time anybody found out would have been the first Peppol document.
 *
 * It is a column rather than a profile reading something out of
 * configuration, because that is the rule PF-118 sets: when a profile needs
 * something the document does not carry, the thing is added to the document
 * and frozen at issue. A profile that reached for a config value would make
 * last year's documents reserialize with this year's address.
 *
 * @financial-not-applicable: `tax_registrations` was created with its
 * `entity_id` and its RLS policy in PF-025; this adds one nullable column to
 * it and declares neither again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_registrations', function (Blueprint $table): void {
            $table->string('eas_code', 8)->nullable();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN tax_registrations.eas_code IS
                'EN 16931 BT-34: the EAS scheme of the entity electronic address, four digits. Peppol routes on it - PF-120.'
        SQL);
    }

    public function down(): void
    {
        Schema::table('tax_registrations', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a column added in
            // this same migration, so nothing deployed before it can read it.
            $table->dropColumn('eas_code');
        });
    }
};
