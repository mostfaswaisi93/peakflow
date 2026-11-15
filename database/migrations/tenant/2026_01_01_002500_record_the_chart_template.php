<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which template an entity's chart came from - PF-039.
 *
 * ## The question this answers
 *
 * "Why does this subsidiary have an account the other one does not." Without
 * these two columns the answer is archaeology: compare the two charts, guess
 * which template each looked like, and hope neither was edited since.
 *
 * The VERSION is the half that earns its place. A template is data and data
 * gets corrected, so two entities provisioned a month apart can legitimately
 * differ - and that is a different situation from somebody having edited one
 * of them, which is the first thing anybody suspects.
 *
 * @online-safe Three nullable columns and one NOT VALID check. No rewrite,
 *              no backfill, no long lock.
 *
 * @unsafe-migration-reviewed: the only flagged statement is the `dropColumn`
 * in `down()`, which runs on a deliberate rollback. The columns it drops are
 * the three this migration adds, and leaving them behind would leave rolled
 * -back code looking at a provenance nothing maintains - which is worse than
 * no provenance, because it reads as current.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->string('chart_template', 64)->nullable();
            $table->unsignedInteger('chart_template_version')->nullable();
            $table->timestampTz('chart_applied_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN entities.chart_template IS
                'The key of the chart template this entity was provisioned from. Null on an entity whose '
                'chart was built by hand, which is a legitimate answer and a different one from "nobody '
                'recorded it".';

            COMMENT ON COLUMN entities.chart_template_version IS
                'The template''s version at the time it was applied. Two entities provisioned a month '
                'apart may differ because the template was corrected between them, which is not the same '
                'situation as somebody having edited one chart - and it is the first thing suspected.';

            /*
             | The three move together or not at all.
             |
             | A template recorded with no version cannot answer the question
             | the version exists for, and a version with no template names
             | nothing. Either all three are set or none is.
             */
            ALTER TABLE entities
                ADD CONSTRAINT entities_chart_provenance_is_complete CHECK (
                    (chart_template IS NULL
                        AND chart_template_version IS NULL
                        AND chart_applied_at IS NULL)
                    OR (chart_template IS NOT NULL
                        AND chart_template_version IS NOT NULL
                        AND chart_applied_at IS NOT NULL)
                ) NOT VALID;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE entities DROP CONSTRAINT IF EXISTS entities_chart_provenance_is_complete');

        Schema::table('entities', function (Blueprint $table): void {
            $table->dropColumn(['chart_template', 'chart_template_version', 'chart_applied_at']);
        });
    }
};
