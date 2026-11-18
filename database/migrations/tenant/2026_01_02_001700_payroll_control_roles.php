<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The four accounts payroll posts to - PF-256.
 *
 * ## Why four and not one "employee costs"
 *
 * A single figure for everything to do with people answers no question
 * anybody asks. Gross pay scales with headcount; the employer's own
 * contributions scale with a statutory rate the business does not set;
 * tax withheld is money held for an authority until a return is filed;
 * social insurance is money held for a different authority on a
 * different schedule.
 *
 * Folding any pair together makes one of those reconciliations
 * impossible without knowing which rows belong to which, and the one
 * that gets abandoned is always the one with the deadline.
 *
 * ## `payroll_tax_payable` is not `withholding_payable`
 *
 * Both are tax held for an authority. One is deducted from an EMPLOYEE's
 * pay and the other from a SUPPLIER's invoice; they are filed on
 * different returns, on different dates, under different rules. Sharing
 * an account makes either filing a manual exercise in separating the
 * rows.
 *
 * ## `social_insurance_payable` holds BOTH shares
 *
 * Deliberately. The employee's deduction and the employer's contribution
 * are paid in one transfer, on one schedule, against one reference -
 * splitting them would produce two balances that have to be added
 * together to reconcile to the single figure the authority expects.
 *
 * @financial-not-applicable: rewrites a role allowlist constraint; no amount, balance or entity scope is involved
 */
return new class extends Migration
{
    private const ADDED = [
        'salary_expense',
        'employer_contribution_expense',
        'payroll_tax_payable',
        'social_insurance_payable',
    ];

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
        'inventory',
        'cost_of_sales',
        'salary_expense',
        'employer_contribution_expense',
        'payroll_tax_payable',
        'social_insurance_payable',
    ];

    public function up(): void
    {
        $this->rewriteAllowlist(self::ROLES);
    }

    public function down(): void
    {
        // @unsafe-migration-reviewed: the rollback restores the list as it stood before this migration, which is this one without the four payroll roles. An account already carrying one would fail the restored constraint, which is correct: the role would no longer resolve to anything.
        $this->rewriteAllowlist(array_values(array_filter(
            self::ROLES,
            static fn (string $role): bool => ! \in_array($role, self::ADDED, true),
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
