<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-wide settings, beside the per-entity ones - PF-074.
 *
 * ## Why two tables and not one with a nullable entity
 *
 * One table with a nullable `entity_id` is the obvious design and it loses
 * the only property worth having: a per-entity setting with a null entity
 * would be representable, and it would mean "all of them" - which is how one
 * company's write-off tolerance quietly becomes the group's.
 *
 * Two tables make the scope a fact about where the row lives. The registry
 * says which scope each key belongs to, and writing to the wrong one is
 * refused by name rather than accepted and resolved differently later.
 *
 * It also lets `entity_settings` keep its row-level security policy, which a
 * table with nullable entities cannot have: `entity_id = current_entity()` is
 * false for NULL, so every tenant-wide row would be invisible to the
 * application.
 *
 * ## The resolution order is entity, then tenant, then the declared default
 *
 * Three levels and no more. A fourth - a plan-level default, say - sounds
 * harmless and makes "why is this setting 30" a four-way search, which is
 * what a settings screen exists to avoid.
 *
 * ## What the pre-flight flags
 *
 * The `dropColumn` in `down()`. It is the rollback of the nullable column
 * this migration adds, so the only way to avoid the warning would be to
 * leave the column behind on a rollback - which leaves `Settings` writing a
 * column the code it rolled back to does not know about.
 *
 * @online-safe Creates one new table and adds one nullable column; no
 *              rewrite and no long lock.
 *
 * @unsafe-migration-reviewed: the only flagged statement is a `dropColumn`
 * in `down()`, which runs on a deliberate rollback. The column it drops is
 * the one this migration adds, and leaving it behind would leave the
 * application writing a column its rolled-back code does not know about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            /*
             | No entity_id, deliberately, and no RLS.
             |
             | These are the tenant's own decisions, read by every entity.
             | Scoping them to the current entity would make them invisible
             | whenever no entity is selected - which is the case during
             | provisioning, in a queued job and on the entity switcher
             | itself.
             */
            $table->string('key');
            $table->string('type', 24);
            $table->text('value')->nullable();

            // Who changed it last and when. The audit log holds the full
            // history; these two answer "who did this" without a join.
            $table->ulid('set_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['key']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE tenant_settings
                ADD CONSTRAINT tenant_settings_type_known
                    CHECK (type IN ('bool', 'int', 'decimal', 'string', 'json'));

            -- The same closed set on the per-entity table, which predates
            -- the registry and accepted any type string.
            ALTER TABLE entity_settings
                ADD CONSTRAINT entity_settings_type_known
                    CHECK (type IN ('bool', 'boolean', 'int', 'integer', 'decimal', 'string', 'json', 'array'))
                    NOT VALID;

            COMMENT ON TABLE tenant_settings IS
                'peakflow:tenant-scoped - the tenant''s own decisions, read by every entity. Deliberately '
                'without row-level security: scoping them to the current entity would hide them during '
                'provisioning, in a queued job and on the entity switcher itself.';
        SQL);

        /*
         | `set_by_user_id` on the per-entity table too.
         |
         | Added here rather than in the original migration because that one
         | has run everywhere; the column is nullable, so existing rows keep
         | saying "we do not know", which is true.
         */
        Schema::table('entity_settings', function (Blueprint $table): void {
            $table->ulid('set_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entity_settings', function (Blueprint $table): void {
            $table->dropColumn('set_by_user_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE entity_settings
                DROP CONSTRAINT IF EXISTS entity_settings_type_known;
        SQL);

        Schema::dropIfExists('tenant_settings');
    }
};
