<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Purchasing\Exceptions\BillRefused;
use App\Tenant\Purchasing\Models\Bill;
use App\Tenant\Purchasing\Services\Bills;
use App\Tenant\Purchasing\Services\DuplicateBillDetector;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Supplier bills - PF-133, PF-139.
 *
 * ## The two things this phase has that sales does not
 *
 * Documents that **arrive** rather than being composed, and the fact that
 * money leaving needs a second person. The first shows up here as three
 * dates that are not interchangeable and a supplier's own document number
 * that is theirs rather than ours; the second is PF-136.
 *
 * ## The two failures that get their own checks
 *
 * Paying the same invoice twice is the most expensive error in accounts
 * payable, and paying the right invoice to the wrong account is the most
 * dangerous. Both are checked here rather than left to a reviewer.
 *
 * ## And the reverse charge, which is the half most systems forget
 *
 * The supplier charges nothing and the recipient accounts for **both** sides.
 * A system that posts only the input side produces a return whose input box
 * is right and whose output box understates the liability by exactly that
 * amount - and because the two net to nothing in the accounts, nothing looks
 * wrong until the authority compares the return against the supplier's own
 * declaration.
 */
final class BillRules
{
    use \Tests\Support\Concerns\EntersBills;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $static = ['a supplier number is normalised for comparison' => $this->normalisation()];

        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return [...$static, 'bills (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedThePayableSide();

            return [
                ...$static,
                ...$this->withTheLedgerProof($this->runEachInASavepoint([
                    'a bill is entered with its lines and its tax grouped' => fn (): array => $this->entry(),
                    'the three dates are kept apart' => fn (): array => $this->threeDates(),
                    'a tax point after the posting date is refused' => fn (): array => $this->taxPointAfterPosting(),
                    'the due date counts from the supplier\'s date' => fn (): array => $this->dueDate(),
                    'posting debits the expense and credits the payable' => fn (): array => $this->posting(),
                    'the reverse charge posts an input AND an output' => fn (): array => $this->reverseCharge(),
                    'reverse-charge tax is not owed to the supplier' => fn (): array => $this->reverseChargeIsNotPayable(),
                    'non-recoverable tax posts to expense, not to the input account' => fn (): array => $this->nonRecoverable(),
                    'the same supplier number twice is refused' => fn (): array => $this->hardDuplicate(),
                    'a punctuated number is the same number' => fn (): array => $this->normalisedDuplicate(),
                    'the same amount and date under another number warns' => fn (): array => $this->nearMatch(),
                    'a nearly-equal amount in the window warns' => fn (): array => $this->similarAmount(),
                    'the same number against another supplier warns' => fn (): array => $this->wrongSupplier(),
                    'a warning is not raised twice for one pair' => fn (): array => $this->warningIsIdempotent(),
                    'an unanswered warning blocks posting' => fn (): array => $this->unansweredBlocksPosting(),
                    'an acknowledgement needs a reason and is recorded' => fn (): array => $this->acknowledgement(),
                    'detection runs again at payment time' => fn (): array => $this->atPaymentTime(),
                    'another entity cannot see these bills' => fn (): array => $this->entityScope(),
                    'and the ledger still proves its own invariants' => fn (): array => [],
                ]), $this->entityId),
            ];
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return list<string> */
    private function normalisation(): array
    {
        $violations = [];

        $same = ['INV-001', 'inv 001', 'INV001', 'inv/001', ' INV-001 '];
        $keys = array_unique(array_map(
            static fn (string $n): string => Bill::normaliseDocumentNumber($n),
            $same,
        ));

        if (\count($keys) !== 1) {
            $violations[] = sprintf(
                'these are one document to the supplier and normalise to %d keys: %s. A constraint on '.
                'the raw string catches only the lazy half of the problem',
                \count($keys),
                implode(', ', $keys),
            );
        }

        /*
         | Leading zeros are KEPT.
         |
         | INV-001 and INV-1 are frequently different documents from the same
         | supplier, and collapsing them would refuse a real invoice. A false
         | refusal is worse than a near-match warning: the warning asks a
         | person and the refusal does not.
         */
        if (Bill::normaliseDocumentNumber('INV-001') === Bill::normaliseDocumentNumber('INV-1')) {
            $violations[] = 'INV-001 and INV-1 normalise to the same key, so a supplier who uses both '.
                'cannot send the second one';
        }

        if (Bill::normaliseDocumentNumber(null) !== '') {
            $violations[] = 'a null number does not normalise to empty, so the partial unique index '.
                'would catch drafts that have not been numbered yet';
        }

        return $violations;
    }

    /** @return list<string> */
    private function entry(): array
    {
        $violations = [];
        $bill = $this->enterBill();

        if ((string) $bill->status !== 'draft') {
            $violations[] = sprintf('a new bill is %s', (string) $bill->status);
        }

        if ((string) $bill->net_amount !== '1000.0000' || (string) $bill->tax_amount !== '150.0000') {
            $violations[] = sprintf(
                'the totals are %s net and %s tax',
                (string) $bill->net_amount,
                (string) $bill->tax_amount,
            );
        }

        if ((string) $bill->gross_amount !== '1150.0000') {
            $violations[] = sprintf('the gross is %s', (string) $bill->gross_amount);
        }

        if ((string) $bill->recoverable_tax_amount !== '150.0000') {
            $violations[] = 'the recoverable tax is not the whole tax on a fully recoverable bill';
        }

        if ($bill->lines()->count() !== 1) {
            $violations[] = 'the line was not written';
        }

        $groups = $bill->taxBreakdown()->get();

        if ($groups->count() !== 1) {
            $violations[] = sprintf('%d rate groups exist for one rate', $groups->count());
        } elseif ((string) $groups->first()->taxable_amount !== '1000.0000') {
            $violations[] = 'the rate group does not carry the taxable amount';
        }

        return $violations;
    }

    /** @return list<string> */
    private function threeDates(): array
    {
        $violations = [];

        /*
         | A bill dated the 28th of March, with a March tax point, posted in
         | April. The supplier's March, the return's March, and our April.
         */
        $bill = $this->enterBill(
            documentDate: $this->date->copy()->setDate(2026, 3, 28),
            taxPoint: $this->date->copy()->setDate(2026, 3, 28),
            postingDate: $this->date->copy(),
        );

        $expected = [
            'supplier_document_date' => '2026-03-28',
            'tax_point' => '2026-03-28',
            'posting_date' => $this->date->toDateString(),
        ];

        foreach ($expected as $column => $value) {
            if ($bill->{$column}->toDateString() !== $value) {
                $violations[] = sprintf(
                    '%s is %s and should be %s. Collapsing the three dates gets two of them wrong: the '.
                    'supplier chases on theirs, the return is filed on the tax point, and the period '.
                    'is ours',
                    $column,
                    $bill->{$column}->toDateString(),
                    $value,
                );
            }
        }

        /*
         | And no accessor blurs them.
         |
         | A `date()` method would be read by whoever came next as "the date
         | of the bill", and the three answer different questions.
         */
        if (method_exists($bill, 'date')) {
            $violations[] = 'Bill has a date() accessor, which is the shape in which three dates become one';
        }

        return $violations;
    }

    /** @return list<string> */
    private function taxPointAfterPosting(): array
    {
        try {
            $this->enterBill(
                taxPoint: $this->date->copy()->addMonth(),
                postingDate: $this->date->copy(),
            );
        } catch (BillRefused $e) {
            return str_contains($e->getMessage(), 'has not opened')
                ? []
                : ['the refusal does not say why: '.$e->getMessage()];
        }

        return ['a bill whose tax point is after its posting date was accepted, which claims input tax '.
            'in a return that has not opened'];
    }

    /** @return list<string> */
    private function dueDate(): array
    {
        $bill = $this->enterBill(documentDate: $this->date->copy()->subDays(10));
        $snapshot = (array) ($bill->terms_snapshot ?? []);

        if ($snapshot === []) {
            return ['the payment terms were not frozen, so the due date would move when the supplier\'s '.
                'terms change'];
        }

        /*
         | Counted from the SUPPLIER's date.
         |
         | "Thirty days" means thirty days from their invoice date, which is
         | what they will chase on. Counting from our posting date would
         | quietly extend every bill that arrived late, and the supplier
         | would be right and we would be wrong.
         */
        return (string) ($snapshot['counted_from'] ?? '') === $bill->supplier_document_date->toDateString()
            ? []
            : [sprintf(
                'the terms were counted from %s and the supplier dated the bill %s',
                (string) ($snapshot['counted_from'] ?? 'nothing'),
                $bill->supplier_document_date->toDateString(),
            )];
    }

    /** @return list<string> */
    private function posting(): array
    {
        $violations = [];
        $bill = $this->post($this->enterBill());

        $lines = $this->journalLinesOf($bill);

        if ($lines === []) {
            return ['the bill posted no journal lines'];
        }

        $payable = $this->amountOnRole($lines, 'accounts_payable');
        $input = $this->amountOnRole($lines, 'vat_input');
        $expense = $this->amountOnAccount($lines, $this->expenseAccountId);

        if (! BigDecimal::of($payable)->isEqualTo('-1150.0000')) {
            $violations[] = sprintf('the payable is credited %s and the supplier is owed 1,150', $payable);
        }

        if (! BigDecimal::of($input)->isEqualTo('150.0000')) {
            $violations[] = sprintf('the input tax is %s', $input);
        }

        if (! BigDecimal::of($expense)->isEqualTo('1000.0000')) {
            $violations[] = sprintf('the expense is %s', $expense);
        }

        if ((string) $bill->status !== 'posted' || $bill->journal_entry_id === null) {
            $violations[] = 'the bill does not record the entry it posted, so the posting cannot be '.
                'traced back from the document';
        }

        return $violations;
    }

    /** @return list<string> */
    private function reverseCharge(): array
    {
        $violations = [];
        $bill = $this->post($this->enterBill(lines: [$this->reverseChargeLine()]));

        $lines = $this->journalLinesOf($bill);

        $input = $this->amountOnRole($lines, 'vat_input');
        $output = $this->amountOnRole($lines, 'vat_output');

        if (! BigDecimal::of($input)->isEqualTo('150.0000')) {
            $violations[] = sprintf('the reverse charge debited %s of input tax and the tax is 150', $input);
        }

        if (! BigDecimal::of($output)->isEqualTo('-150.0000')) {
            $violations[] = sprintf(
                'the reverse charge credited %s of OUTPUT tax. This is the half most systems forget: '.
                'the recipient owes the tax as well as being entitled to claim it, and a system that '.
                'posts only the input side files a return whose output box understates the liability by '.
                'exactly this amount - and the two net to nothing in the accounts, so nothing looks '.
                'wrong until the authority compares the return against the supplier\'s declaration',
                $output,
            );
        }

        /*
         | Two rows, not one netted to zero.
         |
         | They are different boxes on the return. Netting them would
         | balance and would make the return's two sides indistinguishable.
         */
        $taxRows = array_values(array_filter(
            $lines,
            static fn (array $l): bool => \in_array((string) ($l['role'] ?? ''), ['vat_input', 'vat_output'], true),
        ));

        if (\count($taxRows) !== 2) {
            $violations[] = sprintf('%d tax rows were posted and the reverse charge needs two', \count($taxRows));
        }

        return $violations;
    }

    /** @return list<string> */
    private function reverseChargeIsNotPayable(): array
    {
        $bill = $this->enterBill(lines: [$this->reverseChargeLine()]);

        if ((string) $bill->payable_amount !== '1000.0000') {
            return [sprintf(
                'the supplier is owed %s on a reverse-charge bill of 1,000 net. They charged no tax: '.
                'paying them the gross overpays by the tax and leaves the authority\'s side unpaid',
                (string) $bill->payable_amount,
            )];
        }

        $posted = $this->post($bill);
        $payable = $this->amountOnRole($this->journalLinesOf($posted), 'accounts_payable');

        return BigDecimal::of($payable)->isEqualTo('-1000.0000')
            ? []
            : [sprintf('the payable was credited %s and the supplier is owed 1,000', $payable)];
    }

    /** @return list<string> */
    private function nonRecoverable(): array
    {
        $violations = [];
        $bill = $this->enterBill(lines: [$this->partlyRecoverableLine('50')]);

        if ((string) $bill->recoverable_tax_amount !== '75.0000') {
            $violations[] = sprintf('half of 150 is 75 and the bill says %s', (string) $bill->recoverable_tax_amount);
        }

        if ((string) $bill->non_recoverable_tax_amount !== '75.0000') {
            $violations[] = sprintf('the non-recoverable half is %s', (string) $bill->non_recoverable_tax_amount);
        }

        $lines = $this->journalLinesOf($this->post($bill));

        $input = $this->amountOnRole($lines, 'vat_input');
        $expense = $this->amountOnAccount($lines, $this->expenseAccountId);

        if (! BigDecimal::of($input)->isEqualTo('75.0000')) {
            $violations[] = sprintf(
                'the input account was debited %s. Tax that will never be claimed sitting in the input '.
                'account will not tie to any box on any form, and the difference is found during an '.
                'audit by somebody who did not put it there',
                $input,
            );
        }

        /*
         | The non-recoverable half rides with the expense.
         |
         | 1,000 of cost plus 75 of tax that cannot be claimed is 1,075 of
         | cost, and it belongs to the cost centre that bought it.
         */
        if (! BigDecimal::of($expense)->isEqualTo('1075.0000')) {
            $violations[] = sprintf(
                'the expense is %s and should be 1,075: the cost of a purchase includes the tax that '.
                'cannot be recovered',
                $expense,
            );
        }

        return $violations;
    }

    /** @return list<string> */
    private function hardDuplicate(): array
    {
        $this->enterBill(number: 'GC-4471');

        try {
            $this->enterBill(number: 'GC-4471');
        } catch (BillRefused $e) {
            return str_contains($e->getMessage(), 'already recorded')
                ? []
                : ['the refusal does not say the bill is already recorded: '.$e->getMessage()];
        }

        return ['the same supplier number was accepted twice. Paying the same invoice twice is the most '.
            'expensive error in accounts payable and is usually found by the supplier, after the money '.
            'has gone'];
    }

    /** @return list<string> */
    private function normalisedDuplicate(): array
    {
        $this->enterBill(number: 'GC-4471');

        try {
            $this->enterBill(number: 'gc 4471');
        } catch (BillRefused) {
            return [];
        }

        return ['"gc 4471" was accepted beside "GC-4471". They are one document to the supplier, and a '.
            'constraint on the raw string catches only the lazy half of the problem'];
    }

    /** @return list<string> */
    private function nearMatch(): array
    {
        $violations = [];
        $first = $this->enterBill(number: 'GC-9001');
        $second = $this->enterBill(number: 'GC-9002');

        $warnings = $second->duplicateWarnings()->get();

        if ($warnings->isEmpty()) {
            return ['the same supplier, the same date and the same total under a different number '.
                'raised no warning. That is the shape a re-issued invoice takes, and no constraint can '.
                'refuse it because sometimes they are two genuine purchases'];
        }

        $reasons = $warnings->pluck('reason')->all();

        if (! \in_array('same_amount_and_date', $reasons, true)) {
            $violations[] = sprintf('the warning reason is %s', implode(', ', $reasons));
        }

        $detail = (string) $warnings->first()->detail;

        foreach ([(string) $first->supplier_document_number, '1150.00'] as $needle) {
            if (! str_contains($detail, $needle)) {
                $violations[] = sprintf(
                    'the warning does not name "%s": "%s". A generic hold tells the reviewer to go and '.
                    'look; naming the figures lets them decide',
                    $needle,
                    $detail,
                );
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function similarAmount(): array
    {
        $this->enterBill(number: 'GC-7001');

        /*
         | Nine days EARLIER, not later.
         |
         | Measured: dating it later moved the tax point past the posting
         | date, which the entry correctly refused - a supply cannot be
         | taxed into a return that has not opened. The near-match window
         | looks both ways, so the check works either direction and this one
         | does not have to fight a rule that is right.
         */
        $second = $this->enterBill(
            number: 'GC-7002',
            documentDate: $this->date->copy()->subDays(9),
            lines: [[
                'description' => 'Machined brackets, batch 41',
                'quantity' => '100',
                'unit_price' => '10',
                'net_amount' => '1004.0000',
                'expense_account_id' => $this->expenseAccountId,
                'tax_code_id' => $this->taxCodeId,
                'tax_category' => 'S',
                'tax_rate' => '15.000000',
                'tax_amount' => '150.6000',
            ]],
        );

        $reasons = $second->duplicateWarnings()->pluck('reason')->all();

        return \in_array('similar_amount_in_window', $reasons, true)
            ? []
            : [sprintf(
                'a bill within 1%% of another from the same supplier nine days apart raised %s. A '.
                're-issued invoice with a corrected rounding looks exactly like this',
                $reasons === [] ? 'nothing' : implode(', ', $reasons),
            )];
    }

    /** @return list<string> */
    private function wrongSupplier(): array
    {
        $violations = [];
        $this->enterBill(number: 'SHARED-55');

        $other = \App\Tenant\Parties\Models\Party::create([
            'code' => 'SUP-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'legal_name' => 'A Different Supplier LLC',
            'is_supplier' => true,
            'country' => 'SA',
            'default_currency' => 'SAR',
            'active' => true,
        ]);

        $second = $this->enterBill(number: 'SHARED-55', partyId: (string) $other->getKey());

        $warnings = $second->duplicateWarnings()->get();
        $reasons = $warnings->pluck('reason')->all();

        if (! \in_array('same_number_other_supplier', $reasons, true)) {
            return ['the same document number against a different supplier raised no warning. The '.
                'unique constraint cannot see it - numbers are unique per supplier - and it is the '.
                'error that is hardest to get back, because the money has gone somewhere it was never '.
                'owed'];
        }

        $detail = (string) $warnings->firstWhere('reason', 'same_number_other_supplier')->detail;

        if (! str_contains($detail, 'wrong supplier')) {
            $violations[] = 'the warning does not say what the risk is: '.$detail;
        }

        return $violations;
    }

    /** @return list<string> */
    private function warningIsIdempotent(): array
    {
        $this->enterBill(number: 'GC-8001');
        $second = $this->enterBill(number: 'GC-8002');

        $before = $second->duplicateWarnings()->count();

        app(DuplicateBillDetector::class)->examine($second->refresh(), 'payment');
        app(DuplicateBillDetector::class)->examine($second->refresh(), 'payment');

        $after = $second->refresh()->duplicateWarnings()->count();

        return $before === $after
            ? []
            : [sprintf(
                'the warnings went from %d to %d on re-examination. The detector runs at entry and '.
                'again at payment, and a second warning for a pair somebody already acknowledged '.
                'would need the acknowledgement given again every time anybody looked',
                $before,
                $after,
            )];
    }

    /** @return list<string> */
    private function unansweredBlocksPosting(): array
    {
        $this->enterBill(number: 'GC-6001');
        $second = $this->enterBill(number: 'GC-6002');

        if ($second->duplicateWarnings()->whereNull('acknowledged_at')->count() === 0) {
            return ['the fixture raised no warning, so this check proves nothing'];
        }

        try {
            app(Bills::class)->post($second, $this->entity(), $this->actorId());
        } catch (BillRefused $e) {
            return str_contains($e->getMessage(), 'question for a person')
                ? []
                : ['the refusal does not say why it needs a person: '.$e->getMessage()];
        }

        return ['a bill with an unanswered duplicate warning was posted. Either it is the same invoice, '.
            'in which case paying it twice is the error this exists to prevent, or it is not, in which '.
            'case somebody should say so and be answerable for it'];
    }

    /** @return list<string> */
    private function acknowledgement(): array
    {
        $violations = [];
        $this->enterBill(number: 'GC-5001');
        $second = $this->enterBill(number: 'GC-5002');

        $warning = $second->duplicateWarnings()->first();

        if ($warning === null) {
            return ['the fixture raised no warning'];
        }

        $detector = app(DuplicateBillDetector::class);

        try {
            $detector->acknowledge($warning, $this->actorId(), 'no');
            $violations[] = '"no" was accepted as an acknowledgement. That is what somebody clicks at '.
                'four in the afternoon, and whoever defends the payment six months later needs to know '.
                'what was actually checked';
        } catch (\InvalidArgumentException) {
            // As intended.
        }

        $acknowledged = $detector->acknowledge(
            $warning,
            $this->actorId(),
            'Two deliveries on the same day against separate dockets 4471 and 4472; both goods received '.
            'notes checked against the warehouse log.',
        );

        if (! $acknowledged->isAcknowledged()) {
            $violations[] = 'the acknowledgement was not recorded';
        }

        if ((string) $acknowledged->acknowledged_by !== $this->actorId()) {
            $violations[] = 'the acknowledgement does not record who gave it';
        }

        // And now it posts.
        try {
            $this->post($second->refresh());
        } catch (BillRefused $e) {
            $violations[] = 'the bill still cannot post after the warning was answered: '.$e->getMessage();
        }

        return $violations;
    }

    /** @return list<string> */
    private function atPaymentTime(): array
    {
        $violations = [];

        /*
         | The second copy arrives after the first was posted.
         |
         | Which is the usual way it happens: by payment time nobody is
         | looking at the entry screen, and the detector has to run again.
         */
        $first = $this->post($this->enterBill(number: 'GC-3001'));
        $second = $this->enterBill(number: 'GC-3002');

        $atEntry = $second->duplicateWarnings()->count();

        if ($atEntry === 0) {
            $violations[] = 'nothing was found at entry';
        }

        $found = app(DuplicateBillDetector::class)->examine($second->refresh(), 'payment');

        if ($found === []) {
            $violations[] = 'the payment-time examination found nothing';
        }

        $queue = app(DuplicateBillDetector::class)->queue($this->entityId);

        if ($queue === []) {
            $violations[] = 'the exception queue is empty while a warning is unanswered';
        } else {
            $row = $queue[0];

            foreach (['reason', 'detail', 'number', 'suspected_number'] as $key) {
                if (! isset($row[$key])) {
                    $violations[] = sprintf('the queue row has no "%s", so a reviewer cannot compare the two side by side', $key);
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function entityScope(): array
    {
        $this->enterBill();

        $other = (string) \Illuminate\Support\Str::ulid();
        DB::connection('tenant')->statement("SELECT set_config('peakflow.entity_id', ?, false)", [$other]);

        $visible = DB::connection('tenant')->table('bills')->count();

        DB::connection('tenant')->statement("SELECT set_config('peakflow.entity_id', ?, false)", [$this->entityId]);

        return $visible === 0
            ? []
            : [sprintf('another entity sees %d of this one\'s bills', $visible)];
    }

    private function post(Bill $bill): Bill
    {
        return app(Bills::class)->post($bill, $this->entity(), $this->actorId());
    }

    /** @return list<array<string, mixed>> */
    private function journalLinesOf(Bill $bill): array
    {
        $rows = DB::connection('tenant')->table('journal_lines as l')
            ->leftJoin('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('account_roles as r', 'r.account_id', '=', 'a.id')
            ->where('l.journal_entry_id', (string) $bill->journal_entry_id)
            ->select(['l.account_id', 'l.amount', 'r.role'])
            ->get();

        return $rows->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @param list<array<string, mixed>> $lines */
    private function amountOnRole(array $lines, string $role): string
    {
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ((string) ($line['role'] ?? '') === $role) {
                $total = $total->plus((string) $line['amount']);
            }
        }

        return (string) $total->toScale(4);
    }

    /** @param list<array<string, mixed>> $lines */
    private function amountOnAccount(array $lines, string $accountId): string
    {
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ((string) ($line['account_id'] ?? '') === $accountId) {
                $total = $total->plus((string) $line['amount']);
            }
        }

        return (string) $total->toScale(4);
    }
}
