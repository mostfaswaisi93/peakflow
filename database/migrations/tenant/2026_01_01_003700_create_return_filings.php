<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A filed return, frozen - PF-086.
 *
 * @financial
 *
 * ## Why a snapshot and not a query
 *
 * A return is a query over the ledger until it is filed. After that it is a
 * statement the business made to an authority, and the two diverge the first
 * time anything in the period is adjusted: a credit note posted next week, a
 * correction to a rate, a reversal. Recompute the return then and the figures
 * no longer match what was submitted - so the business cannot answer a query
 * about its own filing, and the only evidence of what it said is a PDF
 * somebody saved.
 *
 * So filing writes the figures, the ids of every line behind them, and a hash
 * over the canonical form. Nothing about it can change afterwards, and the
 * reprint is byte-identical because it is the same bytes.
 *
 * ## `tax_point` on a journal line
 *
 * A return is assembled by tax point, and a journal line only knew its
 * accounting date. So a tax-bearing line carries the tax point that decides
 * which return it belongs to, and a CHECK pairs the two columns: a line with a
 * tax code must have a tax point, and a tax point without a tax code means
 * nothing.
 *
 * That pairing is what makes the return COMPLETE by construction - every
 * tax-bearing line falls in exactly one return period - rather than complete
 * as far as anybody has checked.
 *
 * ## The filing lines are rows, not a JSON array
 *
 * A quarter's return can stand behind tens of thousands of lines. As JSON the
 * drill-down would be read by loading the whole document and filtering it in
 * PHP; as rows it is an indexed lookup per box, which is how somebody actually
 * uses it - they click a box, not the return.
 *
 * @unsafe-migration-reviewed: adds a column, a CHECK and an index to the
 * partitioned `journal_lines`, which takes ACCESS EXCLUSIVE on the parent and
 * every partition. There is no concurrent form of ADD COLUMN, of ADD
 * CONSTRAINT or of CREATE INDEX on a partitioned table, so it cannot be split
 * per partition. It is applied while the schema is pre-release and no tenant
 * holds posted lines; back-filling it into a tenant with history needs a
 * maintenance window, and the pre-flight will say so again. The same reasoning
 * and the same conclusion as PF-068's rate columns in
 * 2026_01_01_001300_record_the_rate_on_the_line.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE journal_lines ADD COLUMN tax_point date;

            ALTER TABLE journal_lines
                ADD CONSTRAINT journal_lines_tax_point_pairs_with_the_code
                    CHECK ((tax_code_id IS NULL) = (tax_point IS NULL));

            COMMENT ON COLUMN journal_lines.tax_point IS
                'The date that decides which RETURN period this line falls into - not the accounting date, which decides the period it was booked in. PF-086.';

            CREATE INDEX journal_lines_tax_point_index
                ON journal_lines (entity_id, tax_point)
                WHERE tax_point IS NOT NULL;
        SQL);

        Schema::create('return_filings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('return_period_id')->constrained('return_periods')->restrictOnDelete();

            $table->char('jurisdiction', 2);
            $table->string('form', 32);
            $table->string('label', 16);
            $table->char('currency', 3);

            // The box-by-box figures, as filed.
            $table->jsonb('figures');

            /*
             | The canonical bytes, stored rather than recomputed.
             |
             | "Re-printable byte-identically" is a property of the bytes, and
             | recomputing them relies on the canonicaliser never changing -
             | which is a promise about future code, made in a column
             | comment, about a document an authority may query in six years.
             */
            $table->text('canonical');
            $table->char('hash', 64);

            $table->timestampTz('filed_at');
            $table->char('filed_by', 26);
            $table->string('submission_reference', 128)->nullable();

            $table->timestamps();

            $table->unique(['return_period_id']);
            $table->index(['entity_id', 'jurisdiction', 'label']);
        });

        Schema::create('return_filing_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('return_filing_id')->constrained('return_filings')->cascadeOnDelete();

            $table->string('box', 64);
            $table->char('journal_line_id', 26);
            $table->date('tax_point');
            $table->decimal('amount', 21, 4);

            $table->index(['return_filing_id', 'box']);
        });

        DB::unprepared(<<<'SQL'
            /*
             | A filing is a fact. There is no UPDATE and no DELETE.
             |
             | Not "no update to the figures" - no update at all. A filing with
             | a corrected submission reference is still a filing somebody
             | edited, and once one column can move the argument for the next
             | one is already made.
             */
            CREATE OR REPLACE FUNCTION refuse_to_change_a_filing() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Return filing % (% %) cannot be % . A filed return is a statement the business '
                    'made to an authority: it is superseded by a new submission, never edited. PF-087 '
                    'is the correction flow.',
                    OLD.id, OLD.jurisdiction, OLD.label,
                    CASE WHEN TG_OP = 'DELETE' THEN 'deleted' ELSE 'changed' END
                    USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER return_filings_are_immutable
                BEFORE UPDATE OR DELETE ON return_filings
                FOR EACH ROW
                EXECUTE FUNCTION refuse_to_change_a_filing();

            ALTER TABLE return_filings
                ADD CONSTRAINT return_filings_hash_is_a_digest
                    CHECK (hash ~ '^[0-9a-f]{64}$'),

                ADD CONSTRAINT return_filings_canonical_is_present
                    CHECK (length(canonical) > 0);

            ALTER TABLE return_filing_lines
                ADD CONSTRAINT return_filing_lines_amount_is_not_zero
                    CHECK (amount <> 0);
        SQL);

        foreach (['return_filings', 'return_filing_lines'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS return_filings_are_immutable ON return_filings;');
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_to_change_a_filing();');

        Schema::dropIfExists('return_filing_lines');
        Schema::dropIfExists('return_filings');

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS journal_lines_tax_point_index;

            ALTER TABLE journal_lines
                DROP CONSTRAINT IF EXISTS journal_lines_tax_point_pairs_with_the_code,
                DROP COLUMN IF EXISTS tax_point;
        SQL);
    }
};
