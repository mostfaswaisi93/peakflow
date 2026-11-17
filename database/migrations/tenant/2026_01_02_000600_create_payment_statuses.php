<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the bank said about each instruction - PF-178, PF-179.
 *
 * ## Down to the instruction, not the batch
 *
 * A PAIN.002 reports a group status and then, usually, a status per
 * transaction - and the interesting case is the mixed one: thirty-eight
 * instructions accepted and two refused for an account that no longer
 * exists.
 *
 * A product that read only the group status would mark the whole batch
 * pending, or worse accepted, and two suppliers would go unpaid with
 * nothing in the system saying so. So the status is stored per
 * instruction and the group status is stored beside it rather than
 * instead of it.
 *
 * ## Unmatched statuses are kept, not dropped
 *
 * A status report naming an instruction we cannot find is the most
 * interesting row in the file: a file somebody submitted twice, a
 * reference the bank mangled, a report for another company's payment
 * routed to us. Discarding it loses the only evidence that any of those
 * happened.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_status_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('message_id', 35);
            $table->string('original_message_id', 35)->nullable();
            $table->char('file_id', 26)->nullable();
            $table->char('run_id', 26)->nullable();

            $table->string('group_status', 8)->nullable();
            $table->timestamp('reported_at');

            $table->text('content');
            $table->char('sha256', 64);

            $table->integer('statuses');
            $table->integer('matched');
            $table->integer('unmatched');

            $table->ulid('ingested_by');
            $table->timestamps();

            /*
             | One report per message id, per entity.
             |
             | A bank that resends a report - which happens - must not
             | apply it twice: the second application would reverse an
             | in-transit posting that was already reversed, and the
             | payable would come back owed twice.
             */
            $table->unique(['entity_id', 'message_id']);
            $table->index(['entity_id', 'run_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_status_reports
                ADD CONSTRAINT payment_status_reports_hash_is_a_digest
                    CHECK (sha256 ~ '^[0-9a-f]{64}$'),

                ADD CONSTRAINT payment_status_reports_counts_tie
                    CHECK (statuses = matched + unmatched AND statuses >= 0);

            COMMENT ON TABLE payment_status_reports IS
                'A PAIN.002 as it arrived, with its own hash. Unique per message id because a bank that resends one must not apply it twice - PF-178.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('payment_status_reports')");

        Schema::create('instruction_statuses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('report_id', 26);

            /*
             | Nullable, because an unmatched status is the point.
             |
             | A status naming an instruction we cannot find is kept with
             | the reference the bank used, so somebody can work out which
             | of the three usual causes it was.
             */
            $table->char('instruction_id', 26)->nullable();
            $table->string('bank_reference', 35)->nullable();

            $table->string('status', 8);
            $table->string('reason_code', 16)->nullable();
            $table->text('reason')->nullable();

            $table->decimal('amount', 21, 4)->nullable();
            $table->char('currency', 3)->nullable();

            $table->ulid('journal_entry_id')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'report_id']);
            $table->index(['entity_id', 'instruction_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE instruction_statuses
                /*
                 | The ISO 20022 status codes this release acts on.
                 |
                 | ACCP and ACSC mean the bank took it; RJCT means it
                 | refused it; PDNG means not yet. Anything else is a code
                 | we have not taught the product, and storing it under a
                 | permitted value would make it look handled.
                 */
                ADD CONSTRAINT instruction_statuses_status_is_known
                    CHECK (status IN ('ACCP', 'ACSP', 'ACSC', 'ACTC', 'ACWC', 'PDNG', 'RJCT', 'PART')),

                /*
                 | A rejection carries the bank's reason code.
                 |
                 | It is what tells a closed account from a wrong IBAN
                 | from a duplicate - three facts with three different
                 | responses, and a rejection without one is a payment
                 | somebody has to telephone the bank about.
                 */
                ADD CONSTRAINT instruction_statuses_rejections_say_why
                    CHECK (status <> 'RJCT' OR reason_code IS NOT NULL),

                /*
                 | And a status names something.
                 |
                 | Either the instruction it is about, or the reference the
                 | bank used - a row with neither cannot be investigated
                 | or applied, so it is the one row that must not exist.
                 */
                ADD CONSTRAINT instruction_statuses_name_something
                    CHECK (instruction_id IS NOT NULL OR bank_reference IS NOT NULL);

            /*
             | One live status per instruction, per report.
             |
             | A report that named the same instruction twice with
             | different outcomes is a report to refuse rather than
             | average.
             */
            CREATE UNIQUE INDEX instruction_statuses_once_per_report
                ON instruction_statuses (report_id, instruction_id)
                WHERE instruction_id IS NOT NULL;
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('instruction_statuses')");

        Schema::table('payment_run_instructions', function (Blueprint $table): void {
            /*
             | Where this instruction stands with the bank.
             |
             | On the instruction rather than only in the status table,
             | because "which of these forty did not go" is a question
             | asked against the run and a join to the latest status per
             | instruction is a query nobody writes correctly twice.
             */
            $table->string('bank_status', 8)->nullable();
            $table->string('bank_reason_code', 16)->nullable();
            $table->date('cleared_on')->nullable();
            $table->ulid('transit_entry_id')->nullable();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN payment_run_instructions.bank_status IS
                'The last ISO 20022 status the bank reported for this instruction. Null means no report has arrived, which is different from PDNG - PF-178.';
        SQL);
    }

    public function down(): void
    {
        Schema::table('payment_run_instructions', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a forward-only migration. These four columns are added by this migration's own up(), so dropping them restores the shape that existed before it ran; nothing deployed reads a column this migration introduced.
            $table->dropColumn(['bank_status', 'bank_reason_code', 'cleared_on', 'transit_entry_id']);
        });

        Schema::dropIfExists('instruction_statuses');
        Schema::dropIfExists('payment_status_reports');
    }
};
