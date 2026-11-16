<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The approval engine - PF-136.
 *
 * ## One engine, and the hole it exists to close
 *
 * An approval is not a flag on a document. It is a decision about a
 * **specific version** of one, and the hole that matters is the document
 * edited afterwards: approved at 1,000, changed to 100,000, paid. Every
 * system that stores approval as a boolean has this hole, and it is
 * invisible because the document looks approved and is.
 *
 * So `approval_requests.document_fingerprint` is a hash over the fields that
 * make the document what it is, taken when the chain starts, and
 * `approval_decisions.fingerprint` records what each approver actually saw.
 * A document whose fingerprint has moved has a **stale** request, and the
 * chain starts again.
 *
 * ## Why rules compile into a stored chain
 *
 * The chain is resolved once and written down, rather than re-evaluated on
 * each decision. A rule changed halfway through an approval would otherwise
 * move the goalposts under the people already in it - and nobody could
 * answer "who was supposed to approve this" about a document from last
 * month.
 *
 * ## Sequential, parallel and quorum
 *
 * A step has a `sequence`, and steps sharing one are parallel. `quorum` is
 * how many of that step's approvers have to agree: two of three is a real
 * policy and a chain that could only express "all" or "any" would make the
 * business pick the wrong one.
 *
 * @financial-not-applicable: approvals carry a threshold and a converted
 * amount for the limit test, not postings. `entity_id` is declared and RLS
 * applied because an approval is as entity-scoped as the document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('code', 32);
            $table->string('name_ar', 120);
            $table->string('name_en', 120);

            /*
             | What the rule applies to. Every condition is nullable and an
             | absent one matches everything - so a rule with none is "every
             | document", which is what a small tenant's single rule looks
             | like.
             */
            $table->string('document_type', 24)->nullable();
            $table->decimal('amount_from', 21, 4)->nullable();
            $table->decimal('amount_to', 21, 4)->nullable();
            $table->foreignUlid('party_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->char('dimension_axis', 26)->nullable();
            $table->char('dimension_value', 26)->nullable();

            /*
             | Ordered, because several rules can match one document and the
             | chain is their steps concatenated in this order. A matched set
             | with no order would produce a different chain each time the
             | rows came back in a different order.
             */
            $table->integer('priority')->default(100);
            $table->boolean('stops_here')->default(false);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'active', 'priority']);
        });

        Schema::create('approval_rule_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('approval_rule_id')->constrained()->cascadeOnDelete();

            $table->integer('sequence');
            $table->integer('quorum')->default(1);

            /*
             | Who may act. A role, a specific user, or both - and a step
             | with neither is a step nobody can clear, which the check
             | constraint refuses.
             */
            $table->string('role', 64)->nullable();
            $table->ulid('user_id')->nullable();

            $table->decimal('limit_amount', 21, 4)->nullable();
            $table->integer('escalate_after_hours')->nullable();

            $table->timestamps();

            $table->unique(['approval_rule_id', 'sequence', 'role', 'user_id']);
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('subject_type', 64);
            $table->char('subject_id', 26);

            $table->string('status', 16)->default('pending');

            $table->char('currency', 3);
            $table->decimal('amount', 21, 4);

            /*
             | The amount in the entity's own currency, and the rate used.
             |
             | PF-138: a limit cannot be dodged by invoicing in a weak
             | currency, so the test is on the converted figure - and the
             | rate is recorded, because "why did this need two approvers"
             | is answered by the rate on the day and not by today's.
             */
            $table->decimal('functional_amount', 21, 4)->nullable();
            $table->decimal('fx_rate', 21, 12)->nullable();
            $table->date('fx_rate_date')->nullable();

            /*
             | What was approved - PF-136's whole point.
             |
             | A hash over the document's significant fields when the chain
             | started. A document whose fingerprint has moved has a stale
             | request, and the chain starts again.
             */
            $table->char('document_fingerprint', 64);

            $table->ulid('requested_by')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->text('outcome_detail')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('approval_steps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('approval_request_id')->constrained()->cascadeOnDelete();

            $table->integer('sequence');
            $table->integer('quorum')->default(1);
            $table->integer('approvals')->default(0);
            $table->string('status', 16)->default('pending');

            // Copied from the rule, so the chain is a record rather than a query.
            $table->foreignUlid('approval_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_code', 32)->nullable();
            $table->string('role', 64)->nullable();
            $table->ulid('user_id')->nullable();
            $table->decimal('limit_amount', 21, 4)->nullable();
            $table->integer('escalate_after_hours')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['approval_request_id', 'sequence']);
        });

        Schema::create('approval_decisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('approval_step_id')->constrained('approval_steps')->cascadeOnDelete();

            $table->ulid('user_id');
            $table->string('decision', 16);
            $table->text('comment')->nullable();

            /*
             | What this approver saw.
             |
             | The request's fingerprint at the moment of the decision. If it
             | differs from the request's current one, this approval was
             | given on a document that has since changed - which is exactly
             | what the stale check reads.
             */
            $table->char('fingerprint', 64);

            // Who it was really: a delegate acts, and the delegation is
            // recorded rather than the approval being silently substituted.
            $table->ulid('on_behalf_of')->nullable();

            $table->timestamp('decided_at');

            $table->timestamps();

            /*
             | One decision per person per step.
             |
             | A quorum of two cannot be met by one person pressing approve
             | twice, which is the obvious way to clear a step alone.
             */
            $table->unique(['approval_step_id', 'user_id']);
            $table->index(['entity_id', 'user_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE approval_rules
                ADD CONSTRAINT approval_rules_amount_range_is_ordered
                    CHECK (amount_from IS NULL OR amount_to IS NULL OR amount_to >= amount_from),

                ADD CONSTRAINT approval_rules_dimension_is_a_pair
                    CHECK (
                        (dimension_axis IS NULL AND dimension_value IS NULL)
                        OR (dimension_axis IS NOT NULL AND dimension_value IS NOT NULL)
                    );

            ALTER TABLE approval_rule_steps
                ADD CONSTRAINT approval_rule_steps_quorum_is_positive
                    CHECK (quorum >= 1),

                /*
                 | A step names somebody.
                 |
                 | A step with neither a role nor a user is a step nobody can
                 | clear, and a chain containing one can never complete - so
                 | the document waits forever and whoever configured it finds
                 | out from a complaint.
                 */
                ADD CONSTRAINT approval_rule_steps_names_an_approver
                    CHECK (role IS NOT NULL OR user_id IS NOT NULL);

            ALTER TABLE approval_requests
                ADD CONSTRAINT approval_requests_status_is_known
                    CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled', 'stale')),

                ADD CONSTRAINT approval_requests_fingerprint_is_hex
                    CHECK (document_fingerprint ~ '^[0-9a-f]{64}$'),

                ADD CONSTRAINT approval_requests_decided_is_attributable
                    CHECK (
                        (status = 'pending' AND decided_at IS NULL)
                        OR (status <> 'pending' AND decided_at IS NOT NULL)
                    );

            ALTER TABLE approval_steps
                ADD CONSTRAINT approval_steps_status_is_known
                    CHECK (status IN ('pending', 'open', 'approved', 'rejected', 'skipped')),

                ADD CONSTRAINT approval_steps_quorum_is_positive
                    CHECK (quorum >= 1),

                ADD CONSTRAINT approval_steps_approvals_do_not_exceed_quorum
                    CHECK (approvals >= 0 AND approvals <= quorum);

            ALTER TABLE approval_decisions
                ADD CONSTRAINT approval_decisions_decision_is_known
                    CHECK (decision IN ('approved', 'rejected')),

                ADD CONSTRAINT approval_decisions_fingerprint_is_hex
                    CHECK (fingerprint ~ '^[0-9a-f]{64}$'),

                /*
                 | A rejection says why.
                 |
                 | An approval may be silent - the chain is the reason - and a
                 | rejection that does not is a document returned to somebody
                 | with no idea what to change.
                 */
                ADD CONSTRAINT approval_decisions_rejection_carries_a_reason
                    CHECK (decision <> 'rejected' OR length(btrim(coalesce(comment, ''))) > 5);
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN approval_requests.document_fingerprint IS
                'A hash of the document as it was when the chain started. A document edited after approval returns to the start - PF-136.';
            COMMENT ON CONSTRAINT approval_decisions_approval_step_id_user_id_unique ON approval_decisions IS
                'A quorum of two cannot be met by one person pressing approve twice.';
        SQL);

        foreach ([
            'approval_rules', 'approval_rule_steps', 'approval_requests',
            'approval_steps', 'approval_decisions',
        ] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_rule_steps');
        Schema::dropIfExists('approval_rules');
    }
};
