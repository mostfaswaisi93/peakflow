<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A batch entry's underlying transactions - PF-150.
 *
 * ## Why the entry and its transactions are different rows
 *
 * A payment run of fifty supplier payments arrives on the statement as ONE
 * debit. The bank took one amount on one day, so for the bank balance the
 * entry is the movement - and it is the entry that has to sum into the
 * statement's closing balance, or the account can never be reconciled.
 *
 * But nothing in that entry matches a document. The fifty references, the
 * fifty beneficiaries and the fifty amounts are inside `NtryDtls/TxDtls`,
 * and they are what the matching engine needs. A parser that read only the
 * entry would make a payment run permanently unmatchable; one that read
 * only the transactions would import fifty movements the bank never made
 * and the statement would never tie.
 *
 * So: the entry is the bank transaction, and its `TxDtls` become detail
 * rows that the matcher reads. The detail rows carry no balance meaning at
 * all - nothing sums them into anything - which is the point.
 *
 * ## The single-transaction case writes a detail row too
 *
 * Most entries carry exactly one `TxDtls`, and its remittance and
 * counterparty are flattened onto the transaction itself because that is
 * what every query and the identity key read. The detail row is still
 * written, so "what did the bank say about this line" has one answer for
 * every line rather than two shapes to handle.
 *
 * ## They are as immutable as the transaction
 *
 * Same reason: a third party's assertion. What we think about a detail -
 * which party, which invoice - belongs with the match, not here.
  *
 * @online-safe Creates `bank_transaction_details`, `bank_transaction_details_default`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | Raw SQL, and partitioned by the same key as its parent.
         |
         | A foreign key to a partitioned table must carry the partition
         | key, so the detail rows carry `booking_date` as well - which also
         | means the details of a month can be detached with the month, the
         | reason `bank_transactions` is partitioned in the first place.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE bank_transaction_details (
                id                   char(26)       NOT NULL,
                entity_id            char(26)       NOT NULL,
                bank_transaction_id  char(26)       NOT NULL,
                booking_date         date           NOT NULL,

                /*
                 | Its position within the entry, and whether it is a batch.
                 |
                 | `sequence` is ours: the order the details appeared in the
                 | file. `is_batch` records that the parent entry was a
                 | batch rather than a single transaction, because the two
                 | read very differently on screen and the distinction is
                 | lost once the rows look alike.
                 */
                sequence             integer        NOT NULL,
                is_batch             boolean        NOT NULL DEFAULT false,

                /*
                 | Signed the same way as the transaction.
                 |
                 | A batch of refunds inside an outgoing run is a credit
                 | among debits, so a detail's direction is its own. Storing
                 | the bank's positive amount plus an indicator would make
                 | every consumer apply the sign, and the one that forgets
                 | reports a refund as a payment.
                 */
                amount               numeric(21, 4) NOT NULL,
                currency             char(3)        NOT NULL,

                counterparty_name    text           NULL,
                counterparty_account text           NULL,
                counterparty_bic     varchar(11)    NULL,

                remittance_unstructured text        NULL,
                remittance_structured   jsonb       NULL,

                end_to_end_id        varchar(64)    NULL,
                mandate_id           varchar(64)    NULL,
                instruction_id       varchar(64)    NULL,

                /*
                 | FX, per detail.
                 |
                 | A batch can mix currencies: one EUR payment among SAR
                 | ones is booked in the account's currency with the
                 | instructed amount recorded. On the entry there would be
                 | nowhere to put it.
                 */
                instructed_amount    numeric(21, 4) NULL,
                instructed_currency  char(3)        NULL,
                exchange_rate        numeric(21, 12) NULL,

                charges_amount       numeric(21, 4) NULL,

                created_at           timestamp(0)   NULL,
                updated_at           timestamp(0)   NULL,

                PRIMARY KEY (id, booking_date),

                CONSTRAINT bank_transaction_details_currency_is_an_alpha3
                    CHECK (currency ~ '^[A-Z]{3}$'),

                CONSTRAINT bank_transaction_details_sequence_is_positive
                    CHECK (sequence > 0),

                CONSTRAINT bank_transaction_details_amount_is_not_zero
                    CHECK (amount <> 0),

                /*
                 | An instructed amount names its currency and its rate.
                 |
                 | The same rule as the transaction's. An instructed amount
                 | with no currency is a figure nobody can use, and with no
                 | rate it cannot be reconciled to what was booked.
                 */
                CONSTRAINT bank_transaction_details_fx_is_complete
                    CHECK (
                        instructed_amount IS NULL
                        OR (instructed_currency IS NOT NULL AND exchange_rate IS NOT NULL)
                    ),

                CONSTRAINT bank_transaction_details_are_distinct
                    UNIQUE (bank_transaction_id, booking_date, sequence)
            ) PARTITION BY RANGE (booking_date);

            CREATE INDEX bank_transaction_details_parent_idx
                ON bank_transaction_details (bank_transaction_id, booking_date);
            CREATE INDEX bank_transaction_details_end_to_end_idx
                ON bank_transaction_details (end_to_end_id) WHERE end_to_end_id IS NOT NULL;
            CREATE INDEX bank_transaction_details_entity_idx
                ON bank_transaction_details (entity_id, booking_date DESC);
        SQL);

        $this->createPartitions();

        /*
         | Immutable, like its parent.
         |
         | A detail row is part of the same third-party assertion. What we
         | think about it - which party, which invoice, what it was for -
         | belongs with the match.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_transaction_details_are_the_banks()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'The detail lines of a bank entry are what the bank said was inside it, and are '
                    'not ours to correct. A batch whose detail lines have been edited reconciles to '
                    'our ledger and to nothing the bank has ever sent. What we think a detail line '
                    'refers to belongs with the match.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER bank_transaction_details_are_the_banks
                BEFORE UPDATE ON bank_transaction_details
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_transaction_details_are_the_banks();

            COMMENT ON TABLE bank_transaction_details IS
                'peakflow:no-delete peakflow:tenant-scoped - a batch entry is one movement on the '
                'statement and many transactions to match. The entry reconciles; these match - PF-150.';
        SQL);

        DB::unprepared("SELECT peakflow_apply_entity_rls('bank_transaction_details')");
    }

    /**
     * The same window as the transactions, for the same reason.
     *
     * A partition created during a request is a DDL lock taken while
     * somebody is importing.
     */
    private function createPartitions(): void
    {
        $start = now()->startOfMonth()->subMonths(18);

        for ($i = 0; $i < 54; $i++) {
            $from = $start->copy()->addMonths($i);
            $to = $from->copy()->addMonth();

            DB::unprepared(sprintf(
                'CREATE TABLE %s PARTITION OF bank_transaction_details FOR VALUES FROM (%s) TO (%s);',
                'bank_transaction_details_'.$from->format('Y_m'),
                "'".$from->toDateString()."'",
                "'".$to->toDateString()."'",
            ));
        }

        DB::unprepared(
            'CREATE TABLE bank_transaction_details_default PARTITION OF bank_transaction_details DEFAULT;'
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_transaction_details_are_the_banks ON bank_transaction_details;
            DROP FUNCTION IF EXISTS peakflow_transaction_details_are_the_banks();
            DROP TABLE IF EXISTS bank_transaction_details;
        SQL);
    }
};
