<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two roles stock needs - PF-247, PF-248.
 *
 * ## Inventory is an asset, and deliberately not monetary
 *
 * It is not a claim on anybody in a currency, so it is never revalued at
 * a closing rate. A chart that marked it monetary would restate the cost
 * of goods every month end for a movement in a rate that has nothing to
 * do with what the stock cost.
 *
 * ## Cost of sales is not a reduction of revenue
 *
 * The sale happened at the invoiced figure and it is still that. What it
 * cost to fulfil is a different fact on its own line - which is the only
 * arrangement in which a margin can be read at all. Netting the cost
 * against revenue reports a smaller sale rather than a cost, and the
 * pattern of which products make money disappears.
 *
 * ## The whole list, rewritten
 *
 * Not an `ADD` to the constraint: PostgreSQL has no way to extend a check
 * in place, and a second constraint with an overlapping list is two rules
 * nobody can read together. The list here is the complete one, which is
 * also what makes a review of it possible.
 *
 * @financial-not-applicable: rewrites a role allowlist constraint; no amount, balance or entity scope is involved
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
        'inventory',
        'cost_of_sales',
    ];

    public function up(): void
    {
        $this->rewriteAllowlist(self::ROLES);
    }

    public function down(): void
    {
        // @unsafe-migration-reviewed: the rollback restores the list as it
        // stood before this migration, which is this one without the two
        // stock roles. An account already carrying one would fail the
        // restored constraint, which is correct: the role would no longer
        // resolve to anything.
        $this->rewriteAllowlist(array_values(array_filter(
            self::ROLES,
            static fn (string $role): bool => ! \in_array($role, ['inventory', 'cost_of_sales'], true),
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
