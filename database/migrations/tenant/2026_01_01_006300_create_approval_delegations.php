<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delegation, continuity and limits - PF-137, PF-138.
 *
 * ## A delegate is recorded, never substituted
 *
 * The naive implementation of "approve on behalf of" writes the absent
 * person's id on the decision. That is a forged approval: the record says
 * somebody approved a document they never saw, and the only way to discover
 * it is to notice they were on holiday that week.
 *
 * So `approval_decisions.on_behalf_of` already carries the delegate, and
 * this table says who may act for whom and when. Both halves are needed: a
 * delegation with no window is a standing substitution nobody revisits, and
 * a decision with no delegate recorded is a lie.
 *
 * ## A delegate cannot receive what SoD forbids them
 *
 * The obvious way to launder a conflict is to delegate to the person who
 * created the document. The engine asks the SoD engine about the **actor**
 * rather than about the delegator, so the conflict is caught where it
 * arrives rather than where it was arranged.
 *
 * ## Limits are tested on the converted amount
 *
 * PF-138. A limit of 10,000 in the entity's own currency cannot be dodged by
 * invoicing in a weak one, so the test is on the functional amount - and the
 * rate is recorded on the request, because "why did this need a director"
 * is answered by the rate on the day and not by today's.
 *
 * A limit change is never retroactive: `effective_from` is required, and the
 * limit that applied is the one in force when the chain started.
 *
 * @financial-not-applicable: these carry thresholds and windows, not
 * postings. `entity_id` is declared and RLS applied because a delegation is
 * as entity-scoped as the approval it affects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->ulid('from_user_id');
            $table->ulid('to_user_id');

            /*
             | A window, not a flag.
             |
             | A delegation with no end is a standing substitution nobody
             | revisits: it outlives the holiday, the role and sometimes the
             | employment.
             */
            $table->date('starts_on');
            $table->date('ends_on');

            // Narrowed to one duty where a policy wants that, or all of them.
            $table->string('duty', 64)->nullable();
            $table->decimal('limit_amount', 21, 4)->nullable();

            $table->text('reason');
            $table->ulid('created_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->ulid('revoked_by')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'to_user_id', 'starts_on', 'ends_on']);
            $table->index(['entity_id', 'from_user_id']);
        });

        Schema::create('approval_limits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | A limit belongs to a role or to a user, and exactly one.
             |
             | Both would be two limits with one row and no answer to which
             | applies; neither would be a limit on everybody, which is the
             | entity's own and is a different thing.
             */
            $table->string('role', 64)->nullable();
            $table->ulid('user_id')->nullable();

            $table->decimal('limit_amount', 21, 4);
            $table->char('currency', 3);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->ulid('created_by')->nullable();
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'role', 'effective_from']);
            $table->index(['entity_id', 'user_id', 'effective_from']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE approval_delegations
                ADD CONSTRAINT approval_delegations_window_is_ordered
                    CHECK (ends_on >= starts_on),

                /*
                 | Nobody delegates to themselves.
                 |
                 | It looks harmless and it is the shape in which a
                 | segregation-of-duties conflict is laundered: delegate to
                 | yourself from the person whose approval you need.
                 */
                ADD CONSTRAINT approval_delegations_not_to_oneself
                    CHECK (from_user_id <> to_user_id),

                ADD CONSTRAINT approval_delegations_reason_is_stated
                    CHECK (length(btrim(reason)) > 10),

                ADD CONSTRAINT approval_delegations_revocation_is_attributable
                    CHECK (
                        (revoked_at IS NULL AND revoked_by IS NULL)
                        OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL)
                    );

            ALTER TABLE approval_limits
                ADD CONSTRAINT approval_limits_is_a_role_or_a_user
                    CHECK (
                        (role IS NOT NULL AND user_id IS NULL)
                        OR (role IS NULL AND user_id IS NOT NULL)
                    ),

                ADD CONSTRAINT approval_limits_amount_is_positive
                    CHECK (limit_amount > 0),

                ADD CONSTRAINT approval_limits_window_is_ordered
                    CHECK (effective_to IS NULL OR effective_to >= effective_from);
        SQL);

        /*
         | One delegation per pair per window, overlapping windows refused.
         |
         | Two overlapping delegations from the same person to the same
         | person are two answers to "may they act today", and the code
         | would read whichever came back first.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE approval_delegations
                ADD CONSTRAINT approval_delegations_do_not_overlap
                EXCLUDE USING gist (
                    entity_id WITH =,
                    from_user_id WITH =,
                    to_user_id WITH =,
                    coalesce(duty, '') WITH =,
                    daterange(starts_on, ends_on, '[]') WITH &&
                )
                WHERE (revoked_at IS NULL);
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE approval_delegations IS
                'Who may act for whom, and when. The decision records the delegate - a substitution would be a forged approval - PF-137.';
            COMMENT ON TABLE approval_limits IS
                'Thresholds in the entity''s own currency. A foreign-currency document is converted for the test - PF-138.';
            COMMENT ON CONSTRAINT approval_delegations_not_to_oneself ON approval_delegations IS
                'The shape in which an SoD conflict is laundered.';
        SQL);

        foreach (['approval_delegations', 'approval_limits'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_limits');
        Schema::dropIfExists('approval_delegations');
    }
};
