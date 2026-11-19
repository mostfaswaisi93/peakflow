<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Direct debit mandates and collections - PF-177.
 *
 * ## The sequence type is the whole of this task
 *
 * SEPA Direct Debit distinguishes the FIRST collection under a mandate
 * from every one after it (`FRST` then `RCUR`), and getting it wrong is a
 * scheme-level rejection rather than a warning: a second `FRST` is
 * refused because the debtor's bank has already seen the mandate, and an
 * `RCUR` before any `FRST` is refused because it has not.
 *
 * So the sequence is **derived** from the mandate's own collection
 * history and never stored as a field somebody sets. A field would be
 * edited, and the first edit produces a file the bank rejects for a
 * reason that reads as our bug.
 *
 * ## A cancelled mandate stops collections here, not at the bank
 *
 * A collection against a revoked mandate is returned days later with a
 * reason code, after the customer has seen it on their statement and
 * complained. Refusing it at the moment somebody tries is the difference
 * between a control and a report.
 *
 * ## Pre-notification is a promise with a date
 *
 * The scheme requires the debtor be told before money is taken, a set
 * number of days ahead. That is not paperwork: a collection without it is
 * one the debtor can reverse without giving a reason, for thirteen months
 * under SDD Core. So the notification is recorded with its date, and a
 * collection whose lead time is short is refused with the earliest date
 * it could be made.
 *
 * @financial
  *
 * @online-safe Creates `collections`, `mandate_notifications`, `mandates`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('party_id', 26);

            /*
             | The reference the debtor's bank matches on.
             |
             | Unique per entity and immutable: it is what the debtor's
             | bank and the debtor's own statement identify the mandate
             | by, so changing it is starting a new mandate while claiming
             | the old one's history - which means the next collection
             | goes out as RCUR against a mandate the bank has never seen.
             */
            $table->string('reference', 35);

            $table->string('scheme', 32);
            $table->date('signed_on');
            $table->string('signature_channel', 32);

            $table->char('debtor_account_id', 26)->nullable();
            $table->string('debtor_name', 140);

            $table->string('state', 16)->default('active');
            $table->date('expires_on')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->ulid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->ulid('created_by');
            $table->timestamps();

            $table->unique(['entity_id', 'reference']);
            $table->index(['entity_id', 'party_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE mandates
                ADD CONSTRAINT mandates_state_is_known
                    CHECK (state IN ('active', 'cancelled', 'expired')),

                /*
                 | A signature date in the future is not a signature.
                 |
                 | It is the commonest data-entry error on a mandate form
                 | and it produces a file the bank rejects - so it is
                 | refused where somebody typed it.
                 */
                ADD CONSTRAINT mandates_are_signed_before_they_are_used
                    CHECK (signed_on <= current_date),

                ADD CONSTRAINT mandates_expire_after_they_are_signed
                    CHECK (expires_on IS NULL OR expires_on > signed_on),

                ADD CONSTRAINT mandates_cancellation_is_explained
                    CHECK (
                        (cancelled_at IS NULL AND cancelled_by IS NULL)
                        OR (cancelled_at IS NOT NULL
                            AND cancelled_by IS NOT NULL
                            AND length(btrim(coalesce(cancellation_reason, ''))) >= 10)
                    ),

                /*
                 | And the state agrees with the cancellation.
                 |
                 | A mandate marked active with a cancellation timestamp is
                 | the row an import leaves, and every reader then has to
                 | decide which column to believe.
                 */
                ADD CONSTRAINT mandates_state_agrees_with_cancellation
                    CHECK ((state = 'cancelled') = (cancelled_at IS NOT NULL));

            COMMENT ON TABLE mandates IS
                'A debtor''s standing authority to collect. The sequence type is derived from the collection history and never stored, because a second FRST is a scheme-level rejection - PF-177.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('mandates')");

        Schema::create('mandate_notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('mandate_id', 26);

            /*
             | What the debtor was told, and when.
             |
             | A collection without pre-notification is one the debtor can
             | reverse without giving a reason, for thirteen months under
             | SDD Core. The amount and the date are what was promised, so
             | a collection that differs from them is not covered by it.
             */
            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);
            $table->date('collection_date');
            $table->date('notified_on');
            $table->string('channel', 32);

            $table->ulid('notified_by');
            $table->timestamps();

            $table->index(['entity_id', 'mandate_id', 'collection_date']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE mandate_notifications
                ADD CONSTRAINT mandate_notifications_amount_is_positive
                    CHECK (amount > 0),

                ADD CONSTRAINT mandate_notifications_precede_the_collection
                    CHECK (notified_on <= collection_date);
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('mandate_notifications')");

        Schema::create('collections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('mandate_id', 26);
            $table->char('invoice_id', 26)->nullable();

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);
            $table->date('collection_date');

            /*
             | Derived at the moment of creation, and frozen.
             |
             | `FRST` for the first collection under a mandate, `RCUR`
             | after. Stored because it is what the file said - but
             | computed from the history rather than supplied, so no
             | caller can produce the second FRST that a bank refuses.
             */
            $table->string('sequence_type', 8);

            $table->string('state', 16)->default('presented');
            $table->char('file_id', 26)->nullable();

            $table->string('return_reason_code', 8)->nullable();
            $table->text('return_reason')->nullable();
            $table->date('returned_on')->nullable();
            $table->ulid('return_entry_id')->nullable();

            $table->ulid('journal_entry_id')->nullable();
            $table->ulid('created_by');
            $table->timestamps();

            $table->index(['entity_id', 'mandate_id']);
            $table->index(['entity_id', 'collection_date']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE collections
                ADD CONSTRAINT collections_amount_is_positive
                    CHECK (amount > 0),

                ADD CONSTRAINT collections_sequence_is_known
                    CHECK (sequence_type IN ('FRST', 'RCUR', 'FNAL', 'OOFF')),

                ADD CONSTRAINT collections_state_is_known
                    CHECK (state IN ('presented', 'settled', 'returned', 'refunded')),

                /*
                 | A return carries its reason code.
                 |
                 | The code is what tells an insufficient balance from a
                 | revoked mandate from an account that no longer exists -
                 | three facts with three different responses, and a
                 | return without one is a figure somebody has to chase
                 | the bank about.
                 */
                ADD CONSTRAINT collections_returns_carry_a_reason
                    CHECK (
                        state NOT IN ('returned', 'refunded')
                        OR (return_reason_code IS NOT NULL AND returned_on IS NOT NULL)
                    );

            /*
             | One FRST per mandate, enforced.
             |
             | The scheme-level rejection this task exists to prevent. A
             | partial unique index says it once, so no service, import or
             | retried job can produce a second.
             */
            CREATE UNIQUE INDEX collections_one_first_per_mandate
                ON collections (mandate_id)
                WHERE sequence_type = 'FRST';

            COMMENT ON TABLE collections IS
                'One presentation under a mandate. The sequence type is derived, and a second FRST is refused by a unique index because the bank refuses it at the scheme level - PF-177.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('collections')");

        /*
         | A collection needs a live mandate, checked in the database.
         |
         | A collection against a revoked mandate is returned days later,
         | after the customer has seen it on their statement. Refusing it
         | at the moment somebody tries is the difference between a
         | control and a report - and the service is one door to this
         | table.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_collection_needs_a_live_mandate()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                m record;
            BEGIN
                SELECT state, reference, expires_on
                  INTO m
                  FROM mandates
                 WHERE id = NEW.mandate_id;

                IF m IS NULL THEN
                    RAISE EXCEPTION 'There is no mandate % to collect under.', NEW.mandate_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF m.state <> 'active' THEN
                    RAISE EXCEPTION
                        'Mandate % is %, so there is no authority to collect under it. A collection against a mandate the debtor has revoked is returned days later, after they have seen it on their statement and complained.',
                        m.reference, m.state
                        USING ERRCODE = 'check_violation';
                END IF;

                IF m.expires_on IS NOT NULL AND NEW.collection_date > m.expires_on THEN
                    RAISE EXCEPTION
                        'Mandate % expires on % and this collection is dated %. An expired mandate is no authority at all, and the bank returns the collection.',
                        m.reference, m.expires_on, NEW.collection_date
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER collections_need_a_live_mandate
                BEFORE INSERT ON collections
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_collection_needs_a_live_mandate();

            /*
             | And the mandate's reference never changes.
             |
             | It is what the debtor's bank matches on. Changing it starts
             | a new mandate while claiming the old one's history, so the
             | next collection goes out as RCUR against a mandate the bank
             | has never seen - and is refused at the scheme level.
             */
            CREATE OR REPLACE FUNCTION peakflow_a_mandate_reference_is_fixed()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.reference <> OLD.reference OR NEW.signed_on <> OLD.signed_on THEN
                    RAISE EXCEPTION
                        'Mandate %s reference and signature date are what the debtor''s bank matches on. Changing either starts a new mandate while claiming this one''s collection history, so the next collection goes out as RCUR against a mandate the bank has never seen.',
                        OLD.reference
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER mandates_keep_their_reference
                BEFORE UPDATE ON mandates
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_mandate_reference_is_fixed();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS mandates_keep_their_reference ON mandates;
            DROP TRIGGER IF EXISTS collections_need_a_live_mandate ON collections;
            DROP FUNCTION IF EXISTS peakflow_a_mandate_reference_is_fixed();
            DROP FUNCTION IF EXISTS peakflow_a_collection_needs_a_live_mandate();
        SQL);

        Schema::dropIfExists('collections');
        Schema::dropIfExists('mandate_notifications');
        Schema::dropIfExists('mandates');
    }
};
