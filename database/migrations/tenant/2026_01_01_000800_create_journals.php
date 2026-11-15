<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journals and their numbering series - PF-040.
 *
 * @financial
 *
 * ## A journal is a book, and some books are not written in by hand
 *
 * `opening`, `closing` and `revaluation` are RESERVED: they accept postings
 * only from the process that owns them. An opening balance entered by hand
 * into the opening journal six months into the year is indistinguishable, in
 * the accounts, from the real opening balance - and it will be found by
 * someone comparing this year's opening to last year's closing, which is a
 * year later.
 *
 * ## Account restrictions
 *
 * A journal may be restricted to a set of account paths, so a cash journal
 * cannot post revenue directly. The restriction is by PATH prefix rather than
 * by a list of accounts, because a chart grows and a list does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            $table->string('code', 16);
            $table->string('type', 16);
            $table->string('name_ar');
            $table->string('name_en');

            // Reserved journals accept postings only from their own process.
            $table->boolean('reserved')->default(false);

            /*
             | Allowed account path prefixes, or null for no restriction.
             |
             | By prefix so that adding an account under an allowed parent
             | does not require editing every journal that should accept it.
             */
            $table->json('allowed_account_paths')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'type']);
        });

        /*
         | Gapless numbering - ADR-0009.
         |
         | One row per series, locked with SELECT ... FOR UPDATE while a
         | number is allocated. A sequence would be wrong here: `nextval()` is
         | deliberately non-transactional, so a rolled-back entry burns its
         | number and leaves a gap - and a gap in a statutory numbering series
         | is a question from an auditor that nobody can answer, because the
         | missing document is indistinguishable from a deleted one.
         */
        Schema::create('number_series', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            $table->string('scope', 32);
            $table->string('key', 64);

            $table->string('prefix', 16)->default('');
            $table->smallInteger('padding')->default(5);
            $table->bigInteger('next_number')->default(1);

            // A series restarts per year where the jurisdiction requires it,
            // and must not where it does not - restarting a series that
            // should be continuous creates duplicate document numbers across
            // years.
            $table->boolean('resets_annually')->default(true);
            $table->smallInteger('year')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'scope', 'key', 'year']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE journals
                ADD CONSTRAINT journals_type_known
                    CHECK (type IN ('sales', 'purchases', 'cash', 'bank', 'general', 'opening', 'closing', 'revaluation'));

            ALTER TABLE number_series
                ADD CONSTRAINT number_series_next_positive CHECK (next_number >= 1),
                ADD CONSTRAINT number_series_padding_sane CHECK (padding BETWEEN 0 AND 12);
        SQL);

        foreach (['journals', 'number_series'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('number_series');
        Schema::dropIfExists('journals');
    }
};
