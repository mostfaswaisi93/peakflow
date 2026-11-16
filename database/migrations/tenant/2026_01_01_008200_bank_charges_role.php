<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bank charges control role - PF-164.
 *
 * ## Why bank charges get an account of their own
 *
 * They could go to administrative expenses, and in most small ledgers they
 * do. The cost of that is that a rising bank-charge figure stops being
 * visible: a customer who short-pays every invoice by the transfer fee, a
 * bank that quietly repriced its correspondent charges, a payment route
 * that costs four times what the alternative does - each of those is a
 * number somebody could act on, and each disappears into a line that also
 * holds stationery.
 *
 * PF-164 asks for a standing report of differences by type precisely
 * because of this. The report is only as good as the account behind it.
 *
 * ## The allowlist is rebuilt, not extended
 *
 * The same reasoning as PF-132 and PF-143. A constraint assembled from
 * fragments across six migrations is one nobody can read; this one is a
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
    ];

    public function up(): void
    {
        $this->rewriteAllowlist(self::ROLES);
    }

    public function down(): void
    {
        // @unsafe-migration-reviewed: the rollback restores the list as it
        // stood before this migration, which is this one without
        // `bank_charges`. An account already carrying that role would fail
        // the restored constraint, which is correct: the role would no
        // longer resolve to anything.
        $this->rewriteAllowlist(array_values(array_filter(
            self::ROLES,
            static fn (string $role): bool => $role !== 'bank_charges',
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
