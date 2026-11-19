<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expected credit loss - PF-173, IFRS 9's simplified approach.
 *
 * ## A provision is an estimate; a write-off is a conclusion
 *
 * The distinction this whole task turns on. A provision says a portion of
 * what is owed probably will not arrive and is revised every period; a
 * write-off says a particular debt will not, and is final.
 *
 * They post to different accounts for that reason, and a write-off
 * **releases** the provision that covered its document rather than
 * charging the loss a second time. Without the release, the period of a
 * write-off carries both the estimate and the conclusion - and the
 * impairment line reads as twice the loss that happened.
 *
 * ## Reversed and recomputed, never accumulated
 *
 * Each run reverses the one before it in full and posts a fresh figure.
 * Accumulating movements is the arrangement that drifts: a rate change, a
 * regraded customer or a corrected invoice each leave a residue, and
 * after four quarters the provision is the sum of four approximations
 * nobody can take apart.
 *
 * The cost is more journal entries. What it buys is that the provision on
 * any date is one entry, derived from one matrix and one set of open
 * documents - which is what makes it re-performable.
 *
 * ## The matrix is data, and the lines are kept
 *
 * A rate per ageing bucket per risk grade, maintained by whoever is
 * accountable for the estimate rather than by a release. And every run
 * writes the figure it computed **per document**, because a provision
 * total nobody can decompose is a number somebody has to defend to an
 * auditor by re-deriving it - and that is also what lets a write-off
 * release exactly what covered its own document.
 *
 * @financial
  *
 * @online-safe Creates `ecl_matrix`, `ecl_provision_lines`, `ecl_releases`, `ecl_runs`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecl_matrix', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | The grade, or null for "whatever a customer has no grade".
             |
             | Most customers are ungraded in most businesses, and a
             | matrix that only covered graded ones would provision
             | nothing for the majority of the ledger. Null is that row,
             | and it is the fallback rather than an omission.
             */
            $table->string('risk_grade', 8)->nullable();
            $table->string('bucket', 12);

            /*
             | A proportion, stored at rate scale.
             |
             | `decimal(9,6)` holds 0.001500 - fifteen basis points - and
             | a loss rate of 100% exactly. Not a float: a rate that has
             | been through one is a rate whose product nobody can
             | reproduce.
             */
            $table->decimal('rate', 9, 6);

            $table->text('basis')->nullable();
            $table->ulid('set_by')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'risk_grade', 'bucket']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE ecl_matrix
                ADD CONSTRAINT ecl_matrix_bucket_is_known
                    CHECK (bucket IN ('current', '1-30', '31-60', '61-90', '90+')),

                ADD CONSTRAINT ecl_matrix_grade_is_known
                    CHECK (risk_grade IS NULL
                           OR risk_grade IN ('A', 'B', 'C', 'D', 'E', 'watch', 'default')),

                /*
                 | A rate is a proportion between nothing and everything.
                 |
                 | Above 1 would provision more than is owed, which is not
                 | a loss estimate - it is an arithmetic error that
                 | produces a negative receivable on the balance sheet.
                 */
                ADD CONSTRAINT ecl_matrix_rate_is_a_proportion
                    CHECK (rate >= 0 AND rate <= 1);

            /*
             | One ungraded row per bucket, enforced.
             |
             | The unique index above treats two NULL grades as distinct,
             | which is SQL being consistent and useless here: two
             | fallback rows for one bucket means the rate applied depends
             | on which row a query happened to read first.
             */
            CREATE UNIQUE INDEX ecl_matrix_one_ungraded_row_per_bucket
                ON ecl_matrix (entity_id, bucket)
                WHERE risk_grade IS NULL;

            COMMENT ON TABLE ecl_matrix IS
                'Loss rate per ageing bucket per risk grade, maintained as data by whoever is accountable for the estimate - PF-173.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('ecl_matrix')");

        Schema::create('ecl_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('period_label', 32);
            $table->date('as_of');
            $table->char('currency', 3);

            /*
             | What this run concluded, and what it replaced.
             |
             | `supersedes_id` points back at the run this one reversed,
             | so the chain reads in the direction somebody asks it in:
             | this is the provision now, and here is the one it replaced.
             */
            $table->decimal('provision', 21, 4);
            $table->integer('documents');
            $table->char('supersedes_id', 26)->nullable();

            $table->ulid('journal_entry_id')->nullable();
            $table->ulid('reversal_entry_id')->nullable();

            $table->ulid('computed_by');
            $table->timestamp('computed_at');

            $table->timestamps();

            $table->index(['entity_id', 'as_of']);
            $table->unique(['entity_id', 'period_label']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE ecl_runs
                ADD CONSTRAINT ecl_runs_provision_is_not_negative
                    CHECK (provision >= 0),

                ADD CONSTRAINT ecl_runs_documents_is_not_negative
                    CHECK (documents >= 0);

            COMMENT ON TABLE ecl_runs IS
                'One provision per period, reversing the one before it in full - PF-173. Accumulating movements leaves a residue after every rate change and regrade, and four quarters later nobody can take the figure apart.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('ecl_runs')");

        Schema::create('ecl_provision_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('run_id', 26);

            $table->string('document_type', 24);
            $table->char('document_id', 26);
            $table->char('party_id', 26)->nullable();

            /*
             | The inputs beside the output.
             |
             | The grade, the bucket, the rate and the balance they were
             | applied to. A provision total nobody can decompose is a
             | number somebody has to defend by re-deriving it, and by
             | then the rates may have moved.
             */
            $table->string('risk_grade', 8)->nullable();
            $table->string('bucket', 12);
            $table->decimal('rate', 9, 6);
            $table->decimal('open_balance', 21, 4);
            $table->decimal('provision', 21, 4);
            $table->integer('days_past_due');

            $table->timestamps();

            $table->index(['run_id']);
            $table->index(['entity_id', 'document_type', 'document_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE ecl_provision_lines
                ADD CONSTRAINT ecl_provision_lines_provision_is_not_negative
                    CHECK (provision >= 0),

                /*
                 | The arithmetic, asserted on the way in.
                 |
                 | Rounded to the amount scale, so the stored provision is
                 | the rate applied to the balance and not a figure that
                 | drifted through a presentation step. A line that does
                 | not satisfy this is a line whose rate and result
                 | disagree, which is the one error an auditor
                 | re-performing the matrix would find and nobody else
                 | would.
                 */
                ADD CONSTRAINT ecl_provision_lines_arithmetic_holds
                    CHECK (provision = round(open_balance * rate, 4));

            COMMENT ON TABLE ecl_provision_lines IS
                'The figure each run computed per document, with the grade, bucket, rate and balance behind it - so a provision can be decomposed rather than re-derived - PF-173.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('ecl_provision_lines')");

        Schema::create('ecl_releases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | A provision released because its document was written off.
             |
             | Utilization, in roll-forward language: the estimate turned
             | out to be right about this one, so the provision is used
             | rather than charged again. Without it the period carries
             | both the estimate and the conclusion, and the impairment
             | line reads as twice the loss that happened.
             */
            $table->char('write_off_id', 26);
            $table->char('document_type', 24);
            $table->char('document_id', 26);

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);
            $table->date('released_on');

            $table->char('from_run_id', 26)->nullable();
            $table->ulid('journal_entry_id')->nullable();

            $table->timestamps();

            $table->unique(['write_off_id']);
            $table->index(['entity_id', 'released_on']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE ecl_releases
                ADD CONSTRAINT ecl_releases_amount_is_positive
                    CHECK (amount > 0);

            COMMENT ON TABLE ecl_releases IS
                'Provision used up by a write-off - the estimate was right about this one, so it is utilized rather than charged again - PF-173.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('ecl_releases')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ecl_releases');
        Schema::dropIfExists('ecl_provision_lines');
        Schema::dropIfExists('ecl_runs');
        Schema::dropIfExists('ecl_matrix');
    }
};
