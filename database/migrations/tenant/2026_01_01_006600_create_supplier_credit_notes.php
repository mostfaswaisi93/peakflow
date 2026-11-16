<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier credit notes and their allocation - PF-134.
 *
 * ## A credit note is a bill of another type, not a bill with a minus sign
 *
 * `document_type` already distinguishes them (`380` an invoice, `381` a
 * credit note, UNCL 1001), so a credit note carries positive figures and its
 * own posting rule produces the opposite debits and credits. Storing it as a
 * negative bill would make every query about "what do we owe this supplier"
 * depend on remembering to sum rather than filter, and the first report that
 * forgot would show a credit as a payable.
 *
 * ## The reference is mandatory
 *
 * A credit note either names the bill it credits (`credits_bill_id`) or
 * states the supplier's own document reference in words. A credit against
 * nothing is a figure nobody can tie to a purchase, and it is the shape a
 * supplier's balance takes when somebody has keyed a rebate twice.
 *
 * A volume rebate genuinely has no single invoice behind it, which is why the
 * second form exists - but it still has to say what it is for.
 *
 * ## Allocation posts nothing
 *
 * A credit note and a bill are both in `accounts_payable` against the same
 * party. Allocating one to the other nets two items inside one control
 * account for one counterparty, which changes no balance anywhere: the
 * allocation is what makes the open-item statement right, not an accounting
 * event. A system that posted a journal for it would write two entries that
 * cancel, and somebody would spend an afternoon working out why the ledger
 * has twice as many lines as it has transactions.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->foreignUlid('credits_bill_id')->nullable()
                ->constrained('bills')->nullOnDelete();
            $table->text('credit_reference')->nullable();
            $table->string('credit_disposition', 16)->nullable();
        });

        Schema::table('bill_lines', function (Blueprint $table): void {
            /*
             | Which line of the original this one credits.
             |
             | So the bill can show which of its lines have been credited
             | and by how much, which is the question a buyer asks about a
             | partial return - and the question a line-level total on the
             | bill could answer only until somebody credited the same line
             | twice.
             */
            $table->foreignUlid('credits_line_id')->nullable()
                ->constrained('bill_lines')->nullOnDelete();
        });

        Schema::create('bill_credit_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->foreignUlid('credit_note_id')->constrained('bills')->cascadeOnDelete();
            $table->foreignUlid('bill_id')->constrained('bills')->cascadeOnDelete();

            $table->char('currency', 3);
            $table->decimal('amount', 21, 4);

            $table->timestamp('allocated_at');
            $table->ulid('allocated_by')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            /*
             | One row per pair.
             |
             | Two allocations of the same credit to the same bill are two
             | answers to "how much of this credit has been used", and the
             | code reading them would take whichever came back first. A
             | changed allocation is an update of the row, which is what the
             | service does.
             */
            $table->unique(['credit_note_id', 'bill_id']);
            $table->index(['entity_id', 'bill_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bills
                ADD CONSTRAINT bills_a_credit_note_says_what_it_credits
                    CHECK (
                        document_type <> '381'
                        OR credits_bill_id IS NOT NULL
                        OR length(btrim(coalesce(credit_reference, ''))) > 3
                    ),

                ADD CONSTRAINT bills_credit_fields_belong_to_a_credit_note
                    CHECK (
                        document_type = '381'
                        OR (credits_bill_id IS NULL AND credit_reference IS NULL
                            AND credit_disposition IS NULL)
                    ),

                /*
                 | Allocate it, or take the money back.
                 |
                 | A credit with no disposition is a balance nobody has
                 | decided about, which is how a supplier ends up holding
                 | money that was never claimed. The two are different
                 | events: an allocation reduces what we owe, a refund is
                 | cash coming in.
                 */
                ADD CONSTRAINT bills_credit_disposition_is_known
                    CHECK (
                        credit_disposition IS NULL
                        OR credit_disposition IN ('allocate', 'refund')
                    ),

                ADD CONSTRAINT bills_a_credit_note_does_not_credit_itself
                    CHECK (credits_bill_id IS NULL OR credits_bill_id <> id);

            ALTER TABLE bill_credit_allocations
                ADD CONSTRAINT bill_credit_allocations_are_positive
                    CHECK (amount > 0),

                ADD CONSTRAINT bill_credit_allocations_are_not_self_referential
                    CHECK (credit_note_id <> bill_id);
        SQL);

        /*
         | The over-credit guarantee, in the database.
         |
         | "Cannot exceed the bill's remaining uncredited value" is the whole
         | of PF-134's second criterion, and a service check is bypassed by
         | an import, a second worker or a retried job. Two credit notes
         | entered at the same moment against one bill would both pass a
         | check that read the total before either was written.
         |
         | The trigger reads the committed total with the row's own values
         | included, so the second one is refused by the database rather
         | than by whoever reconciles the supplier's statement.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_refuse_over_crediting_a_bill()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                original    RECORD;
                credited    numeric(21, 4);
            BEGIN
                IF NEW.document_type <> '381' OR NEW.credits_bill_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT gross_amount, currency, party_id
                INTO original
                FROM bills
                WHERE id = NEW.credits_bill_id;

                IF original IS NULL THEN
                    RETURN NEW;
                END IF;

                IF original.currency <> NEW.currency THEN
                    RAISE EXCEPTION
                        'A credit note in % cannot credit a bill in %. Converting one to compare them '
                        'would make the remaining uncredited value depend on the day somebody asked.',
                        NEW.currency, original.currency
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF original.party_id <> NEW.party_id THEN
                    RAISE EXCEPTION
                        'A credit note from one supplier cannot credit another supplier''s bill. The '
                        'credit would reduce a balance that was never owed to whoever issued it.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT coalesce(sum(gross_amount), 0)
                INTO credited
                FROM bills
                WHERE document_type = '381'
                  AND credits_bill_id = NEW.credits_bill_id
                  AND status <> 'cancelled'
                  AND id <> NEW.id;

                IF credited + NEW.gross_amount > original.gross_amount THEN
                    RAISE EXCEPTION
                        'Crediting % would take the total credited against this bill to % of %. A '
                        'credit beyond what was charged is a refund the supplier has not agreed to, '
                        'and the difference is found by whoever reconciles their statement.',
                        NEW.gross_amount,
                        credited + NEW.gross_amount,
                        original.gross_amount
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bills_are_not_over_credited
                BEFORE INSERT OR UPDATE ON bills
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_refuse_over_crediting_a_bill();
        SQL);

        /*
         | And the same guarantee for allocations, from both ends.
         |
         | A credit cannot be allocated beyond its own value, and a bill
         | cannot have more allocated to it than it still owes. Either way
         | round the result is a supplier balance that does not tie to the
         | documents behind it.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_refuse_over_allocating_a_credit()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                credit      RECORD;
                target      RECORD;
                allocated   numeric(21, 4);
                against     numeric(21, 4);
            BEGIN
                SELECT gross_amount, currency, party_id, document_type
                INTO credit
                FROM bills WHERE id = NEW.credit_note_id;

                SELECT gross_amount, paid_amount, currency, party_id, document_type
                INTO target
                FROM bills WHERE id = NEW.bill_id;

                IF credit.document_type <> '381' THEN
                    RAISE EXCEPTION
                        'Only a credit note can be allocated. Allocating one invoice against another '
                        'would net two amounts the business owes into one it does not.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF target.document_type <> '380' THEN
                    RAISE EXCEPTION
                        'A credit note can only be allocated to an invoice.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF credit.party_id <> target.party_id THEN
                    RAISE EXCEPTION
                        'A credit from one supplier cannot be allocated to another supplier''s bill. '
                        'The money is owed to whoever issued the credit.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF credit.currency <> target.currency OR NEW.currency <> credit.currency THEN
                    RAISE EXCEPTION
                        'An allocation crosses currencies (% against %). The gain or loss on that '
                        'conversion is a posting, which an allocation deliberately is not.',
                        credit.currency, target.currency
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT coalesce(sum(amount), 0) INTO allocated
                FROM bill_credit_allocations
                WHERE credit_note_id = NEW.credit_note_id AND id <> NEW.id;

                IF allocated + NEW.amount > credit.gross_amount THEN
                    RAISE EXCEPTION
                        'Allocating % would use % of a credit note worth %.',
                        NEW.amount, allocated + NEW.amount, credit.gross_amount
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT coalesce(sum(amount), 0) INTO against
                FROM bill_credit_allocations
                WHERE bill_id = NEW.bill_id AND id <> NEW.id;

                IF against + NEW.amount > target.gross_amount - target.paid_amount THEN
                    RAISE EXCEPTION
                        'Allocating % would cover % of a bill with % still outstanding. A bill cannot '
                        'be settled twice.',
                        NEW.amount,
                        against + NEW.amount,
                        target.gross_amount - target.paid_amount
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bill_credit_allocations_do_not_exceed_either_side
                BEFORE INSERT OR UPDATE ON bill_credit_allocations
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_refuse_over_allocating_a_credit();
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON CONSTRAINT bills_a_credit_note_says_what_it_credits ON bills IS
                'A credit against nothing is a figure nobody can tie to a purchase - PF-134.';
            COMMENT ON FUNCTION peakflow_refuse_over_crediting_a_bill() IS
                'Two credit notes entered at the same moment would both pass a service check that read the total before either was written.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('bill_credit_allocations')");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bill_credit_allocations_do_not_exceed_either_side ON bill_credit_allocations;
            DROP FUNCTION IF EXISTS peakflow_refuse_over_allocating_a_credit();
            DROP TRIGGER IF EXISTS bills_are_not_over_credited ON bills;
            DROP FUNCTION IF EXISTS peakflow_refuse_over_crediting_a_bill();
        SQL);

        Schema::dropIfExists('bill_credit_allocations');

        Schema::table('bill_lines', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: the rollback of a column added in
            // this same migration, so nothing deployed before it reads it.
            $table->dropColumn(['credits_line_id']);
        });

        Schema::table('bills', function (Blueprint $table): void {
            // @unsafe-migration-reviewed: as above.
            $table->dropColumn(['credits_bill_id', 'credit_reference', 'credit_disposition']);
        });
    }
};
