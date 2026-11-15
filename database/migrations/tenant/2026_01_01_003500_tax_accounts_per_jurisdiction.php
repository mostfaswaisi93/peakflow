<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VAT control accounts are per jurisdiction, not per entity - PF-084.
 *
 * ## Not marked financial, because the table already is
 *
 * The architecture rule holds a migration that declares itself financial to
 * carrying a NOT NULL entity_id and calling peakflow_apply_entity_rls(). This
 * one adds a column to `account_roles`, which PF-038's migration created with
 * both. Re-applying the policy here would either be a no-op or an error
 * depending on how the helper handles an existing policy, and either way it
 * would be a line that exists to satisfy a rule rather than to do something.
 *
 * ## Why one pair per entity is not enough
 *
 * An entity registered in two jurisdictions files two returns, and each return
 * has to reconcile to an account. One output account holding both makes
 * neither reconcilable: the figure in it is the sum of two authorities'
 * liabilities, and the only way to split it afterwards is to re-read every
 * document - which is what reconciliation was supposed to avoid.
 *
 * It is not a rare arrangement. A Saudi company with a Jordanian branch, an EU
 * seller registered for OSS, any business that crossed a distance-selling
 * threshold: all of them file more than one return from one set of books.
 *
 * ## The jurisdiction is REQUIRED for the VAT roles and refused for the others
 *
 * `CHECK ((role IN ('vat_input','vat_output')) = (jurisdiction IS NOT NULL))`,
 * which is both halves of one rule. A VAT account with no jurisdiction is the
 * unreconcilable case above; a bank account WITH one is a column somebody
 * filled in because it was there, and the next reader has to work out whether
 * it means anything.
 *
 * ## The unique index collapses NULL to a sentinel
 *
 * `COALESCE(jurisdiction, '--')`, because NULL is not equal to NULL: a plain
 * unique index over `(entity_id, role, jurisdiction)` would permit two bank
 * roles with no jurisdiction, and "exactly one account per role" is the
 * property the whole posting layer rests on.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE account_roles ADD COLUMN jurisdiction char(2);

            /*
             | Backfilled from the entity's own country.
             |
             | Every existing VAT role was assigned by a chart template to an
             | entity that has a country, and that country is where it is
             | registered. Leaving them NULL and relaxing the CHECK would
             | start the rule off exempting exactly the rows that need it.
             */
            UPDATE account_roles
               SET jurisdiction = entities.country
              FROM entities
             WHERE entities.id = account_roles.entity_id
               AND account_roles.role IN ('vat_input', 'vat_output');

            ALTER TABLE account_roles
                ADD CONSTRAINT account_roles_jurisdiction_matches_the_role
                    CHECK ((role IN ('vat_input', 'vat_output')) = (jurisdiction IS NOT NULL));

            DROP INDEX IF EXISTS account_roles_one_per_entity;

            CREATE UNIQUE INDEX account_roles_one_per_entity
                ON account_roles (entity_id, role, COALESCE(jurisdiction, '--'));

            COMMENT ON COLUMN account_roles.jurisdiction IS
                'ISO 3166-1 alpha-2. Required for vat_input and vat_output, refused for every other role - PF-084.';

            /*
             | And two more roles, for withholding - PF-083.
             |
             | The CHECK that lists the known roles was written out as a
             | literal by PF-038's migration, so extending the enum means
             | replacing it. Dropped and recreated rather than relaxed: a
             | constraint that lists the roles is what stops a typo becoming a
             | role nothing resolves, and the whole point is that the list is
             | closed.
             |
             | They are per ENTITY, not per jurisdiction. A withholding
             | liability is owed to one authority at a time and the
             | jurisdiction travels on the certificate, which is where the
             | return reads it from.
             */
            ALTER TABLE account_roles DROP CONSTRAINT IF EXISTS account_roles_known;

            ALTER TABLE account_roles
                ADD CONSTRAINT account_roles_known CHECK (role IN (
                    'accounts_receivable', 'accounts_payable', 'vat_input', 'vat_output',
                    'bank', 'payments_in_transit', 'fx_gain_loss', 'fx_revaluation',
                    'rounding_difference', 'suspense', 'deferred_revenue', 'retained_earnings',
                    'inter_entity', 'bad_debt', 'ecl_provision',
                    'withholding_receivable', 'withholding_payable'
                ));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS account_roles_one_per_entity;

            ALTER TABLE account_roles
                DROP CONSTRAINT IF EXISTS account_roles_jurisdiction_matches_the_role,
                DROP COLUMN IF EXISTS jurisdiction;

            CREATE UNIQUE INDEX account_roles_one_per_entity
                ON account_roles (entity_id, role);
        SQL);
    }
};
