<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivering a document on a channel a customer reads - PF-257.
 *
 * ## Consent, with its date, before anything is sent
 *
 * Sending an invoice to a wrong number is a data breach with a
 * timestamp on it. The number is somebody's, it carries a name and an
 * amount, and the person who receives it has no relationship with the
 * business.
 *
 * So `messaging_consents` holds the number, the channel, who gave
 * consent and WHEN, and whether the number has been verified as
 * reachable. Nothing is sent without a row here, and the row is per
 * channel: agreeing to be telephoned is not agreeing to be sent an
 * invoice on WhatsApp.
 *
 * ## An attempt per try, with its outcome
 *
 * "Did they get the invoice" has to have an answer that is not a guess.
 * So each attempt is a row: the channel, the address, the template, the
 * provider's id for it, and then the states as they arrive - accepted,
 * delivered, read, failed.
 *
 * `accepted` is not `delivered` and `delivered` is not `read`. A
 * product that collapsed them would answer "we sent it" to a question
 * about whether somebody saw it, which is the answer that ends an
 * argument badly.
 *
 * ## A failure never changes the document
 *
 * There is deliberately no foreign key from `invoices` to the attempt,
 * and no column on the document for its delivery state. An invoice is
 * ISSUED and UNDELIVERED, which are two facts; a product that marked
 * the document would have a state machine where a network error moves
 * an issued invoice, and the first time that happens the hash chain
 * disagrees with the row.
 *
 * ## Inbound replies land where a person sees them
 *
 * A channel that only sends is a channel customers learn to ignore. So
 * `messaging_inbound` holds what came back, unread until somebody reads
 * it, with the party resolved where the number is known - and NOT
 * resolved where it is not, because guessing which customer a number
 * belongs to is how a reply is read by the wrong account manager.
 *
 * @financial-not-applicable: records consent, delivery attempts and inbound replies. No amount, no balance and no posting - the figures in a message are rendered from a document that has its own entity scope, and these tables carry `entity_id` with RLS all the same because a consent is per entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_consents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->char('party_id', 26);

            $table->string('channel', 24);

            /*
             | The number in E.164, which is the only form that is
             | comparable.
             |
             | `+966 50 123 4567`, `00966501234567` and `0501234567` are
             | one number written three ways, and a product that stored
             | what somebody typed would send one invoice to a number it
             | has consent for and refuse the next.
             */
            $table->string('address', 32);

            $table->timestamp('consented_at');

            /*
             | How the consent was given, in a sentence.
             |
             | "Ticked the box on the portal", "said so on the telephone
             | on the 3rd", "signed the contract clause". A regulator
             | asking about consent is asking this, and a boolean
             | answers none of it.
             */
            $table->text('consent_basis');
            $table->ulid('recorded_by');

            /*
             | Verified separately from consented.
             |
             | Consent is a decision the customer made; reachability is a
             | fact about the number. Both are needed and neither
             | implies the other: a customer can agree and give a number
             | with a digit missing.
             */
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by_code', 12)->nullable();

            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawn_reason')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'party_id', 'channel', 'address']);
            $table->index(['entity_id', 'address']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE messaging_consents
                ADD CONSTRAINT messaging_consents_channel_is_known
                    CHECK (channel IN ('whatsapp', 'sms', 'email')),

                /*
                 | E.164: a plus and up to fifteen digits.
                 |
                 | Asserted here rather than trusted from a form,
                 | because this column is compared against what a
                 | provider reports and against what a reply arrives
                 | from - and three spellings of one number make all
                 | three comparisons wrong.
                 */
                ADD CONSTRAINT messaging_consents_address_is_comparable
                    CHECK (
                        channel <> 'email'
                        OR address ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
                    ),

                ADD CONSTRAINT messaging_consents_numbers_are_e164
                    CHECK (
                        channel = 'email'
                        OR address ~ '^\+[1-9][0-9]{6,14}$'
                    ),

                ADD CONSTRAINT messaging_consents_say_how_it_was_given
                    CHECK (length(btrim(consent_basis)) >= 10),

                ADD CONSTRAINT messaging_consents_verification_is_attributable
                    CHECK (
                        (verified_at IS NULL AND verified_by_code IS NULL)
                        OR (verified_at IS NOT NULL AND verified_by_code IS NOT NULL)
                    ),

                /*
                 | Withdrawal is a date and a reason, and it is final.
                 |
                 | Not a deleted row: "did we have consent in March" is
                 | asked after a complaint, and a row that was removed
                 | answers it with silence.
                 */
                ADD CONSTRAINT messaging_consents_withdrawal_says_why
                    CHECK (
                        (withdrawn_at IS NULL AND withdrawn_reason IS NULL)
                        OR (withdrawn_at IS NOT NULL AND length(btrim(coalesce(withdrawn_reason, ''))) >= 10)
                    );

            COMMENT ON TABLE messaging_consents IS
                'Who agreed to be sent what, on which number, and when - sending an invoice to a wrong number is a data breach with a timestamp on it - PF-257.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('messaging_consents')");

        Schema::create('messaging_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | What was sent, as a type and an id rather than a foreign
             | key.
             |
             | Because an invoice, a credit note, a statement and a
             | payment receipt all get delivered, and a column per
             | document type is a migration every time a new one is
             | added. The document's own tables carry no delivery state
             | at all - see the class note.
             */
            $table->string('document_type', 32);
            $table->char('document_id', 26);
            $table->char('party_id', 26);

            $table->string('channel', 24);
            $table->string('address', 32);
            $table->string('template', 64)->nullable();
            $table->string('language', 8)->nullable();

            /*
             | Our reference, so a retry is not a second message.
             |
             | The same contract as PF-255's client id, and for a sharper
             | reason here: a provider charges per message, and a
             | customer who receives two copies of one invoice
             | telephones about it.
             */
            $table->string('reference', 64);
            $table->unsignedSmallInteger('attempt');

            $table->string('state', 16);
            $table->string('provider_message_id', 128)->nullable();
            $table->string('failure_code', 48)->nullable();
            $table->text('outcome');

            $table->timestamp('sent_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->unique('reference');
            $table->index(['entity_id', 'document_type', 'document_id']);
            $table->index(['entity_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE messaging_attempts
                ADD CONSTRAINT messaging_attempts_state_is_known
                    CHECK (state IN ('accepted', 'delivered', 'read', 'failed')),

                ADD CONSTRAINT messaging_attempts_say_what_happened
                    CHECK (length(btrim(outcome)) >= 10),

                /*
                 | Accepted is not delivered, and delivered is not read.
                 |
                 | The timestamps have to follow the state, or "did they
                 | read it" is answered from a column that was set
                 | because the provider said "queued". That is the
                 | answer that ends an argument badly.
                 */
                ADD CONSTRAINT messaging_attempts_delivery_follows_acceptance
                    CHECK (delivered_at IS NULL OR state IN ('delivered', 'read')),

                ADD CONSTRAINT messaging_attempts_reading_follows_delivery
                    CHECK (
                        read_at IS NULL
                        OR (state = 'read' AND delivered_at IS NOT NULL AND read_at >= delivered_at)
                    ),

                /*
                 | A failure names its code; a success does not.
                 |
                 | The code is what makes the right response possible: a
                 | template not approved needs somebody to submit it, an
                 | unreachable number needs the customer contacted
                 | another way, a rate limit needs only patience. A
                 | single "failed" sends all three nowhere.
                 */
                ADD CONSTRAINT messaging_attempts_failures_name_a_code
                    CHECK (
                        (state = 'failed' AND failure_code IS NOT NULL AND provider_message_id IS NULL)
                        OR (state <> 'failed' AND failure_code IS NULL)
                    ),

                ADD CONSTRAINT messaging_attempts_accepted_has_an_id
                    CHECK (state = 'failed' OR provider_message_id IS NOT NULL),

                ADD CONSTRAINT messaging_attempts_are_counted_from_one
                    CHECK (attempt >= 1);

            COMMENT ON TABLE messaging_attempts IS
                'One row per try, with the states as they arrive - "did they get the invoice" has to have an answer that is not a guess, and a failure never changes the document - PF-257.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('messaging_attempts')");

        /*
         | An attempt is a fact. Its state moves FORWARD only.
         |
         | accepted -> delivered -> read, and anything -> failed. A
         | product that let a read go back to delivered would answer
         | "did they read it" differently depending on when the question
         | was asked.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_delivery_only_moves_forward()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                rank_old int;
                rank_new int;
            BEGIN
                IF NEW.reference <> OLD.reference
                   OR NEW.document_id <> OLD.document_id
                   OR NEW.address <> OLD.address
                   OR NEW.sent_at <> OLD.sent_at THEN
                    RAISE EXCEPTION
                        'What was sent, to whom and when is a fact. Changing it makes "did they get the invoice" unanswerable, which is the question this log exists for.'
                        USING ERRCODE = 'check_violation';
                END IF;

                rank_old := CASE OLD.state
                    WHEN 'failed' THEN 0 WHEN 'accepted' THEN 1
                    WHEN 'delivered' THEN 2 WHEN 'read' THEN 3 END;
                rank_new := CASE NEW.state
                    WHEN 'failed' THEN 0 WHEN 'accepted' THEN 1
                    WHEN 'delivered' THEN 2 WHEN 'read' THEN 3 END;

                /*
                 | `failed` is reachable from `accepted` and nowhere
                 | else: a message the provider accepted can still bounce,
                 | and one that was READ did not fail.
                 */
                IF NEW.state = 'failed' AND OLD.state <> 'accepted' THEN
                    RAISE EXCEPTION
                        'An attempt that was % cannot become failed. A message somebody read did not fail.',
                        OLD.state
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.state <> 'failed' AND rank_new < rank_old THEN
                    RAISE EXCEPTION
                        'Delivery states move forward: accepted, delivered, read. Going back from % to % would answer "did they read it" differently depending on when the question was asked.',
                        OLD.state, NEW.state
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER messaging_attempts_move_forward
                BEFORE UPDATE ON messaging_attempts
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_delivery_only_moves_forward();

            CREATE OR REPLACE FUNCTION peakflow_an_attempt_is_not_deleted()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'A delivery attempt is the record of what was sent to a customer. Removing one is how "we never sent that" becomes true after the fact.'
                    USING ERRCODE = 'check_violation';
            END;
            $$;

            CREATE TRIGGER messaging_attempts_are_not_deleted
                BEFORE DELETE ON messaging_attempts
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_an_attempt_is_not_deleted();
        SQL);

        Schema::create('messaging_inbound', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('channel', 24);
            $table->string('address', 32);

            /*
             | Resolved where the number is known, and NULL where it is
             | not.
             |
             | Guessing which customer a number belongs to is how a reply
             | is read by the wrong account manager - and the wrong
             | account manager then answers a question about somebody
             | else's invoice.
             */
            $table->char('party_id', 26)->nullable();

            $table->text('body');
            $table->string('provider_message_id', 128);
            $table->timestamp('received_at');

            /*
             | Unread until somebody reads it.
             |
             | A channel that only sends is a channel customers learn to
             | ignore, and the way that happens is replies arriving
             | somewhere nobody looks.
             */
            $table->timestamp('read_at')->nullable();
            $table->ulid('read_by')->nullable();

            $table->timestamps();

            $table->unique('provider_message_id');
            $table->index(['entity_id', 'read_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE messaging_inbound
                ADD CONSTRAINT messaging_inbound_channel_is_known
                    CHECK (channel IN ('whatsapp', 'sms', 'email')),

                ADD CONSTRAINT messaging_inbound_carries_something
                    CHECK (length(btrim(body)) >= 1),

                ADD CONSTRAINT messaging_inbound_reading_is_attributable
                    CHECK (
                        (read_at IS NULL AND read_by IS NULL)
                        OR (read_at IS NOT NULL AND read_by IS NOT NULL)
                    );

            COMMENT ON TABLE messaging_inbound IS
                'What came back, unread until somebody reads it - a channel that only sends is a channel customers learn to ignore - PF-257.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('messaging_inbound')");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS messaging_attempts_are_not_deleted ON messaging_attempts;
            DROP TRIGGER IF EXISTS messaging_attempts_move_forward ON messaging_attempts;
            DROP FUNCTION IF EXISTS peakflow_an_attempt_is_not_deleted();
            DROP FUNCTION IF EXISTS peakflow_delivery_only_moves_forward();
        SQL);

        Schema::dropIfExists('messaging_inbound');
        Schema::dropIfExists('messaging_attempts');
        Schema::dropIfExists('messaging_consents');
    }
};
