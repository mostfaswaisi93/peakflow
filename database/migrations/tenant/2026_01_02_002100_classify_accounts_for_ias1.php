<?php

declare(strict_types=1);

use App\Tenant\Ledger\Enums\ControlRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reclassify every account onto an IAS 1 line - PF-184.
 *
 * ## What was in the column before
 *
 * `statement_line` held `'balance_sheet'` or `'profit_and_loss'`, written by
 * the chart applier from `AccountType::statement()`. That answers a
 * different question: which statement, not which line. Every asset carried
 * the same value, so a statement built from it would have had two rows.
 *
 * ## Why the mapping is by control role and code, in that order
 *
 * Each key is written as `ControlRole::X->value` rather than as a string, so
 * a role this product does not have is a PHP error here rather than an UPDATE
 * that matches nothing - the first version of this map invented eleven role
 * names and every one of them silently classified no accounts at all.
 *
 * A role is the strongest thing an account carries: `accounts_receivable`
 * means trade receivables in every chart this product ships and in every
 * chart a customer has edited, because the posting rules resolve payments
 * through it. Where there is no role the shipped charts' own codes decide,
 * and they are stable - the three templates share a structure.
 *
 * Anything else is left NULL deliberately. A guess would be a classification
 * nobody chose that the statement presents as though somebody had, and PF-184
 * asks for the unclassified to be shown as unclassified. A customer who added
 * an account will see it in that section and move it; a customer whose
 * account this migration guessed wrong would see a statement that balances
 * and is in the wrong place.
 *
 * @unsafe-migration-reviewed: it rewrites a column on an existing table, and
 * the column is read by nothing yet - the statement that reads it ships in
 * the same change. So the write cannot race a reader, and the old values
 * were not a classification anybody could have relied on.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | By role first.
         |
         | `role` is on `account_roles`, not on `accounts`, because one
         | entity's chart assigns each role once and the roles are what the
         | posting rules resolve. An account that holds a role is an account
         | whose purpose is known with certainty.
         */
        $byRole = [
            ControlRole::Bank->value => 'cash_and_cash_equivalents',
            ControlRole::PaymentsInTransit->value => 'cash_and_cash_equivalents',
            ControlRole::AccountsReceivable->value => 'trade_and_other_receivables',
            ControlRole::EclProvision->value => 'trade_and_other_receivables',
            ControlRole::Inventory->value => 'inventories',
            ControlRole::VatInput->value => 'current_tax_assets',
            ControlRole::WithholdingReceivable->value => 'current_tax_assets',
            ControlRole::Suspense->value => 'other_current_assets',
            ControlRole::InterEntity->value => 'other_current_assets',
            ControlRole::AccountsPayable->value => 'trade_and_other_payables',
            ControlRole::GoodsReceivedNotInvoiced->value => 'trade_and_other_payables',
            ControlRole::EmployeePayable->value => 'trade_and_other_payables',
            ControlRole::VatOutput->value => 'current_tax_liabilities',
            ControlRole::WithholdingPayable->value => 'current_tax_liabilities',
            ControlRole::PayrollTaxPayable->value => 'current_tax_liabilities',
            ControlRole::DeferredRevenue->value => 'other_current_liabilities',
            ControlRole::UnappliedCash->value => 'other_current_liabilities',
            ControlRole::SocialInsurancePayable->value => 'other_current_liabilities',
            ControlRole::RetainedEarnings->value => 'retained_earnings',
            ControlRole::CostOfSales->value => 'cost_of_sales',
            ControlRole::SalaryExpense->value => 'administrative_expenses',
            ControlRole::EmployerContributionExpense->value => 'administrative_expenses',
            ControlRole::BadDebt->value => 'impairment_losses',
            ControlRole::EclCharge->value => 'impairment_losses',
            ControlRole::BadDebtRecovery->value => 'other_income',
            ControlRole::BankCharges->value => 'finance_costs',
            ControlRole::FxGainLoss->value => 'other_expenses',
            ControlRole::FxRevaluation->value => 'other_expenses',
            ControlRole::RoundingDifference->value => 'other_expenses',
        ];

        // The old values were never lines, so none of them survives.
        DB::table('accounts')->update(['statement_line' => null]);

        foreach ($byRole as $role => $line) {
            DB::statement(
                'UPDATE accounts SET statement_line = ? '.
                'WHERE postable AND id IN (SELECT account_id FROM account_roles WHERE role = ?)',
                [$line, $role],
            );
        }

        /*
         | Then by code, for the accounts the shipped charts give no role.
         |
         | Scoped to accounts that are still unclassified, so a role never
         | loses to a code: the role is the stronger statement of purpose and
         | a customer who moved an account to a different code still has the
         | role on it.
         */
        $byCode = [
            '1.1.1.1' => 'cash_and_cash_equivalents',
            '1.1.1.2' => 'cash_and_cash_equivalents',
            '1.1.1.3' => 'cash_and_cash_equivalents',
            '1.1.2.1' => 'trade_and_other_receivables',
            '1.1.2.2' => 'trade_and_other_receivables',
            '1.1.3' => 'current_tax_assets',
            '1.1.4' => 'other_current_assets',
            '1.1.5' => 'other_current_assets',
            '1.1.6' => 'current_tax_assets',
            '1.1.7' => 'inventories',
            '1.2.1' => 'property_plant_and_equipment',
            '3.1' => 'issued_capital',
            '3.2' => 'retained_earnings',
            '4.1' => 'revenue',
            '4.2' => 'other_income',
            '4.3' => 'other_income',
            '5.1' => 'cost_of_sales',
            '5.2' => 'administrative_expenses',
        ];

        foreach ($byCode as $code => $line) {
            DB::statement(
                'UPDATE accounts SET statement_line = ? WHERE postable AND code = ? AND statement_line IS NULL',
                [$line, $code],
            );
        }

        /*
         | A group account keeps NULL, always.
         |
         | Its children carry the lines. A heading that carried one too would
         | be added to the same section twice and the statement would still
         | balance, which is the hardest kind of wrong to see.
         */
        DB::statement('UPDATE accounts SET statement_line = NULL WHERE NOT postable');
    }

    public function down(): void
    {
        /*
         | There is nothing to restore.
         |
         | The previous contents were derived from `type` by a line of code
         | that no longer exists, and recomputing them would put a value back
         | that was never a statement line. A rollback leaves the column
         | empty, which is the honest state: unclassified.
         */
        DB::table('accounts')->update(['statement_line' => null]);
    }
};
