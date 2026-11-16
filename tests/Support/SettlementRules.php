<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Money\Money;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Settlement\Enums\PaymentDirection;
use App\Tenant\Settlement\Enums\PaymentMethod;
use App\Tenant\Settlement\Services\Allocations;
use App\Tenant\Settlement\Services\Payments;
use App\Tenant\Settlement\Services\Refunds;
use App\Tenant\Settlement\Services\UnappliedCash;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payments, unapplied cash and allocations - PF-166, PF-167, PF-168.
 *
 * ## The decision this whole module rests on
 *
 * A payment never touches a party balance. "They sent 10,000" and "10,000
 * of their invoices are settled" are different facts, and they become one
 * fact when somebody says which invoices.
 *
 * Reducing the balance on receipt gives the right total and a customer
 * ledger nobody can take apart: 4,000 outstanding that is three invoices
 * and a receipt, with nothing saying which invoice the receipt was for.
 * Every question about it afterwards is a reconstruction, and the
 * reconstruction happens in a spreadsheet beside the accounting system.
 *
 * ## And the invariant that keeps the subledger honest
 *
 * An allocation exceeds neither the payment's unallocated amount nor the
 * document's open balance. Break the first and cash applies twice: two
 * invoices report settled by one payment and only the bank reconciliation
 * notices. Break the second and a customer owes a negative figure, which
 * is a credit nobody granted.
 *
 * Both are triggers rather than service checks, and the property check at
 * the end is the one that matters most: for any sequence of allocations
 * and reversals, the open balance and the control account still agree.
 */
final class SettlementRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['settlement (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();
            $this->postTheOpeningEntry();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- Payments - PF-166 ---------------------------------
                'a receipt posts to cash and to a clearing position' => fn (): array => $this->receiptPosts(),
                'a receipt does not move what the customer owes' => fn (): array => $this->receiptLeavesTheBalance(),
                'a disbursement posts the other way' => fn (): array => $this->disbursementPosts(),
                'a payment in another currency is refused, not converted' => fn (): array => $this->currencyMismatch(),
                'a cash method against a bank account is refused' => fn (): array => $this->methodAndAccountAgree(),
                'a payment names exactly one account' => fn (): array => $this->exactlyOneAccount(),
                'a posted payment cannot be edited' => fn (): array => $this->immutable(),
                'a payment is corrected by reversal' => fn (): array => $this->reversal(),
                'reversing an allocated payment is refused' => fn (): array => $this->reversingAllocated(),

                // ---- Unapplied cash - PF-167 ---------------------------
                'unallocated cash is a liability, not an error' => fn (): array => $this->unappliedIsALiability(),
                'unapplied cash ages from the value date' => fn (): array => $this->unappliedAges(),
                'the receipts account for the control balance' => fn (): array => $this->unappliedReconciles(),
                'a receipt with no payer is reported as such' => fn (): array => $this->unidentifiedPayer(),
                'the customer statement shows what they sent' => fn (): array => $this->customerStatement(),

                // ---- Allocations - PF-168 ------------------------------
                'one payment settles several documents' => fn (): array => $this->partialAcrossDocuments(),
                'allocating moves what the customer owes' => fn (): array => $this->allocationMovesTheBalance(),
                'the database refuses more than the payment' => fn (): array => $this->overAllocatingThePayment(),
                'the database refuses more than the document' => fn (): array => $this->overAllocatingTheDocument(),
                'an allocation is in the payment\'s currency' => fn (): array => $this->allocationCurrency(),
                'a reversed payment cannot be allocated' => fn (): array => $this->allocatingAReversedPayment(),
                'reversal is a posting, and re-allocation is ordinary' => fn (): array => $this->reverseThenReallocate(),
                'a strategy proposes and writes nothing' => fn (): array => $this->strategyProposes(),
                'oldest first takes the oldest' => fn (): array => $this->oldestFirst(),
                'a quoted reference beats the oldest' => fn (): array => $this->byReference(),
                'a strategy says why it chose each document' => fn (): array => $this->strategyExplainsItself(),

                // ---- Over-payment, credits and refunds - PF-169 --------
                'an overpayment becomes a credit they can use' => fn (): array => $this->overpaymentIsACredit(),
                'a refund is a document with a reason and an approver' => fn (): array => $this->refundIsADocument(),
                'a refund is not a negative receipt' => fn (): array => $this->refundIsNotANegativeReceipt(),
                'refunding more than the credit is refused' => fn (): array => $this->refundingTooMuch(),
                'a refund needs a reason' => fn (): array => $this->refundNeedsAReason(),
                'a credit shows as a credit, not netted away' => fn (): array => $this->creditIsVisible(),

                // ---- The property that matters -------------------------
                'for any sequence, the balance and the control agree' => fn (): array => $this->theProperty(),
            ]));
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- Payments ---------------------------------------------------------

    /** @return list<string> */
    private function receiptPosts(): array
    {
        $invoice = $this->issue();
        $result = $this->receipt('1000.0000', (string) $invoice->party_id);

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $result['journal_entry_id'])
            ->get();

        $failures = [];

        $onBank = $lines->firstWhere('account_id', $this->bankGlAccountId);
        $onClearing = $lines->firstWhere('account_id', $this->accountFor(ControlRole::UnappliedCash));

        if ($onBank === null) {
            $failures[] = 'a receipt did not reach the bank account, so the money arrived nowhere';
        } elseif (! BigDecimal::of((string) $onBank->functional_amount)->isEqualTo('1000.0000')) {
            $failures[] = sprintf('the bank account moved by %s rather than 1000', (string) $onBank->functional_amount);
        }

        if ($onClearing === null) {
            return [...$failures, 'nothing was posted to unapplied cash, so the other side went somewhere unnamed'];
        }

        /*
         | A liability: the credit side.
         |
         | Money a customer sent that nothing has claimed is money held for
         | them. Posting it to revenue would report performance for a sale
         | that may not exist.
         */
        if (! BigDecimal::of((string) $onClearing->functional_amount)->isEqualTo('-1000.0000')) {
            $failures[] = sprintf(
                'unapplied cash moved by %s, so a receipt is not being held as a liability',
                (string) $onClearing->functional_amount,
            );
        }

        if ($onClearing->party_id === null) {
            $failures[] = 'the clearing line carries no party, so "whose unallocated money is this" has no answer';
        }

        return $failures;
    }

    /** @return list<string> */
    private function receiptLeavesTheBalance(): array
    {
        $invoice = $this->issue();
        $before = app(Allocations::class)->openBalance('invoice', (string) $invoice->getKey(), 'SAR');

        $this->receipt((string) $invoice->gross_amount, (string) $invoice->party_id);

        $after = app(Allocations::class)->openBalance('invoice', (string) $invoice->getKey(), 'SAR');

        /*
         | The whole design, in one assertion.
         |
         | What a customer owes changes when somebody allocates the money
         | to a document, not when it arrives. A balance that moved on
         | receipt would be right in total and impossible to take apart:
         | nothing would say which invoice the receipt was for.
         */
        if (! $before->equals($after)) {
            return [sprintf(
                'receiving money changed the invoice open balance from %s to %s, so the customer '.
                'ledger moved before anybody said which invoice the money was for',
                (string) $before->amount,
                (string) $after->amount,
            )];
        }

        $receivable = $this->balanceOf(ControlRole::AccountsReceivable);

        return $receivable->isZero()
            ? ['the receivable control is zero, so this check is not reading what it thinks it is']
            : [];
    }

    /** @return list<string> */
    private function disbursementPosts(): array
    {
        $result = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Disbursement,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('640.0000', 'SAR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $this->bank()->getKey(),
        );

        $onBank = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $result['journal_entry_id'])
            ->where('account_id', $this->bankGlAccountId)
            ->value('functional_amount');

        return BigDecimal::of((string) $onBank)->isEqualTo('-640.0000')
            ? []
            : [sprintf(
                'money going out moved the bank account by %s rather than -640, so the direction is '.
                'being read from somewhere other than the payment',
                (string) $onBank,
            )];
    }

    /** @return list<string> */
    private function currencyMismatch(): array
    {
        $refusal = $this->refusal(fn () => app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('500.0000', 'EUR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $this->bank()->getKey(),
        ));

        if ($refusal === null) {
            return ['a euro payment was recorded against a riyal account, which means a rate was picked and nothing says which'];
        }

        /*
         | The refusal names both currencies and says what to do.
         |
         | "Currency mismatch" leaves somebody guessing which side is
         | wrong, and the useful answer - ask the bank what they actually
         | credited - is the one that resolves it.
         */
        return str_contains($refusal, 'picking a rate')
            ? []
            : [sprintf('the refusal does not say why converting it would be worse: %s', $refusal)];
    }

    /** @return list<string> */
    private function methodAndAccountAgree(): array
    {
        $refusal = $this->refusal(fn () => app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::InCash,
            amount: Money::of('100.0000', 'SAR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $this->bank()->getKey(),
        ));

        return $refusal !== null
            ? []
            : ['cash was recorded against a bank account, which puts a line on a reconciliation no statement will carry'];
    }

    /** @return list<string> */
    private function exactlyOneAccount(): array
    {
        $neither = $this->refusal(fn () => app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('100.0000', 'SAR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
        ));

        $both = $this->refusal(fn () => app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('100.0000', 'SAR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $this->bank()->getKey(),
            cashAccountId: $this->bankGlAccountId,
        ));

        $failures = [];

        if ($neither === null) {
            $failures[] = 'a payment was recorded against no account at all, so the money moved nowhere';
        }

        if ($both === null) {
            $failures[] = 'a payment named a bank account and a cash account, so it cannot be traced to either';
        }

        return $failures;
    }

    /** @return list<string> */
    private function immutable(): array
    {
        $result = $this->receipt('400.0000', null);

        /*
         | Changed straight in the table, past every service.
         |
         | "We received 400 on the 18th and it turned out to be 40" is a
         | fact about the 18th, and editing the row deletes it. An import
         | or a correction script reaches this table without passing a
         | service.
         */
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('payments')
            ->where('id', (string) $result['id'])
            ->update(['amount' => '40.0000']));

        if ($refusal === null) {
            return ['a posted payment was edited, so what happened on the day it was received is gone'];
        }

        return str_contains($refusal, 'Reverse it')
            ? []
            : [sprintf('the refusal does not say how to correct it: %s', $refusal)];
    }

    /** @return list<string> */
    private function reversal(): array
    {
        $result = $this->receipt('300.0000', null);

        $before = $this->balanceOf(ControlRole::UnappliedCash);

        $reversed = app(Payments::class)->reverse(
            (string) $result['id'],
            $this->actorId(),
            'the cheque was returned unpaid by the issuing bank',
            $this->date->copy(),
        );

        $failures = [];

        if (($reversed['reversing_entry_id'] ?? null) === $result['journal_entry_id']) {
            $failures[] = 'the reversal reused the original entry, so what was recorded that day is gone';
        }

        $row = DB::connection('tenant')->table('payments')->where('id', (string) $result['id'])->first();

        if ($row === null) {
            $failures[] = 'the payment was deleted rather than reversed';
        } elseif ($row->reversed_at === null) {
            $failures[] = 'the payment is not marked as reversed';
        }

        $after = $this->balanceOf(ControlRole::UnappliedCash);

        if ($before->equals($after)) {
            $failures[] = 'the clearing position did not move back, so the reversal posted nothing';
        }

        return $failures;
    }

    /** @return list<string> */
    private function reversingAllocated(): array
    {
        $invoice = $this->issue();
        $result = $this->receipt('100.0000', (string) $invoice->party_id);

        app(Allocations::class)->allocate(
            (string) $result['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of('100.0000', 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $refusal = $this->refusal(fn () => app(Payments::class)->reverse(
            (string) $result['id'],
            $this->actorId(),
            'reversing cash that documents are relying on',
            $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['cash was reversed under a live allocation, leaving an invoice settled by money that was never received'];
        }

        return str_contains($refusal, 'undo the allocations first')
            ? []
            : [sprintf('the refusal does not say what to do instead: %s', $refusal)];
    }

    // ---- Unapplied cash ---------------------------------------------------

    /** @return list<string> */
    private function unappliedIsALiability(): array
    {
        $this->receipt('750.0000', null);

        $balance = app(UnappliedCash::class)->balance($this->entityId, 'SAR');

        /*
         | A credit balance, which is what a liability is.
         |
         | Not an error state and not a holding pen: money a customer sent
         | that nothing has claimed is money being held for them.
         */
        return $balance->isNegative()
            ? []
            : [sprintf(
                'unapplied cash holds %s, which is not a credit - so an unallocated receipt is '.
                'being treated as something other than money owed back',
                (string) $balance->amount,
            )];
    }

    /** @return list<string> */
    private function unappliedAges(): array
    {
        $this->receipt('200.0000', null, $this->date->copy());

        $young = app(UnappliedCash::class)->ageing(
            $this->entityId,
            'SAR',
            $this->date->copy()->addDays(10)->toDateString(),
        );

        $old = app(UnappliedCash::class)->ageing(
            $this->entityId,
            'SAR',
            $this->date->copy()->addDays(200)->toDateString(),
        );

        $failures = [];

        if (! $young['buckets']['0-30']->amount->isEqualTo('200.0000')) {
            $failures[] = sprintf(
                'a receipt ten days old totals %s in the 0-30 bucket',
                (string) $young['buckets']['0-30']->amount,
            );
        }

        if (! $old['buckets']['90+']->amount->isEqualTo('200.0000')) {
            $failures[] = sprintf(
                'the same receipt at 200 days totals %s in the 90+ bucket',
                (string) $old['buckets']['90+']->amount,
            );
        }

        /*
         | Old unapplied cash is a misidentified payer, nearly always.
         |
         | The report says so, because the figure alone gets carried and
         | the sentence gets acted on.
         */
        if (! str_contains((string) $old['why'], 'payer nobody matched')) {
            $failures[] = 'a three-month-old receipt is reported as a figure with no suggestion of what it usually means';
        }

        return $failures;
    }

    /** @return list<string> */
    private function unappliedReconciles(): array
    {
        $invoice = $this->issue();
        $partly = $this->receipt('500.0000', (string) $invoice->party_id);
        $this->receipt('250.0000', null);

        /*
         | One of them partly allocated, deliberately.
         |
         | With every receipt fully unallocated, "what is unapplied" and
         | "what was received" are the same number - and a report that
         | forgot to subtract the allocations would agree with the control
         | account for the wrong reason. This is the case that tells them
         | apart.
         */
        app(Allocations::class)->allocate(
            (string) $partly['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of('200.0000', 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $result = app(UnappliedCash::class)->reconciles($this->entityId, 'SAR');

        return $result['agrees']
            ? []
            : [sprintf('the unallocated receipts do not account for the control balance: %s', $result['why'])];
    }

    /** @return list<string> */
    private function unidentifiedPayer(): array
    {
        $this->receipt('310.0000', null);

        $ageing = app(UnappliedCash::class)->ageing($this->entityId, 'SAR', $this->date->toDateString());

        return ($ageing['unidentified_payers'] ?? 0) >= 1
            ? []
            : ['a receipt with no payer on it is not reported as one, so the commonest cause of old unapplied cash is invisible'];
    }

    /** @return list<string> */
    private function customerStatement(): array
    {
        $invoice = $this->issue();
        $partyId = (string) $invoice->party_id;

        $this->receipt('120.0000', $partyId);

        $statement = app(UnappliedCash::class)->statementFor(
            $this->entityId,
            $partyId,
            $this->date->toDateString(),
        );

        $failures = [];

        if ($statement['documents'] === []) {
            $failures[] = 'the statement lists no open documents, so there is nothing for the customer to recognise';
        }

        /*
         | And the payment they made.
         |
         | The customer is the only person who definitely knows what it
         | was for. A statement that lists their invoices and hides their
         | payment starts an argument; one that shows both lets them
         | answer in a sentence.
         */
        if ($statement['unapplied'] === []) {
            $failures[] = 'the statement hides the payment the customer sent, so they cannot tell you what it was for';
        }

        if (! $statement['held']->amount->isEqualTo('120.0000')) {
            $failures[] = sprintf('the statement reports %s held rather than 120', (string) $statement['held']->amount);
        }

        if (! $statement['net']->equals($statement['owed']->minus($statement['held']))) {
            $failures[] = 'the net on the statement is not what is owed less what is held';
        }

        return $failures;
    }

    // ---- Allocations ------------------------------------------------------

    /** @return list<string> */
    private function partialAcrossDocuments(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)->plus((string) $second->gross_amount);
        $payment = $this->receipt($total, (string) $first->party_id);

        foreach ([$first, $second] as $invoice) {
            app(Allocations::class)->allocate(
                (string) $payment['id'],
                'invoice',
                (string) $invoice->getKey(),
                Money::of((string) $invoice->gross_amount, 'SAR'),
                $this->actorId(),
                on: $this->date->copy(),
            );
        }

        $failures = [];

        if (! app(Allocations::class)->unallocated((string) $payment['id'])->isZero()) {
            $failures[] = 'a payment allocated across both invoices still reports money unallocated';
        }

        foreach ([$first, $second] as $invoice) {
            $open = app(Allocations::class)->openBalance('invoice', (string) $invoice->getKey(), 'SAR');

            if (! $open->isZero()) {
                $failures[] = sprintf('invoice %s still shows %s open after it was settled', $invoice->number, (string) $open->amount);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function allocationMovesTheBalance(): array
    {
        $invoice = $this->issue();
        $payment = $this->receipt((string) $invoice->gross_amount, (string) $invoice->party_id);

        $before = $this->balanceOf(ControlRole::AccountsReceivable);

        $result = app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of((string) $invoice->gross_amount, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $after = $this->balanceOf(ControlRole::AccountsReceivable);

        $failures = [];

        /*
         | This is where a party balance moves, and the only place.
         |
         | The receipt put money in a clearing position; this is somebody
         | saying which document it was for, and every movement on the
         | balance names the document it belongs to.
         */
        if ($before->minus($after)->amount->isNegative()
            || $before->equals($after)) {
            $failures[] = sprintf(
                'allocating did not reduce the receivable control (%s to %s), so settling an '.
                'invoice leaves the customer still owing it',
                (string) $before->amount,
                (string) $after->amount,
            );
        }

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $result['journal_entry_id'])
            ->get();

        foreach ($lines as $line) {
            if ($line->party_id === null) {
                $failures[] = 'an allocation line carries no party, so the control moved and the customer balance did not';
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function overAllocatingThePayment(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $payment = $this->receipt((string) $first->gross_amount, (string) $first->party_id);

        app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $first->getKey(),
            Money::of((string) $first->gross_amount, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        /*
         | Written straight to the table, past the service.
         |
         | This is THE invariant that keeps the receivables subledger
         | equal to its control account, and a service is one code path.
         */
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('allocations')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'payment_id' => (string) $payment['id'],
            'document_type' => 'invoice',
            'document_id' => (string) $second->getKey(),
            'party_id' => (string) $second->party_id,
            'amount' => (string) $second->gross_amount,
            'currency' => 'SAR',
            'allocated_on' => $this->date->toDateString(),
            'strategy' => 'explicit',
            'allocated_by' => $this->actorId(),
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($refusal === null) {
            return ['one payment settled two invoices worth twice it, so the control account moved by money that never arrived'];
        }

        return str_contains($refusal, 'more cash than arrived')
            ? []
            : [sprintf('the database refusal does not say what is wrong: %s', $refusal)];
    }

    /** @return list<string> */
    private function overAllocatingTheDocument(): array
    {
        $invoice = $this->issue();
        $twice = (string) BigDecimal::of((string) $invoice->gross_amount)->multipliedBy(2);

        $payment = $this->receipt($twice, (string) $invoice->party_id);

        $refusal = $this->refusal(fn () => app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of($twice, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['an invoice was allocated twice what it is worth, so a customer owes a negative figure nobody granted'];
        }

        return str_contains($refusal, 'below zero')
            ? []
            : [sprintf('the refusal does not say what it would do to the document: %s', $refusal)];
    }

    /** @return list<string> */
    private function allocationCurrency(): array
    {
        $invoice = $this->issue();
        $payment = $this->receipt('100.0000', (string) $invoice->party_id);

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('allocations')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'payment_id' => (string) $payment['id'],
            'document_type' => 'invoice',
            'document_id' => (string) $invoice->getKey(),
            'party_id' => (string) $invoice->party_id,
            'amount' => '10.0000',
            'currency' => 'EUR',
            'allocated_on' => $this->date->toDateString(),
            'strategy' => 'explicit',
            'allocated_by' => $this->actorId(),
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $refusal !== null && str_contains($refusal, 'different units')
            ? []
            : ['an allocation in another currency was accepted, which is arithmetic between two units'];
    }

    /** @return list<string> */
    private function allocatingAReversedPayment(): array
    {
        $invoice = $this->issue();
        $payment = $this->receipt('100.0000', (string) $invoice->party_id);

        app(Payments::class)->reverse(
            (string) $payment['id'],
            $this->actorId(),
            'the transfer was recalled by the sending bank',
            $this->date->copy(),
        );

        $refusal = $this->refusal(fn () => app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of('100.0000', 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        ));

        return $refusal !== null
            ? []
            : ['money that was taken back settled an invoice, so a document reports settled by nothing'];
    }

    /** @return list<string> */
    private function reverseThenReallocate(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $payment = $this->receipt((string) $first->gross_amount, (string) $first->party_id);

        $wrong = app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $first->getKey(),
            Money::of((string) $first->gross_amount, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        app(Allocations::class)->reverse(
            (string) $wrong['id'],
            $this->actorId(),
            'the customer says this payment was for the second invoice, not the first',
            $this->date->copy(),
        );

        $failures = [];

        $row = DB::connection('tenant')->table('allocations')->where('id', (string) $wrong['id'])->first();

        if ($row === null) {
            $failures[] = 'the allocation was deleted, so "it was against that invoice and somebody changed it" has no answer';
        }

        /*
         | And allocating correctly afterwards is ordinary.
         |
         | Which is the point of reversing rather than blocking: the
         | moment somebody needs to re-allocate is the moment after they
         | discovered the first allocation was wrong.
         */
        $again = $this->refusal(fn () => app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $second->getKey(),
            Money::of((string) $second->gross_amount, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        ));

        if ($again !== null) {
            $failures[] = sprintf('re-allocating after a reversal was refused: %s', $again);
        }

        return $failures;
    }

    /** @return list<string> */
    private function strategyProposes(): array
    {
        $invoice = $this->issue();
        $payment = $this->receipt((string) $invoice->gross_amount, (string) $invoice->party_id);

        $before = DB::connection('tenant')->table('allocations')->count();

        $proposal = app(Allocations::class)->propose((string) $payment['id'], 'oldest_first');

        $after = DB::connection('tenant')->table('allocations')->count();

        $failures = [];

        /*
         | Nothing written.
         |
         | A strategy that applied its own answer is one nobody inspects
         | until it is wrong - and "oldest first" is wrong whenever a
         | customer has told you which invoice they are paying.
         */
        if ($after !== $before) {
            $failures[] = 'proposing an allocation wrote one, so the strategy applied itself';
        }

        if ($proposal['proposed'] === []) {
            $failures[] = 'the strategy proposed nothing against an open invoice for the right payer';
        }

        return $failures;
    }

    /** @return list<string> */
    private function oldestFirst(): array
    {
        $older = $this->issue();
        $newer = $this->issue();

        /*
         | A payment worth one and a half invoices.
         |
         | Enough to settle the older one and spill into the newer, which
         | is what makes this check able to see two things at once: the
         | order, and that a proposal stops at what each document is owed.
         | A payment equal to one invoice would prove only the first.
         */
        $gross = BigDecimal::of((string) $older->gross_amount);
        $payment = $this->receipt(
            (string) $gross->plus($gross->dividedBy(2, 4, \Brick\Math\RoundingMode::HalfUp)),
            (string) $older->party_id,
        );

        $proposal = app(Allocations::class)->propose((string) $payment['id'], 'oldest_first');

        if ($proposal['proposed'] === []) {
            return ['oldest first proposed nothing'];
        }

        $failures = [];

        if ((string) $proposal['proposed'][0]['document_id'] !== (string) $older->getKey()) {
            $failures[] = sprintf(
                'oldest first proposed %s before %s, so the order it claims is not the order it uses',
                (string) $proposal['proposed'][0]['number'],
                (string) $older->number,
            );
        }

        if (! $proposal['proposed'][0]['amount']->amount->isEqualTo($gross)) {
            $failures[] = sprintf(
                'the first proposal is for %s against a document owed %s - a proposal that exceeds '.
                'the open balance is one the database would refuse, and offering something '.
                'impossible is worse than offering nothing',
                (string) $proposal['proposed'][0]['amount']->amount,
                (string) $gross,
            );
        }

        if (\count($proposal['proposed']) !== 2) {
            $failures[] = sprintf(
                'a payment worth one and a half invoices proposed %d document(s), so the spill into '.
                'the next one is not happening',
                \count($proposal['proposed']),
            );
        } elseif ((string) $proposal['proposed'][1]['document_id'] !== (string) $newer->getKey()) {
            $failures[] = 'the remainder was not proposed against the next oldest document';
        }

        return $failures;
    }

    /** @return list<string> */
    private function byReference(): array
    {
        $older = $this->issue();
        $quoted = $this->issue();

        $payment = $this->receipt((string) $quoted->gross_amount, (string) $quoted->party_id);

        $proposal = app(Allocations::class)->propose(
            (string) $payment['id'],
            'by_reference',
            (string) $quoted->number,
        );

        if ($proposal['proposed'] === []) {
            return ['a payment quoting an invoice number proposed nothing'];
        }

        /*
         | A payer naming a document is the strongest instruction there is.
         |
         | Oldest first is a guess about intent; a quoted reference is the
         | intent stated. A strategy that preferred the guess would be
         | overruling the customer.
         */
        return (string) $proposal['proposed'][0]['document_id'] === (string) $quoted->getKey()
            ? []
            : [sprintf(
                'the quoted invoice %s was not proposed first - %s was - so a payer naming a '.
                'document is being overruled by a date',
                (string) $quoted->number,
                (string) $proposal['proposed'][0]['number'],
            )];
    }

    /** @return list<string> */
    private function strategyExplainsItself(): array
    {
        $invoice = $this->issue();
        $payment = $this->receipt((string) $invoice->gross_amount, (string) $invoice->party_id);

        $proposal = app(Allocations::class)->propose((string) $payment['id'], 'oldest_first');

        foreach ($proposal['proposed'] as $line) {
            if (mb_strlen(trim((string) ($line['why'] ?? ''))) < 30) {
                return ['a proposed allocation does not say why that document was chosen, so it cannot be inspected'];
            }
        }

        return $proposal['proposed'] === []
            ? ['nothing was proposed, so this check cannot read a reason']
            : [];
    }

    // ---- Over-payment, credits and refunds --------------------------------

    /** @return list<string> */
    private function overpaymentIsACredit(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);
        $partyId = (string) $invoice->party_id;

        $payment = $this->receipt((string) $gross->plus('300.0000'), $partyId);

        app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of((string) $gross, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $credit = app(Refunds::class)->creditFor($this->entityId, $partyId, 'SAR');

        if (! $credit->amount->isEqualTo('300.0000')) {
            return [sprintf(
                'a customer who overpaid by 300 has %s on account, so the excess is not being held '.
                'as a credit',
                (string) $credit->amount,
            )];
        }

        /*
         | And it is usable against their next document.
         |
         | Which is what makes it a credit rather than a holding pen: the
         | same unallocated cash, allocated to an invoice issued later.
         */
        $next = $this->issue();

        $refusal = $this->refusal(fn () => app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $next->getKey(),
            Money::of('300.0000', 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        ));

        return $refusal === null
            ? []
            : [sprintf('a credit balance could not be used against a later invoice: %s', $refusal)];
    }

    /** @return list<string> */
    private function refundIsADocument(): array
    {
        $partyId = $this->aPartyWithCredit('450.0000');

        $result = app(Refunds::class)->issue(
            $this->entityId,
            $partyId,
            Money::of('450.0000', 'SAR'),
            'the customer cancelled the order after paying and asked for the money back',
            $this->actorId(),
            (string) $this->bank()->getKey(),
            $this->date->copy(),
        );

        $row = DB::connection('tenant')->table('refunds')->where('id', (string) $result['id'])->first();

        $failures = [];

        if ($row === null) {
            return ['no refund document was written, so "how much have we refunded this year" has no answer'];
        }

        foreach (['number', 'reason', 'approved_by', 'payment_id'] as $column) {
            if (($row->{$column} ?? null) === null) {
                $failures[] = sprintf('the refund carries no %s', $column);
            }
        }

        if (! app(Refunds::class)->creditFor($this->entityId, $partyId, 'SAR')->isZero()) {
            $failures[] = 'the credit was not consumed by the refund, so the money could be refunded twice';
        }

        return $failures;
    }

    /** @return list<string> */
    private function refundIsNotANegativeReceipt(): array
    {
        $partyId = $this->aPartyWithCredit('200.0000');

        $result = app(Refunds::class)->issue(
            $this->entityId,
            $partyId,
            Money::of('200.0000', 'SAR'),
            'the duplicate payment the customer made last week, returned to them',
            $this->actorId(),
            (string) $this->bank()->getKey(),
            $this->date->copy(),
        );

        $payment = DB::connection('tenant')->table('payments')
            ->where('id', (string) $result['payment_id'])
            ->first();

        $failures = [];

        /*
         | A disbursement, not a receipt with a minus in front of it.
         |
         | A negative receipt nets against the customer's cash in every
         | report that sums payments, so the refund total becomes a query
         | nobody can write - and that is the figure somebody asks for
         | after a chargeback.
         */
        if ($payment === null) {
            return ['the refund moved no money'];
        }

        if ((string) $payment->direction !== 'disbursement') {
            $failures[] = sprintf('the refund was recorded as a %s rather than money going out', (string) $payment->direction);
        }

        if (BigDecimal::of((string) $payment->amount)->isNegative()) {
            $failures[] = 'the refund is a negative amount, so it nets away in every report that sums payments';
        }

        $report = app(Refunds::class)->report($this->entityId);

        return $report === []
            ? [...$failures, 'the refund does not appear on the refund report']
            : $failures;
    }

    /** @return list<string> */
    private function refundingTooMuch(): array
    {
        $partyId = $this->aPartyWithCredit('100.0000');

        $refusal = $this->refusal(fn () => app(Refunds::class)->issue(
            $this->entityId,
            $partyId,
            Money::of('500.0000', 'SAR'),
            'refunding more than this customer ever paid us, to see whether it is caught',
            $this->actorId(),
            (string) $this->bank()->getKey(),
            $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['more was refunded than the customer had on account, leaving a debit balance on somebody nobody sold anything to'];
        }

        return str_contains($refusal, 'on account')
            ? []
            : [sprintf('the refusal does not say what they actually have: %s', $refusal)];
    }

    /** @return list<string> */
    private function refundNeedsAReason(): array
    {
        $partyId = $this->aPartyWithCredit('100.0000');

        $refusal = $this->refusal(fn () => app(Refunds::class)->issue(
            $this->entityId,
            $partyId,
            Money::of('100.0000', 'SAR'),
            'refund',
            $this->actorId(),
            (string) $this->bank()->getKey(),
            $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['money went back to a customer with the word "refund" beside it, which is not an answer to why'];
        }

        /*
         | The service's refusal, not the column's.
         |
         | The table refuses a short reason too, which is why it is there.
         | A person issuing a refund should be told what the reason is for
         | and who reads it, not handed the name of a check constraint.
         */
        return str_contains($refusal, 'length of a sentence')
            ? []
            : [sprintf('the refusal came from the table rather than from issuing the refund: %s', $refusal)];
    }

    /** @return list<string> */
    private function creditIsVisible(): array
    {
        $invoice = $this->issue();
        $partyId = (string) $invoice->party_id;

        $this->receipt('400.0000', $partyId);

        $statement = app(UnappliedCash::class)->statementFor($this->entityId, $partyId, $this->date->toDateString());
        $ageing = app(UnappliedCash::class)->ageing($this->entityId, 'SAR', $this->date->toDateString());

        $failures = [];

        /*
         | Shown as its own figure, never netted into what is owed.
         |
         | "You owe 4,000" and "you owe 5,000 and we are holding 1,000 of
         | yours" lead to different replies, and only the second lets the
         | customer tell you what the 1,000 was for.
         */
        if (! $statement['credit']->amount->isEqualTo('-400.0000')) {
            $failures[] = sprintf(
                'the statement reports a credit of %s rather than -400, so a customer credit is not '.
                'presented as a credit',
                (string) $statement['credit']->amount,
            );
        }

        if ($statement['owed']->equals($statement['net'])) {
            $failures[] = 'what is owed and the net are the same figure, so the credit was netted away invisibly';
        }

        if (! ($ageing['credit_buckets']['0-30'] ?? Money::zero('SAR'))->amount->isEqualTo('-400.0000')) {
            $failures[] = 'the ageing report has no negative bucket for the credit, so it is invisible there';
        }

        return $failures;
    }

    private function aPartyWithCredit(string $amount): string
    {
        $invoice = $this->issue();
        $partyId = (string) $invoice->party_id;

        $this->receipt($amount, $partyId);

        return $partyId;
    }

    // ---- The property -----------------------------------------------------

    /** @return list<string> */
    private function theProperty(): array
    {
        $invoices = [$this->issue(), $this->issue(), $this->issue()];
        $partyId = (string) $invoices[0]->party_id;
        $gross = BigDecimal::of((string) $invoices[0]->gross_amount);

        $payments = [];

        foreach ([3, 2, 1] as $multiple) {
            $payments[] = $this->receipt((string) $gross->multipliedBy($multiple), $partyId);
        }

        /*
         | A fixed sequence, not a random one.
         |
         | "For any sequence" is the property; a seed nobody can reproduce
         | is a failing check nobody can debug. This sequence is chosen to
         | hit the cases that break it: allocate, partially allocate,
         | reverse, re-allocate the freed cash elsewhere, and over-allocate
         | so a refusal is part of the sequence too.
         */
        $live = [];
        $quarter = $gross->dividedBy(4, 4, \Brick\Math\RoundingMode::HalfUp);

        foreach ([[0, 0], [0, 1], [1, 1], [1, 2], [2, 2]] as [$p, $i]) {
            $result = $this->tryAllocate($payments[$p]['id'], $invoices[$i], (string) $quarter);

            if ($result !== null) {
                $live[] = $result;
            }
        }

        foreach ([0, 2] as $index) {
            if (! isset($live[$index])) {
                continue;
            }

            app(Allocations::class)->reverse(
                (string) $live[$index]['id'],
                $this->actorId(),
                'reversed as part of the property sequence, to free the cash again',
                $this->date->copy(),
            );
        }

        foreach ([[0, 2], [2, 0]] as [$p, $i]) {
            $this->tryAllocate($payments[$p]['id'], $invoices[$i], (string) $quarter);
        }

        /*
         | And the invariant, after all of it.
         |
         | The sum of the documents' open balances equals the receivables
         | control account. If any allocation, reversal or refusal left
         | the two out of step, this is where it shows - and it shows as a
         | figure rather than as a story.
         */
        $open = Money::zero('SAR');

        foreach ($invoices as $invoice) {
            $open = $open->plus(app(Allocations::class)->openBalance('invoice', (string) $invoice->getKey(), 'SAR'));
        }

        $control = $this->balanceOf(ControlRole::AccountsReceivable);

        if (! $open->equals($control)) {
            return [sprintf(
                'after a sequence of allocations and reversals the documents are open for %s and '.
                'the receivables control holds %s. The subledger and the control have come apart, '.
                'which is the one thing this module exists to prevent',
                (string) $open->amount,
                (string) $control->amount,
            )];
        }

        /*
         | And the cash side too.
         |
         | Unapplied cash should hold exactly what is unallocated across
         | the payments. A sequence can satisfy the receivable identity
         | and still leave the clearing position wrong, and that error
         | surfaces as a bank reconciliation nobody can explain.
         */
        $unallocated = Money::zero('SAR');

        foreach ($payments as $payment) {
            $unallocated = $unallocated->plus(app(Allocations::class)->unallocated((string) $payment['id']));
        }

        $clearing = app(UnappliedCash::class)->balance($this->entityId, 'SAR');

        return $clearing->plus($unallocated)->isZero()
            ? []
            : [sprintf(
                'unallocated cash across the payments is %s and the clearing account holds %s, so '.
                'the two have come apart',
                (string) $unallocated->amount,
                (string) $clearing->amount,
            )];
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function tryAllocate(string $paymentId, object $invoice, string $amount): ?array
    {
        try {
            return app(Allocations::class)->allocate(
                $paymentId,
                'invoice',
                (string) $invoice->getKey(),
                Money::of($amount, 'SAR'),
                $this->actorId(),
                on: $this->date->copy(),
            );
        } catch (\Throwable) {
            /*
             | A refusal is part of the sequence, not a failure of it.
             |
             | The property is that the books agree after ANY sequence,
             | including one where the database refused a step - and a
             | refusal that left a posting behind is exactly the bug this
             | is looking for.
             */
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function receipt(string $amount, ?string $partyId, ?Carbon $on = null): array
    {
        return app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of($amount, 'SAR'),
            valueDate: $on ?? $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $this->bank()->getKey(),
            partyId: $partyId,
        );
    }

    private function accountFor(ControlRole $role): string
    {
        return (string) AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', $role->value)
            ->value('account_id');
    }

    private function balanceOf(ControlRole $role): Money
    {
        $row = DB::connection('tenant')->selectOne(<<<'SQL'
            SELECT coalesce(sum(functional_amount), 0)::text AS balance
            FROM journal_lines
            WHERE entity_id = ?
              AND account_id = ?
        SQL, [$this->entityId, $this->accountFor($role)]);

        return Money::of((string) ($row->balance ?? '0'), 'SAR');
    }
}
