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
                'invoice_then_write_off',
                'invoice.issue',
                'writeoff.record',
                'object',
                'من يُصدر الفاتورة لا يجوز أن يُعدمها؛ وإلا أمكنه إخراج المال من الشركة بنقرتين لا يترك لقارئ أثراً.',
                'Whoever issued an invoice must not be the one to write it off. A person who can do both can move money out of a business with two clicks and leave nothing for a reader to find - which makes this the pair closest in kind to the payment one. Overridable all the same: a finance function of one has nobody else to invoice, and refusing the override would leave them unable to write off any debt at all. The override is itself permissioned, time-boxed and audited, which is the right shape for that bind - and the two pairs that can never be overridden stay two, because a product that keeps adding to that list is one whose customers cannot operate.',
                true,
            ],
            [
                'reconcile_then_write_off',
                'bank.reconcile',
                'writeoff.record',
                'object',
                'من طابق الحركات على المستند هو من قرّر أنّ شيئاً منه لم يُسدَّد؛ فلا يجوز أن يكون هو من يُعدم الباقي.',
                'Whoever matched the payments against a document decided how much of it was unpaid, so they must not also be the one to conclude the rest is lost. Scoped to the document, because a person may reconcile a thousand lines and still write off an invoice they never touched.',
                true,
            ],
            [
                'match_then_write_off',
                'bank.reconcile',
                'reconciliation.write_off',
                'object',
                'من قرّر أن الدفعة لا تغطي الفاتورة لا يجوز أن يقرّر بنفسه إعدام الفرق؛ وإلا صار الفرق قراراً يتخذه شخص واحد على مستند واحد.',
                'Whoever decided a payment did not cover an invoice must not also decide the shortfall need not be chased. Scoped to the document, because the pair that matters is on one invoice: a person may reconcile a thousand lines and approve a write-off on an invoice they never touched.',
                true,
            ],
            [
                'reconcile_then_sign_off',
                'bank.reconcile',
                'suspense.sign_off',
                'period',
                'من يطابق الحركات البنكية هو من قرّر ما الذي تعذّر تفسيره؛ فلا يجوز أن يكون هو من يعتمد بقاءه معلّقاً.',
                'Whoever matched the bank lines decided which ones could not be explained. If the same person signs off that the leftovers are acceptable, the control is a formality performed by the person it exists to check - and an unexplained credit is the easiest place in an accounting system to hide a receipt somebody took. Overridable, unlike the payment pair: a finance function of one has nobody else to sign, and refusing the override would leave them unable to close a period at all - which means periods left open, backdated postings and a worse outcome than a recorded override.',
                true,
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
                'invoice_then_correct',
                'invoice.issue',
                'corrected_invoice.issue',
                'object',
                'من يُصدر الفاتورة لا يجوز أن يُصدر الفاتورة المصححة البديلة لها، فهي تستبدل الأصل بالكامل.',
                'Whoever issues an invoice must not issue the replacement that supersedes it. A corrected invoice restates the original in full, so it is the stronger instrument of the two: a credit note leaves the invoice in the record to be read, and a replacement says the original should have read differently. The debit note is deliberately NOT paired with issuing, because it increases what the customer owes - the fraud it enables is overcharging, which is a different control.',
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
