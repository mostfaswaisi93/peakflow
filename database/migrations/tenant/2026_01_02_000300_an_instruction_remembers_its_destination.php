<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the beneficiary account looked like when the run was prepared - PF-175.
 *
 * ## The clock cannot answer this question
 *
 * PF-175 asks that the releaser be shown exactly what changed since
 * preparation, and the first attempt compared the account's `updated_at`
 * against the run's `prepared_at`. Both are stored to the second, so an
 * account changed in the same second as the preparation compared equal
 * and reported nothing. In production the gap is days and it would have
 * worked for years; the check that exercised it found the hole on the
 * first run.
 *
 * And a timestamp is the wrong question anyway. "Was this row written
 * after that one" is not "is the money still going where somebody
 * checked" - a row rewritten with the same content has changed nothing,
 * and a row changed and changed back is also nothing.
 *
 * ## So the instruction remembers the destination
 *
 * A hash of what identifies the account: its tail, its BIC, its country
 * and whether it is active. The review recomputes it and compares, which
 * is clock-free, survives a replica read, and says nothing about a
 * bookkeeping column being touched.
 *
 * Deliberately **not** over `verified_at`. An account re-verified with no
 * change to where the money goes is not a change to where the money goes,
 * and blocking on it would be noise - whereas details changed and not
 * re-verified has its own branch and a better message.
 *
 * Nullable, because runs released before this column existed have no
 * fingerprint to compare and must not start reporting a change that
 * nothing can describe.
 *
 * @financial-not-applicable: one hash column on an existing entity-scoped table with its own forced RLS policy; no amount or balance is involved
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_run_instructions', function (Blueprint $table): void {
            $table->char('account_fingerprint', 64)->nullable();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN payment_run_instructions.account_fingerprint IS
                'sha-256 of the beneficiary account''s tail, BIC, country and active flag as they stood when the run was prepared - so "has the destination changed" is answered by content rather than by a clock that only counts seconds - PF-175.';
        SQL);
    }

    public function down(): void
    {
        Schema::table('payment_run_instructions', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a forward-only migration. The column is added by this migration's own up(), so dropping it restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn('account_fingerprint');
        });
    }
};
