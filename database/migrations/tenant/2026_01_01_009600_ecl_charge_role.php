<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The expected credit loss charge role - PF-173.
 *
 * ## Why the charge is not `bad_debt`
 *
 * Because a provision is an **estimate** and a write-off is a
 * **conclusion**. One says a portion of what is owed probably will not
 * arrive and is revised every period; the other says a particular debt
 * will not, and is final.
 *
 * Sharing an expense account makes the impairment line a mixture of the
 * two and destroys the figure anybody actually wants: how much of last
 * year's estimate turned out to be real. That comparison is what
 * calibrates the matrix, and a matrix nobody can calibrate is one
 * somebody picked once and never revisited.
 *
 * ## The allowlist is rebuilt, not extended
 *
 * The same reasoning as PF-132, PF-143, PF-164, PF-167 and PF-172. A
 * constraint assembled from fragments across nine migrations is one
 * nobody can read; this is a diff somebody can check against
 * `ControlRole`, which is what `ControlRoleRules` compares it to in both
 * directions.
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
        'bad_debt_recovery',
        'ecl_charge',
    ];

    public function up(): void
    {
        $this->rewriteAllowlist(self::ROLES);
    }

    public function down(): void
    {
        // @unsafe-migration-reviewed: the rollback restores the list as it
        // stood before this migration, which is this one without
        // `ecl_charge`. An account already carrying that role would fail
        // the restored constraint, which is correct: the role would no
        // longer resolve to anything.
        $this->rewriteAllowlist(array_values(array_filter(
            self::ROLES,
            static fn (string $role): bool => $role !== 'ecl_charge',
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
