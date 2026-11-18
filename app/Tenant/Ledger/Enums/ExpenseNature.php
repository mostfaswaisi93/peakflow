<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * What an expense IS, as opposed to what it was for - PF-185.
 *
 * ## Why an expense needs two classifications
 *
 * IAS 1.99 lets an entity present its expenses by nature or by function,
 * and 1.104 says that an entity presenting by FUNCTION must additionally
 * disclose them by nature. So this is not a choice between two views: a
 * product that supports only the functional one cannot produce a compliant
 * set of statements at all.
 *
 * The two axes are genuinely independent. Salaries paid to a production
 * supervisor are `employee_benefits` by nature and `cost_of_sales` by
 * function; salaries paid to a bookkeeper are the same nature and
 * `administrative_expenses` by function. Deriving either from the other is
 * the mistake that makes the second statement a restatement of the first.
 *
 * ## Why it is on the ACCOUNT and not on the posting
 *
 * A posting's nature does not vary: an account for salaries holds salaries.
 * Asking for it per line would put the question to the person entering a
 * bill, who is the one person who cannot answer it consistently across a
 * year, and the by-nature statement would be the average of their guesses.
 *
 * ## Revenue has none
 *
 * IAS 1.102's analysis is of EXPENSES. Revenue appears in the by-nature
 * statement as itself, at the top, exactly as it does by function - so a
 * revenue account carrying a nature would be a classification nothing
 * reads, and the first person to add one would reasonably expect it to
 * change something.
 */
enum ExpenseNature: string
{
    case RawMaterialsAndConsumables = 'raw_materials_and_consumables';

    /**
     * The movement in stock, which is a NEGATIVE expense when stock grows.
     *
     * IAS 1.102 presents it as its own line for that reason: purchases are
     * an expense in the period they are made, and the part not yet sold has
     * to be taken back out, or the by-nature statement reports a cost the
     * entity has not incurred.
     */
    case ChangesInInventories = 'changes_in_inventories';

    case EmployeeBenefits = 'employee_benefits';

    case DepreciationAndAmortisation = 'depreciation_and_amortisation';

    case Impairment = 'impairment';

    case FinanceCosts = 'finance_costs';

    case TaxExpense = 'tax_expense';

    case OtherExpenses = 'other_expenses';

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' ? $this->arabic() : $this->english();
    }

    private function english(): string
    {
        return match ($this) {
            self::RawMaterialsAndConsumables => 'Raw materials and consumables used',
            self::ChangesInInventories => 'Changes in inventories',
            self::EmployeeBenefits => 'Employee benefits expense',
            self::DepreciationAndAmortisation => 'Depreciation and amortisation',
            self::Impairment => 'Impairment losses',
            self::FinanceCosts => 'Finance costs',
            self::TaxExpense => 'Tax expense',
            self::OtherExpenses => 'Other expenses',
        };
    }

    private function arabic(): string
    {
        return match ($this) {
            self::RawMaterialsAndConsumables => 'مواد ومستلزمات مستهلكة',
            self::ChangesInInventories => 'التغيّر في المخزون',
            self::EmployeeBenefits => 'مصروف منافع الموظفين',
            self::DepreciationAndAmortisation => 'الإهلاك والإطفاء',
            self::Impairment => 'خسائر الانخفاض في القيمة',
            self::FinanceCosts => 'تكاليف تمويلية',
            self::TaxExpense => 'مصروف الضريبة',
            self::OtherExpenses => 'مصروفات أخرى',
        };
    }

    /** The paragraph that asks for this analysis. */
    public function reference(): string
    {
        return $this === self::TaxExpense ? 'IAS 1.82(d)' : 'IAS 1.102';
    }
}
