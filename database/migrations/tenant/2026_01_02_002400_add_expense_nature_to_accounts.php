<?php

declare(strict_types=1);

use App\Tenant\Ledger\Enums\ControlRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The second analysis of an expense - PF-185.
 *
 * ## Why an expense needs two columns and not one
 *
 * IAS 1.99 lets an entity present expenses by nature or by function, and
 * 1.104 says an entity presenting by function must ALSO disclose them by
 * nature. So this is not a choice between two views of the same data: a
 * product carrying only `statement_line` cannot produce a compliant set of
 * statements, and the gap would surface at an audit rather than here.
 *
 * Neither axis follows from the other. A production supervisor's salary is
 * `employee_benefits` by nature and `cost_of_sales` by function; a
 * bookkeeper's is the same nature under `administrative_expenses`. A
 * by-nature statement derived from the functional one would be a
 * restatement of it, which is worse than not offering one.
 *
 * ## Nullable, and the null means "nobody classified this"
 *
 * As with `statement_line`: a shipped template may not leave it empty on a
 * postable expense account, and the loader refuses one that does. A
 * customer's own account can be unclassified, and the by-nature statement
 * shows those under their own heading rather than dropping them.
 *
 * @unsafe-migration-reviewed: it adds a nullable column to `accounts` and
 * backfills it under a WHERE scoped to expense accounts with a known control
 * role. `ADD COLUMN ... NULL` takes no table rewrite in PostgreSQL 11+, and
 * nothing reads the column until the statement that ships with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            /*
             | Beside `statement_line`, because they are the same KIND of
             | fact: two classifications that a presentation reads and a
             | posting never does.
             */
            $table->string('expense_nature', 64)->nullable()->after('statement_line');
        });

        /*
         | Backfilled by control role, and by nothing else.
         |
         | A role is a statement of purpose the posting rules already rely
         | on. Guessing from an account's name would put a classification
         | nobody chose into a disclosure an auditor reads, and the whole
         | point of the column is that somebody chose.
         */
        $byRole = [
            ControlRole::CostOfSales->value => 'raw_materials_and_consumables',
            ControlRole::SalaryExpense->value => 'employee_benefits',
            ControlRole::EmployerContributionExpense->value => 'employee_benefits',
            ControlRole::BadDebt->value => 'impairment',
            ControlRole::EclCharge->value => 'impairment',
            ControlRole::BankCharges->value => 'finance_costs',
            ControlRole::FxGainLoss->value => 'other_expenses',
            ControlRole::FxRevaluation->value => 'other_expenses',
            ControlRole::RoundingDifference->value => 'other_expenses',
        ];

        foreach ($byRole as $role => $nature) {
            DB::statement(
                'UPDATE accounts SET expense_nature = ? '.
                "WHERE postable AND type = 'expense' AND id IN "
                .'(SELECT account_id FROM account_roles WHERE role = ?)',
                [$nature, $role],
            );
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('expense_nature');
        });
    }
};
