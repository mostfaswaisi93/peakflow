<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The employee payable control role - PF-143.
 *
 * ## Why the allowlist is rebuilt rather than extended
 *
 * The same reasoning as PF-132's: a constraint that enumerates the known
 * roles is what stops a typo becoming a role nothing resolves, and a
 * constraint assembled from fragments across five migrations is one nobody
 * can read. So it is dropped and recreated with the whole list, which is a
 * diff somebody can check against `ControlRole`.
 *
 * `ControlRoleRules` now compares the two in both directions - a role in the
 * enum and not in the constraint cannot be assigned, and one in the
 * constraint and not in the enum is how a typo becomes a column value.
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
    ];

    public function up(): void
    {
        $this->rewriteAllowlist(self::ROLES);
    }

    public function down(): void
    {
        // @unsafe-migration-reviewed: the rollback restores the list as it
        // stood before this migration, which is this one without
        // `employee_payable`. Any account already carrying that role would
        // fail the restored constraint, which is correct: the role would no
        // longer resolve to anything.
        $this->rewriteAllowlist(array_values(array_filter(
            self::ROLES,
            static fn (string $role): bool => $role !== 'employee_payable',
        )));
    }

    /** @param list<string> $roles */
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
