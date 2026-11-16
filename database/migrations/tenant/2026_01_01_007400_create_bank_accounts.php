<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank accounts and their GL mirror - PF-145, PF-146.
 *
 * ## The distinction this phase is built on
 *
 * The GL account holds what PeakFlow believes; the statement holds what the
 * bank says. Reconciliation is the process of explaining the difference, and
 * a design that stores one balance for both has deleted the question.
 *
 * So a bank account is a **1:1 immutable link** to a GL account carrying the
 * `bank` control role, and the two balances are never merged. The third -
 * available - is the statement's less what has been released and not yet
 * presented, which is the figure a treasurer actually spends against.
 *
 * ## An account belongs to exactly one entity
 *
 * A bank account shared across legal entities is a structure PeakFlow
 * refuses. It is common in practice and it is always wrong: the cash belongs
 * to whichever entity holds the account, and the others are being lent money
 * - which is an inter-company loan with terms, interest and a tax position,
 * not a column on a bank account. Modelling it as shared makes every
 * entity's cash figure a guess and makes the inter-company balance invisible.
 *
 * `bank_accounts` therefore has one `entity_id` and no join table, and the GL
 * link is to one account in one entity's chart.
 *
 * ## The identifiers are encrypted at the column level
 *
 * An IBAN is the instruction to move money. It is the single most valuable
 * field in the product to an attacker who can also change it - see the
 * beneficiary/release duty pair in PF-033 - and column-level encryption is
 * what keeps it out of a database dump, a replica, a backup and a log.
 *
 * The last four digits are kept in the clear, because every screen that
 * mentions an account needs to identify it and decrypting on every row of a
 * list is how a page comes to take four seconds.
 *
 * @financial
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);

            $table->string('label', 120);
            $table->string('status', 16)->default('active');

            $table->string('institution_name', 160);
            $table->string('institution_country', 2);
            $table->string('branch', 160)->nullable();
            $table->string('holder_legal_name', 200);
            $table->char('currency', 3);

            /*
             | The identifiers, encrypted, with a visible tail.
             |
             | An IBAN is the instruction to move money: it belongs out of
             | every dump, replica, backup and log. The tail is in the clear
             | because every screen that mentions the account has to identify
             | it, and decrypting on every row of a list is how a page comes
             | to take four seconds.
             */
            $table->text('iban_encrypted')->nullable();
            $table->string('iban_tail', 4)->nullable();
            $table->string('bic', 11)->nullable();

            /*
             | Domestic identifiers, for the countries IBAN does not reach.
             |
             | The United States, Canada, Australia and India between them are
             | most of the world's payment traffic and none of them uses IBAN.
             | A schema that modelled only IBAN would make those accounts
             | unrepresentable, and the usual workaround - an IBAN column with
             | a routing number in it - breaks every validation that column
             | exists for.
             */
            $table->string('domestic_scheme', 16)->nullable();
            $table->text('domestic_identifier_encrypted')->nullable();
            $table->string('domestic_identifier_tail', 4)->nullable();
            $table->text('account_number_encrypted')->nullable();

            /*
             | The GL mirror: one account, one bank account, immutably.
             |
             | Two bank accounts sharing a GL account cannot be reconciled
             | separately, and one bank account with two GL accounts has two
             | answers to "what do we believe the balance is". The immutability
             | is a trigger below: re-pointing the link after a statement has
             | been imported silently moves history.
             */
            $table->ulid('gl_account_id');

            $table->decimal('opening_balance', 21, 4)->default(0);
            $table->date('opening_date');

            $table->text('note')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'label']);
            $table->unique('gl_account_id');
            $table->index(['entity_id', 'status']);
        });

        Schema::create('bank_statements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('entity_id', 26);
            $table->foreignUlid('bank_account_id')->constrained()->restrictOnDelete();

            /*
             | The bank's own number, which banks number inconsistently.
             |
             | Per month, per page, per download: it is a label rather than a
             | sequence, which is why continuity is asserted on the BALANCE
             | and not on this. Kept because a person reconciling has the
             | paper statement in front of them and that is what it says.
             */
            $table->string('statement_number', 64)->nullable();
            $table->integer('sequence');

            $table->date('period_from');
            $table->date('period_to');

            $table->decimal('opening_balance', 21, 4);
            $table->decimal('closing_balance', 21, 4);
            $table->char('currency', 3);

            $table->string('source_format', 16);
            $table->string('status', 16)->default('imported');

            /*
             | What was imported, exactly, and its hash.
             |
             | A statement is a third party's assertion and a dispute about one
             | is settled by the file that arrived - not by what our parser
             | made of it. The same reasoning as the inbound document archive.
             */
            $table->char('content_hash', 64)->nullable();
            $table->string('file_name', 255)->nullable();

            $table->timestamp('imported_at');
            $table->ulid('imported_by')->nullable();

            /*
             | Superseded, never deleted.
             |
             | A bank re-issues a statement - a correction, a missing entry,
             | a reversal - and the old one is still the document somebody
             | reconciled against last month. Deleting it makes that
             | reconciliation unexplainable.
             */
            $table->ulid('superseded_by_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->ulid('superseded_by_user')->nullable();
            $table->text('supersession_reason')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['bank_account_id', 'sequence']);
            $table->index(['entity_id', 'bank_account_id', 'period_to']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bank_accounts
                ADD CONSTRAINT bank_accounts_status_is_known
                    CHECK (status IN ('active', 'dormant', 'closed')),

                ADD CONSTRAINT bank_accounts_country_is_an_alpha2
                    CHECK (institution_country ~ '^[A-Z]{2}$'),

                ADD CONSTRAINT bank_accounts_currency_is_an_alpha3
                    CHECK (currency ~ '^[A-Z]{3}$'),

                /*
                 | An account is reachable by SOMETHING.
                 |
                 | An IBAN, or a domestic scheme with its identifier. A row
                 | with neither is an account nobody can pay into, which is
                 | a master-data record that looks complete and is not -
                 | discovered on the day somebody tries to pay from it.
                 */
                ADD CONSTRAINT bank_accounts_are_reachable
                    CHECK (
                        iban_encrypted IS NOT NULL
                        OR (domestic_scheme IS NOT NULL AND domestic_identifier_encrypted IS NOT NULL)
                    ),

                ADD CONSTRAINT bank_accounts_an_iban_has_a_tail
                    CHECK ((iban_encrypted IS NULL) = (iban_tail IS NULL)),

                ADD CONSTRAINT bank_accounts_a_domestic_identifier_has_a_scheme
                    CHECK ((domestic_scheme IS NULL) = (domestic_identifier_encrypted IS NULL)),

                /*
                 | A BIC is eight or eleven characters, upper case.
                 |
                 | ISO 9362. The eleven-character form names a branch and the
                 | eight-character form the institution; nothing between them
                 | is a BIC, and a nine-character value is a typo somebody
                 | will send money with.
                 */
                ADD CONSTRAINT bank_accounts_bic_is_iso_9362
                    CHECK (bic IS NULL OR bic ~ '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$');

            ALTER TABLE bank_statements
                ADD CONSTRAINT bank_statements_status_is_known
                    CHECK (status IN ('imported', 'superseded', 'reconciled')),

                ADD CONSTRAINT bank_statements_period_is_ordered
                    CHECK (period_to >= period_from),

                ADD CONSTRAINT bank_statements_sequence_is_positive
                    CHECK (sequence > 0),

                ADD CONSTRAINT bank_statements_format_is_known
                    CHECK (source_format IN ('camt053', 'camt052', 'camt054', 'mt940', 'mt942',
                                             'ofx', 'csv', 'manual')),

                /*
                 | A supersession is attributable and explained.
                 |
                 | "Superseded" with no reason is the state somebody finds
                 | while asking why last month's reconciliation no longer
                 | ties to anything.
                 */
                ADD CONSTRAINT bank_statements_supersession_is_explained
                    CHECK (
                        superseded_by_id IS NULL
                        OR (superseded_at IS NOT NULL AND superseded_by_user IS NOT NULL
                            AND length(btrim(coalesce(supersession_reason, ''))) > 10)
                    ),

                ADD CONSTRAINT bank_statements_a_superseded_one_says_so
                    CHECK (superseded_by_id IS NULL OR status = 'superseded'),

                ADD CONSTRAINT bank_statements_do_not_supersede_themselves
                    CHECK (superseded_by_id IS NULL OR superseded_by_id <> id);
        SQL);

        /*
         | The GL link is immutable, and it is to the bank subtree.
         |
         | Checked in the database because the link is the thing that makes
         | the two balances comparable at all. Re-pointing it moves history
         | silently: every posting made through the old link stays where it
         | was, and the account's ledger balance changes without a single
         | journal entry being written.
         */
        DB::unprepared(<<<'SQL'
            --
            CREATE OR REPLACE FUNCTION peakflow_bank_account_gl_link_is_fixed()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                link      RECORD;
                role_acct RECORD;
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.gl_account_id <> OLD.gl_account_id THEN
                    RAISE EXCEPTION
                        'The GL account behind a bank account cannot be changed. Every posting made '
                        'through the old link stays where it was, so the account''s ledger balance '
                        'would change without a single journal entry being written - and the '
                        'reconciliation that tied last month would no longer tie to anything.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT a.id, a.code, a.path, a.entity_id, a.postable
                INTO link
                FROM accounts a
                WHERE a.id = NEW.gl_account_id;

                IF link IS NULL THEN
                    RAISE EXCEPTION
                        'There is no GL account %. A bank account mirrors a chart line that already '
                        'exists.',
                        NEW.gl_account_id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF link.entity_id IS DISTINCT FROM NEW.entity_id THEN
                    RAISE EXCEPTION
                        'GL account % belongs to another entity. The cash belongs to whichever entity '
                        'holds the account, so a link across the boundary makes both entities'' cash '
                        'figures a guess.',
                        link.code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NOT link.postable THEN
                    RAISE EXCEPTION
                        'GL account % is a header and nothing can post to it, so its ledger balance is '
                        'permanently zero and the two balances could never be compared.',
                        link.code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT a.id, a.code, a.path
                INTO role_acct
                FROM accounts a
                JOIN account_roles r ON r.account_id = a.id AND r.role = 'bank'
                WHERE a.entity_id = NEW.entity_id;

                IF role_acct IS NULL THEN
                    RAISE EXCEPTION
                        'This entity''s chart has no account carrying the "bank" role, so there is '
                        'nothing for a bank account to mirror.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                /*
                 | The bank role names a SUBTREE, not a single account.
                 |
                 | `account_roles` is unique on (entity_id, role), so exactly one account
                 | per entity carries `bank` - which is right, because the posting rules
                 | that credit "the bank" need one unambiguous target. Requiring the role
                 | on every bank account's GL link would therefore cap an entity at one
                 | bank account for ever, and a SAR operating account alongside a USD one
                 | is not an edge case.
                 */
                IF link.id <> role_acct.id AND left(link.path, length(role_acct.path) + 1) <> role_acct.path || '.' THEN
                    RAISE EXCEPTION
                        'GL account % is outside the subtree of bank account %, which is the part of the '
                        'chart the trial balance reports as cash.',
                        link.code, role_acct.code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bank_accounts_gl_link_is_fixed
                BEFORE INSERT OR UPDATE ON bank_accounts
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_bank_account_gl_link_is_fixed();
        SQL);

        /*
         | The opening balance is immutable once a statement exists.
         |
         | It is the one figure in the account that nothing else can
         | reconstruct: change it afterwards and every reconciliation since
         | the first import is out by the difference, with no document
         | anywhere recording why.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_bank_opening_balance_is_fixed()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                statements integer;
            BEGIN
                IF NEW.opening_balance = OLD.opening_balance AND NEW.opening_date = OLD.opening_date THEN
                    RETURN NEW;
                END IF;

                SELECT count(*) INTO statements
                FROM bank_statements
                WHERE bank_account_id = NEW.id;

                IF statements > 0 THEN
                    RAISE EXCEPTION
                        'The opening balance cannot be changed once a statement has been imported: % '
                        'exist. It is the one figure nothing else can reconstruct, so every '
                        'reconciliation since the first import would be out by the difference with no '
                        'document recording why.',
                        statements
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bank_accounts_opening_balance_is_fixed
                BEFORE UPDATE ON bank_accounts
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_bank_opening_balance_is_fixed();
        SQL);

        /*
         | Statement continuity, in the database - PF-147.
         |
         | Each import asserts its opening balance equals the previous
         | statement's closing balance for that account. A mismatch stops the
         | import and reports the gap, because the alternative is a
         | reconciliation that can never be completed and nobody knows why:
         | the difference sits there for months and every month's work starts
         | by rediscovering it.
         |
         | On the BALANCE rather than the statement number, because banks
         | number statements inconsistently - per month, per page, per
         | download - so a number-based assertion fires on a bank that simply
         | numbers differently.
         |
         | The first statement for an account is exempt and compares against
         | the account's opening balance instead.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION peakflow_bank_statements_are_continuous()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                previous RECORD;
                account  RECORD;
            BEGIN
                SELECT closing_balance, sequence, period_to
                INTO previous
                FROM bank_statements
                WHERE bank_account_id = NEW.bank_account_id
                  AND sequence < NEW.sequence
                  AND status <> 'superseded'
                  AND id <> NEW.id
                ORDER BY sequence DESC
                LIMIT 1;

                IF previous IS NULL THEN
                    SELECT opening_balance, opening_date, currency
                    INTO account
                    FROM bank_accounts
                    WHERE id = NEW.bank_account_id;

                    IF account IS NOT NULL AND NEW.opening_balance <> account.opening_balance THEN
                        RAISE EXCEPTION
                            'The first statement for this account opens at % and the account''s own '
                            'opening balance is %. One of the two is wrong, and importing anyway '
                            'starts the account''s history with a difference nobody can explain.',
                            NEW.opening_balance, account.opening_balance
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.opening_balance <> previous.closing_balance THEN
                    RAISE EXCEPTION
                        'This statement opens at % and statement % closed at % on %. The gap is %: '
                        'importing it would leave a hole in the account''s history, and a '
                        'reconciliation over a hole can never be completed. Import the missing '
                        'statement, or record a bank-side gap deliberately.',
                        NEW.opening_balance,
                        previous.sequence,
                        previous.closing_balance,
                        previous.period_to,
                        NEW.opening_balance - previous.closing_balance
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER bank_statements_are_continuous
                BEFORE INSERT OR UPDATE ON bank_statements
                FOR EACH ROW
                EXECUTE FUNCTION peakflow_bank_statements_are_continuous();
        SQL);

        DB::unprepared(<<<'SQL'
            /*
             | `peakflow:no-delete` on both - PF-147.
             |
             | A statement is superseded, never deleted: it is the document
             | somebody reconciled against last month, and deleting it makes
             | that reconciliation unexplainable. A bank account is never
             | deleted either - it is closed, because its history is what
             | every reconciliation since it opened was measured against.
             |
             | The marker rather than a REVOKE here, so the provisioner
             | applies it to the table and any partition, and so the reason
             | lives next to the rule.
             */
            COMMENT ON TABLE bank_statements IS
                'peakflow:no-delete peakflow:tenant-scoped - superseded, never deleted: it is the '
                'document somebody reconciled against last month - PF-147.';

            COMMENT ON TABLE bank_accounts IS
                'peakflow:no-delete peakflow:tenant-scoped - the GL account holds what PeakFlow '
                'believes; the statement holds what the bank says. Closed, never deleted: its '
                'history is what every reconciliation since it opened was measured against '
                '- PF-145, PF-146.';
            COMMENT ON CONSTRAINT bank_accounts_are_reachable ON bank_accounts IS
                'An account with no identifier is one nobody can pay into, discovered on the day somebody tries.';
            COMMENT ON FUNCTION peakflow_bank_statements_are_continuous() IS
                'A reconciliation over a hole can never be completed, and nobody knows why - PF-147.';
        SQL);

        foreach (['bank_accounts', 'bank_statements'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s')", $table));
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_statements_are_continuous ON bank_statements;
            DROP FUNCTION IF EXISTS peakflow_bank_statements_are_continuous();
            DROP TRIGGER IF EXISTS bank_accounts_opening_balance_is_fixed ON bank_accounts;
            DROP FUNCTION IF EXISTS peakflow_bank_opening_balance_is_fixed();
            DROP TRIGGER IF EXISTS bank_accounts_gl_link_is_fixed ON bank_accounts;
            DROP FUNCTION IF EXISTS peakflow_bank_account_gl_link_is_fixed();
        SQL);

        Schema::dropIfExists('bank_statements');
        Schema::dropIfExists('bank_accounts');
    }
};
