<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bad debt recovery control role - PF-172.
 *
 * ## Why a recovery is not a credit to bad debt
 *
 * Money that arrives after a debt was written off is a new receipt, and it
 * belongs in an account of its own. Crediting bad debt back would say the
 * conclusion had been wrong, and it was not: on the day it was made, with
 * what was known then, it was right.
 *
 * Netted, the two figures describe neither. Kept apart, "we wrote off
 * 400,000 and recovered 30,000" is a sentence about how good the write-off
 * decisions were - which is the only question that improves them, and the
 * one a provision matrix is eventually calibrated against.
 *
 * Typed as revenue rather than a negative expense, for the same reason: an
 * expense would let it net against bad debt in every report that groups by
 * account type.
 *
 * ## The allowlist is rebuilt, not extended
 *
 * The same reasoning as PF-132, PF-143, PF-164 and PF-167. A constraint
 * assembled from fragments across eight migrations is one nobody can read;
 * this is a diff somebody can check against `ControlRole`, which is what
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
        'bad_debt_recovery',
    ];

    public function up(): void
    {
        $this->rewriteAllowlist(self::ROLES);
    }

    public function down(): void
    {
        // @unsafe-migration-reviewed: the rollback restores the list as it
        // stood before this migration, which is this one without
        // `bad_debt_recovery`. An account already carrying that role would fail
        // the restored constraint, which is correct: the role would no
        // longer resolve to anything.
        $this->rewriteAllowlist(array_values(array_filter(
            self::ROLES,
            static fn (string $role): bool => $role !== 'bad_debt_recovery',
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
