<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Enums;

/**
 * The IAS 1 line items, as a closed set - PF-184.
 *
 * ## Why the chart carries this and the report does not
 *
 * A statement of financial position built by a report that knows which
 * account codes to add up is a report that is wrong the first time somebody
 * adds an account. The classification belongs to the ACCOUNT, declared in
 * the chart template beside `type` and `monetary`, and the report is a
 * grouping over it. Adding an account to a chart then changes the statement
 * without anybody editing the statement.
 *
 * ## Why `type` was not enough
 *
 * `AccountType` has five cases and IAS 1 asks for more than five lines. Cost
 * of sales and administrative expenses are both expenses and are never added
 * together; trade receivables and property are both assets and sit on
 * opposite sides of the current split. The type decides the natural sign and
 * whether the account closes at year end; the line decides where it is
 * presented. Two questions, two columns.
 *
 * ## A closed set, and what that costs
 *
 * IAS 1.55 allows additional line items when they are relevant, and this
 * enum does not. That is a deliberate narrowing: an open set means two
 * entities in one group classify the same account differently and the
 * consolidation adds them under two headings that look like two things. A
 * line this product does not have is a line to add here, in code, with its
 * paragraph reference - which is where somebody notices whether IAS 1
 * actually asks for it.
 *
 * ## The order of the cases is the order of the statement
 *
 * `cases()` is read in order by the report, so there is no second list of
 * presentation order to drift from this one.
 */
enum StatementLine: string
{
    // ---- Statement of financial position: assets (IAS 1.54(a)-(i)) ------

    case CashAndCashEquivalents = 'cash_and_cash_equivalents';
    case TradeAndOtherReceivables = 'trade_and_other_receivables';
    case Inventories = 'inventories';
    case CurrentTaxAssets = 'current_tax_assets';
    case OtherCurrentAssets = 'other_current_assets';

    case PropertyPlantAndEquipment = 'property_plant_and_equipment';
    case IntangibleAssets = 'intangible_assets';
    case OtherNonCurrentAssets = 'other_non_current_assets';

    // ---- Liabilities (IAS 1.54(k)-(o)) ---------------------------------

    case TradeAndOtherPayables = 'trade_and_other_payables';
    case CurrentTaxLiabilities = 'current_tax_liabilities';
    case CurrentProvisions = 'current_provisions';
    case OtherCurrentLiabilities = 'other_current_liabilities';

    case Borrowings = 'borrowings';
    case NonCurrentProvisions = 'non_current_provisions';
    case OtherNonCurrentLiabilities = 'other_non_current_liabilities';

    // ---- Equity (IAS 1.54(r), 1.78(e)) ---------------------------------

    case IssuedCapital = 'issued_capital';
    case OtherReserves = 'other_reserves';
    case RetainedEarnings = 'retained_earnings';

    // ---- Statement of profit or loss (IAS 1.82, 1.99-105) --------------

    case Revenue = 'revenue';
    case OtherIncome = 'other_income';
    case CostOfSales = 'cost_of_sales';
    case DistributionCosts = 'distribution_costs';
    case AdministrativeExpenses = 'administrative_expenses';
    case ImpairmentLosses = 'impairment_losses';
    case OtherExpenses = 'other_expenses';
    case FinanceCosts = 'finance_costs';
    case IncomeTaxExpense = 'income_tax_expense';

    // ---- Other comprehensive income (IAS 1.82A, 1.7) -------------------

    /**
     * Items that MAY later be reclassified into profit or loss.
     *
     * IAS 1.82A splits other comprehensive income by this, and the split
     * is not presentational: an item in the first group will one day move
     * into profit and an item in the second never will. Presenting them
     * together tells the reader that every one of them is still to come.
     */
    case ForeignOperationTranslation = 'foreign_operation_translation';
    case CashFlowHedgeReserve = 'cash_flow_hedge_reserve';

    /** Items that will NEVER be reclassified into profit or loss. */
    case RevaluationSurplus = 'revaluation_surplus';
    case DefinedBenefitRemeasurement = 'defined_benefit_remeasurement';

    public function statement(): FinancialStatement
    {
        return match ($this->section()) {
            StatementSection::Assets, StatementSection::Liabilities, StatementSection::Equity
                => FinancialStatement::FinancialPosition,
            StatementSection::Income,
            StatementSection::Expenses,
            StatementSection::OtherComprehensiveIncome
                => FinancialStatement::ProfitOrLoss,
        };
    }

    public function section(): StatementSection
    {
        return match ($this) {
            self::CashAndCashEquivalents,
            self::TradeAndOtherReceivables,
            self::Inventories,
            self::CurrentTaxAssets,
            self::OtherCurrentAssets,
            self::PropertyPlantAndEquipment,
            self::IntangibleAssets,
            self::OtherNonCurrentAssets => StatementSection::Assets,

            self::TradeAndOtherPayables,
            self::CurrentTaxLiabilities,
            self::CurrentProvisions,
            self::OtherCurrentLiabilities,
            self::Borrowings,
            self::NonCurrentProvisions,
            self::OtherNonCurrentLiabilities => StatementSection::Liabilities,

            self::IssuedCapital,
            self::OtherReserves,
            self::RetainedEarnings => StatementSection::Equity,

            self::Revenue,
            self::OtherIncome => StatementSection::Income,

            self::CostOfSales,
            self::DistributionCosts,
            self::AdministrativeExpenses,
            self::ImpairmentLosses,
            self::OtherExpenses,
            self::FinanceCosts,
            self::IncomeTaxExpense => StatementSection::Expenses,

            self::ForeignOperationTranslation,
            self::CashFlowHedgeReserve,
            self::RevaluationSurplus,
            self::DefinedBenefitRemeasurement => StatementSection::OtherComprehensiveIncome,
        };
    }

    public function maturity(): Maturity
    {
        return match ($this) {
            self::CashAndCashEquivalents,
            self::TradeAndOtherReceivables,
            self::Inventories,
            self::CurrentTaxAssets,
            self::OtherCurrentAssets,
            self::TradeAndOtherPayables,
            self::CurrentTaxLiabilities,
            self::CurrentProvisions,
            self::OtherCurrentLiabilities => Maturity::Current,

            self::PropertyPlantAndEquipment,
            self::IntangibleAssets,
            self::OtherNonCurrentAssets,
            self::Borrowings,
            self::NonCurrentProvisions,
            self::OtherNonCurrentLiabilities => Maturity::NonCurrent,

            default => Maturity::NotApplicable,
        };
    }

    /**
     * The account types this line may be used on.
     *
     * The check that matters: an asset account classified as a payable is a
     * statement that does not balance, and the chart loader refuses it at
     * load rather than letting the report discover it. Revenue and expense
     * are kept apart for the same reason - "other income" on an expense
     * account would be presented as income with an expense's sign.
     *
     * @return list<AccountType>
     */
    public function allowedTypes(): array
    {
        return match ($this->section()) {
            StatementSection::Assets => [AccountType::Asset],
            StatementSection::Liabilities => [AccountType::Liability],
            StatementSection::Equity => [AccountType::Equity],
            StatementSection::Income => [AccountType::Revenue],
            StatementSection::Expenses => [AccountType::Expense],

            /*
             | OCI accumulates in EQUITY - IAS 21.39(c).
             |
             | A translation difference is not revenue and is not an
             | expense; it goes to a separate component of equity and
             | stays there until the foreign operation is disposed of.
             | Allowing a revenue account onto an OCI line would put the
             | difference through profit, which is the single error IAS
             | 21.39 exists to prevent.
             */
            StatementSection::OtherComprehensiveIncome => [AccountType::Equity],
        };
    }

    /**
     * Which band of the statement of profit or loss this line sits in.
     *
     * The subtotals are the running totals at the band boundaries, so
     * adding a line puts it in a band and nothing else changes. A line on
     * the statement of financial position has no band, and asking for one
     * is a question about the wrong statement.
     */
    public function band(): ?ProfitOrLossBand
    {
        return match ($this) {
            self::Revenue, self::CostOfSales => ProfitOrLossBand::Gross,

            self::OtherIncome,
            self::DistributionCosts,
            self::AdministrativeExpenses,
            self::ImpairmentLosses,
            self::OtherExpenses => ProfitOrLossBand::Operating,

            self::FinanceCosts => ProfitOrLossBand::Financing,

            self::IncomeTaxExpense => ProfitOrLossBand::Tax,

            self::ForeignOperationTranslation,
            self::CashFlowHedgeReserve,
            self::RevaluationSurplus,
            self::DefinedBenefitRemeasurement => ProfitOrLossBand::OtherComprehensiveIncome,

            default => null,
        };
    }

    /**
     * Whether an OCI item may one day move into profit or loss - IAS 1.82A.
     *
     * Null for every line that is not OCI. The split is not presentational:
     * an item in the first group will one day be recycled and an item in
     * the second never will, and presenting them together tells the reader
     * that all of them are still to come.
     */
    public function mayBeReclassified(): ?bool
    {
        return match ($this) {
            self::ForeignOperationTranslation, self::CashFlowHedgeReserve => true,
            self::RevaluationSurplus, self::DefinedBenefitRemeasurement => false,
            default => null,
        };
    }

    /** The paragraph of IAS 1 that asks for this line. */
    public function reference(): string
    {
        return match ($this) {
            self::CashAndCashEquivalents => 'IAS 1.54(i)',
            self::TradeAndOtherReceivables => 'IAS 1.54(h)',
            self::Inventories => 'IAS 1.54(g)',
            self::CurrentTaxAssets => 'IAS 1.54(n)',
            self::OtherCurrentAssets => 'IAS 1.55',
            self::PropertyPlantAndEquipment => 'IAS 1.54(a)',
            self::IntangibleAssets => 'IAS 1.54(c)',
            self::OtherNonCurrentAssets => 'IAS 1.55',
            self::TradeAndOtherPayables => 'IAS 1.54(k)',
            self::CurrentTaxLiabilities => 'IAS 1.54(n)',
            self::CurrentProvisions => 'IAS 1.54(l)',
            self::OtherCurrentLiabilities => 'IAS 1.55',
            self::Borrowings => 'IAS 1.54(m)',
            self::NonCurrentProvisions => 'IAS 1.54(l)',
            self::OtherNonCurrentLiabilities => 'IAS 1.55',
            self::IssuedCapital => 'IAS 1.54(r)',
            self::OtherReserves => 'IAS 1.54(r)',
            self::RetainedEarnings => 'IAS 1.54(r)',
            self::Revenue => 'IAS 1.82(a)',
            self::OtherIncome => 'IAS 1.85',
            self::CostOfSales => 'IAS 1.103',
            self::DistributionCosts => 'IAS 1.103',
            self::AdministrativeExpenses => 'IAS 1.103',
            self::ImpairmentLosses => 'IAS 1.82(ba)',
            self::OtherExpenses => 'IAS 1.103',
            self::FinanceCosts => 'IAS 1.82(b)',
            self::IncomeTaxExpense => 'IAS 1.82(d)',
            self::ForeignOperationTranslation => 'IAS 21.39(c)',
            self::CashFlowHedgeReserve => 'IFRS 9.6.5.11',
            self::RevaluationSurplus => 'IAS 16.39',
            self::DefinedBenefitRemeasurement => 'IAS 19.120(c)',
        };
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' ? $this->arabic() : $this->english();
    }

    private function english(): string
    {
        return match ($this) {
            self::CashAndCashEquivalents => 'Cash and cash equivalents',
            self::TradeAndOtherReceivables => 'Trade and other receivables',
            self::Inventories => 'Inventories',
            self::CurrentTaxAssets => 'Current tax assets',
            self::OtherCurrentAssets => 'Other current assets',
            self::PropertyPlantAndEquipment => 'Property, plant and equipment',
            self::IntangibleAssets => 'Intangible assets',
            self::OtherNonCurrentAssets => 'Other non-current assets',
            self::TradeAndOtherPayables => 'Trade and other payables',
            self::CurrentTaxLiabilities => 'Current tax liabilities',
            self::CurrentProvisions => 'Provisions',
            self::OtherCurrentLiabilities => 'Other current liabilities',
            self::Borrowings => 'Borrowings',
            self::NonCurrentProvisions => 'Non-current provisions',
            self::OtherNonCurrentLiabilities => 'Other non-current liabilities',
            self::IssuedCapital => 'Issued capital',
            self::OtherReserves => 'Other reserves',
            self::RetainedEarnings => 'Retained earnings',
            self::Revenue => 'Revenue',
            self::OtherIncome => 'Other income',
            self::CostOfSales => 'Cost of sales',
            self::DistributionCosts => 'Distribution costs',
            self::AdministrativeExpenses => 'Administrative expenses',
            self::ImpairmentLosses => 'Impairment losses',
            self::OtherExpenses => 'Other expenses',
            self::FinanceCosts => 'Finance costs',
            self::IncomeTaxExpense => 'Income tax expense',
            self::ForeignOperationTranslation => 'Exchange differences on translating foreign operations',
            self::CashFlowHedgeReserve => 'Cash flow hedges',
            self::RevaluationSurplus => 'Revaluation surplus',
            self::DefinedBenefitRemeasurement => 'Remeasurements of defined benefit plans',
        };
    }

    private function arabic(): string
    {
        return match ($this) {
            self::CashAndCashEquivalents => 'النقد وما في حكمه',
            self::TradeAndOtherReceivables => 'المدينون التجاريون وأرصدة مدينة أخرى',
            self::Inventories => 'المخزون',
            self::CurrentTaxAssets => 'أصول ضريبية متداولة',
            self::OtherCurrentAssets => 'أصول متداولة أخرى',
            self::PropertyPlantAndEquipment => 'الممتلكات والآلات والمعدات',
            self::IntangibleAssets => 'أصول غير ملموسة',
            self::OtherNonCurrentAssets => 'أصول غير متداولة أخرى',
            self::TradeAndOtherPayables => 'الدائنون التجاريون وأرصدة دائنة أخرى',
            self::CurrentTaxLiabilities => 'التزامات ضريبية متداولة',
            self::CurrentProvisions => 'المخصصات',
            self::OtherCurrentLiabilities => 'التزامات متداولة أخرى',
            self::Borrowings => 'القروض',
            self::NonCurrentProvisions => 'مخصصات غير متداولة',
            self::OtherNonCurrentLiabilities => 'التزامات غير متداولة أخرى',
            self::IssuedCapital => 'رأس المال المُصدَر',
            self::OtherReserves => 'احتياطيات أخرى',
            self::RetainedEarnings => 'الأرباح المُبقاة',
            self::Revenue => 'الإيرادات',
            self::OtherIncome => 'إيرادات أخرى',
            self::CostOfSales => 'تكلفة المبيعات',
            self::DistributionCosts => 'تكاليف التوزيع',
            self::AdministrativeExpenses => 'مصروفات إدارية',
            self::ImpairmentLosses => 'خسائر الانخفاض في القيمة',
            self::OtherExpenses => 'مصروفات أخرى',
            self::FinanceCosts => 'تكاليف تمويلية',
            self::IncomeTaxExpense => 'مصروف ضريبة الدخل',
            self::ForeignOperationTranslation => 'فروق ترجمة العمليات الأجنبية',
            self::CashFlowHedgeReserve => 'تغطيات التدفق النقدي',
            self::RevaluationSurplus => 'فائض إعادة التقييم',
            self::DefinedBenefitRemeasurement => 'إعادة قياس خطط المنافع المحددة',
        };
    }
}
