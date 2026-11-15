<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The group a party belongs to - PF-108, and PF-065 retrospectively.
 *
 * ## Why this column was missing
 *
 * `price_lists` has carried a `party_group` since PF-065, and `PriceResolver`
 * takes the group as an argument - which meant the caller had to know it, and
 * every caller passed null. A price list scoped to "distributors" was
 * therefore unreachable: the data model could express the rule and nothing
 * could evaluate it.
 *
 * Found while assigning dunning policies per group, which needs the same
 * answer to the same question. One column, read by both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->string('party_group', 32)->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE INDEX parties_by_group ON parties (party_group) WHERE party_group IS NOT NULL;

            COMMENT ON COLUMN parties.party_group IS
                'A commercial grouping - "distributors", "government" - that price lists (PF-065) and dunning policies (PF-108) can both be scoped to.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS parties_by_group');

        Schema::table('parties', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the DOWN path of the migration that
            // added the column.
            $table->dropColumn('party_group');
        });
    }
};
