<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The permission catalogue - PF-028.
 *
 * Verb-shaped: `invoice.issue`, not `invoices` or `can_invoice`. A permission
 * that does not name an act cannot be reasoned about, and a role screen built
 * from nouns forces the person configuring it to guess.
 *
 * Each carries a plain-language bilingual description, because the person who
 * SHOULD be configuring roles is a controller, not a developer.
 *
 * Two flags carry real weight:
 *
 *   requires_mfa      cannot be GRANTED to a user with no enrolled second
 *                     factor - refused at grant time with an explanation,
 *                     not at use time where it blocks real work (PF-031)
 *   requires_step_up  needs a FRESH factor assertion at the moment of the
 *                     act, so a stolen session is not enough (PF-032)
 */
final class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [];

        foreach ($this->catalogue() as [$key, $module, $ar, $en, $mfa, $stepUp]) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'key' => $key,
                'module' => $module,
                'label_ar' => $ar,
                'label_en' => $en,
                'requires_mfa' => $mfa,
                'requires_step_up' => $stepUp,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::connection('tenant')->table('permissions')->upsert(
            $rows,
            ['key'],
            ['module', 'label_ar', 'label_en', 'requires_mfa', 'requires_step_up', 'updated_at'],
        );
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:bool,5:bool}> */
    private function catalogue(): array
    {
        return [
            // Ledger
            ['journal.draft', 'ledger', 'إنشاء مسودة قيد', 'Draft a journal entry', false, false],
            ['journal.post', 'ledger', 'ترحيل قيد', 'Post a journal entry', true, false],
            ['journal.reverse', 'ledger', 'عكس قيد مُرحَّل', 'Reverse a posted entry', true, false],
            ['account.manage', 'ledger', 'إدارة شجرة الحسابات', 'Manage the chart of accounts', false, false],
            ['dimension.manage', 'ledger', 'إدارة الأبعاد التحليلية', 'Manage analysis dimensions', false, false],
            ['period.close', 'ledger', 'إقفال فترة مالية', 'Close an accounting period', true, false],
            ['period.reopen', 'ledger', 'إعادة فتح فترة مُقفلة', 'Reopen a closed period', true, true],
            ['period.post_adjusting', 'ledger', 'ترحيل قيود تسوية', 'Post adjusting entries', true, false],

            // Sales
            ['invoice.draft', 'sales', 'إنشاء مسودة فاتورة', 'Draft an invoice', false, false],
            ['invoice.issue', 'sales', 'إصدار فاتورة', 'Issue an invoice', false, false],
            ['credit_note.issue', 'sales', 'إصدار إشعار دائن', 'Issue a credit note', false, false],
            ['quote.manage', 'sales', 'إدارة عروض الأسعار', 'Manage quotes', false, false],
            ['dunning.manage', 'sales', 'إدارة سياسات المطالبة', 'Manage dunning policies', false, false],

            // Purchasing
            ['bill.create', 'purchasing', 'إدخال فاتورة مورد', 'Enter a supplier invoice', false, false],
            ['bill.approve', 'purchasing', 'اعتماد فاتورة مورد', 'Approve a supplier invoice', false, false],
            ['purchase_order.manage', 'purchasing', 'إدارة أوامر الشراء', 'Manage purchase orders', false, false],
            ['expense_claim.submit', 'purchasing', 'تقديم مطالبة مصروفات', 'Submit an expense claim', false, false],
            ['expense_claim.approve', 'purchasing', 'اعتماد مطالبة مصروفات', 'Approve an expense claim', false, false],

            // Settlement - where money leaves
            ['payment.record', 'settlement', 'تسجيل عملية دفع', 'Record a payment', false, false],
            ['payment.allocate', 'settlement', 'تخصيص سداد', 'Allocate a payment', false, false],
            ['payment.prepare', 'settlement', 'تحضير دفعة سداد', 'Prepare a payment run', false, false],
            ['payment.release', 'settlement', 'إطلاق دفعة سداد', 'Release a payment run', true, true],
            ['writeoff.record', 'settlement', 'إعدام دين', 'Write off a receivable', true, false],

            // Banking
            ['bank.manage', 'banking', 'إدارة الحسابات البنكية', 'Manage bank accounts', false, false],
            ['bank.import', 'banking', 'استيراد كشف حساب', 'Import a bank statement', false, false],
            ['bank.reconcile', 'banking', 'إجراء تسوية بنكية', 'Reconcile a bank account', false, false],
            ['reconciliation.write_off', 'banking', 'إعدام فرق تسوية', 'Write off a reconciliation difference', true, false],

            // Parties and catalog
            ['party.manage', 'parties', 'إدارة العملاء والموردين', 'Manage customers and suppliers', false, false],
            ['supplier.bank_details.update', 'parties', 'تعديل بيانات بنك المورد', 'Change a supplier\'s bank details', true, true],
            ['item.manage', 'catalog', 'إدارة الأصناف', 'Manage items', false, false],
            ['price_list.manage', 'catalog', 'إدارة قوائم الأسعار', 'Manage price lists', false, false],

            // Tax
            ['tax.manage', 'tax', 'إدارة الضرائب', 'Manage tax codes and rates', true, false],
            ['tax_return.prepare', 'tax', 'إعداد إقرار ضريبي', 'Prepare a tax return', false, false],
            ['tax_return.file', 'tax', 'تقديم إقرار ضريبي', 'File a tax return', true, false],

            // Reporting
            ['report.view', 'reporting', 'عرض التقارير', 'View reports', false, false],
            ['report.export', 'reporting', 'تصدير التقارير', 'Export reports', false, false],

            // Administration
            ['user.manage', 'identity', 'إدارة المستخدمين', 'Manage users', true, false],
            ['role.manage', 'identity', 'إدارة الأدوار والصلاحيات', 'Manage roles and permissions', true, false],
            ['entity.manage', 'tenancy', 'إدارة الكيانات', 'Manage entities', true, false],
            ['settings.manage', 'tenancy', 'إدارة الإعدادات', 'Manage settings', false, false],
            ['sod.override', 'identity', 'منح استثناء فصل مهام', 'Grant a segregation-of-duties override', true, true],
            ['audit.view', 'identity', 'عرض سجل التدقيق', 'View the audit trail', false, false],
        ];
    }
}
