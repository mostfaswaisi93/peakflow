<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The unapplied cash control role - PF-167.
 *
 * ## Why unallocated money needs an account rather than a flag
 *
 * A customer sends 10,000 and nothing says which invoices it settles. That
 * money is a **liability**: we are holding it for them. It is not revenue,
 * and it is not a reduction of what they owe, because what they owe changes
 * when somebody allocates the money to a document.
 *
 * Without an account for it the receipt has two places it can go and both
 * are wrong. Against receivables it reduces a balance nobody decided to
 * reduce, and the customer ledger becomes a figure that cannot be taken
 * apart. Against revenue it reports performance for a sale that may not
 * exist.
 *
 * With an account, unallocated cash is a balance somebody can age, report
 * and ask a customer about - and old unallocated cash usually means a payer
 * nobody has identified, which is a question worth asking early.
 *
 * ## The allowlist is rebuilt, not extended
 *
 * The same reasoning as PF-132, PF-143 and PF-164. A constraint assembled
 * from fragments across seven migrations is one nobody can read; this is a
 * diff somebody can check against `ControlRole`, which is what
 * `ControlRoleRules` compares it to in both directions.
 *
 * @financial-not-applicable: this alters a CHECK constraint on
 * `account_roles`, whose rows are scoped through the account they name. No
 * table is created and no financial row is written.
 */
return new class extends Migration
{
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
        'withholding_receivable',
        'withholding_payable',
        'goods_received_not_invoiced',
        'employee_payable',
        'bad_debt',
        'ecl_provision',
        'bank_charges',
        'unapplied_cash',
    ];

    public function up(): void
    {
        $this->rewriteAllowlist(self::ROLES);
    }

    public function down(): void
    {
        // @unsafe-migration-reviewed: the rollback restores the list as it
        // stood before this migration, which is this one without
        // `unapplied_cash`. An account already carrying that role would fail
        // the restored constraint, which is correct: the role would no
        // longer resolve to anything.
        $this->rewriteAllowlist(array_values(array_filter(
            self::ROLES,
            static fn (string $role): bool => $role !== 'unapplied_cash',
        )));
    }

    /** @param  list<string>  $roles */
    private function rewriteAllowlist(array $roles): void
    {
        $list = implode(', ', array_map(
            static fn (string $role): string => sprintf("'%s'", $role),
            $roles,
        ));

        DB::unprepared(sprintf(
            'ALTER TABLE account_roles DROP CONSTRAINT IF EXISTS account_roles_known;
             ALTER TABLE account_roles ADD CONSTRAINT account_roles_known CHECK (role IN (%s));',
            $list,
        ));
    }
};
