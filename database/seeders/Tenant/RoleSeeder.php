<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The seeded roles.
 *
 * Each is a defensible real-world job rather than a permission bundle invented
 * to fill a tier. The two that matter structurally:
 *
 *   ap_clerk    can CREATE a bill and PREPARE a payment run, and can do
 *               neither of the things those must be separated from. The SoD
 *               rules still apply on top - a role is not a substitute for
 *               them, because a role cannot know who did the first act.
 *
 *   auditor     read-only, including the audit trail. An auditor who has to
 *               ask someone to run a report for them is an auditor who is
 *               being managed rather than auditing.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $connection = DB::connection('tenant');
        $now = now();

        $permissionIds = $connection->table('permissions')->pluck('id', 'key');

        foreach ($this->roles() as $key => [$ar, $en, $permissions]) {
            $roleId = $connection->table('roles')->where('key', $key)->value('id');

            if ($roleId === null) {
                $roleId = (string) Str::ulid();

                $connection->table('roles')->insert([
                    'id' => $roleId,
                    'key' => $key,
                    'name_ar' => $ar,
                    'name_en' => $en,
                    'system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $keys = $permissions === ['*'] ? $permissionIds->keys()->all() : $permissions;

            $rows = [];
            foreach ($keys as $permissionKey) {
                $permissionId = $permissionIds[$permissionKey] ?? null;

                if ($permissionId === null) {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                $connection->table('role_permissions')->upsert($rows, ['role_id', 'permission_id'], ['updated_at']);
            }
        }
    }

    /** @return array<string, array{0:string,1:string,2:list<string>}> */
    private function roles(): array
    {
        return [
            'owner' => ['المالك', 'Owner', ['*']],

            'controller' => ['المدير المالي', 'Controller', [
                'journal.draft', 'journal.post', 'journal.reverse', 'account.manage',
                'dimension.manage', 'period.close', 'period.post_adjusting',
                'invoice.draft', 'invoice.issue', 'credit_note.issue', 'quote.manage', 'dunning.manage',
                'bill.create', 'bill.approve', 'purchase_order.manage', 'expense_claim.approve',
                'payment.record', 'payment.allocate', 'payment.prepare', 'payment.release', 'writeoff.record',
                'bank.manage', 'bank.import', 'bank.reconcile', 'reconciliation.write_off',
                'party.manage', 'item.manage', 'price_list.manage',
                'tax.manage', 'tax_return.prepare', 'tax_return.file',
                'report.view', 'report.export', 'settings.manage', 'audit.view',
            ]],

            'accountant' => ['المحاسب', 'Accountant', [
                'journal.draft', 'journal.post', 'account.manage', 'dimension.manage',
                'invoice.draft', 'invoice.issue', 'credit_note.issue', 'quote.manage',
                'bill.create', 'purchase_order.manage',
                'payment.record', 'payment.allocate',
                'bank.import', 'bank.reconcile',
                'party.manage', 'item.manage', 'price_list.manage',
                'tax_return.prepare', 'report.view', 'report.export',
            ]],

            'ap_clerk' => ['موظف المدفوعات', 'Accounts payable clerk', [
                'bill.create', 'purchase_order.manage', 'expense_claim.submit',
                'payment.prepare', 'party.manage', 'report.view',
            ]],

            'ar_clerk' => ['موظف المقبوضات', 'Accounts receivable clerk', [
                'invoice.draft', 'invoice.issue', 'quote.manage',
                'payment.record', 'payment.allocate', 'party.manage', 'report.view',
            ]],

            'auditor' => ['المدقق', 'Auditor', [
                'report.view', 'report.export', 'audit.view',
            ]],
        ];
    }
}
