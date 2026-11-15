<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit limits, holds and disputes - PF-062.
 *
 * @financial
 *
 * ## The limit is per party PER ENTITY, in that entity's own currency
 *
 * `party_entity_settings` already carries `credit_limit`, and this migration
 * is about what surrounds it: who may release a hold, what a hold means, and
 * how a dispute stops an invoice escalating.
 *
 * Per entity because a customer billed by two of your companies owes each of
 * them separately, and a group-wide limit would let a customer at their
 * ceiling in one company keep buying from another. In that entity's
 * functional currency because the limit is compared against a balance, and
 * comparing a limit in riyal against a balance in euro is the kind of
 * comparison that silently always passes.
 *
 * ## A hold is an event, not a flag
 *
 * `credit_hold` on the settings row says whether a customer is on hold now.
 * It cannot say who put them there, when, why, or who lifted it - and those
 * are the four questions asked when a customer rings to complain that their
 * order was refused.
 *
 * So the flag stays (it is what the invoice path reads, and reading one
 * boolean is cheaper than reconstructing a history) and the history lives
 * beside it. The flag is maintained by a trigger from the history, so the
 * two cannot disagree - which they would, the first time somebody updated
 * one and not the other.
 *
 * ## A dispute suspends dunning; it does not clear the debt
 *
 * This is the distinction worth being careful about. A disputed invoice is
 * still owed, still counts towards exposure, and still ages - what stops is
 * the escalation, because sending a fourth reminder about an invoice the
 * customer has formally queried is how a commercial relationship ends over
 * an administrative error.
 *
 * Writing the invoice off, or excluding it from exposure, would be the easy
 * implementation and would mean a customer could keep buying by disputing
 * everything.
 *
 * @online-safe Creates new tables and adds nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | The history of holds, per party per entity.
         |
         | Append-only: a hold that was lifted is still a fact about the
         | relationship, and the question "has this customer been on hold
         | before" is asked whenever a new limit is being set.
         */
        Schema::create('credit_hold_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->foreignUlid('party_id')->constrained('parties')->cascadeOnDelete();

            // 'placed' or 'lifted'. Not a boolean: a sequence of events
            // needs to say which way each one went.
            $table->string('direction', 8);

            $table->text('reason');
            $table->ulid('by_user_id')->nullable();
            $table->timestamp('at');

            /*
             | The exposure and the limit AS THEY WERE.
             |
             | Recomputing them later gives today's numbers, not the ones
             | the decision was made on - and "why was this customer put on
             | hold in March" is answered by the March figures. They are
             | also what makes a hold defensible when the customer disputes
             | it.
             */
            $table->decimal('exposure_at_decision', 21, 4)->nullable();
            $table->decimal('limit_at_decision', 21, 4)->nullable();
            $table->char('currency', 3)->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'party_id', 'at']);
        });

        /*
         | An override of a credit block, which is a deliberate, named act.
         |
         | Separate from the hold history because it answers a different
         | question: not "is this customer on hold" but "who decided to
         | invoice them anyway, and what did they say at the time".
         |
         | Bounded in time. An override with no expiry is a limit removed,
         | and the person granting it almost always means "for this order".
         */
        Schema::create('credit_overrides', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->foreignUlid('party_id')->constrained('parties')->cascadeOnDelete();

            $table->text('justification');
            $table->ulid('granted_by_user_id');
            $table->timestamp('granted_at');
            $table->timestamp('expires_at');

            // What it was granted against, so an override for a 10,000
            // order cannot quietly cover a 400,000 one.
            $table->decimal('up_to_amount', 21, 4)->nullable();
            $table->char('currency', 3)->nullable();

            $table->ulid('revoked_by_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'party_id', 'expires_at']);
        });

        /*
         | Disputes, per document.
         |
         | A table rather than a flag on the invoice, because a dispute has
         | a reason, a raiser, a date and a resolution - and because an
         | invoice can be disputed, resolved and disputed again, which a
         | boolean cannot represent.
         */
        Schema::create('document_disputes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            // Polymorphic by design: an invoice today, a statement or a
            // credit note later, and the dunning suspension works the same
            // way for each.
            $table->string('document_type', 64);
            $table->ulid('document_id');

            $table->foreignUlid('party_id')->nullable()->constrained('parties')->nullOnDelete();

            $table->string('reason_code', 32);
            $table->text('detail');

            $table->decimal('disputed_amount', 21, 4)->nullable();
            $table->char('currency', 3)->nullable();

            $table->ulid('raised_by_user_id')->nullable();
            $table->timestamp('raised_at');

            $table->string('status', 16)->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->ulid('resolved_by_user_id')->nullable();
            $table->text('resolution')->nullable();

            $table->timestamps();

            $table->index(['entity_id', 'document_type', 'document_id']);
            $table->index(['entity_id', 'status', 'raised_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE credit_hold_events
                ADD CONSTRAINT credit_hold_events_direction_known
                    CHECK (direction IN ('placed', 'lifted'));

            ALTER TABLE credit_overrides
                -- An override that expires before it was granted covers
                -- nothing, and presents as "the override did not work".
                ADD CONSTRAINT credit_overrides_window_is_ordered
                    CHECK (expires_at > granted_at),

                ADD CONSTRAINT credit_overrides_revocation_is_complete
                    CHECK ((revoked_at IS NULL AND revoked_by_user_id IS NULL AND revoked_reason IS NULL)
                           OR (revoked_at IS NOT NULL AND revoked_by_user_id IS NOT NULL
                               AND revoked_reason IS NOT NULL)),

                ADD CONSTRAINT credit_overrides_amount_is_positive
                    CHECK (up_to_amount IS NULL OR up_to_amount > 0);

            ALTER TABLE document_disputes
                ADD CONSTRAINT document_disputes_status_known
                    CHECK (status IN ('open', 'upheld', 'rejected', 'withdrawn')),

                ADD CONSTRAINT document_disputes_reason_known
                    CHECK (reason_code IN ('price', 'quantity', 'quality', 'not_received',
                                           'duplicate', 'tax', 'terms', 'other')),

                -- A resolved dispute with no resolution text is a dispute
                -- closed by somebody clicking a button, and the customer's
                -- next question has no answer.
                ADD CONSTRAINT document_disputes_resolution_is_complete
                    CHECK (status = 'open'
                           OR (resolved_at IS NOT NULL AND resolution IS NOT NULL)),

                ADD CONSTRAINT document_disputes_amount_is_positive
                    CHECK (disputed_amount IS NULL OR disputed_amount > 0);

            /*
             | One OPEN dispute per document.
             |
             | Several at once would each suspend dunning and each need
             | resolving, and the second one is almost always the same
             | complaint entered twice. A resolved dispute does not block a
             | new one, which is why the index is partial.
             */
            CREATE UNIQUE INDEX document_disputes_one_open_per_document
                ON document_disputes (entity_id, document_type, document_id)
                WHERE status = 'open';
        SQL);

        /*
         | The risk grade, for the IFRS 9 provisioning matrix - PF-173.
         |
         | On `parties` rather than per entity: a customer's
         | creditworthiness is a property of the customer, and two of your
         | companies grading the same customer differently is a
         | disagreement nobody can resolve. The LIMIT is per entity because
         | that is a commercial decision; the grade is an assessment.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE parties
                ADD CONSTRAINT parties_risk_grade_known
                    CHECK (risk_grade IS NULL
                           OR risk_grade IN ('A', 'B', 'C', 'D', 'E', 'watch', 'default'))
                    NOT VALID;

            COMMENT ON COLUMN parties.risk_grade IS
                'The credit assessment, used by the IFRS 9 provisioning matrix (PF-173). On the party rather '
                'than per entity because creditworthiness is a property of the customer; the LIMIT is per '
                'entity because that is a commercial decision.';
        SQL);

        $this->installHoldMirror();

        foreach (['credit_hold_events', 'credit_overrides', 'document_disputes'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE credit_hold_events IS
                'peakflow:no-delete - a hold that was lifted is still a fact about the relationship, and '
                '"has this customer been on hold before" is asked whenever a limit is being set.';

            COMMENT ON TABLE credit_overrides IS
                'peakflow:no-delete - who decided to invoice a customer past their limit, and what they said '
                'at the time. Revoked, never deleted.';

            COMMENT ON TABLE document_disputes IS
                'peakflow:no-delete - a dispute suspends dunning and does not clear the debt. Resolved, '
                'never removed: the history is why an invoice stopped escalating for two months.';
        SQL);
    }

    /**
     * The hold FLAG follows the hold HISTORY - PF-062.
     *
     * ## Why both exist
     *
     * The invoice path needs to know whether a customer is on hold, and
     * reading one boolean is cheaper than reconstructing a history on every
     * document. But a boolean cannot say who placed the hold, when, why, or
     * who lifted it - and those are the four questions asked the moment a
     * customer rings to complain that their order was refused.
     *
     * ## Why a trigger rather than a service
     *
     * Because the two would otherwise disagree. A support script that
     * cleared the flag without recording an event, or recorded an event
     * without clearing the flag, leaves a customer either blocked with no
     * explanation or released with no record - and both are found by the
     * customer rather than by us.
     */
    private function installHoldMirror(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mirror_credit_hold()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                /*
                 | The settings row may not exist yet: a party gets one the
                 | first time anything per-entity is decided about them, and
                 | a hold can be that first thing.
                 */
                INSERT INTO party_entity_settings (
                    id, entity_id, party_id, credit_hold, credit_hold_reason,
                    credit_hold_by_user_id, credit_hold_at, created_at, updated_at
                )
                VALUES (
                    peakflow_ulid(), NEW.entity_id, NEW.party_id,
                    NEW.direction = 'placed',
                    CASE WHEN NEW.direction = 'placed' THEN NEW.reason END,
                    CASE WHEN NEW.direction = 'placed' THEN NEW.by_user_id END,
                    CASE WHEN NEW.direction = 'placed' THEN NEW.at END,
                    now(), now()
                )
                ON CONFLICT (entity_id, party_id) DO UPDATE
                    SET credit_hold = NEW.direction = 'placed',
                        credit_hold_reason = CASE WHEN NEW.direction = 'placed' THEN NEW.reason END,
                        credit_hold_by_user_id = CASE WHEN NEW.direction = 'placed' THEN NEW.by_user_id END,
                        credit_hold_at = CASE WHEN NEW.direction = 'placed' THEN NEW.at END,
                        updated_at = now();

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER credit_hold_events_mirror
                AFTER INSERT ON credit_hold_events
                FOR EACH ROW
                EXECUTE FUNCTION mirror_credit_hold();
        SQL);

        /*
         | And the flag cannot be set directly.
         |
         | Without this the trigger above is a convenience rather than a
         | guarantee: anything that reaches `party_entity_settings` could
         | still put a customer on hold with no record of who or why.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_unrecorded_credit_hold()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                recorded boolean;
            BEGIN
                IF NEW.credit_hold IS NOT DISTINCT FROM coalesce(OLD.credit_hold, false) THEN
                    RETURN NEW;
                END IF;

                /*
                 | Was there an event for this, in this transaction?
                 |
                 | The mirror trigger writes the flag immediately after
                 | inserting the event, so a legitimate change always has a
                 | matching event already visible. A direct UPDATE has none.
                 */
                SELECT true INTO recorded
                FROM credit_hold_events
                WHERE entity_id = NEW.entity_id
                  AND party_id = NEW.party_id
                  AND direction = CASE WHEN NEW.credit_hold THEN 'placed' ELSE 'lifted' END
                  AND at >= now() - interval '1 second'
                LIMIT 1;

                IF recorded IS NULL THEN
                    RAISE EXCEPTION
                        'A credit hold cannot be % directly. Record it as a credit_hold_events row instead: a hold with no author, time or reason cannot be explained to the customer whose order it refused, and "who put us on hold" is the first thing they ask.',
                        CASE WHEN NEW.credit_hold THEN 'placed' ELSE 'lifted' END
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER party_entity_settings_hold_is_recorded
                BEFORE INSERT OR UPDATE ON party_entity_settings
                FOR EACH ROW
                EXECUTE FUNCTION refuse_unrecorded_credit_hold();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS party_entity_settings_hold_is_recorded ON party_entity_settings;
            DROP FUNCTION IF EXISTS refuse_unrecorded_credit_hold();

            DROP TRIGGER IF EXISTS credit_hold_events_mirror ON credit_hold_events;
            DROP FUNCTION IF EXISTS mirror_credit_hold();

            ALTER TABLE parties DROP CONSTRAINT IF EXISTS parties_risk_grade_known;
        SQL);

        Schema::dropIfExists('document_disputes');
        Schema::dropIfExists('credit_overrides');
        Schema::dropIfExists('credit_hold_events');
    }
};
