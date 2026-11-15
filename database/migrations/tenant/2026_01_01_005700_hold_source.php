<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who placed a credit hold: a person, or the dunning policy - PF-109.
 *
 * ## Why a column and not the reason text
 *
 * PF-109 asks for a hold "applied and released automatically by policy, with
 * a manual override that is permissioned and audited". The automatic release
 * therefore has to know which holds are its own, and the only thing that
 * distinguished them was the opening words of a free-text reason. A nightly
 * job that decides whether to lift a credit hold by matching a prefix in
 * prose is a job that lifts a hold the credit manager placed by hand the
 * first time somebody rewords a template.
 *
 * So the source is recorded, defaults to `manual` - the value that must not
 * need thinking about - and the release only ever touches `dunning`.
 *
 * ## Not `@financial`
 *
 * The tag is for a migration that CREATES a financial table: it requires an
 * `entity_id` and a call to `peakflow_apply_entity_rls()`, and the check that
 * enforces it refused this file until the tag came off. Rightly -
 * `credit_hold_events` was created with both in PF-062, and declaring them
 * again here would either be a no-op or a second, divergent answer to which
 * entity owns a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_hold_events', function (Blueprint $table): void {
            $table->string('source', 16)->default('manual');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE credit_hold_events
                ADD CONSTRAINT credit_hold_events_source_known
                CHECK (source IN ('manual', 'dunning'))
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN credit_hold_events.source IS
                'Who decided: a person (manual) or the dunning policy (dunning). The automatic release lifts only its own holds - PF-109.'
        SQL);
    }

    public function down(): void
    {
        Schema::table('credit_hold_events', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: this is the rollback of a column
            // added in the same migration, so nothing that was deployed
            // before it can be reading the column it drops.
            $table->dropColumn('source');
        });
    }
};
