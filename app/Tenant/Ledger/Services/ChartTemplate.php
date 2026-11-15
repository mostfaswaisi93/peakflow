<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Services;

use App\Tenant\Ledger\Enums\AccountType;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\Account;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Models\Journal;
use Illuminate\Support\Facades\DB;

/**
 * Opens a new entity with a usable chart of accounts - PF-039.
 *
 * ## An empty chart is not a neutral starting point
 *
 * A tenant handed an empty tree has to invent a chart before they can record
 * anything, and what they invent will be shaped by the first three
 * transactions they happen to think of. They also cannot post at all until
 * the control-account roles are assigned, and nothing tells them that - the
 * first posting simply fails with a message about a role.
 *
 * So provisioning seeds a statutory-shaped chart with the roles already
 * assigned, and the tenant edits from something that works.
 *
 * ## A template is data, versioned, with a source
 *
 * Each template records where its structure comes from. "The accountant who
 * set up the first customer" is not a source, and a chart nobody can trace is
 * one nobody can defend when an auditor asks why revenue is split the way it
 * is.
 */
final class ChartTemplate
{
    public const GENERIC = 'ifrs_generic';

    /**
     * The generic IFRS chart.
     *
     * Four levels at most: statement, group, sub-group, posting account. Each
     * row is [code, parent code or null, type, Arabic name, English name,
     * postable, control role or null, monetary].
     *
     * ## The last element is the IAS 21 classification, and it is stated
     *
     * Monetary items are restated at the closing rate at each period end and
     * non-monetary items are not, so every account has to say which it is.
     * It cannot be derived: property and receivables are both assets, and a
     * prepayment is both current and non-monetary.
     *
     * Two rows are worth reading twice. **Deferred revenue is false**: it is
     * settled by delivering goods and services, not by paying money, and
     * revaluing it puts an exchange difference through profit or loss for an
     * obligation that is not denominated in currency. It is the account most
     * often wrongly marked monetary.
     *
     * **Suspense is false** for a different reason: it holds items nobody has
     * identified, and revaluing an unidentified item asserts a
     * classification that has not been made.
     *
     * @return list<array{0: string, 1: ?string, 2: AccountType, 3: string, 4: string, 5: bool, 6: ?ControlRole, 7: bool}>
     */
    private function generic(): array
    {
        return [
            // ---- Assets -------------------------------------------------
            ['1', null, AccountType::Asset, 'الأصول', 'Assets', false, null, false],
            ['1.1', '1', AccountType::Asset, 'الأصول المتداولة', 'Current assets', false, null, false],
            ['1.1.1', '1.1', AccountType::Asset, 'النقد وما في حكمه', 'Cash and cash equivalents', false, null, false],
            ['1.1.1.1', '1.1.1', AccountType::Asset, 'الصندوق', 'Petty cash', true, null, true],
            ['1.1.1.2', '1.1.1', AccountType::Asset, 'البنك', 'Bank', true, ControlRole::Bank, true],
            ['1.1.1.3', '1.1.1', AccountType::Asset, 'مدفوعات قيد التحصيل', 'Payments in transit', true, ControlRole::PaymentsInTransit, true],
            ['1.1.2', '1.1', AccountType::Asset, 'الذمم المدينة', 'Receivables', false, null, false],
            ['1.1.2.1', '1.1.2', AccountType::Asset, 'ذمم العملاء', 'Trade receivables', true, ControlRole::AccountsReceivable, true],
            ['1.1.2.2', '1.1.2', AccountType::Asset, 'مخصص الخسائر الائتمانية', 'Expected credit loss provision', true, ControlRole::EclProvision, true],
            ['1.1.3', '1.1', AccountType::Asset, 'ضريبة القيمة المضافة المدخلة', 'Input VAT', true, ControlRole::VatInput, true],
            ['1.1.4', '1.1', AccountType::Asset, 'حسابات وسيطة', 'Suspense', true, ControlRole::Suspense, false],
            ['1.1.5', '1.1', AccountType::Asset, 'حسابات جارية بين الشركات', 'Inter-entity', true, ControlRole::InterEntity, true],
            ['1.2', '1', AccountType::Asset, 'الأصول غير المتداولة', 'Non-current assets', false, null, false],
            ['1.2.1', '1.2', AccountType::Asset, 'الممتلكات والمعدات', 'Property, plant and equipment', true, null, false],

            // ---- Liabilities --------------------------------------------
            ['2', null, AccountType::Liability, 'الالتزامات', 'Liabilities', false, null, false],
            ['2.1', '2', AccountType::Liability, 'الالتزامات المتداولة', 'Current liabilities', false, null, false],
            ['2.1.1', '2.1', AccountType::Liability, 'ذمم الموردين', 'Trade payables', true, ControlRole::AccountsPayable, true],
            ['2.1.2', '2.1', AccountType::Liability, 'ضريبة القيمة المضافة المخرجة', 'Output VAT', true, ControlRole::VatOutput, true],
            ['2.1.3', '2.1', AccountType::Liability, 'إيرادات مؤجلة', 'Deferred revenue', true, ControlRole::DeferredRevenue, false],

            // ---- Equity --------------------------------------------------
            ['3', null, AccountType::Equity, 'حقوق الملكية', 'Equity', false, null, false],
            ['3.1', '3', AccountType::Equity, 'رأس المال', 'Share capital', true, null, false],
            ['3.2', '3', AccountType::Equity, 'الأرباح المحتجزة', 'Retained earnings', true, ControlRole::RetainedEarnings, false],

            // ---- Revenue -------------------------------------------------
            ['4', null, AccountType::Revenue, 'الإيرادات', 'Revenue', false, null, false],
            ['4.1', '4', AccountType::Revenue, 'إيرادات المبيعات', 'Sales revenue', true, null, false],
            ['4.2', '4', AccountType::Revenue, 'إيرادات أخرى', 'Other income', true, null, false],

            // ---- Expenses ------------------------------------------------
            ['5', null, AccountType::Expense, 'المصروفات', 'Expenses', false, null, false],
            ['5.1', '5', AccountType::Expense, 'تكلفة المبيعات', 'Cost of sales', true, null, false],
            ['5.2', '5', AccountType::Expense, 'مصروفات إدارية', 'Administrative expenses', true, null, false],
            ['5.3', '5', AccountType::Expense, 'فروق العملة', 'Foreign exchange gain or loss', true, ControlRole::FxGainLoss, false],
            ['5.4', '5', AccountType::Expense, 'إعادة تقييم العملة', 'FX revaluation', true, ControlRole::FxRevaluation, false],
            ['5.5', '5', AccountType::Expense, 'فروق التقريب', 'Rounding difference', true, ControlRole::RoundingDifference, false],
            ['5.6', '5', AccountType::Expense, 'ديون معدومة', 'Bad debt', true, ControlRole::BadDebt, false],
        ];
    }

    /**
     * The journals every entity needs - PF-040.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
     */
    private function journals(): array
    {
        return [
            ['SAL', 'sales', 'المبيعات', 'Sales', false],
            ['PUR', 'purchases', 'المشتريات', 'Purchases', false],
            ['CSH', 'cash', 'الصندوق', 'Cash', false],
            ['BNK', 'bank', 'البنك', 'Bank', false],
            ['GEN', 'general', 'عام', 'General', false],
            // Reserved: these accept postings only from the process that owns
            // them. An opening balance typed by hand six months into the year
            // is indistinguishable from the real one, and is found a year
            // later by whoever compares this opening to last year's closing.
            ['OPN', 'opening', 'الأرصدة الافتتاحية', 'Opening balances', true],
            ['CLS', 'closing', 'الإقفال', 'Closing', true],
            ['REV', 'revaluation', 'إعادة التقييم', 'Revaluation', true],
        ];
    }

    /**
     * Apply the template to an entity.
     *
     * Idempotent: an entity that already has accounts is left alone, because
     * re-running this over a live chart would either duplicate codes or
     * silently rewrite names somebody chose.
     *
     * @return array{accounts: int, journals: int, roles: int}
     */
    public function apply(string $entityId): array
    {
        if (Account::query()->where('entity_id', $entityId)->exists()) {
            return ['accounts' => 0, 'journals' => 0, 'roles' => 0];
        }

        return DB::connection('tenant')->transaction(function () use ($entityId): array {
            $byCode = [];
            $roles = 0;

            foreach ($this->generic() as [$code, $parentCode, $type, $nameAr, $nameEn, $postable, $role, $monetary]) {
                $parent = $parentCode === null ? null : $byCode[$parentCode];

                $account = Account::create([
                    'entity_id' => $entityId,
                    'code' => $code,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'parent_id' => $parent?->getKey(),
                    // The path IS the code here, because the generic chart is
                    // already coded hierarchically. A template whose codes do
                    // not nest would build the path from its ancestors.
                    'path' => $code,
                    'depth' => substr_count($code, '.'),
                    'type' => $type->value,
                    'statement_line' => $type->statement(),
                    'postable' => $postable,
                    'monetary' => $monetary,
                ]);

                $byCode[$code] = $account;

                if ($role !== null) {
                    AccountRole::create([
                        'entity_id' => $entityId,
                        'role' => $role->value,
                        'account_id' => $account->getKey(),
                    ]);

                    $roles++;
                }
            }

            foreach ($this->journals() as [$code, $type, $nameAr, $nameEn, $reserved]) {
                /*
                 | A reserved journal declares WHICH rule may post into it.
                 |
                 | `reserved` on its own was a comment: any rule could post
                 | into the opening-balances journal, and an opening balance
                 | typed by hand is indistinguishable from the real one.
                 */
                $rule = match ($type) {
                    'opening' => 'ledger.opening_balance',
                    'closing' => 'ledger.year_end_close',
                    'revaluation' => 'ledger.fx_revaluation',
                    default => null,
                };

                Journal::create([
                    'entity_id' => $entityId,
                    'code' => $code,
                    'type' => $type,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'reserved' => $reserved,
                    'posting_rule' => $rule,
                ]);
            }

            return [
                'accounts' => count($byCode),
                'journals' => count($this->journals()),
                'roles' => $roles,
            ];
        });
    }

    /**
     * Which required roles this entity is still missing.
     *
     * Asked before the first posting, so the answer is a list of roles rather
     * than a constraint violation naming one of them.
     *
     * @return list<ControlRole>
     */
    public function missingRequiredRoles(string $entityId): array
    {
        /*
         | `->pluck('role')` returns ControlRole INSTANCES, not strings,
         | because the model casts the column. Comparing those against
         | `$role->value` with a strict `in_array` never matches, so every
         | required role reported as missing on an entity that had them all.
         |
         | Mapped back to values explicitly rather than relying on how a cast
         | behaves through pluck, which is the kind of thing that changes in a
         | framework minor version.
         */
        $assigned = AccountRole::query()
            ->where('entity_id', $entityId)
            ->get()
            ->map(static fn (AccountRole $assignment): string => $assignment->role->value)
            ->all();

        return array_values(array_filter(
            ControlRole::cases(),
            static fn (ControlRole $role): bool => $role->isRequiredToPost()
                && ! in_array($role->value, $assigned, true),
        ));
    }
}
