<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parties, their addresses and their bank details - PF-059, PF-060, PF-061.
 *
 * @financial
 *
 * ## One table with roles, not a customers table and a suppliers table
 *
 * The same company is frequently both. Two tables means two rows, and the two
 * rows diverge the first time someone updates an address in one of them - and
 * then an invoice goes to the old address while a payment goes to the new
 * bank account, and nobody can say which record is current.
 *
 * ## Parties are shared; their BALANCES are not
 *
 * A party belongs to the tenant, because the same supplier serves every
 * company in the group. What they owe is per entity: a customer billed by two
 * of your companies owes each of them separately, and netting those is a
 * decision a human makes, not a consequence of how the data is stored.
 *
 * So `parties` carries no entity_id and `party_entity_settings` does.
 *
 * ## Bank details are the highest-value fraud surface in the product
 *
 * Beneficiary substitution - changing where a supplier's money goes, then
 * releasing a payment - is the attack this whole system is shaped against.
 * The controls here are layered deliberately:
 *
 *   - the account number is encrypted at the COLUMN, separately from disk or
 *     database encryption, because those protect against a stolen disk and
 *     not against a SELECT
 *   - every change is audited with before and after
 *   - a change marks the party as requiring re-verification, and the next
 *     payment is blocked until a second person confirms through a channel
 *     this system did not supply - ringing the number on an old invoice, not
 *     the one on the email asking for the change
 *   - the SoD pair with `payment.release` can never be overridden
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // No entity_id: see the class note. Shared across the tenant.
            $table->string('code', 32)->nullable();
            $table->string('legal_name');
            $table->string('trading_name')->nullable();

            /*
             | Roles as flags rather than a single `type`.
             |
             | A `type` column forces the same company to be duplicated the
             | day they start buying from you as well as selling, and the
             | duplicate is what diverges.
             */
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_employee')->default(false);

            $table->char('country', 2);
            $table->char('default_currency', 3)->nullable();
            $table->string('default_locale', 8)->nullable();
            $table->string('registration_number', 64)->nullable();

            $table->string('tax_treatment', 32)->default('standard');
            $table->ulid('payment_term_id')->nullable();

            $table->string('risk_grade', 8)->nullable();
            $table->boolean('active')->default(true);

            // The party this one was merged INTO, if it was. Kept rather than
            // deleting the loser, so a document issued years ago still
            // resolves the name it was issued to.
            $table->ulid('merged_into_id')->nullable();
            $table->timestamp('merged_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code']);
            $table->index(['legal_name']);
            $table->index(['is_customer', 'active']);
            $table->index(['is_supplier', 'active']);
            $table->index(['merged_into_id']);
        });

        /*
         | What a party means to ONE entity: their credit limit, their hold,
         | their balance. Per entity because a customer billed by two of your
         | companies owes each separately.
         */
        Schema::create('party_entity_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('entity_id');
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();

            $table->decimal('credit_limit', 21, 4)->nullable();
            $table->boolean('credit_hold')->default(false);
            $table->text('credit_hold_reason')->nullable();
            $table->ulid('credit_hold_by_user_id')->nullable();
            $table->timestamp('credit_hold_at')->nullable();

            $table->ulid('receivable_account_id')->nullable();
            $table->ulid('payable_account_id')->nullable();

            $table->timestamps();

            $table->unique(['entity_id', 'party_id']);
        });

        Schema::create('party_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();

            // EN 16931's address terms, which is what an e-invoice needs.
            $table->string('type', 16);
            $table->string('line_1')->nullable();
            $table->string('line_2')->nullable();
            $table->string('line_3')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country_subdivision', 64)->nullable();
            $table->char('country', 2);

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['party_id', 'type']);
        });

        Schema::create('party_tax_registrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();

            $table->char('jurisdiction', 2);
            $table->string('scheme', 32);
            $table->string('identifier', 64);

            // The Peppol Electronic Address Scheme code, which is what
            // routing needs - an identifier without its scheme is not
            // routable, it is a number.
            $table->string('eas_code', 8)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['party_id', 'jurisdiction', 'scheme', 'identifier']);
        });

        Schema::create('party_bank_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();

            $table->string('label')->nullable();
            $table->char('country', 2);
            $table->char('currency', 3)->nullable();

            /*
             | Encrypted at the COLUMN, separately from disk or database
             | encryption.
             |
             | Those protect a stolen disk. They do nothing against a SELECT -
             | from a compromised application, a support query, a replica
             | somebody forgot, or an export. The column is what protects
             | against the threat that actually happens.
             */
            $table->text('iban_encrypted')->nullable();
            $table->text('account_number_encrypted')->nullable();

            /*
             | The last four digits, in clear, so a human can confirm an
             | account without decrypting it.
             |
             | This is the deliberate trade: showing four digits lets someone
             | verify "the account ending 4471" on a phone call, which is the
             | control that actually catches substitution. Showing none would
             | make verification impossible and push people to email the full
             | number to each other.
             */
            $table->string('last_four', 4)->nullable();

            $table->string('bic', 11)->nullable();
            $table->string('bank_name')->nullable();

            /*
             | Re-verification after a change - PF-061.
             |
             | Set whenever the account details change. The next payment to
             | this party is BLOCKED until a second person confirms through a
             | channel this system did not supply: the number on an old
             | invoice, not the one in the email asking for the change.
             */
            $table->boolean('requires_reverification')->default(true);
            $table->ulid('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            /*
             | Free text, not a 32-character code.
             |
             | This is the evidence: "phoned the number on invoice 2024-114"
             | is what makes a verification meaningful, and "phone" is what
             | makes it meaningless. A short column forces the second form
             | and destroys the only thing an auditor could read.
             */
            $table->text('verification_channel')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['party_id', 'active']);
        });

        Schema::create('party_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('party_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('role', 16)->default('primary');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('locale', 8)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['party_id', 'role']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE party_addresses
                ADD CONSTRAINT party_addresses_type_known
                    CHECK (type IN ('legal', 'billing', 'delivery', 'remit_to'));

            ALTER TABLE party_contacts
                ADD CONSTRAINT party_contacts_role_known
                    CHECK (role IN ('primary', 'billing', 'ap', 'ar', 'technical'));

            ALTER TABLE parties
                ADD CONSTRAINT parties_merge_is_not_circular
                    CHECK (merged_into_id IS NULL OR merged_into_id <> id);

            -- A merged party is not a party any more, so it must not be
            -- selectable. Enforced here because a UI filter is not a rule.
            ALTER TABLE parties
                ADD CONSTRAINT parties_merged_is_inactive
                    CHECK (merged_into_id IS NULL OR active = false);
        SQL);

        /*
         | Changing bank details marks the account for re-verification.
         |
         | In the database rather than in a service, because the attack is
         | precisely somebody reaching the column another way - a support
         | script, a bulk import, a console session. The flag is what the
         | payment block reads.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION require_reverification_on_change()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.iban_encrypted IS DISTINCT FROM OLD.iban_encrypted
                   OR NEW.account_number_encrypted IS DISTINCT FROM OLD.account_number_encrypted
                   OR NEW.bic IS DISTINCT FROM OLD.bic THEN
                    NEW.requires_reverification := true;
                    NEW.verified_by_user_id := NULL;
                    NEW.verified_at := NULL;
                    NEW.verification_channel := NULL;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER party_bank_accounts_reverify
                BEFORE UPDATE ON party_bank_accounts
                FOR EACH ROW
                EXECUTE FUNCTION require_reverification_on_change();
        SQL);

        /*
         | Only `party_entity_settings` is entity-scoped. The rest belong to
         | the tenant and carry no entity_id, which is why they declare it -
         | the coverage check requires a reason, not an omission.
         */
        DB::unprepared("SELECT peakflow_apply_entity_rls('party_entity_settings');");

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE parties IS
                'peakflow:tenant-scoped - a party is shared across the tenant because the same supplier serves '
                'every company in the group; what they OWE is per entity and lives in party_entity_settings.';

            COMMENT ON TABLE party_bank_accounts IS
                'peakflow:tenant-scoped - bank details belong to the party, not to one of your companies. '
                'Beneficiary substitution is the highest-value attack here; see the migration notes.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS party_bank_accounts_reverify ON party_bank_accounts;
            DROP FUNCTION IF EXISTS require_reverification_on_change();
        SQL);

        Schema::dropIfExists('party_contacts');
        Schema::dropIfExists('party_bank_accounts');
        Schema::dropIfExists('party_tax_registrations');
        Schema::dropIfExists('party_addresses');
        Schema::dropIfExists('party_entity_settings');
        Schema::dropIfExists('parties');
    }
};
