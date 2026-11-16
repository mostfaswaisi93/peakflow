<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the engine proposes, and what it learns - PF-156 to PF-161.
 *
 * ## A proposal is a row, not a computation repeated on every page load
 *
 * Three reasons, and the third is the one that decides it.
 *
 * It is slow to recompute: a subset-sum search against four hundred open
 * invoices is bounded precisely because it is expensive, and running it
 * again every time somebody scrolls past the transaction defeats the bound.
 *
 * It must be stable: a proposal a person is looking at cannot change
 * underneath them because another invoice was issued while they read it.
 *
 * And it must be **answerable afterwards**. "Why was this payment applied to
 * invoice 4471" is asked months later, by somebody holding a bank statement
 * and no access to the state the engine saw. A proposal that was never
 * stored cannot answer it, and neither can one that stored only its score -
 * which is why `factors` is here beside `confidence`.
 *
 * ## The outcome lives on the proposal, which is what makes accuracy real
 *
 * PF-161 asks for a per-pass report of proposed, accepted, corrected and
 * rejected. Two ways to get it: a counter per pass incremented on each
 * decision, or the decision recorded on the proposal and the report derived.
 *
 * A counter is cheaper and drifts. It drifts the first time a proposal is
 * reversed, or a migration backfills, or two tabs decide the same proposal -
 * and a drifting accuracy report is worse than none, because a degrading
 * pass is exactly what it exists to show and a wrong number hides it.
 *
 * ## Rules are versioned because a past match has to stay explainable
 *
 * A rule edited today must not change what it says about a match it made
 * last March. So a rule is never updated in place: an edit writes a new
 * version, the old one is marked superseded, and a proposal points at the
 * exact version that produced it.
 *
 * @financial-not-applicable: proposals and rules are the engine's working
 * state. Nothing here is a balance, and none of it posts - a proposal
 * becomes a `matches` row before anything reaches the ledger. They are
 * entity-scoped all the same, because a rule written for one company must
 * not fire in another.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | What the engine proposed, with the reasoning kept.
         |
         | Partitioned by nothing: proposals age out with their transactions
         | and the volume is one order of magnitude below the journal's.
         */
        Schema::create('match_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->char('bank_transaction_id', 26);
            $table->date('booking_date');

            $table->string('matched_type', 24);
            $table->char('matched_id', 26)->nullable();

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);

            $table->string('pass', 32);
            $table->decimal('confidence', 5, 4);
            $table->text('reason');

            /*
             | Every factor that moved the score, with its weight.
             |
             | The score alone is a number nobody can argue with. The
             | factors are what let somebody say "it scored this high
             | because the amount matched exactly, and the amount matching
             | exactly means less than you think when the customer pays
             | round numbers" - which is the conversation that improves the
             | engine.
             */
            $table->jsonb('factors');

            /*
             | Where it came in the ranking, and whether anything else was
             | close.
             |
             | Ambiguity is a property of the SET of proposals, not of one
             | of them, so it is recorded on each: a proposal that was
             | ambiguous when made must still read as ambiguous after the
             | others are decided.
             */
            $table->smallInteger('rank');
            $table->boolean('was_ambiguous')->default(false);

            $table->string('status', 16)->default('proposed');
            $table->timestamp('decided_at')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->ulid('match_id')->nullable();

            /* The rule version that produced it, where a rule did. */
            $table->ulid('matching_rule_id')->nullable();

            $table->timestamps();

            $table->index(['bank_transaction_id', 'booking_date']);
            $table->index(['entity_id', 'pass', 'status']);
            $table->index(['entity_id', 'status', 'created_at']);
        });

        /*
         | Tenant-authored rules, versioned.
         |
         | `rule_key` is stable across versions and `version` counts up, so
         | "which rule is this" and "which version of it decided that" are
         | different questions with different answers.
         */
        Schema::create('matching_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->char('rule_key', 26);
            $table->integer('version')->default(1);
            $table->string('name', 160);

            /*
             | First match wins, so the order is the rule.
             |
             | Explicit rather than by creation date: a rule added later is
             | routinely meant to run first, and "move it up" is a thing
             | people need to be able to do without recreating everything
             | below it.
             */
            $table->integer('priority')->default(100);

            // Conditions. All given ones must hold; absent ones do not.
            $table->string('direction', 8)->nullable();
            $table->text('counterparty_pattern')->nullable();
            $table->text('remittance_pattern')->nullable();
            $table->string('counterparty_account', 64)->nullable();
            $table->string('code_domain', 8)->nullable();
            $table->string('code_family', 8)->nullable();
            $table->string('code_subfamily', 8)->nullable();
            $table->string('bank_code_proprietary', 32)->nullable();
            $table->decimal('amount_min', 21, 4)->nullable();
            $table->decimal('amount_max', 21, 4)->nullable();
            $table->ulid('bank_account_id')->nullable();

            // What it produces.
            $table->ulid('gl_account_id')->nullable();
            $table->ulid('party_id')->nullable();
            $table->jsonb('dimensions')->nullable();
            $table->string('matched_type', 24)->default('journal_entry');

            $table->boolean('is_active')->default(true);
            $table->ulid('superseded_by_id')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'rule_key', 'version']);
            $table->index(['entity_id', 'is_active', 'priority']);
        });

        /*
         | What the engine has learned about who a counterparty is.
         |
         | PF-160's whole point: a confirmed fuzzy match stores the
         | counterparty's account or BIC against the party, so the same payer
         | matches EXACTLY next time. The engine learns by accumulating
         | identifiers, never by lowering a threshold - a threshold that
         | moves on its own cannot be audited, and nobody can say why last
         | month's matches were made at a bar this month's would not clear.
         */
        Schema::create('party_bank_identifiers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('party_id')->constrained('parties')->cascadeOnDelete();

            $table->string('identifier_type', 24);
            $table->string('identifier', 128);

            /*
             | Who confirmed it, and from which transaction.
             |
             | An identifier that arrived from a human confirmation is
             | evidence; one that appeared by itself is a guess that has
             | learned to look like evidence.
             */
            $table->ulid('confirmed_by')->nullable();
            $table->char('learned_from_transaction_id', 26)->nullable();
            $table->integer('times_seen')->default(1);
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'identifier_type', 'identifier']);
            $table->index(['entity_id', 'party_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE match_proposals
                ADD CONSTRAINT match_proposals_type_is_known
                    CHECK (matched_type IN ('invoice', 'bill', 'payment', 'journal_entry',
                                            'write_off', 'transfer', 'unidentified')),

                ADD CONSTRAINT match_proposals_confidence_is_a_proportion
                    CHECK (confidence >= 0 AND confidence <= 1),

                ADD CONSTRAINT match_proposals_amount_is_not_zero
                    CHECK (amount <> 0),

                ADD CONSTRAINT match_proposals_rank_is_positive
                    CHECK (rank > 0),

                /*
                 | A reason that says something, as on `matches`.
                 |
                 | "Pass 1" is not a reason. This is what somebody reads
                 | when asked why a payment was applied to an invoice it
                 | should not have been.
                 */
                ADD CONSTRAINT match_proposals_reason_is_stated
                    CHECK (length(btrim(reason)) > 10),

                ADD CONSTRAINT match_proposals_status_is_known
                    CHECK (status IN ('proposed', 'accepted', 'corrected', 'rejected', 'superseded')),

                /*
                 | A decided proposal says who and when.
                 |
                 | Without it the accuracy report counts decisions nobody
                 | made, and "who accepted this" has no answer - which is
                 | the first question asked about an automatic match that
                 | turned out wrong.
                 */
                ADD CONSTRAINT match_proposals_a_decision_is_attributed
                    CHECK (
                        status = 'proposed'
                        OR (decided_at IS NOT NULL AND decided_by IS NOT NULL)
                    ),

                /*
                 | An accepted proposal points at the match it became.
                 |
                 | Otherwise "accepted" is a word with nothing behind it,
                 | and the chain from a bank line to the document it settled
                 | has a gap in exactly the place an auditor follows it.
                 */
                ADD CONSTRAINT match_proposals_an_accepted_one_became_a_match
                    CHECK (status <> 'accepted' OR match_id IS NOT NULL);

            ALTER TABLE matching_rules
                ADD CONSTRAINT matching_rules_version_is_positive
                    CHECK (version > 0),

                ADD CONSTRAINT matching_rules_direction_is_known
                    CHECK (direction IS NULL OR direction IN ('debit', 'credit')),

                ADD CONSTRAINT matching_rules_name_says_something
                    CHECK (length(btrim(name)) >= 3),

                /*
                 | A rule that tests nothing matches everything.
                 |
                 | Which is never what somebody meant, and is how a rule
                 | written for bank charges comes to claim every receipt in
                 | the account. At least one condition.
                 */
                ADD CONSTRAINT matching_rules_test_something
                    CHECK (
                        direction IS NOT NULL
                        OR counterparty_pattern IS NOT NULL
                        OR remittance_pattern IS NOT NULL
                        OR counterparty_account IS NOT NULL
                        OR code_domain IS NOT NULL
                        OR bank_code_proprietary IS NOT NULL
                        OR amount_min IS NOT NULL
                        OR amount_max IS NOT NULL
                    ),

                ADD CONSTRAINT matching_rules_amount_range_is_ordered
                    CHECK (amount_min IS NULL OR amount_max IS NULL OR amount_max >= amount_min),

                /*
                 | A rule produces something.
                 |
                 | A rule that identifies a transaction and says nothing
                 | about what to do with it has moved the work rather than
                 | done it.
                 */
                ADD CONSTRAINT matching_rules_produce_something
                    CHECK (gl_account_id IS NOT NULL OR party_id IS NOT NULL),

                /*
                 | A superseded rule is not active, and names its successor.
                 |
                 | Both together: "superseded" with no successor is a rule
                 | that silently stopped firing, which is indistinguishable
                 | from one somebody deleted and is explained by neither.
                 */
                ADD CONSTRAINT matching_rules_supersession_is_complete
                    CHECK (
                        superseded_by_id IS NULL
                        OR (superseded_at IS NOT NULL AND is_active = false)
                    );

            ALTER TABLE party_bank_identifiers
                ADD CONSTRAINT party_bank_identifiers_type_is_known
                    CHECK (identifier_type IN ('counterparty_account', 'counterparty_bic',
                                               'normalised_name', 'end_to_end_prefix')),

                /*
                 | An identifier long enough to mean something.
                 |
                 | A three-character "identifier" matches half the ledger,
                 | and storing one teaches the engine to be confidently
                 | wrong about every payer whose name starts the same way.
                 */
                ADD CONSTRAINT party_bank_identifiers_are_long_enough
                    CHECK (length(btrim(identifier)) >= 4);
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE match_proposals IS
                'peakflow:tenant-scoped - the reasoning is kept because "why was this applied here" is asked months later - PF-156, PF-161.';
            COMMENT ON COLUMN match_proposals.factors IS
                'Every factor that moved the score. The score alone is a number nobody can argue with.';
            COMMENT ON TABLE matching_rules IS
                'peakflow:tenant-scoped - versioned, so a rule edited today does not change what it says about a match it made last March - PF-159.';
            COMMENT ON TABLE party_bank_identifiers IS
                'peakflow:tenant-scoped - the engine learns by accumulating identifiers, never by lowering a threshold - PF-160.';
        SQL);

        foreach (['match_proposals', 'matching_rules', 'party_bank_identifiers'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('party_bank_identifiers');
        Schema::dropIfExists('matching_rules');
        Schema::dropIfExists('match_proposals');
    }
};
