<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, and the unapplied cash role they land in - PF-166, PF-167.
 *
 * ## A payment never touches a party balance
 *
 * It posts to the bank or cash account and to a **clearing position**.
 * What a customer owes changes when somebody allocates the money to a
 * document, not when the money arrives, and the reason is that the two are
 * different facts: "they sent 10,000" and "10,000 of their invoices are
 * settled" are only the same once somebody has said which invoices.
 *
 * A system that reduced the balance on receipt produces a customer ledger
 * nobody can take apart - a balance of 4,000 that is three invoices and a
 * receipt, with no way to say which invoice the receipt was for. Every
 * query about it afterwards is a reconstruction.
 *
 * ## So unallocated cash is a liability, and that is correct
 *
 * Not an error state and not a holding pen. Money a customer sent that
 * nothing has been allocated against is money being held for them. Typing
 * it as revenue or as a reduction of receivables makes an unallocated
 * receipt look like performance, which is the shape of a figure somebody
 * eventually has to restate.
 *
 * ## Immutable once posted
 *
 * The same rule as every other posted document in this system. A payment
 * recorded wrongly is reversed, and the reversal is a posting of its own -
 * because "we received 10,000 on the 3rd and it turned out to be 1,000" is
 * a fact about the 3rd, and editing the row deletes it.
 *
 * @financial
  *
 * @online-safe Creates `payments`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('number', 32);
            $table->string('direction', 12);

            /*
             | The method, coded per UNCL 4461 - PF-166.
             |
             | The same code list the e-invoice carries, deliberately: a
             | payment recorded as "transfer" in one place and `30` in
             | another is two vocabularies for one fact, and the
             | reconciliation between them is work nobody budgeted for.
             */
            $table->string('method', 4);

            /*
             | Where the money moved, as one nullable pair.
             |
             | A bank account for a transfer, a cash account for notes.
             | Exactly one of them, enforced below: a payment that names
             | both is a payment nobody can trace, and one that names
             | neither has not happened anywhere.
             */
            $table->char('bank_account_id', 26)->nullable();
            $table->char('cash_account_id', 26)->nullable();

            $table->char('party_id', 26)->nullable();

            $table->decimal('amount', 21, 4);
            $table->char('currency', 3);
            $table->date('value_date');

            $table->text('note')->nullable();
            $table->string('external_reference', 64)->nullable();

            $table->ulid('journal_entry_id')->nullable();
            $table->ulid('recorded_by');
            $table->timestamp('recorded_at');

            $table->timestamp('reversed_at')->nullable();
            $table->ulid('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->char('reverses_id', 26)->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'number']);
            $table->index(['entity_id', 'party_id', 'value_date']);
            $table->index(['entity_id', 'direction', 'value_date']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_direction_is_known
                    CHECK (direction IN ('receipt', 'disbursement')),

                /*
                 | The UNCL 4461 subset this system can actually produce.
                 |
                 | A closed list rather than any code: a method nothing
                 | knows how to post is a payment that reaches the ledger
                 | by whichever branch happened to be last.
                 */
                ADD CONSTRAINT payments_method_is_known
                    CHECK (method IN ('1', '10', '20', '30', '42', '48', '49', '58', '59', '68', '97')),

                ADD CONSTRAINT payments_amount_is_positive
                    CHECK (amount > 0),

                /*
                 | Exactly one account, and it is the direction-neutral
                 | fact about where the money is.
                 |
                 | The sign lives in `direction`, not in the amount: an
                 | amount that could be negative gives two ways to say
                 | "money out" and a query that reads one of them.
                 */
                ADD CONSTRAINT payments_name_exactly_one_account
                    CHECK (
                        (bank_account_id IS NOT NULL AND cash_account_id IS NULL)
                        OR (bank_account_id IS NULL AND cash_account_id IS NOT NULL)
                    ),

                ADD CONSTRAINT payments_reversal_is_explained
                    CHECK (
                        (reversed_at IS NULL AND reversed_by IS NULL)
                        OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL
                            AND length(btrim(coalesce(reversal_reason, ''))) > 10)
                    );
        SQL);

        /*
         | Immutable once posted - PF-166.
         |
         | The same shape as the invoice rule: the columns that say what
         | happened cannot change after the entry exists. Reversal
         | columns can, because that is how a mistake is corrected, and
         | so can `updated_at`.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_a_posted_payment_does_not_change()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.journal_entry_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.amount <> OLD.amount
                   OR NEW.currency <> OLD.currency
                   OR NEW.direction <> OLD.direction
                   OR NEW.method <> OLD.method
                   OR NEW.value_date <> OLD.value_date
                   OR NEW.party_id IS DISTINCT FROM OLD.party_id
                   OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                   OR NEW.cash_account_id IS DISTINCT FROM OLD.cash_account_id
                   OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id
                THEN
                    RAISE EXCEPTION
                        'Payment % was posted and cannot be changed. "We received % on % and it '
                        'turned out to be something else" is a fact about that day, and editing '
                        'the row deletes it. Reverse it, which is a posting of its own.',
                        OLD.number, OLD.amount, OLD.value_date
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER payments_are_immutable_once_posted
                BEFORE UPDATE ON payments
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_a_posted_payment_does_not_change();

            COMMENT ON TABLE payments IS
                'Receipts and disbursements. They post to cash and to a clearing position, never to a party balance - that is a consequence of allocation - PF-166.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('payments')");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payments_are_immutable_once_posted ON payments;
            DROP FUNCTION IF EXISTS peakflow_a_posted_payment_does_not_change();
        SQL);

        Schema::dropIfExists('payments');
    }
};
