<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A transaction between two entities of the same tenant - PF-057.
 *
 * ## One row, one amount - which is the whole design
 *
 * The obvious shape is two rows, one per side: entity A records what it is
 * owed, entity B records what it owes. It is also how inter-company balances
 * come to disagree in every system that has them, because two rows can hold
 * two numbers and nothing makes them the same.
 *
 * So there is one row, holding ONE settlement amount in ONE currency, and the
 * two journal entries both derive from it. The pair cannot fail to net to
 * zero in the settlement currency, because there is only one figure. What the
 * consolidation has to eliminate is then arithmetic rather than a
 * reconciliation.
 *
 * ## The settlement currency, and why the pair is not denominated functionally
 *
 * Entity A keeps books in SAR and entity B in EUR, and A lends B 1,000 USD.
 * There is no single "amount" in A's or B's currency: A records a 1,000 USD
 * receivable translated to SAR at its rate, B records a 1,000 USD payable
 * translated to EUR at its rate, and those two functional figures are
 * supposed to differ.
 *
 * The 1,000 USD is the fact both sides agree on. Each entity's functional
 * amount is a translation OF it, recorded with its rate and provenance on the
 * line (PF-069), and revalued at each period end by the existing monetary
 * revaluation - because an inter-entity balance is a monetary item like any
 * other.
 *
 * The consolidation eliminates the settlement amounts, which cancel exactly,
 * and the difference between the two functional translations is a translation
 * difference that is REPORTED rather than absorbed. Absorbing it is how a
 * consolidated balance sheet acquires an unexplained plug.
 *
 * ## Both sides, or neither
 *
 * A receivable with no payable is the failure this table exists to make
 * unrepresentable. The two entry ids are checked together by a deferred
 * constraint, so a half-posted transaction cannot survive a commit - and the
 * service posts both inside one transaction, so it cannot be left half-done
 * by a crash either.
 *
 * ## And a line to an inter-entity account must name its transaction
 *
 * That is the guard that matters in practice. The control-account rule
 * already stops a human posting into an inter-entity account; this stops a
 * REGISTERED RULE doing it without a counterpart - which is the shape of
 * every inter-company balance nobody can explain.
 *
 * @online-safe Creates one table, adds one nullable column to a partitioned
 *              table (a catalogue operation that propagates to the
 *              partitions without rewriting them) and three triggers.
 *
 * @unsafe-migration-reviewed: the flagged statements are the `dropColumn` in
 * `down()`, which drops the column this migration adds, and the ALTER on the
 * partitioned `journal_lines` - which is an ADD COLUMN of a nullable column,
 * propagated to the partitions as a catalogue change with no rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inter_entity_transactions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            /*
             | No `entity_id`, deliberately - and this is the one table in
             | the tenant schema where that is right.
             |
             | Every other table carries one because row-level security
             | scopes it to the entity in context. This row belongs to TWO
             | entities, and scoping it to either would make it invisible
             | from the other - so the pair would be unreconcilable from
             | exactly the two places that need to see it.
             |
             | It is still tenant-scoped: it lives in the tenant's own
             | database. What it is not is entity-scoped.
             */
            $table->char('from_entity_id', 26);
            $table->char('to_entity_id', 26);

            $table->string('reference', 64);
            $table->string('description', 512);
            $table->date('accounting_date');

            // The one figure both sides agree on.
            $table->char('settlement_currency', 3);
            $table->decimal('settlement_amount', 21, 4);

            $table->char('from_entry_id', 26)->nullable();
            $table->char('to_entry_id', 26)->nullable();

            $table->char('created_by_user_id', 26)->nullable();
            $table->timestampsTz();

            $table->unique(['from_entity_id', 'reference']);
            $table->index(['from_entity_id', 'accounting_date']);
            $table->index(['to_entity_id', 'accounting_date']);
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->char('inter_entity_transaction_id', 26)->nullable();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE inter_entity_transactions IS
                'peakflow:no-delete - one transaction between two entities of this tenant, holding ONE '
                'settlement amount in ONE currency. Two rows (one per side) is how inter-company balances '
                'come to disagree; one row cannot. Deliberately NOT entity-scoped: it belongs to two '
                'entities and scoping it to either would hide it from the other.';

            COMMENT ON COLUMN inter_entity_transactions.settlement_amount IS
                'The figure both sides agree on, in the settlement currency. Each entity''s functional '
                'amount is a translation of it, recorded with its rate on the line; the two functional '
                'figures are SUPPOSED to differ when the entities keep books in different currencies, and '
                'the difference is a translation difference the consolidation reports rather than absorbs.';

            COMMENT ON COLUMN journal_lines.inter_entity_transaction_id IS
                'Which inter-entity transaction this line belongs to. Required on any line reaching an '
                'account with the inter_entity control role, and forbidden on any line that does not - '
                'which is what makes an inter-company balance nobody can explain unrepresentable.';

            ALTER TABLE inter_entity_transactions
                ADD CONSTRAINT inter_entity_two_different_entities
                    CHECK (from_entity_id <> to_entity_id),
                ADD CONSTRAINT inter_entity_settlement_is_not_zero
                    CHECK (settlement_amount <> 0),
                ADD CONSTRAINT inter_entity_currency_is_uppercase
                    CHECK (settlement_currency = upper(settlement_currency));

            /*
             | Both entries, or neither, checked at COMMIT.
             |
             | Deferred because the row is written before the entries exist -
             | the lines have to name it, so it has to be there first. A
             | per-statement check would reject the only order that works.
             */
            CREATE OR REPLACE FUNCTION assert_inter_entity_has_both_sides()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                settled record;
            BEGIN
                /*
                 | The row is RE-READ, not taken from NEW.
                 |
                 | A deferred AFTER trigger fires at commit and is handed the
                 | tuple version that its own event created - so the INSERT's
                 | firing sees the row as inserted, with both entry ids still
                 | null, however many updates followed. The first version of
                 | this trusted NEW and refused every transaction with "has
                 | only no entry at all", on rows whose ids had been filled in
                 | milliseconds later.
                 |
                 | Re-reading gives the state the transaction is actually
                 | about to commit, which is the only state worth checking.
                 | It also makes the INSERT and UPDATE firings idempotent.
                 */
                SELECT reference, from_entry_id, to_entry_id
                INTO settled
                FROM inter_entity_transactions
                WHERE id = NEW.id;

                -- Gone before commit. Nothing to check.
                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                IF settled.from_entry_id IS NOT NULL AND settled.to_entry_id IS NOT NULL THEN
                    RETURN NULL;
                END IF;

                RAISE EXCEPTION
                    'Inter-entity transaction % has only %. A receivable with no payable is an '
                    'inter-company balance that nothing explains and that consolidation cannot '
                    'eliminate - it shows up as a permanent unexplained difference in the group '
                    'accounts. Post both sides or neither.',
                    settled.reference,
                    CASE
                        WHEN settled.from_entry_id IS NOT NULL THEN 'the lending side'
                        WHEN settled.to_entry_id IS NOT NULL THEN 'the borrowing side'
                        ELSE 'no entry at all'
                    END
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE CONSTRAINT TRIGGER inter_entity_transactions_both_sides
                AFTER INSERT OR UPDATE ON inter_entity_transactions
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION assert_inter_entity_has_both_sides();

            /*
             | An inter-entity line names its transaction, and only an
             | inter-entity line may.
             |
             | Both directions, because each catches a different mistake. A
             | line reaching the inter-entity account with no transaction is
             | the balance nobody can explain. A line naming a transaction
             | while pointing somewhere else is a transaction whose
             | elimination would remove the wrong figure.
             */
            CREATE OR REPLACE FUNCTION assert_inter_entity_line_is_paired()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_temp
            AS $$
            DECLARE
                is_inter_entity boolean;
                account_code    text;
            BEGIN
                SELECT
                    EXISTS (
                        SELECT 1 FROM account_roles r
                        WHERE r.account_id = NEW.account_id
                          AND r.role = 'inter_entity'
                    ),
                    a.code
                INTO is_inter_entity, account_code
                FROM accounts a
                WHERE a.id = NEW.account_id;

                IF is_inter_entity AND NEW.inter_entity_transaction_id IS NULL THEN
                    RAISE EXCEPTION
                        'A line to the inter-entity account % names no inter-entity transaction. An '
                        'inter-company balance with no counterpart cannot be eliminated in '
                        'consolidation and cannot be explained to the other entity''s accountant - it '
                        'simply sits there, growing.',
                        account_code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NOT is_inter_entity AND NEW.inter_entity_transaction_id IS NOT NULL THEN
                    RAISE EXCEPTION
                        'A line to account % names an inter-entity transaction and is not an '
                        'inter-entity line. The elimination would then remove a figure that is not the '
                        'inter-company balance.',
                        account_code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER journal_lines_inter_entity_is_paired
                BEFORE INSERT OR UPDATE ON journal_lines
                FOR EACH ROW
                EXECUTE FUNCTION assert_inter_entity_line_is_paired();

            /*
             | Its own row-level security policy, because the shared one
             | cannot express this.
             |
             | `peakflow_apply_entity_rls()` writes `entity_id =
             | peakflow_current_entity()`, which needs a column this table
             | does not have and should not: the row belongs to TWO entities.
             | Calling it was the first attempt and PostgreSQL refused it,
             | naming the missing column - which is the right failure and a
             | clearer one than a policy that silently matched nothing.
             |
             | The rule here is "either side": an entity sees the
             | transactions it is party to, from its own side of them, which
             | is precisely what makes the pair reconcilable from both ends.
             |
             | FORCE ROW LEVEL SECURITY, so the table's owner is bound by it
             | too - the migrator bypasses it through its own BYPASSRLS
             | attribute rather than through table ownership, which is the
             | same arrangement every other tenant table has.
             */
            ALTER TABLE inter_entity_transactions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE inter_entity_transactions FORCE ROW LEVEL SECURITY;

            CREATE POLICY peakflow_either_side ON inter_entity_transactions
                USING (
                    from_entity_id = peakflow_current_entity()
                    OR to_entity_id = peakflow_current_entity()
                )
                WITH CHECK (
                    from_entity_id = peakflow_current_entity()
                    OR to_entity_id = peakflow_current_entity()
                );

            COMMENT ON POLICY peakflow_either_side ON inter_entity_transactions IS
                'An entity sees the inter-entity transactions it is party to, from either side. The '
                'shared entity-scope policy cannot express this: it compares a single entity_id column, '
                'and this row belongs to two entities - scoping it to either would hide the pair from '
                'exactly one of the two places that need to reconcile it.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_inter_entity_is_paired ON journal_lines;
            DROP TRIGGER IF EXISTS inter_entity_transactions_both_sides ON inter_entity_transactions;
            DROP FUNCTION IF EXISTS assert_inter_entity_line_is_paired();
            DROP FUNCTION IF EXISTS assert_inter_entity_has_both_sides();
        SQL);

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropColumn('inter_entity_transaction_id');
        });

        Schema::dropIfExists('inter_entity_transactions');
    }
};
