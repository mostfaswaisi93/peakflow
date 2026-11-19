<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the scheduler actually did - PF-241, PF-238.
 *
 * ## Named as missing twice before it was built
 *
 * PF-241 asks for "failed scheduled runs by task and tenant" and PF-238's
 * evidence pack has a `not_included` entry saying the same thing: the
 * failures raise `scheduled_job_failed` through the alert sink, so they
 * reach a log - and a section whose completeness depends on log retention is
 * not evidence.
 *
 * This is the queryable history those two were missing. One row per run,
 * written when it finishes, by the scheduler itself.
 *
 * ## On the platform plane, not the tenant's
 *
 * The schedule is fleet-wide: `invariants:watch --all` is one run over every
 * tenant, not one run per tenant. Writing this per tenant would mean fifteen
 * rows claiming to be fifteen runs, and the figure somebody wants - "did the
 * nightly chain walk happen" - would have to be reassembled from them.
 *
 * `tenant` is therefore nullable and is filled only where a run really is
 * about one.
 *
 * ## It records the exit code, not a verdict
 *
 * A command exits non-zero for two different reasons - it found something,
 * or it could not run - and the distinction is in its output rather than its
 * code. Storing a boolean "failed" here would throw away the difference that
 * matters, and the operator reading this wants the code they would have seen
 * on a console.
 *
 * @online-safe Creates one table. Nothing reads it until the scheduler
 *              writes to it, which is after this has run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            $table->string('command', 191);
            $table->string('tenant', 64)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->unsignedInteger('duration_ms');

            /*
             | Nullable, because a run the scheduler never started has no
             | code - and that is a different fact from exiting non-zero.
             | `withoutOverlapping` skipping a run is the common case.
             */
            $table->integer('exit_code')->nullable();

            $table->timestamps();

            $table->index(['command', 'finished_at']);
            $table->index(['exit_code', 'finished_at']);
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE scheduled_runs IS
                'One row per finished scheduled run. The queryable history that PF-241 asks for and PF-238 named as missing: the failures also raise an alert, and an alert is a notification rather than a record.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_runs');
    }
};
