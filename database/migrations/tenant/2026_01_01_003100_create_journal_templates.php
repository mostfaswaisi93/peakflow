<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal templates and recurring journals - PF-055.
 *
 * ## Why a template is a row and not a saved draft
 *
 * The accrual posted every month is the same shape with a different amount,
 * and the way people do it without a template is to find last month's entry
 * and copy it. That works until somebody copies the one that was reversed, or
 * the one from before the chart changed - and the copy carries the original's
 * date, which is then edited, usually correctly.
 *
 * A template is the shape without the amounts: which accounts, which side,
 * which dimensions, in which order. The amounts come from whoever posts it.
 *
 * ## A recurring journal is a template with a schedule, and it does not post
 * by itself
 *
 * It PREPARES a draft and tells somebody. An entry appearing in the ledger
 * because a date passed is an entry nobody decided to make, and the first
 * time it is wrong is the month a rate changed and nobody looked.
 *
 * The one exception people ask for - "just post it, it is the same every
 * month" - is exactly the case where nobody notices for a year.
 *
 * ## The lines hold a SHARE, not an amount
 *
 * A template line carries a proportion of the entry's total rather than a
 * figure, so one amount drives the whole entry and the shares are what make
 * it balance. Storing amounts would mean a template that balanced when it was
 * written and silently does not after somebody edits one line.
 *
 * Shares are checked to sum to zero at the line level the same way a posting
 * is: the debits' shares and the credits' shares cancel.
 *
 * @online-safe Two new tables and one deferred constraint trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_templates', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('entity_id', 26);

            $table->string('key', 64);
            $table->string('name_ar', 160);
            $table->string('name_en', 160);
            $table->string('narration', 512)->nullable();
            $table->char('journal_code', 8)->default('GEN');

            /*
             | The currency the template is written in, or null for "the
             | entity's own".
             |
             | Null rather than copying the functional currency, because an
             | entity's functional currency can be corrected before it has
             | posted anything - and a template that captured the old one
             | would then be a template in a currency the entity does not
             | keep books in.
             */
            $table->char('currency', 3)->nullable();

            // A recurring template: when it next prepares a draft.
            $table->string('recurrence', 32)->nullable();
            $table->date('next_run_on')->nullable();
            $table->date('recurs_until')->nullable();
            $table->timestampTz('last_prepared_at')->nullable();

            $table->boolean('active')->default(true);
            $table->char('created_by_user_id', 26)->nullable();
            $table->timestampsTz();

            $table->unique(['entity_id', 'key']);
            $table->index(['entity_id', 'next_run_on']);
        });

        Schema::create('journal_template_lines', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('entity_id', 26);
            $table->char('journal_template_id', 26);

            $table->unsignedSmallInteger('position');
            $table->char('account_id', 26);

            /*
             | A share of the entry's total, signed. Debits positive.
             |
             | Scaled like a rate rather than like money, because a third of
             | an accrual is 0.333333333333 and rounding it at four places
             | would leave a residual the entry has to absorb somewhere.
             */
            $table->decimal('share', 21, 12);

            $table->string('narration', 512)->nullable();
            $table->char('dimension_1', 26)->nullable();
            $table->char('dimension_2', 26)->nullable();
            $table->char('dimension_3', 26)->nullable();
            $table->char('dimension_4', 26)->nullable();
            $table->char('dimension_5', 26)->nullable();
            $table->char('dimension_6', 26)->nullable();
            $table->char('dimension_7', 26)->nullable();
            $table->char('dimension_8', 26)->nullable();
            $table->timestampsTz();

            $table->unique(['journal_template_id', 'position']);
            $table->index(['entity_id', 'journal_template_id']);
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE journal_templates IS
                'peakflow:tenant-scoped - the shape of a recurring entry without its amounts. A recurring '
                'template PREPARES a draft and tells somebody; it does not post. An entry appearing in '
                'the ledger because a date passed is an entry nobody decided to make, and the first time '
                'it is wrong is the month a rate changed and nobody looked.';

            COMMENT ON COLUMN journal_template_lines.share IS
                'A signed proportion of the entry total, debits positive. A share rather than an amount, '
                'so one figure drives the whole entry - storing amounts would mean a template that '
                'balanced when it was written and silently does not after somebody edits one line.';

            ALTER TABLE journal_templates
                ADD CONSTRAINT journal_templates_recurrence_is_complete CHECK (
                    (recurrence IS NULL AND next_run_on IS NULL)
                    OR (recurrence IS NOT NULL AND next_run_on IS NOT NULL)
                ),
                ADD CONSTRAINT journal_templates_recurrence_is_known CHECK (
                    recurrence IS NULL
                    OR recurrence IN ('monthly', 'quarterly', 'annually')
                );

            ALTER TABLE journal_template_lines
                ADD CONSTRAINT journal_template_lines_share_is_not_zero CHECK (share <> 0);

            /*
             | The shares must cancel, checked at COMMIT.
             |
             | Deferred for the same reason the balanced-entry trigger is: a
             | template is transiently unbalanced while its lines are being
             | inserted, and a per-statement check would reject every
             | template with more than one line.
             |
             | A template whose shares do not cancel produces an unbalanced
             | entry every time anybody uses it - and the refusal would
             | arrive at the posting, naming the entry rather than the
             | template that caused it.
             */
            CREATE OR REPLACE FUNCTION assert_template_shares_cancel()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                total numeric(21,12);
                lines integer;
                template record;
            BEGIN
                SELECT coalesce(sum(share), 0), count(*)
                INTO total, lines
                FROM journal_template_lines
                WHERE journal_template_id = NEW.journal_template_id;

                -- Every line gone: the template was deleted with them.
                IF lines = 0 THEN
                    RETURN NULL;
                END IF;

                IF total = 0 AND lines >= 2 THEN
                    RETURN NULL;
                END IF;

                SELECT key, name_en INTO template
                FROM journal_templates
                WHERE id = NEW.journal_template_id;

                IF lines < 2 THEN
                    RAISE EXCEPTION
                        'Template "%" has one line. An entry needs at least two, and a template that '
                        'produces one produces an entry the balanced-entry constraint refuses - naming '
                        'the entry rather than the template that caused it.',
                        coalesce(template.key, NEW.journal_template_id)
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION
                    'The shares in template "%" sum to % rather than zero. Every entry made from it '
                    'would be out by that proportion of its total, and the refusal would arrive at the '
                    'posting with nothing to say which template was wrong.',
                    coalesce(template.key, NEW.journal_template_id), total
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE CONSTRAINT TRIGGER journal_template_lines_shares_cancel
                AFTER INSERT OR UPDATE OR DELETE ON journal_template_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION assert_template_shares_cancel();
        SQL);

        foreach (['journal_templates', 'journal_template_lines'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_template_lines_shares_cancel ON journal_template_lines;
            DROP FUNCTION IF EXISTS assert_template_shares_cancel();
        SQL);

        Schema::dropIfExists('journal_template_lines');
        Schema::dropIfExists('journal_templates');
    }
};
