<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How tightly a user asked for the interface to be packed - PF-203.
 *
 * Stored with the user rather than in the session, for the same reason the
 * theme is: somebody who works at `dense` works that way on the next machine
 * too, and a setting that has to be found again on every device is a setting
 * that is used once.
 *
 * NULL means "never chose", and the browser's `density` cookie or the default
 * (`compact`) applies. The default itself is NOT written here: a column
 * default would freeze today's answer into every row, so the day the product
 * changes its mind about which density is the default, every existing user
 * would keep the old one and nobody would be able to tell whether they had
 * chosen it.
 *
 * No CHECK constraint on the values, deliberately: an unknown value renders
 * as the default (`App\Support\Ui\Density::fromPreference`), so a mode removed
 * in a later release degrades to a readable page instead of failing a write.
 *
 * @online-safe Adds one nullable column with no default - a catalogue change
 *              in PostgreSQL, no table rewrite and no long lock.
 *
 * @unsafe-migration-reviewed: the only flagged statement is the `dropColumn`
 * in `down()`, which runs on a deliberate rollback. The column it drops is the
 * one this migration adds, and the code it rolls back to neither reads nor
 * writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('density', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('density');
        });
    }
};
