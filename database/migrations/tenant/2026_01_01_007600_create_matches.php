<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applied matches - PF-146's dependency, PF-160's subject.
 *
 * ## Why this lands with the balances rather than with the engine
 *
 * "Unrecorded bank items" is one of the two components the three balances
 * itemise, and it means "imported and matched to nothing". That is a link
 * that does not exist rather than a flag on the transaction - which is forced
 * by the transaction being immutable, and is the right shape anyway: a
 * `matched` boolean has one answer per transaction, and a transaction
 * routinely settles three invoices.
 *
 * So the table the balance query asks about has to exist before the balance
 * query means anything. The matching **engine** - the seven passes, the
 * confidence scores, the subset-sum search - is PF-156 to PF-160. This is
 * the shape of what it will write.
 *
 * ## Splits in both directions
 *
 * One transaction against several documents, and one document against
 * several transactions. Both happen constantly: a customer pays four
 * invoices with one transfer, and a customer pays one invoice in three
 * instalments. A schema with a one-to-one link forces the first case into a
 * suspense account and the second into a write-off.
 *
 * ## Reversible individually, and the reversal is a row
 *
 * A match applied to the wrong document is corrected by reversing that one
 * match, not by unreconciling a month. And the reversal keeps the original:
 * "this was matched to invoice 4471 and somebody changed it" is the question
 * an auditor asks, and a deleted row cannot answer it.
 *
 * @financial
  *
 * @online-safe Creates `matches`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | The transaction, by id and booking date.
             |
             | Both, because `bank_transactions` is partitioned on the
             | booking date: a foreign key to a partitioned table needs the
             | partition key, and carrying it is also what lets the planner
             | prune when joining.
             */
            $table->char('bank_transaction_id', 26);
            $table->date('booking_date');

            /*
             | What it was matched to, as a type and an id.
             |
             | Not four nullable foreign keys. A transaction matches an
             | invoice, a bill, a payment, a journal entry or a write-off,
             | and each of those is a different table - four columns of
             | which three are always null is a row shape that invites a
             | query to forget one.
             */
            $table->string('matched_type', 24);
            $table->char('matched_id', 26)->nullable();

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);

            /*
             | How the engine decided, and how sure it was.
             |
             | The reason is a sentence for a person: "the remittance field
             | carried invoice 4471" is actionable and "pass 1" is not. A
             | proposal nobody can explain is a proposal nobody should
             | apply, and the same holds after it is applied.
             */
            $table->string('pass', 32);
            $table->decimal('confidence', 5, 4);
            $table->text('reason');
            $table->boolean('applied_automatically')->default(false);

            $table->timestamp('applied_at');
            $table->ulid('applied_by')->nullable();

            $table->timestamp('reversed_at')->nullable();
            $table->ulid('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->ulid('journal_entry_id')->nullable();

            $table->timestamps();

            $table->index(['bank_transaction_id', 'booking_date']);
            $table->index(['entity_id', 'matched_type', 'matched_id']);
            $table->index(['entity_id', 'applied_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE matches
                ADD CONSTRAINT matches_type_is_known
                    CHECK (matched_type IN ('invoice', 'bill', 'payment', 'journal_entry',
                                            'write_off', 'transfer', 'unidentified')),

                ADD CONSTRAINT matches_amount_is_not_zero
                    CHECK (amount <> 0),

                ADD CONSTRAINT matches_confidence_is_a_proportion
                    CHECK (confidence >= 0 AND confidence <= 1),

                /*
                 | A reason that says something.
                 |
                 | "Pass 1" is not a reason. The sentence is what somebody
                 | reads when they are asked why a payment was applied to an
                 | invoice it should not have been, and a match nobody can
                 | explain is a match nobody can defend.
                 */
                ADD CONSTRAINT matches_reason_is_stated
                    CHECK (length(btrim(reason)) > 10),

                ADD CONSTRAINT matches_reversal_is_explained
                    CHECK (
                        (reversed_at IS NULL AND reversed_by IS NULL)
                        OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL
                            AND length(btrim(coalesce(reversal_reason, ''))) > 5)
                    ),

                /*
                 | A match to nothing names what it is instead.
                 |
                 | `unidentified` and `write_off` carry no id, because there
                 | is no document: the first is money nobody can explain yet
                 | and the second is a difference somebody decided to absorb.
                 | Every other type points at a row.
                 */
                ADD CONSTRAINT matches_a_document_match_names_its_document
                    CHECK (
                        matched_type IN ('unidentified', 'write_off')
                        OR matched_id IS NOT NULL
                    );
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE matches IS
                'Splits in both directions: one transaction settles several documents, and one document is settled by several transactions - PF-160.';
            COMMENT ON CONSTRAINT matches_reason_is_stated ON matches IS
                'A match nobody can explain is a match nobody can defend.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('matches')");
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
