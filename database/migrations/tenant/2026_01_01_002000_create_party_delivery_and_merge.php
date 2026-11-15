<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery preferences, and what a party merge can and cannot do - PF-059, PF-063.
 *
 * @financial
 *
 * ## A document is rendered in the RECIPIENT's language
 *
 * Which is the whole reason a locale lives on the contact rather than on the
 * user who pressed send. An invoice from a Saudi company to a German customer
 * is read by somebody in Germany, and sending it in Arabic because the issuer
 * works in Arabic is a document the recipient files without reading - which
 * then ages into a dunning letter about an invoice they could not understand.
 *
 * The delivery preference says which contact, which channel, which address
 * and which format PER DOCUMENT TYPE, because they genuinely differ: an
 * invoice goes to accounts payable as a Peppol document, and a statement goes
 * to the finance director as a PDF by email.
 *
 * ## A merge keeps the loser forever
 *
 * This is the part that looks wrong and is forced.
 *
 * Posted journal lines are immutable - that is the ledger's central
 * invariant - so a merge CANNOT repoint historical postings at the survivor.
 * Which leaves two options: rewrite history, or keep the loser and resolve
 * through it.
 *
 * The loser is kept. `merged_into_id` points at the survivor, the loser is
 * deactivated so nothing new can be addressed to it, and every lookup follows
 * the chain. An invoice issued three years ago still says who it was issued
 * to, which is what an invoice is for - and exposure, statements and aging
 * sum across the chain.
 *
 * The alternative - deleting the loser and repointing - is what every system
 * that has had to explain a merge to an auditor wishes it had not done.
 *
 * ## A chain, not a pair
 *
 * A merged into B, then B merged into C. Following one hop would resolve A to
 * B, which is also merged, so the trigger below refuses merging INTO a party
 * that is itself merged - the chain stays one deep and "who is this really"
 * has one answer rather than a walk.
 *
 * @online-safe Creates a new table and adds one nullable column with a
 *              constant default; no rewrite and no long lock.
 *
 * @unsafe-migration-reviewed: the only flagged statement is the `dropColumn`
 * in `down()`, which runs on a deliberate rollback. It drops the column this
 * migration adds; leaving it behind would leave delivery preferences
 * pointing at contacts whose `receives_documents` flag the rolled-back code
 * does not read, which is worse than dropping it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | Which contact gets which document, how, and in what language.
         |
         | Per entity as well as per party, because the same customer may
         | deal with two of your companies through different people - and a
         | shared preference would send a subsidiary's invoices to the
         | contact who handles the parent's.
         */
        Schema::create('party_delivery_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->foreignUlid('party_id')->constrained('parties')->cascadeOnDelete();

            /*
             | Null means "every document type this party has no specific
             | preference for", which is how almost every party is
             | configured: one default, and an exception for statements.
             */
            $table->string('document_type', 32)->nullable();

            $table->foreignUlid('contact_id')->nullable()
                ->constrained('party_contacts')->nullOnDelete();

            $table->string('channel', 16);

            /*
             | The address for THIS channel, which is not always the
             | contact's own: a customer may want invoices emailed to a
             | shared inbox while the named contact is a person.
             */
            $table->string('address')->nullable();

            // Null means "the contact's locale, then the entity's default".
            $table->string('locale', 8)->nullable();

            $table->string('format', 16)->default('pdf');

            // A copy to somebody else, which is a real requirement and is
            // not a second preference row - it never becomes the primary.
            $table->string('copy_to')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['entity_id', 'party_id', 'document_type']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE party_delivery_preferences
                ADD CONSTRAINT party_delivery_preferences_channel_known
                    CHECK (channel IN ('email', 'portal', 'print', 'peppol', 'sms', 'none')),

                ADD CONSTRAINT party_delivery_preferences_format_known
                    CHECK (format IN ('pdf', 'ubl', 'pdf_ubl', 'csv', 'html')),

                ADD CONSTRAINT party_delivery_preferences_locale_known
                    CHECK (locale IS NULL OR locale IN ('ar', 'en')),

                /*
                 | A channel that needs an address must have one.
                 |
                 | A preference saying "email" with no address is a document
                 | that silently goes nowhere - and the symptom is a customer
                 | who says they never received it, which is indistinguishable
                 | from a customer who did.
                 */
                ADD CONSTRAINT party_delivery_preferences_address_is_present
                    CHECK (channel NOT IN ('email', 'sms', 'peppol') OR address IS NOT NULL),

                -- Peppol is a structured document; a PDF down that channel
                -- is rejected by the access point, not by us.
                ADD CONSTRAINT party_delivery_preferences_peppol_is_structured
                    CHECK (channel <> 'peppol' OR format IN ('ubl', 'pdf_ubl'));

            /*
             | One ACTIVE preference per party, entity and document type.
             |
             | Two would each claim to be the answer and the winner would
             | depend on row order, which is the same ambiguity the price
             | lists refuse. `coalesce` because NULL is not equal to NULL in
             | a unique index, so two defaults would otherwise both be
             | permitted.
             */
            CREATE UNIQUE INDEX party_delivery_preferences_one_per_type
                ON party_delivery_preferences (entity_id, party_id, coalesce(document_type, '*'))
                WHERE active;
        SQL);

        /*
         | Per-contact delivery details, so a contact is a usable recipient.
         |
         | `party_contacts` already has an email, a phone and a locale. What
         | it lacks is whether this contact should receive documents at all -
         | a technical contact is a person to ring, not somebody to send an
         | invoice to, and a preference pointing at one is a document that
         | reaches an engineer.
         */
        Schema::table('party_contacts', function (Blueprint $table): void {
            $table->boolean('receives_documents')->default(false);
        });

        $this->installMergeGuards();

        DB::unprepared("SELECT peakflow_apply_entity_rls('party_delivery_preferences');");

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE party_delivery_preferences IS
                'Which contact gets which document, how, and IN WHAT LANGUAGE. Per entity as well as per '
                'party: the same customer may deal with two of your companies through different people.';

            COMMENT ON COLUMN party_delivery_preferences.locale IS
                'The RECIPIENT''s language, not the sender''s. An invoice sent in Arabic to a German reader '
                'is filed unread, and then ages into a dunning letter about an invoice nobody could read.';
        SQL);
    }

    /**
     * What a merge may and may not do - PF-059.
     *
     * ## The chain stays one deep
     *
     * A merged into B, then B merged into C, would resolve A to B - which is
     * itself merged. Every lookup would have to walk, and a walk can loop.
     * So merging INTO an already-merged party is refused: merge into C
     * directly, which is what was meant.
     *
     * ## A merged party cannot come back
     *
     * Un-merging would have to decide which of the documents written since
     * belong to which party, and there is no information to decide it with.
     * The answer is a new party, which is honest about what happened.
     */
    private function installMergeGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_merge_is_resolvable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                survivor_merged_into char(26);
            BEGIN
                IF NEW.merged_into_id IS NULL THEN
                    /*
                     | Un-merging is refused.
                     |
                     | It would have to decide which of the documents written
                     | since the merge belong to which party, and there is no
                     | information to decide it with. A new party is the
                     | honest answer.
                     */
                    IF OLD.merged_into_id IS NOT NULL THEN
                        RAISE EXCEPTION
                            'Party % was merged into % and cannot be un-merged. Documents issued since then name one party, and nothing records which of them belonged to the other. Create a new party instead.',
                            OLD.id, OLD.merged_into_id
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.merged_into_id IS NOT NULL AND NEW.merged_into_id <> OLD.merged_into_id THEN
                    RAISE EXCEPTION
                        'Party % is already merged into %; it cannot be re-pointed at %. Merge the survivor instead.',
                        OLD.id, OLD.merged_into_id, NEW.merged_into_id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT merged_into_id INTO survivor_merged_into
                FROM parties
                WHERE id = NEW.merged_into_id;

                IF survivor_merged_into IS NOT NULL THEN
                    RAISE EXCEPTION
                        'Party % cannot be merged into %, because % is itself merged into %. Every lookup would then have to walk the chain, and a walk can loop - merge into % directly, which is what was meant.',
                        NEW.id, NEW.merged_into_id, NEW.merged_into_id, survivor_merged_into, survivor_merged_into
                        USING ERRCODE = 'restrict_violation';
                END IF;

                /*
                 | A merge records WHEN, always.
                 |
                 | "When did these become one customer" bounds every question
                 | asked afterwards about which documents belong to which,
                 | and a null date makes all of them unanswerable.
                 */
                IF NEW.merged_at IS NULL THEN
                    NEW.merged_at := now();
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER parties_merge_is_resolvable
                BEFORE UPDATE ON parties
                FOR EACH ROW
                EXECUTE FUNCTION assert_merge_is_resolvable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS parties_merge_is_resolvable ON parties;
            DROP FUNCTION IF EXISTS assert_merge_is_resolvable();
        SQL);

        Schema::table('party_contacts', function (Blueprint $table): void {
            $table->dropColumn('receives_documents');
        });

        Schema::dropIfExists('party_delivery_preferences');
    }
};
