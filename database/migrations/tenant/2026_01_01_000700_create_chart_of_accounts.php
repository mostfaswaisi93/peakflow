<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts and the control-account roles - PF-037, PF-038.
 *
 * @financial
 *
 * ## The type decides three things, so it is not free text
 *
 * An account's `type` determines its natural sign, whether it closes at year
 * end, and which statement it rolls up to. Those are not independent choices
 * a user gets to make per account - an expense that does not close at year
 * end is not an expense, it is a bug that makes retained earnings wrong by
 * exactly its balance.
 *
 * ## The tree is a materialized path, not a recursive query
 *
 * `path` holds the ancestry as a dotted string of codes. A trial balance that
 * rolls up to parents is then a `LIKE 'path%'` on an index rather than a
 * recursive CTE per account, which at five thousand accounts is the
 * difference between a report and a timeout.
 *
 * The cost is that moving a subtree rewrites its descendants' paths. Moving
 * accounts is rare, reading them is constant, and the write is a single
 * UPDATE on an indexed prefix.
 *
 * ## Control accounts are the invariant that cannot be restored
 *
 * A control account is the ledger's copy of a subledger total: receivables,
 * payables, VAT, bank. Once a human posts to one by hand, the subledger and
 * the ledger disagree, and no reconciliation brings them back - you cannot
 * tell which of the two is now wrong without re-deriving the whole history.
 *
 * So a manual journal naming a control account is refused BY THE DATABASE.
 * This is precisely the rule a backfill script will not respect, and the
 * script is written by someone who is certain their case is the exception.
  *
 * @online-safe Creates `account_roles`, `accounts`, with the constraints, indexes and triggers
 *              that belong to them. Nothing can be reading a table that did
 *              not exist until this ran, so no lock it takes is on anything
 *              serving traffic.
 */
return new class extends Migration
{
    /**
     * The control-account roles - PF-038.
     *
     * Each is a position in the posting rules, not a name a customer chooses.
     * A tenant may call the account whatever they like; the ROLE is what the
     * posting rules resolve, which is how `Ledger` posts a receivable without
     * knowing what an invoice is.
     *
     * @var list<string>
     */
    private const ROLES = [
        'accounts_receivable',
        'accounts_payable',
        'vat_input',
        'vat_output',
        'bank',
        'payments_in_transit',
        'fx_gain_loss',
        'fx_revaluation',
        'rounding_difference',
        'suspense',
        'deferred_revenue',
        'retained_earnings',
        'inter_entity',
        'bad_debt',
        'ecl_provision',
    ];

    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');

            $table->string('code', 32);
            $table->string('name_ar');
            $table->string('name_en');

            $table->ulid('parent_id')->nullable();

            // The materialized path. See the class note on why this and not a
            // recursive query.
            $table->string('path', 512);
            $table->smallInteger('depth')->default(0);

            $table->string('type', 16);

            // The IFRS statement line this rolls up to. Separate from `type`
            // because two expense accounts can belong to different statement
            // lines - cost of sales and administrative expenses are both
            // expenses and are never added together.
            $table->string('statement_line', 64)->nullable();

            /*
             | A currency restriction, not a currency.
             |
             | Most accounts take any currency and are reported in the
             | functional one. A bank account is different: it holds exactly
             | one currency, and a posting in another is a mistake rather than
             | a translation.
             */
            $table->char('currency', 3)->nullable();

            $table->boolean('postable')->default(true);
            $table->boolean('requires_dimension')->default(false);
            $table->boolean('reconcilable')->default(false);
            $table->boolean('blocked')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'path']);
            $table->index(['entity_id', 'type']);
            $table->index(['entity_id', 'parent_id']);
        });

        Schema::create('account_roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->string('role', 32);
            $table->foreignUlid('account_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        $roles = "'".implode("', '", self::ROLES)."'";

        DB::unprepared(<<<SQL
            ALTER TABLE accounts
                ADD CONSTRAINT accounts_type_known
                    CHECK (type IN ('asset', 'liability', 'equity', 'revenue', 'expense')),
                ADD CONSTRAINT accounts_path_not_empty CHECK (length(path) > 0);

            ALTER TABLE account_roles
                ADD CONSTRAINT account_roles_known CHECK (role IN ({$roles}));
        SQL);

        /*
         | Exactly one account per role per entity.
         |
         | A partial unique index rather than a plain one, because a role may
         | legitimately be UNASSIGNED while an entity is being set up - and a
         | plain unique index over a nullable column would permit two
         | unassigned rows and then silently allow the second assignment.
         */
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX account_roles_one_per_entity
                ON account_roles (entity_id, role);

            -- The reverse question - "is this account a control account" - is
            -- asked on every manual posting, so it gets its own index.
            CREATE INDEX account_roles_by_account ON account_roles (account_id);
        SQL);

        /*
         | A header account cannot be posted to - PF-037.
         |
         | Enforced where the posting happens rather than at the account, so
         | that marking an account non-postable takes effect immediately for
         | every future line without a backfill.
         |
         | The function is created here and the trigger is attached in the
         | journal-lines migration, because the table does not exist yet.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_account_is_postable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                account_record record;
                control_role   text;
            BEGIN
                SELECT code, name_en, postable, blocked, currency
                INTO account_record
                FROM accounts
                WHERE id = NEW.account_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Account % does not exist in this entity.', NEW.account_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                IF NOT account_record.postable THEN
                    RAISE EXCEPTION
                        'Account %  (%) is a header and cannot be posted to. Post to one of its children; a '
                        'header holds the total of what is below it and a posting made directly to one is '
                        'invisible in every breakdown of that total.',
                        account_record.code, account_record.name_en
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF account_record.blocked THEN
                    RAISE EXCEPTION
                        'Account % (%) is blocked and no longer accepts postings.',
                        account_record.code, account_record.name_en
                        USING ERRCODE = 'restrict_violation';
                END IF;

                /*
                 | A currency-restricted account refuses another currency.
                 | A USD bank account receiving a EUR line is not an FX
                 | translation, it is a line posted to the wrong account.
                 */
                IF account_record.currency IS NOT NULL AND NEW.currency <> account_record.currency THEN
                    RAISE EXCEPTION
                        'Account % (%) holds % only; this line is in %. A balance in two currencies on one '
                        'account cannot be reconciled against a statement.',
                        account_record.code, account_record.name_en, account_record.currency, NEW.currency
                        USING ERRCODE = 'restrict_violation';
                END IF;

                /*
                 | Control accounts: only a registered posting rule may touch
                 | one. `peakflow.posting_rule` is set by the posting service
                 | for the duration of its transaction, so a manual entry - or
                 | a backfill script, or a REPL session - has it unset and is
                 | refused.
                 */
                SELECT role INTO control_role FROM account_roles WHERE account_id = NEW.account_id;

                IF FOUND AND coalesce(current_setting('peakflow.posting_rule', true), '') = '' THEN
                    RAISE EXCEPTION
                        'Account % (%) is the % control account and may only be posted to by a posting rule. '
                        'Once a control account and its subledger disagree, neither can be shown to be the '
                        'correct one without re-deriving the whole history - which is why this is refused here '
                        'rather than in the application.',
                        account_record.code, account_record.name_en, control_role
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        foreach (['accounts', 'account_roles'] as $table) {
            DB::unprepared(sprintf("SELECT peakflow_apply_entity_rls('%s');", $table));
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS assert_account_is_postable() CASCADE;');

        Schema::dropIfExists('account_roles');
        Schema::dropIfExists('accounts');
    }
};
