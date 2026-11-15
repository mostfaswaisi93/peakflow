<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the company is, in time - PF-106.
 *
 * ## Why this is on the entity and not on the tenant
 *
 * A group with a company in Riyadh and one in Casablanca is one tenant with
 * two entities, and "daily at 01:00" means a different moment for each. A
 * tenant-level timezone would bill one of them on the wrong date - for a week
 * each March and October, by one day, which is the hardest kind of wrong to
 * notice because every other week of the year it is right.
 *
 * ## Why a schedule copies it rather than reading it
 *
 * Changing the company's timezone should not retroactively move the billing
 * dates of every schedule that already exists. The schedule takes a copy when
 * it is created, the same decision as every other snapshot in this system,
 * and a schedule that should follow a move is edited deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('UTC');
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN entities.timezone IS
                'An IANA identifier - PF-106. Scheduled work runs at the local time here, because "daily at 01:00" means 01:00 where the business is.';
        SQL);
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the DOWN path of the migration that
            // added the column, so the only code reading it arrived with it.
            $table->dropColumn('timezone');
        });
    }
};
