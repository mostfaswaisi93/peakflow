<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank transactions - PF-148, PF-149.
 *
 * ## They are a third party's assertion, and immutable
 *
 * A bank transaction is what the bank says happened. It is not ours to
 * correct: a line we disagree with is a line to dispute with the bank, and
 * editing it locally produces a statement that reconciles perfectly to a
 * ledger and to nothing the bank has ever sent.
 *
 * So the imported columns are append-only, enforced by a trigger, and
 * everything we think about a transaction lives in
 * `bank_transaction_enrichment` - separate nullable columns beside it rather
 * than rewriting the imported value. `DELETE` is revoked at the role level,
 * like the journal.
 *
 * ## Two dates, and collapsing them is a bug that surfaces at month end
 *
 * The **booking date** is when the bank moved it, and the GL posts on that.
 * The **value date** is when it earns or costs interest, and forecasting and
 * FX use that. They differ by days on a cross-border payment and by weeks on
 * a cheque, and a schema with one date has to choose which of interest and
 * the trial balance to be wrong about.
 *
 * ## Idempotency, because people re-download overlapping files constantly
 *
 * Keyed on `(account, bank_reference)` where the bank supplies one, and
 * otherwise on a content hash of the fields that identify a line. Both are
 * one unique index on a single generated column, because two partial indexes
 * would let a line with a reference and a line without one collide in
 * neither.
 *
 * The hash includes the line's **sequence within its statement**, so two
 * genuinely identical transactions on one day stay two transactions. A
 * customer paying the same amount twice in a morning is ordinary, and
 * deduplication that swallowed the second would hide a real duplicate
 * payment - which is the error this whole area exists to catch.
 *
 * @financial
  *
 * @online-safe Creates `bank_transaction_enrichment`, `bank_transactions`, `bank_transactions_`, `bank_transactions_default`, with the constraints,
 *              indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    private const MONTHS_BACK = 18;

    private const MONTHS_AHEAD = 18;

    public function up(): void
    {
        /*
         | Raw SQL, because the table is partitioned and generated.
         |
         | The schema builder cannot express `PARTITION BY RANGE` or a
         | `GENERATED ALWAYS AS` column, and the identity key has to be
         | generated: computing it in the application means an import that
         | forgot to is an import that duplicates, and the index cannot see
         | the difference.
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE bank_transactions (
                id                  char(26)       NOT NULL,
                entity_id           char(26)       NOT NULL,
                bank_account_id     char(26)       NOT NULL,
                bank_statement_id   char(26)       NULL,

                -- The bank moved it on this date, and the GL posts on it.
                booking_date        date           NOT NULL,

                -- It earns or costs interest from this one. Interest, FX and
                -- forecasting use the value date; collapsing the two is a bug
                -- that surfaces at month end.
                value_date          date           NOT NULL,

                -- Positive is money in. One signed column rather than a debit
                -- and a credit, for the same reason the ledger has one: two
                -- columns permit a row that is both, and the first report to
                -- read only one of them is silently wrong.
                amount              numeric(21, 4) NOT NULL,
                currency            char(3)        NOT NULL,

                counterparty_name   text           NULL,
                counterparty_account text          NULL,
                counterparty_bic    varchar(11)    NULL,

                -- Unstructured is what most banks send; structured is what
                -- makes a document-level match possible, and it is kept as
                -- the bank sent it rather than flattened into the other.
                remittance_unstructured text       NULL,
                remittance_structured   jsonb      NULL,

                bank_reference      varchar(64)    NULL,
                end_to_end_id       varchar(64)    NULL,
                mandate_id          varchar(64)    NULL,

                -- ISO 20022, as domain/family/sub-family. Retained in three
                -- parts because the routing in PF-155 reads the family and a
                -- flattened string would have to be re-split by everything
                -- that uses it.
                code_domain         varchar(8)     NULL,
                code_family         varchar(8)     NULL,
                code_subfamily      varchar(8)     NULL,
                bank_code_proprietary varchar(64)  NULL,

                -- A reversal is a fact about the transaction, not a second
                -- transaction: the bank says this entry undoes one.
                is_reversal         boolean        NOT NULL DEFAULT false,
                reverses_reference  varchar(64)    NULL,

                charges_amount      numeric(21, 4) NULL,
                instructed_amount   numeric(21, 4) NULL,
                instructed_currency char(3)        NULL,
                exchange_rate       numeric(21, 12) NULL,

                -- Where the line sat in the file. Part of the identity of a
                -- line the bank gave no reference to, and the only thing that
                -- keeps two identical transactions on one day distinct.
                statement_sequence  integer        NOT NULL,

                -- Provisional until a booked statement supersedes it - an
                -- intraday report feeds the cash position and does not post.
                is_provisional      boolean        NOT NULL DEFAULT false,
                superseded_by_id    char(26)       NULL,

                source_format       varchar(16)    NOT NULL,
                imported_at         timestamp(0)   NOT NULL,

                -- The raw line, as it arrived.
                source_payload      jsonb          NULL,

                created_at          timestamp(0)   NULL,
                updated_at          timestamp(0)   NULL,

                /*
                 | The identity key, generated rather than computed.
                 |
                 | The bank's reference where there is one, and otherwise a
                 | hash of the fields that identify a line - including its
                 | sequence, so two identical transactions on one day stay
                 | two. Generated in the database because an import that
                 | forgot to compute it is an import that duplicates, and the
                 | unique index below cannot see the difference.
                 */
                identity_key text GENERATED ALWAYS AS (
                    CASE
                        WHEN bank_reference IS NOT NULL AND btrim(bank_reference) <> ''
                            THEN 'ref:' || upper(btrim(bank_reference))
                        ELSE 'hash:' || encode(sha256(
                            (
                                /*
                                 | The date as a day count, not as text.
                                 |
                                 | `to_char` and `date::text` are STABLE
                                 | rather than immutable - they read
                                 | `DateStyle` - so PostgreSQL refuses them
                                 | in a generated column, and rightly: the
                                 | key would change meaning with a session
                                 | setting. Subtracting two dates gives an
                                 | integer, which is immutable and which
                                 | cannot be read differently by anybody.
                                 */
                                (value_date - DATE '2000-01-01')::text || '|' ||
                                amount::text || '|' ||
                                coalesce(currency, '') || '|' ||
                                coalesce(upper(btrim(counterparty_account)), '') || '|' ||
                                coalesce(upper(btrim(counterparty_name)), '') || '|' ||
                                coalesce(btrim(remittance_unstructured), '') || '|' ||
                                statement_sequence::text
                            )::bytea
                        ), 'hex')
                    END
                ) STORED,

                PRIMARY KEY (id, booking_date)
            ) PARTITION BY RANGE (booking_date);

            CREATE UNIQUE INDEX bank_transactions_are_imported_once
                ON bank_transactions (bank_account_id, identity_key, booking_date);

            CREATE INDEX bank_transactions_account_booking_idx
                ON bank_transactions (bank_account_id, booking_date DESC);
            CREATE INDEX bank_transactions_account_value_idx
                ON bank_transactions (bank_account_id, value_date DESC);
            CREATE INDEX bank_transactions_statement_idx
                ON bank_transactions (bank_statement_id, statement_sequence);
            CREATE INDEX bank_transactions_amount_idx
                ON bank_transactions (bank_account_id, amount, booking_date DESC);
            CREATE INDEX bank_transactions_end_to_end_idx
                ON bank_transactions (end_to_end_id) WHERE end_to_end_id IS NOT NULL;
        SQL);

        $this->createPartitions();

        DB::unprepared(<<<'SQL'
            ALTER TABLE bank_transactions
                ADD CONSTRAINT bank_transactions_amount_is_not_zero
                    CHECK (amount <> 0),

                ADD CONSTRAINT bank_transactions_currency_is_an_alpha3
                    CHECK (currency ~ '^[A-Z]{3}$'),

                ADD CONSTRAINT bank_transactions_sequence_is_positive
                    CHECK (statement_sequence > 0),

                /*
                 | A value date before the booking date is normal; the other
                 | way round by more than a few days is not.
                 |
                 | A cheque banked today values last week, and a forward-dated
                 | payment values next month - both happen. A value date years
                 | from the booking date is a parser that read the wrong
                 | field, which is the ordinary failure of a format whose
                 | dates are positional.
                 */
                ADD CONSTRAINT bank_transactions_dates_are_plausible
                    CHECK (value_date BETWEEN booking_date - 400 AND booking_date + 400),

                ADD CONSTRAINT bank_transactions_format_is_known
                    CHECK (source_format IN ('camt053', 'camt052', 'camt054', 'mt940', 'mt942',
                                             'ofx', 'csv', 'manual')),

                /*
                 | An FX amount comes with its currency and its rate.
                 |
                 | An instructed amount with no currency is a number nobody
                 | can use, and with no rate the difference between it and
                 | the settled amount cannot be attributed.
                 */
                ADD CONSTRAINT bank_transactions_fx_is_complete
                    CHECK (
                        instructed_amount IS NULL
                        OR (instructed_currency IS NOT NULL AND exchange_rate IS NOT NULL)
                    ),

                ADD CONSTRAINT bank_transactions_a_reversal_is_marked
                    CHECK (reverses_reference IS NULL OR is_reversal);
        SQL);

        Schema::create('bank_transaction_enrichment', function ($table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            /*
             | The transaction this is about, by id and booking date.
             |
             | Both, because the parent is partitioned on the booking date
             | and a foreign key to a partitioned table needs the partition
             | key - which is also what lets the planner prune when joining.
             */
            $table->char('bank_transaction_id', 26);
            $table->date('booking_date');

            /*
             | What WE think, in columns of our own.
             |
             | Never by rewriting the imported value: a transaction is a
             | third party's assertion, and a statement whose lines we have
             | edited reconciles perfectly to our ledger and to nothing the
             | bank has ever sent.
             */
            $table->foreignUlid('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('party_source', 24)->nullable();
            $table->text('normalised_counterparty')->nullable();
            $table->string('extracted_reference', 64)->nullable();
            $table->string('category', 32)->nullable();
            $table->text('note')->nullable();

            $table->ulid('enriched_by')->nullable();
            $table->timestamps();

            $table->unique(['bank_transaction_id', 'booking_date']);
            $table->index(['entity_id', 'party_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bank_transaction_enrichment
                ADD CONSTRAINT bank_transaction_enrichment_party_source_is_known
                    CHECK (
                        party_source IS NULL
                        OR party_source IN ('counterparty_account', 'counterparty_bic', 'rule', 'human')
                    ),

                /*
                 | A party we decided on names how we decided.
                 |
                 | The same reasoning as the inbound document matcher: "the
                 | account number is on file" and "somebody chose from a
                 | list" are the same party and a different level of
                 | confidence, and the second is the one an auditor asks
                 | about.
                 */
                ADD CONSTRAINT bank_transaction_enrichment_a_party_names_its_source
                    CHECK ((party_id IS NULL) = (party_source IS NULL));
        SQL);

        /*
         | The imported columns are append-only.
         |
         | A line we disagree with is a line to dispute with the bank. The
         | columns the enrichment table exists for are the ones that may
         | move, and `is_provisional`/`superseded_by_id` because an intraday
         | entry is explicitly replaced by the booked statement.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_bank_transactions_are_the_banks()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.id <> OLD.id
                   OR NEW.entity_id <> OLD.entity_id
                   OR NEW.bank_account_id <> OLD.bank_account_id
                   OR NEW.booking_date <> OLD.booking_date
                   OR NEW.value_date <> OLD.value_date
                   OR NEW.amount <> OLD.amount
                   OR NEW.currency <> OLD.currency
                   OR NEW.statement_sequence <> OLD.statement_sequence
                   OR NEW.counterparty_name IS DISTINCT FROM OLD.counterparty_name
                   OR NEW.counterparty_account IS DISTINCT FROM OLD.counterparty_account
                   OR NEW.remittance_unstructured IS DISTINCT FROM OLD.remittance_unstructured
                   OR NEW.remittance_structured IS DISTINCT FROM OLD.remittance_structured
                   OR NEW.bank_reference IS DISTINCT FROM OLD.bank_reference
                   OR NEW.end_to_end_id IS DISTINCT FROM OLD.end_to_end_id
                   OR NEW.source_payload IS DISTINCT FROM OLD.source_payload THEN
                    RAISE EXCEPTION
                        'A bank transaction is what the bank says happened, and is not ours to '
                        'correct. A line you disagree with is a line to dispute with the bank: '
                        'editing it locally produces a statement that reconciles perfectly to our '
                        'ledger and to nothing the bank has ever sent. What we think about a '
                        'transaction belongs in bank_transaction_enrichment.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bank_transactions_are_the_banks
                BEFORE UPDATE ON bank_transactions
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_bank_transactions_are_the_banks();
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE bank_transactions IS
                'peakflow:no-delete peakflow:tenant-scoped - a third party''s assertion. Immutable: a line '
                'you disagree with is a line to dispute with the bank - PF-148.';
            COMMENT ON COLUMN bank_transactions.identity_key IS
                'Generated, not computed: an import that forgot to compute it is an import that duplicates - PF-149.';
            COMMENT ON COLUMN bank_transactions.value_date IS
                'Interest, FX and forecasting use this; the GL posts on the booking date. Collapsing them surfaces at month end.';
        SQL);

        foreach (['bank_transactions', 'bank_transaction_enrichment'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }

        /*
         | `DELETE` is revoked through the marker, not by hand here.
         |
         | The table's comment carries `peakflow:no-delete`, and
         | TenantProvisioner::revokeAppendOnly() revokes the privilege from
         | the application role - on the parent AND on every partition,
         | which is the part a hand-rolled REVOKE here got wrong.
         |
         | PostgreSQL checks privileges on the relation the statement names.
         | A query against `bank_transactions` is checked against the parent,
         | so revoking there looks sufficient and is not: each partition
         | carries its own grant, and `DELETE FROM bank_transactions_2026_05`
         | would have been permitted. Thirty-seven partitions, thirty-seven
         | holes, and the only sign of it was a privilege query.
         |
         | Found while proving PF-148's immutability criterion, which asked
         | the question per partition because the journal's own rule already
         | did.
         |
         | A privilege the role does not hold beats a trigger here: a trigger
         | refusing a delete can be disabled by whoever owns the table.
         */
    }

    /**
     * Partitions created ahead of time, never on demand.
     *
     * The same reasoning as the journal's: a partition created during a
     * request is a DDL lock taken while somebody is importing, and the first
     * import of a new month is exactly when the month-end rush happens.
     */
    private function createPartitions(): void
    {
        $start = now()->startOfMonth()->subMonths(self::MONTHS_BACK);

        for ($i = 0; $i < self::MONTHS_BACK + self::MONTHS_AHEAD; $i++) {
            $from = $start->copy()->addMonths($i);
            $to = $from->copy()->addMonth();

            DB::unprepared(sprintf(
                'CREATE TABLE bank_transactions_%s PARTITION OF bank_transactions '.
                "FOR VALUES FROM ('%s') TO ('%s');",
                $from->format('Y_m'),
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            ));
        }

        /*
         | A default partition, so a date outside the range is never
         | REJECTED.
         |
         | Losing an imported transaction because nobody extended the
         | partitions would be far worse than storing it somewhere the
         | planner cannot prune - and a non-empty default partition is itself
         | the alert that the scheduled task has stopped running.
         */
        DB::unprepared('CREATE TABLE bank_transactions_default PARTITION OF bank_transactions DEFAULT;');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_transactions_are_the_banks ON bank_transactions;
            DROP FUNCTION IF EXISTS peakflow_bank_transactions_are_the_banks();
        SQL);

        Schema::dropIfExists('bank_transaction_enrichment');
        DB::unprepared('DROP TABLE IF EXISTS bank_transactions;');
    }
};
