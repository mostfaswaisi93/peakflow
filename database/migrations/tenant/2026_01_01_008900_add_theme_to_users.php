<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The colour theme a user chose - UX-004.
 *
 * Stored with the user rather than in the session, so it follows them to
 * another device and survives signing out: a person who needs high contrast
 * needs it on the next machine too, and should not have to find the setting
 * again on a screen they cannot read.
 *
 * NULL means "never chose", and the browser's `theme` cookie or Automatic
 * applies. Automatic itself is stored as `auto`, not as NULL: otherwise a
 * person who chose Automatic on one machine would keep seeing whatever an old
 * cookie said on another, because NULL would send the lookup on to the cookie.
 *
 * No CHECK constraint on the values, deliberately: an unknown value renders as
 * automatic (`App\Support\Ui\Theme::fromPreference`), so a theme removed in a
 * later release degrades to something readable instead of failing a write.
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
            $table->string('theme', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }
};
