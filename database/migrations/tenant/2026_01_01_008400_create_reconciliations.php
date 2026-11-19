<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reconciliation is a document - PF-165.
 *
 * ## What an auditor re-performs
 *
 * Not "the bank account was reconciled on the 3rd". A reconciliation they
 * can re-perform needs the statement it was done against, the balance it
 * agreed to, the ledger balance at that moment, every reconciling item
 * that bridged the two, who authorised it and when - and it needs all of
 * those **as they stood**, because that is what re-performance means.
 *
 * A screen that recomputes the figures when you open it answers a
 * different question: not "was this correct in May" but "is it correct
 * now". The second is useful and it is not the one an audit asks.
 *
 * ## So the figures are frozen, and that is the whole design
 *
 * `ledger_balance`, `statement_balance` and the four itemised components
 * are stored. Recomputing them later would silently restate a
 * reconciliation every time a prior-period entry was posted - and a
 * reconciliation that restates itself cannot be evidence of anything.
 *
 * The arithmetic is **asserted on the way in** rather than displayed: the
 * identity has to hold at the moment of reconciling, and a row that does
 * not satisfy it is a row the table refuses.
 *
 * ## Unreconciling keeps the old one
 *
 * A superseded reconciliation is retained with a reason and an author. The
 * question an auditor asks is "it was reconciled and then it was not -
 * what changed", and a table that overwrites cannot answer it.
 *
 * @financial
  *
 * @online-safe Creates `reconciliations`, `rule_candidates`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('bank_account_id', 26);

            /*
             | The statement it was reconciled against.
             |
             | By id and by period, because a reconciliation is of a
             | statement and not of a date range somebody typed. The
             | period is carried so the document reads without a join -
             | an auditor's first question is which statement, and it
             | should not need a second query.
             */
            $table->char('bank_statement_id', 26);
            $table->date('period_from');
            $table->date('period_to');

            /*
             | The three balances and the four components, frozen.
             |
             | ledger = statement - unpresented + unsettled - unrecorded
             |          - unposted opening
             |
             | Stored rather than recomputed. A reconciliation that
             | restates itself when somebody posts a prior-period entry is
             | not evidence; it is a screen.
             */
            $table->decimal('ledger_balance', 21, 4);
            $table->decimal('statement_balance', 21, 4);
            $table->decimal('unpresented', 21, 4);
            $table->decimal('unsettled', 21, 4);
            $table->decimal('unrecorded', 21, 4);
            $table->decimal('unposted_opening', 21, 4);
            $table->char('currency', 3);

            $table->integer('matched_lines');
            $table->integer('unexplained_lines');

            $table->ulid('authorised_by');
            $table->timestamp('authorised_at');

            /*
             | Superseded, not deleted.
             |
             | `superseded_by_id` points forward to the reconciliation
             | that replaced this one, so the chain reads in the
             | direction somebody asks it in: "this is the current one,
             | what was there before".
             */
            $table->timestamp('undone_at')->nullable();
            $table->ulid('undone_by')->nullable();
            $table->text('undone_reason')->nullable();
            $table->char('superseded_by_id', 26)->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'bank_account_id', 'period_to']);
            $table->index(['entity_id', 'authorised_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE reconciliations
                /*
                 | The identity, asserted rather than displayed.
                 |
                 | PF-165 asks for exactly this. A reconciliation statement
                 | that shows the arithmetic and does not check it is a
                 | page somebody reads past; the figures agree or the row
                 | does not exist.
                 |
                 | Written with every term on one side so the comparison is
                 | against zero: floating a subtraction across an equals
                 | sign in SQL invites a reading where one side rounds and
                 | the other does not.
                 */
                ADD CONSTRAINT reconciliations_balance
                    CHECK (
                        ledger_balance
                        - statement_balance
                        + unpresented
                        - unsettled
                        + unrecorded
                        + unposted_opening = 0
                    ),

                ADD CONSTRAINT reconciliations_period_runs_forwards
                    CHECK (period_to >= period_from),

                ADD CONSTRAINT reconciliations_counts_are_not_negative
                    CHECK (matched_lines >= 0 AND unexplained_lines >= 0),

                /*
                 | Undoing one is explained, like every other reversal.
                 |
                 | And the three columns move together: an `undone_at`
                 | with no author or no reason is a reversal nobody can be
                 | asked about, which is the state this whole table exists
                 | to make impossible.
                 */
                ADD CONSTRAINT reconciliations_undoing_is_explained
                    CHECK (
                        (undone_at IS NULL AND undone_by IS NULL AND undone_reason IS NULL)
                        OR (undone_at IS NOT NULL AND undone_by IS NOT NULL
                            AND length(btrim(coalesce(undone_reason, ''))) > 10)
                    );
        SQL);

        /*
         | One live reconciliation per statement.
         |
         | A partial index, so a superseded one does not block the
         | reconciliation that replaced it. Without this, reconciling the
         | same statement twice leaves two live documents and nothing
         | saying which one the close should look at.
         */
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX reconciliations_one_live_per_statement
                ON reconciliations (bank_statement_id)
                WHERE undone_at IS NULL;

            COMMENT ON TABLE reconciliations IS
                'What an auditor re-performs: the statement, the balances as they stood, the items that bridged them, and who authorised it - PF-165.';
            COMMENT ON CONSTRAINT reconciliations_balance ON reconciliations IS
                'The identity is asserted on the way in. A reconciliation statement that displays arithmetic without checking it is a page somebody reads past.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('reconciliations')");

        Schema::create('rule_candidates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | A correction somebody made, kept as a suggestion - PF-165.
             |
             | The engine never changes its own behaviour because a person
             | disagreed with it once. It records what they did instead,
             | and when the same correction has been made enough times it
             | is offered back as a rule for somebody to write
             | deliberately.
             |
             | The alternative - learning silently - produces an engine
             | whose behaviour nobody can account for: it proposes
             | something different this month and the only explanation is
             | a correction somebody made in March.
             */
            $table->char('bank_transaction_id', 26);
            $table->date('booking_date');

            $table->string('rejected_pass', 32);
            $table->char('rejected_id', 26)->nullable();
            $table->char('corrected_type', 24);
            $table->char('corrected_id', 26);

            /*
             | What the two had in common, as the rule would test it.
             |
             | Stored as the conditions a rule would carry rather than as
             | a description, so the suggestion is something somebody can
             | accept rather than something they have to translate.
             */
            $table->jsonb('conditions');
            $table->integer('seen_times')->default(1);

            $table->string('status', 16)->default('open');
            $table->char('became_rule_id', 26)->nullable();
            $table->timestamp('last_seen_at');

            $table->timestamps();

            $table->index(['entity_id', 'status', 'seen_times']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE rule_candidates
                ADD CONSTRAINT rule_candidates_status_is_known
                    CHECK (status IN ('open', 'accepted', 'dismissed')),

                ADD CONSTRAINT rule_candidates_seen_at_least_once
                    CHECK (seen_times >= 1),

                ADD CONSTRAINT rule_candidates_accepted_name_their_rule
                    CHECK (status <> 'accepted' OR became_rule_id IS NOT NULL);

            COMMENT ON TABLE rule_candidates IS
                'Corrections offered back as rules somebody may write - the engine never silently changes its own behaviour - PF-165.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('rule_candidates')");
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_candidates');
        Schema::dropIfExists('reconciliations');
    }
};
