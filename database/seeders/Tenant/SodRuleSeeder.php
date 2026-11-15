<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The incompatible duty pairs - PF-033.
 *
 * Each one is a real fraud or a real error, not a theoretical control. The
 * rationale is stored alongside the rule because a refusal has to be able to
 * explain WHY, and "policy" is not an explanation anyone can act on.
 */
final class SodRuleSeeder extends Seeder
{
    public function run(): void
    {
        $connection = DB::connection('tenant');
        $now = now();
        $rows = [];

        foreach ($this->rules() as [$key, $a, $b, $scope, $ar, $en, $overridable]) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'key' => $key,
                'duty_a' => $a,
                'duty_b' => $b,
                'scope' => $scope,
                'rationale_ar' => $ar,
                'rationale_en' => $en,
                'overridable' => $overridable,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $connection->table('sod_rules')->upsert(
            $rows,
            ['key'],
            ['duty_a', 'duty_b', 'scope', 'rationale_ar', 'rationale_en', 'overridable', 'updated_at'],
        );
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:bool}> */
    private function rules(): array
    {
        return [
            [
                'beneficiary_then_release',
                'supplier.bank_details.update',
                'payment.release',
                'party',
                'من يغيّر حساب المورد البنكي لا يجوز أن يُطلق الدفعة إليه: هذه أعلى قيمة احتيال في النظام بأكمله.',
                'Whoever changes a supplier\'s bank account must not be the one to release a payment to it. This is the highest-value fraud in the entire product, and it is the one pair that can never be overridden.',
                false,
            ],
            [
                'supplier_then_release',
                'party.manage',
                'payment.release',
                'party',
                'من يُنشئ المورد لا يجوز أن يُطلق الدفعة إليه، وإلا أمكنه أن يدفع لنفسه.',
                'Whoever creates a supplier must not release a payment to it, or they can pay themselves.',
                true,
            ],
            [
                'bill_create_then_approve',
                'bill.create',
                'bill.approve',
                'object',
                'لا يعتمد أحد فاتورته التي أدخلها.',
                'Nobody approves a bill they entered.',
                true,
            ],
            [
                'prepare_then_release',
                'payment.prepare',
                'payment.release',
                'object',
                'مبدأ المُعِد والمُعتمِد على الأموال الخارجة: شخصان، لا واحد.',
                'Maker-checker on outgoing funds: two people, not one.',
                false,
            ],
            [
                'post_then_close',
                'journal.post',
                'period.close',
                'period',
                'من يُرحّل قيداً لا يجوز أن يُقفل الفترة فوقه، وإلا أمكنه إخفاؤه.',
                'Whoever posts an entry must not close the period over it, or they can hide it.',
                true,
            ],
            [
                'invoice_then_credit',
                'invoice.issue',
                'credit_note.issue',
                'object',
                'من يُصدر الفاتورة لا يجوز أن يُصدر إشعارها الدائن، وإلا أمكنه إعدام مديونية أنشأها.',
                'Whoever issues an invoice must not credit it, or they can erase a receivable they created.',
                true,
            ],
            [
                'match_then_writeoff',
                'bank.reconcile',
                'reconciliation.write_off',
                'object',
                'من يُطابق الحركة لا يجوز أن يُعدم فرقها دون مراجعة.',
                'Whoever matches a transaction must not write off its difference unreviewed.',
                true,
            ],
            [
                'claim_then_approve',
                'expense_claim.submit',
                'expense_claim.approve',
                'object',
                'لا يعتمد أحد مطالبته الخاصة.',
                'Nobody approves their own expense claim.',
                true,
            ],
            [
                'prepare_then_file_return',
                'tax_return.prepare',
                'tax_return.file',
                'object',
                'من يُعدّ الإقرار لا يجوز أن يُقدّمه وحده في المنشآت التي تسمح بالفصل.',
                'Whoever prepares a tax return should not be the one to file it, where the business is large enough to separate the two.',
                true,
            ],
        ];
    }
}
