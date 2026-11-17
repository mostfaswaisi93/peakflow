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
use App\Tenant\Settlement\Services\WriteOffs;
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

                // ---- Rounding differences - PF-171 ---------------------
                'the last unit is posted, not shrunk away' => fn (): array => $this->roundingIsPosted(),
                'the receivable is closed by the posting' => fn (): array => $this->roundingClosesTheDocument(),
                'above tolerance it is not a rounding difference' => fn (): array => $this->roundingAboveTolerance(),
                'closing a settled document is refused' => fn (): array => $this->roundingOnNothing(),
                'the allocation guard knows what a difference relieved' => fn (): array => $this->guardKnowsAboutDifferences(),

                // ---- Realized FX - PF-170 ------------------------------
                'the worked three-currency example posts to the cent' => fn (): array => $this->threeCurrencies(),
                'a partial settlement realizes a proportional share' => fn (): array => $this->proportionalRealization(),
                'the rate used is the payment\'s value date' => fn (): array => $this->valueDateRate(),
                'both rates are recorded on the allocation' => fn (): array => $this->ratesAreRecorded(),
                'a foreign receipt posts what it is worth' => fn (): array => $this->foreignReceiptIsTranslated(),
                'realized and unrealized never share an account' => fn (): array => $this->realizedIsNotRevaluation(),
                'a payment with no rate cannot be allocated' => fn (): array => $this->allocatingWithoutARate(),

                // ---- Write-offs - PF-172 -------------------------------
                'a write-off posts to bad debt and closes the document' => fn (): array => $this->writeOffPosts(),
                'a write-off needs a reason code that exists' => fn (): array => $this->writeOffNeedsACode(),
                'a write-off needs a sentence beside the code' => fn (): array => $this->writeOffNeedsASentence(),
                'above the threshold it needs a second name' => fn (): array => $this->writeOffNeedsASecondApprover(),
                'the approver is not the person who recorded it' => fn (): array => $this->writeOffIsNotSelfApproved(),
                'the database refuses an unapproved write-off' => fn (): array => $this->databaseRefusesUnapprovedWriteOff(),
                'the threshold that applied is stored' => fn (): array => $this->writeOffThresholdIsStored(),
                'writing off more than is owed is refused' => fn (): array => $this->writeOffBeyondTheDocument(),
                'a written-off document is closed, not deleted' => fn (): array => $this->writtenOffIsClosedNotDeleted(),
                'a recovery is a new receipt, not an un-write-off' => fn (): array => $this->recoveryIsNotAnUnWriteOff(),
                'recovering more than was written off is refused' => fn (): array => $this->recoveringTooMuch(),
                'the report groups by reason, period and customer' => fn (): array => $this->writeOffReport(),
                'whoever invoiced does not write off' => fn (): array => $this->writeOffSodPairs(),

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

    // ---- Write-offs -------------------------------------------------------

    /** @return list<string> */
    private function writeOffPosts(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;

        $result = $this->writeOff($invoice, $gross);

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $result['journal_entry_id'])
            ->get();

        $failures = [];

        $onBadDebt = $lines->firstWhere('account_id', $this->accountFor(ControlRole::BadDebt));
        $onReceivable = $lines->firstWhere('account_id', $this->accountFor(ControlRole::AccountsReceivable));

        if ($onBadDebt === null) {
            $failures[] = 'a write-off did not reach bad debt, so a credit loss is somewhere else in the accounts';
        } elseif (! BigDecimal::of((string) $onBadDebt->functional_amount)->isEqualTo($gross)) {
            $failures[] = sprintf('bad debt took %s rather than %s', (string) $onBadDebt->functional_amount, $gross);
        }

        if ($onReceivable === null) {
            return [...$failures, 'the receivable was not relieved, so the invoice stays open for money nobody is chasing'];
        }

        /*
         | The party is on the receivable line.
         |
         | Without it the control moves and the customer's own balance
         | does not, so the first symptom is a statement chasing money
         | somebody already decided to lose.
         */
        if ($onReceivable->party_id === null) {
            $failures[] = 'the receivable line carries no party, so the control moved and the customer balance did not';
        }

        /*
         | And it is not an adjustment to revenue.
         |
         | The sale happened at the invoiced figure and it is still that.
         | Restating revenue would make the margin look correct while the
         | cash never arrived.
         */
        if ($lines->firstWhere('account_id', $this->revenueAccountId) !== null) {
            $failures[] = 'a write-off moved revenue, so the margin on that sale will look correct although the cash never arrived';
        }

        return $failures;
    }

    /** @return list<string> */
    private function writeOffNeedsACode(): array
    {
        $invoice = $this->issue();

        $refusal = $this->refusal(fn () => app(WriteOffs::class)->record(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            amount: Money::of('100.0000', 'SAR'),
            reasonCode: 'because_i_said_so',
            reason: 'a reason code nobody has ever defined, which should not be accepted here',
            userId: $this->actorId(),
            partyId: (string) $invoice->party_id,
            on: $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['a write-off was recorded against a reason code nobody defined, so "which kinds of debt fail" is unanswerable'];
        }

        /*
         | And the refusal lists the codes that do exist.
         |
         | "Unknown code" leaves somebody guessing. The list is what lets
         | them pick the right one, or decide none fits and add it.
         */
        return str_contains($refusal, 'insolvency')
            ? []
            : [sprintf('the refusal does not say which codes are in use: %s', $refusal)];
    }

    /** @return list<string> */
    private function writeOffNeedsASentence(): array
    {
        $invoice = $this->issue();

        $refusal = $this->refusal(fn () => app(WriteOffs::class)->record(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            amount: Money::of('100.0000', 'SAR'),
            reasonCode: 'insolvency',
            reason: 'gone bust',
            userId: $this->actorId(),
            partyId: (string) $invoice->party_id,
            on: $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['a write-off was recorded with "gone bust" beside it, which says nothing about which customer or which conversation'];
        }

        return str_contains($refusal, 'length of a sentence')
            ? []
            : [sprintf('the refusal came from the table rather than from recording: %s', $refusal)];
    }

    /** @return list<string> */
    private function writeOffNeedsASecondApprover(): array
    {
        $invoice = $this->issue();
        $threshold = app(WriteOffs::class)->threshold($this->entityId);

        /*
         | Above the threshold, with nobody else's name on it.
         |
         | The default threshold is 1,000 and the fixture invoice is
         | 1,150, which is deliberately just over - a check whose
         | scenario is ten times the threshold would also pass if the
         | comparison were written the wrong way round.
         */
        if (! BigDecimal::of((string) $invoice->gross_amount)->isGreaterThan($threshold->amount)) {
            return [sprintf(
                'this check needs an amount above the %s threshold and the fixture invoice is %s',
                (string) $threshold->amount,
                (string) $invoice->gross_amount,
            )];
        }

        $refusal = $this->refusal(fn () => app(WriteOffs::class)->record(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            amount: Money::of((string) $invoice->gross_amount, 'SAR'),
            reasonCode: 'insolvency',
            reason: 'the liquidator confirmed there will be no distribution to unsecured creditors',
            userId: $this->actorId(),
            partyId: (string) $invoice->party_id,
            on: $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['a write-off above the threshold went through on one signature'];
        }

        if (! str_contains($refusal, 'second name')) {
            return [sprintf('the refusal does not say a second approver is needed: %s', $refusal)];
        }

        /*
         | And below the threshold it does not.
         |
         | An approval step on every small write-off is a step people
         | click through, and the habit carries to the ones that matter.
         */
        $small = $this->refusal(fn () => app(WriteOffs::class)->record(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            amount: Money::of('50.0000', 'SAR'),
            reasonCode: 'uneconomic_to_pursue',
            reason: 'fifty riyals outstanding on a closed account, not worth the cost of a letter',
            userId: $this->actorId(),
            partyId: (string) $invoice->party_id,
            on: $this->date->copy(),
        ));

        return $small === null
            ? []
            : [sprintf('a small write-off demanded an approver, which trains people to approve without reading: %s', $small)];
    }

    /** @return list<string> */
    private function writeOffIsNotSelfApproved(): array
    {
        $invoice = $this->issue();

        $refusal = $this->refusal(fn () => app(WriteOffs::class)->record(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            amount: Money::of((string) $invoice->gross_amount, 'SAR'),
            reasonCode: 'insolvency',
            reason: 'the liquidator confirmed there will be no distribution to unsecured creditors',
            userId: $this->actorId(),
            approvedBy: $this->actorId(),
            partyId: (string) $invoice->party_id,
            on: $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['somebody approved their own write-off, which is the control performed by the person it exists to check'];
        }

        /*
         | The service's refusal, not the table's.
         |
         | The constraint refuses a self-approval too, which is why it is
         | there. A person recording one should be told what is wrong with
         | it in a sentence, not handed the name of a check constraint on
         | a control that exists to check them.
         */
        return str_contains($refusal, 'cannot be the person who recorded it')
            ? []
            : [sprintf('the refusal came from the table rather than from recording: %s', $refusal)];
    }

    /** @return list<string> */
    private function databaseRefusesUnapprovedWriteOff(): array
    {
        $invoice = $this->issue();

        /*
         | Written straight to the table, past the service.
         |
         | This is the control that stops one person moving money out of
         | a business, and a service is one code path while an import, a
         | correction script and a bulk tool are three others.
         */
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('write_offs')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'number' => 'WOF-RAW-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'document_type' => 'invoice',
            'document_id' => (string) $invoice->getKey(),
            'party_id' => (string) $invoice->party_id,
            'amount' => '9000.0000',
            'currency' => 'SAR',
            'written_off_on' => $this->date->toDateString(),
            'reason_code_id' => (string) DB::connection('tenant')->table('write_off_reason_codes')->value('id'),
            'reason' => 'nine thousand written off with nobody having approved it, straight into the table',
            'recorded_by' => $this->actorId(),
            'recorded_at' => now(),
            'threshold' => '1000.0000',
            'approved_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $refusal !== null
            ? []
            : ['the table accepted a write-off above its own threshold with no approver, so the control is only as good as one code path'];
    }

    /** @return list<string> */
    private function writeOffThresholdIsStored(): array
    {
        $invoice = $this->issue();
        $result = $this->writeOff($invoice, '50.0000', 'uneconomic_to_pursue');

        $row = DB::connection('tenant')->table('write_offs')->where('id', (string) $result['id'])->first();
        $threshold = app(WriteOffs::class)->threshold($this->entityId);

        if ($row === null) {
            return ['the write-off was posted and not recorded'];
        }

        /*
         | The figure, not just the verdict.
         |
         | A tenant who raises the threshold next week would otherwise
         | make every past write-off look as though it had always been
         | within one signature.
         */
        return BigDecimal::of((string) $row->threshold)->isEqualTo($threshold->amount)
            ? []
            : [sprintf(
                'the write-off stored a threshold of %s against an actual %s, so the evidence does '.
                'not match the claim',
                (string) $row->threshold,
                (string) $threshold->amount,
            )];
    }

    /** @return list<string> */
    private function writeOffBeyondTheDocument(): array
    {
        $invoice = $this->issue();
        $twice = (string) BigDecimal::of((string) $invoice->gross_amount)->multipliedBy(2);

        $refusal = $this->refusal(fn () => app(WriteOffs::class)->record(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            amount: Money::of($twice, 'SAR'),
            reasonCode: 'insolvency',
            reason: 'writing off twice what the customer was ever invoiced for, to see whether it is caught',
            userId: $this->actorId(),
            approvedBy: $this->secondActorId(),
            partyId: (string) $invoice->party_id,
            on: $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['more was written off than the document was worth, taking it below zero'];
        }

        return str_contains($refusal, 'below zero')
            ? []
            : [sprintf('the refusal does not say what it would do to the document: %s', $refusal)];
    }

    /** @return list<string> */
    private function writtenOffIsClosedNotDeleted(): array
    {
        $invoice = $this->issue();
        $this->writeOff($invoice, (string) $invoice->gross_amount);

        $failures = [];

        /*
         | Closed: the open balance is nil.
         |
         | Through the one shared definition, so this also proves the
         | write-off term reached the trigger and the strategy query and
         | not only the report.
         */
        $open = app(Allocations::class)->openBalance('invoice', (string) $invoice->getKey(), 'SAR');

        if (! $open->isZero()) {
            $failures[] = sprintf('the invoice still shows %s open after being written off in full', (string) $open->amount);
        }

        /*
         | Not deleted.
         |
         | A written-off invoice is still the document the customer holds
         | and the tax return reported. Deleting it would make the sale
         | disappear along with the loss.
         */
        $still = DB::connection('tenant')->table('invoices')->where('id', (string) $invoice->getKey())->exists();

        if (! $still) {
            $failures[] = 'the invoice was deleted, so the sale disappeared along with the loss';
        }

        $payment = $this->receipt('10.0000', (string) $invoice->party_id);

        $refusal = $this->refusal(fn () => app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of('10.0000', 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        ));

        if ($refusal === null) {
            $failures[] = 'money was allocated to a document that had been written off in full, so it reports settled twice';
        }

        return $failures;
    }

    /** @return list<string> */
    private function recoveryIsNotAnUnWriteOff(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;

        $writeOff = $this->writeOff($invoice, $gross);

        $badDebtBefore = $this->balanceOf(ControlRole::BadDebt);

        $result = app(WriteOffs::class)->recover(
            (string) $writeOff['id'],
            Money::of('300.0000', 'SAR'),
            (string) $this->bank()->getKey(),
            $this->actorId(),
            'the liquidator made an unexpected distribution eighteen months later',
            $this->date->copy(),
        );

        $failures = [];

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $result['journal_entry_id'])
            ->get();

        /*
         | Income of its own, never a credit back to bad debt.
         |
         | The conclusion was right on the day it was made. Netting the
         | two destroys the only question that improves those decisions:
         | whether the business writes off too eagerly or too late.
         */
        if ($lines->firstWhere('account_id', $this->accountFor(ControlRole::BadDebtRecovery)) === null) {
            $failures[] = 'a recovery did not reach the recovery account, so it has been netted against something';
        }

        $badDebtAfter = $this->balanceOf(ControlRole::BadDebt);

        if (! $badDebtBefore->equals($badDebtAfter)) {
            $failures[] = sprintf(
                'bad debt moved from %s to %s when money was recovered, so the write-off is being '.
                'treated as having been wrong',
                (string) $badDebtBefore->amount,
                (string) $badDebtAfter->amount,
            );
        }

        /*
         | And the document stays closed.
         |
         | Reopening it would make the same invoice appear twice in every
         | report that counts what was collected.
         */
        $open = app(Allocations::class)->openBalance('invoice', (string) $invoice->getKey(), 'SAR');

        if (! $open->isZero()) {
            $failures[] = sprintf('the invoice reopened to %s when money was recovered', (string) $open->amount);
        }

        return $failures;
    }

    /** @return list<string> */
    private function recoveringTooMuch(): array
    {
        $invoice = $this->issue();
        $writeOff = $this->writeOff($invoice, '100.0000', 'uneconomic_to_pursue');

        $refusal = $this->refusal(fn () => app(WriteOffs::class)->recover(
            (string) $writeOff['id'],
            Money::of('400.0000', 'SAR'),
            (string) $this->bank()->getKey(),
            $this->actorId(),
            'recovering four times what was written off',
            $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['more was recovered than was ever written off, which credits income for money that may be owed back'];
        }

        return str_contains($refusal, 'payment on account')
            ? []
            : [sprintf('the refusal does not say what the excess actually is: %s', $refusal)];
    }

    /** @return list<string> */
    private function writeOffReport(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $this->writeOff($first, '200.0000', 'insolvency');
        $written = $this->writeOff($second, '150.0000', 'uneconomic_to_pursue');

        app(WriteOffs::class)->recover(
            (string) $written['id'],
            Money::of('50.0000', 'SAR'),
            (string) $this->bank()->getKey(),
            $this->actorId(),
            'a partial payment arrived after the account was closed',
            $this->date->copy(),
        );

        $report = app(WriteOffs::class)->report($this->entityId);

        $failures = [];

        /*
         | All three groupings, because each answers a different question.
         |
         | By reason: which kinds of debt fail, which is what a provision
         | matrix is built from. By period: whether it is getting worse.
         | By customer: whether it is one relationship or a pattern.
         */
        foreach (['by_reason', 'by_period', 'by_customer'] as $grouping) {
            if (($report[$grouping] ?? []) === []) {
                $failures[] = sprintf('the report has nothing %s', str_replace('_', ' ', $grouping));
            }
        }

        if (! isset($report['by_reason']['insolvency'], $report['by_reason']['uneconomic_to_pursue'])) {
            $failures[] = 'two different reason codes are not reported separately, so the report cannot say which kinds of debt fail';
        }

        if ($report['total'] === null || ! $report['total']->amount->isEqualTo('350.0000')) {
            $failures[] = sprintf('two write-offs of 200 and 150 total %s', (string) ($report['total']?->amount ?? 'nothing'));
        }

        /*
         | And recoveries are reported beside, not netted in.
         |
         | Netted, nobody can tell a business that writes off too eagerly
         | from one that writes off too late.
         */
        if ($report['recovered'] === null || ! $report['recovered']->amount->isEqualTo('50.0000')) {
            $failures[] = sprintf(
                'the recovery is reported as %s rather than 50, or is netted into the write-off total',
                (string) ($report['recovered']?->amount ?? 'nothing'),
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function writeOffSodPairs(): array
    {
        $failures = [];

        foreach ([
            ['invoice_then_write_off', 'invoice.issue'],
            ['reconcile_then_write_off', 'bank.reconcile'],
        ] as [$key, $other]) {
            $rule = \App\Tenant\Identity\Models\SodRule::query()->where('key', $key)->first();

            if ($rule === null) {
                $failures[] = sprintf('nothing pairs %s against writing a debt off', $other);

                continue;
            }

            $duties = [(string) $rule->duty_a, (string) $rule->duty_b];
            sort($duties);
            $expected = [$other, 'writeoff.record'];
            sort($expected);

            if ($duties !== $expected) {
                $failures[] = sprintf('%s pairs %s rather than %s', $key, implode(' and ', $duties), implode(' and ', $expected));
            }
        }

        $map = (array) config('sod.performed_as');

        if (($map['writeoff.record'] ?? []) === []) {
            $failures[] = 'the write-off duty maps to no audit action, so the rules cannot see it happen';
        }

        /*
         | And it is a different duty from absorbing a difference.
         |
         | Concluding money is not coming and absorbing a rounding unit
         | on money that did arrive are different decisions. Mapping them
         | to one duty would bar somebody who absorbed a penny this
         | morning from concluding a liquidation this afternoon.
         */
        if (\in_array('difference.written_off', (array) ($map['writeoff.record'] ?? []), true)) {
            $failures[] = 'absorbing a reconciliation difference counts as writing a debt off, so two different decisions share one approver';
        }

        return $failures;
    }

    /** @return array<string, mixed> */
    private function writeOff(object $invoice, string $amount, string $code = 'insolvency'): array
    {
        $threshold = app(WriteOffs::class)->threshold($this->entityId);
        $needsASecond = BigDecimal::of($amount)->isGreaterThan($threshold->amount);

        return app(WriteOffs::class)->record(
            entityId: $this->entityId,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            amount: Money::of($amount, 'SAR'),
            reasonCode: $code,
            reason: 'the liquidator confirmed there will be no distribution to unsecured creditors of this customer',
            userId: $this->actorId(),
            approvedBy: $needsASecond ? $this->secondActorId() : null,
            partyId: (string) $invoice->party_id,
            on: $this->date->copy(),
        );
    }

    /**
     * A second person, for the approvals that need one.
     *
     * A fixed id rather than a real user: what is being checked is that
     * the approver is somebody else, and the identity machinery has its
     * own suite for whether that somebody may approve.
     */
    private function secondActorId(): string
    {
        return str_repeat('1', 26);
    }

    // ---- Rounding differences ---------------------------------------------

    /** @return list<string> */
    private function roundingIsPosted(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);
        $short = (string) $gross->minus('0.0100');

        $payment = $this->receipt($short, (string) $invoice->party_id);

        app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of($short, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $result = app(Allocations::class)->closeRounding(
            'invoice',
            (string) $invoice->getKey(),
            $this->entityId,
            $this->actorId(),
            (string) $invoice->party_id,
            $this->date->copy(),
        );

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $result['journal_entry_id'])
            ->get();

        $failures = [];

        /*
         | Its own journal line, which is the whole point.
         |
         | A receivable reduced to agree with a payment is a figure that
         | no longer traces to the invoice behind it, and the penny
         | nobody posted is the one that makes a control account disagree
         | with its subledger by an amount too small to investigate and
         | too persistent to go away.
         */
        $onRounding = $lines->firstWhere('account_id', $this->accountFor(ControlRole::RoundingDifference));

        if ($onRounding === null) {
            return ['the last unit never reached the rounding difference account, so it was absorbed somewhere unnamed'];
        }

        if (! BigDecimal::of((string) $onRounding->functional_amount)->isEqualTo('0.0100')) {
            $failures[] = sprintf(
                'the rounding line is %s rather than the 0.01 that was short',
                (string) $onRounding->functional_amount,
            );
        }

        $onReceivable = $lines->firstWhere('account_id', $this->accountFor(ControlRole::AccountsReceivable));

        if ($onReceivable === null) {
            $failures[] = 'the receivable was not relieved by the rounding posting';
        } elseif ($onReceivable->party_id === null) {
            $failures[] = 'the receivable line carries no party, so the control moved and the customer balance did not';
        }

        return $failures;
    }

    /** @return list<string> */
    private function roundingClosesTheDocument(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);
        $short = (string) $gross->minus('0.0100');

        $payment = $this->receipt($short, (string) $invoice->party_id);

        app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of($short, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        app(Allocations::class)->closeRounding(
            'invoice',
            (string) $invoice->getKey(),
            $this->entityId,
            $this->actorId(),
            (string) $invoice->party_id,
            $this->date->copy(),
        );

        $open = app(Allocations::class)->openBalance('invoice', (string) $invoice->getKey(), 'SAR');

        return $open->isZero()
            ? []
            : [sprintf(
                'the invoice still shows %s open after the rounding difference was posted, so the '.
                'document is not closed and will be chased for a penny',
                (string) $open->amount,
            )];
    }

    /** @return list<string> */
    private function roundingAboveTolerance(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);

        /*
         | Forty short, not a penny.
         |
         | Above the tolerance this is not a rounding difference at all -
         | it is a short payment, a charge or something withheld, and
         | which of those it is belongs to classification. Absorbing it
         | into rounding would be the quiet write-off the whole tolerance
         | exists to prevent.
         */
        $short = (string) $gross->minus('40.0000');
        $payment = $this->receipt($short, (string) $invoice->party_id);

        app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of($short, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $refusal = $this->refusal(fn () => app(Allocations::class)->closeRounding(
            'invoice',
            (string) $invoice->getKey(),
            $this->entityId,
            $this->actorId(),
            (string) $invoice->party_id,
            $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['forty riyals was absorbed as a rounding difference, which is the quiet write-off the tolerance exists to prevent'];
        }

        /*
         | The service's refusal, not the table's.
         |
         | The constraint refuses an unapproved write-off above tolerance
         | too, which is why it is there. A person settling an invoice
         | should be told the figure their entity accepts and that this
         | is a decision somebody has to make, not handed a constraint
         | name.
         */
        return str_contains($refusal, 'accepts without approval')
            ? []
            : [sprintf('the refusal came from the table rather than from recording the difference: %s', $refusal)];
    }

    /** @return list<string> */
    private function roundingOnNothing(): array
    {
        $invoice = $this->issue();
        $payment = $this->receipt((string) $invoice->gross_amount, (string) $invoice->party_id);

        app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of((string) $invoice->gross_amount, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $refusal = $this->refusal(fn () => app(Allocations::class)->closeRounding(
            'invoice',
            (string) $invoice->getKey(),
            $this->entityId,
            $this->actorId(),
            (string) $invoice->party_id,
            $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['a rounding difference was posted against a document that was already settled'];
        }

        /*
         | Refused by closing, not by recording.
         |
         | A zero difference is refused downstream too, which is why that
         | guard exists - but "this document has nothing left on it"
         | tells somebody they are looking at the wrong invoice, and
         | "there is no difference to record" tells them nothing they can
         | act on.
         */
        return str_contains($refusal, 'nothing left on it')
            ? []
            : [sprintf('the refusal came from recording the difference rather than from closing: %s', $refusal)];
    }

    /** @return list<string> */
    private function guardKnowsAboutDifferences(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;

        /*
         | Forty written off, then the full gross allocated.
         |
         | The ledger relieved the receivable by forty, so only the rest
         | is still owed. A guard that computed the open balance from
         | gross and allocations alone would let this through - taking
         | the document below zero by the one route the guard exists to
         | refuse.
         */
        app(\App\Tenant\Banking\Services\Differences::class)->record(
            \App\Tenant\Banking\Enums\DifferenceType::ShortPayment,
            Money::of('40.0000', 'SAR'),
            [
                'entity_id' => $this->entityId,
                'subject_type' => 'invoice',
                'subject_id' => (string) $invoice->getKey(),
                'counter_account_id' => $this->accountFor(ControlRole::AccountsReceivable),
                'party_id' => (string) $invoice->party_id,
                'arose_on' => $this->date->toDateString(),
            ],
            $this->actorId(),
            'the customer disputes the delivery charge and will not pay it',
            approvedBy: $this->actorId(),
        );

        $open = app(Allocations::class)->openBalance('invoice', (string) $invoice->getKey(), 'SAR');

        $failures = [];

        if (! $open->amount->isEqualTo(BigDecimal::of($gross)->minus('40.0000'))) {
            $failures[] = sprintf(
                'the invoice shows %s open after forty was written off against it',
                (string) $open->amount,
            );
        }

        $payment = $this->receipt($gross, (string) $invoice->party_id);

        $refusal = $this->refusal(fn () => app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of($gross, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        ));

        if ($refusal === null) {
            $failures[] = 'a document part-relieved by a write-off absorbed an allocation for its full gross, taking it below zero';
        }

        return $failures;
    }

    // ---- Realized FX ------------------------------------------------------

    /** @return list<string> */
    private function threeCurrencies(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(\dirname(__DIR__).'/Fixtures/settlement/three-currency.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->seedTheRates($fixture['rates']);

        $failures = [];

        foreach ($fixture['cases'] as $case) {
            foreach ($this->runTheCase($case) as $failure) {
                $failures[] = sprintf('[%s] %s', $case['name'], $failure);
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $case
     * @return list<string>
     */
    private function runTheCase(array $case): array
    {
        $currency = (string) $case['currency'];
        $expected = $case['expected'];

        $invoice = $this->aForeignInvoice(
            $currency,
            (string) $case['invoice_gross'],
            $case['invoice_rate'] === null ? null : (string) $case['invoice_rate'],
        );

        $account = $currency === 'SAR'
            ? $this->bank()
            : $this->aForeignAccount($currency);

        $payment = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of((string) $case['payment_amount'], $currency),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $account->getKey(),
            partyId: (string) $invoice->party_id,
        );

        $result = app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of((string) $case['allocate'], $currency),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $result['journal_entry_id'])
            ->get();

        $failures = [];

        /*
         | Each line compared against a figure written down by hand.
         |
         | A fixture whose expected figures are computed by the code under
         | test proves that the code agrees with itself. These were worked
         | out from the rates, and a change that moves any of them has to
         | move the fixture too, in a diff somebody reads.
         */
        foreach ([
            [ControlRole::UnappliedCash, 'unapplied_cash'],
            [ControlRole::AccountsReceivable, 'accounts_receivable'],
            [ControlRole::FxGainLoss, 'fx_gain_loss'],
        ] as [$role, $key]) {
            $line = $lines->firstWhere('account_id', $this->accountFor($role));
            $want = $expected[$key] ?? null;

            if ($want === null) {
                if ($line !== null) {
                    $failures[] = sprintf(
                        'a line was posted to %s where the worked example expects none (%s)',
                        $role->value,
                        (string) $line->functional_amount,
                    );
                }

                continue;
            }

            if ($line === null) {
                $failures[] = sprintf('nothing was posted to %s, where the example expects %s', $role->value, (string) $want);

                continue;
            }

            if (! BigDecimal::of((string) $line->functional_amount)->isEqualTo((string) $want)) {
                $failures[] = sprintf(
                    '%s posted %s where the worked example says %s',
                    $role->value,
                    (string) $line->functional_amount,
                    (string) $want,
                );
            }
        }

        $row = DB::connection('tenant')->table('allocations')->where('id', (string) $result['id'])->first();
        $wantRealized = $expected['realized_fx'] ?? null;

        if ($wantRealized === null) {
            if ($row?->realized_fx !== null) {
                $failures[] = sprintf('the allocation recorded a realized figure of %s where none was expected', (string) $row->realized_fx);
            }
        } elseif ($row === null || ! BigDecimal::of((string) $row->realized_fx)->isEqualTo((string) $wantRealized)) {
            $failures[] = sprintf(
                'the allocation recorded %s realized where the worked example says %s',
                (string) ($row->realized_fx ?? 'nothing'),
                (string) $wantRealized,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function proportionalRealization(): array
    {
        $this->seedTheRates([
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => '2026-05-01', 'value' => '3.9000000000'],
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => $this->date->toDateString(), 'value' => '4.0000000000'],
        ]);

        $invoice = $this->aForeignInvoice('EUR', '1000.0000', '3.9000000000');
        $account = $this->aForeignAccount('EUR');

        $payment = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('1000.0000', 'EUR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $account->getKey(),
            partyId: (string) $invoice->party_id,
        );

        $failures = [];

        /*
         | A quarter, then another quarter.
         |
         | The whole document's difference is 100. A quarter of it is 25,
         | and the mistake that looks right - realizing the document's
         | difference on every allocation - would post 100 twice.
         */
        foreach (['250.0000', '250.0000'] as $slice) {
            $result = app(Allocations::class)->allocate(
                (string) $payment['id'],
                'invoice',
                (string) $invoice->getKey(),
                Money::of($slice, 'EUR'),
                $this->actorId(),
                on: $this->date->copy(),
            );

            $row = DB::connection('tenant')->table('allocations')->where('id', (string) $result['id'])->first();

            if ($row === null || ! BigDecimal::of((string) $row->realized_fx)->isEqualTo('25.0000')) {
                $failures[] = sprintf(
                    'a quarter of a 1,000 EUR invoice realized %s where the proportional share is 25',
                    (string) ($row->realized_fx ?? 'nothing'),
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function valueDateRate(): array
    {
        /*
         | Two rates: one for the value date, a different one for later.
         |
         | Using today's would make the same receipt worth a different
         | figure depending on when somebody got round to entering it,
         | which is a figure nobody can defend.
         */
        $this->seedTheRates([
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => $this->date->toDateString(), 'value' => '4.0000000000'],
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => $this->date->copy()->addDays(5)->toDateString(), 'value' => '4.5000000000'],
        ]);

        $account = $this->aForeignAccount('EUR');

        $payment = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('100.0000', 'EUR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $account->getKey(),
        );

        $row = DB::connection('tenant')->table('payments')->where('id', (string) $payment['id'])->first();

        if ($row === null || $row->fx_rate === null) {
            return ['a foreign-currency payment recorded no rate, so nothing can be measured against it'];
        }

        return BigDecimal::of((string) $row->fx_rate)->isEqualTo('4.0000000000')
            ? []
            : [sprintf(
                'the payment was posted at %s rather than the %s on its value date',
                (string) $row->fx_rate,
                '4.0000000000',
            )];
    }

    /** @return list<string> */
    private function ratesAreRecorded(): array
    {
        $this->seedTheRates([
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => '2026-05-01', 'value' => '3.9000000000'],
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => $this->date->toDateString(), 'value' => '4.0000000000'],
        ]);

        $invoice = $this->aForeignInvoice('EUR', '500.0000', '3.9000000000');
        $account = $this->aForeignAccount('EUR');

        $payment = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('500.0000', 'EUR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $account->getKey(),
            partyId: (string) $invoice->party_id,
        );

        $result = app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of('500.0000', 'EUR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $row = DB::connection('tenant')->table('allocations')->where('id', (string) $result['id'])->first();

        $failures = [];

        /*
         | Both rates, not one.
         |
         | "We used 4.00" cannot be checked. "Booked at 3.90, settled at
         | 4.00, so the difference on 500 is 50" is arithmetic an auditor
         | re-performs in a line.
         */
        if ($row === null) {
            return ['the allocation was not recorded'];
        }

        if ($row->document_fx_rate === null || ! BigDecimal::of((string) $row->document_fx_rate)->isEqualTo('3.9000000000')) {
            $failures[] = sprintf('the rate the document was booked at is recorded as %s', (string) ($row->document_fx_rate ?? 'nothing'));
        }

        if ($row->payment_fx_rate === null || ! BigDecimal::of((string) $row->payment_fx_rate)->isEqualTo('4.0000000000')) {
            $failures[] = sprintf('the rate the money arrived at is recorded as %s', (string) ($row->payment_fx_rate ?? 'nothing'));
        }

        if ($row->fx_rate_date === null) {
            $failures[] = 'the allocation does not say which day the settlement rate came from';
        }

        return $failures;
    }

    /** @return list<string> */
    private function foreignReceiptIsTranslated(): array
    {
        $this->seedTheRates([
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => $this->date->toDateString(), 'value' => '4.0000000000'],
        ]);

        $account = $this->aForeignAccount('EUR');

        $payment = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('100.0000', 'EUR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $account->getKey(),
        );

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $payment['journal_entry_id'])
            ->get();

        $failures = [];

        /*
         | The payment's own entry, which nothing else was looking at.
         |
         | The allocation checks read the allocation entry, so a payment
         | rule that stopped translating would post 100 SAR for 100 EUR,
         | balance perfectly, and leave every one of them passing. The
         | cash account would be out by three quarters and the first sign
         | would be a bank reconciliation.
         */
        /*
         | The account's own GL id, read from the account.
         |
         | Not by asking for "a second bank GL account" again: that helper
         | mints a fresh one once the previous is attached, so the check
         | would be looking for a line on an account nothing posted to.
         */
        $onBank = $lines->firstWhere('account_id', (string) $account->gl_account_id);

        if ($onBank === null) {
            return ['the receipt did not reach the account it was paid into'];
        }

        if (! BigDecimal::of((string) $onBank->functional_amount)->isEqualTo('400.0000')) {
            $failures[] = sprintf(
                '100 EUR at 4.00 posted %s to the cash account rather than 400',
                (string) $onBank->functional_amount,
            );
        }

        if ((string) $onBank->currency !== 'EUR') {
            $failures[] = sprintf(
                'the line is denominated in %s, so what the bank actually holds is not on it',
                (string) $onBank->currency,
            );
        }

        if ($onBank->fx_rate === null) {
            $failures[] = 'the line carries no rate, so the figure cannot be traced back to what produced it';
        }

        return $failures;
    }

    /** @return list<string> */
    private function realizedIsNotRevaluation(): array
    {
        $this->seedTheRates([
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => '2026-05-01', 'value' => '3.9000000000'],
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => $this->date->toDateString(), 'value' => '4.0000000000'],
        ]);

        $invoice = $this->aForeignInvoice('EUR', '1000.0000', '3.9000000000');
        $account = $this->aForeignAccount('EUR');

        $payment = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('1000.0000', 'EUR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $account->getKey(),
            partyId: (string) $invoice->party_id,
        );

        $result = app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of('1000.0000', 'EUR'),
            $this->actorId(),
            on: $this->date->copy(),
        );

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $result['journal_entry_id'])
            ->get();

        $failures = [];

        /*
         | Realized goes to fx_gain_loss; revaluation has its own account.
         |
         | The money arrived, so this difference is settled and will not
         | move again. What the revaluation account holds is still open
         | and still moving, and a report that added the two would be
         | counting the same exposure twice - once as settled and once as
         | outstanding.
         */
        if ($lines->firstWhere('account_id', $this->accountFor(ControlRole::FxRevaluation)) !== null) {
            $failures[] = 'a realized difference reached the revaluation account, where it would be added to exposures that are still open';
        }

        if ($lines->firstWhere('account_id', $this->accountFor(ControlRole::FxGainLoss)) === null) {
            $failures[] = 'a realized difference did not reach fx_gain_loss';
        }

        return $failures;
    }

    /** @return list<string> */
    private function allocatingWithoutARate(): array
    {
        $this->seedTheRates([
            ['from' => 'EUR', 'to' => 'SAR', 'as_of' => $this->date->toDateString(), 'value' => '4.0000000000'],
        ]);

        $invoice = $this->aForeignInvoice('EUR', '100.0000', null);
        $account = $this->aForeignAccount('EUR');

        $payment = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of('100.0000', 'EUR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $account->getKey(),
            partyId: (string) $invoice->party_id,
        );

        $refusal = $this->refusal(fn () => app(Allocations::class)->allocate(
            (string) $payment['id'],
            'invoice',
            (string) $invoice->getKey(),
            Money::of('100.0000', 'EUR'),
            $this->actorId(),
            on: $this->date->copy(),
        ));

        if ($refusal === null) {
            return ['a document booked without a rate was settled anyway, so the realized figure came from nowhere'];
        }

        return str_contains($refusal, 'booked without a rate')
            ? []
            : [sprintf('the refusal does not say what is missing: %s', $refusal)];
    }

    // ---- FX fixture helpers -----------------------------------------------

    /**
     * Let the entity transact in a currency, which it must before it posts.
     *
     * The ledger refuses a posting in a currency nobody enabled, rather
     * than converting at a rate nobody chose - which is right, and means
     * an FX fixture has to say which currencies the business deals in
     * before it can pretend to receive one.
     */
    private function enableTheCurrency(string $currency): void
    {
        DB::connection('tenant')->table('entity_currencies')->insertOrIgnore([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'currency' => $currency,
            'is_functional' => false,
            'enabled' => true,
            'presentation_exponent' => 2,
        ]);
    }

    /** @param  list<array<string, string>>  $rates */
    private function seedTheRates(array $rates): void
    {
        foreach ($rates as $rate) {
            $this->enableTheCurrency((string) $rate['from']);
        }

        foreach ($rates as $rate) {
            app(\App\Tenant\Catalog\Services\ExchangeRates::class)->record(
                (string) $rate['from'],
                (string) $rate['to'],
                Carbon::parse((string) $rate['as_of']),
                (string) $rate['value'],
            );
        }
    }

    /**
     * An issued invoice in a foreign currency, with the rate it was booked at.
     *
     * Written directly rather than through the issuer, because the issuer
     * posts - and what is being checked here is the settlement entry, not
     * the issue one. The columns the allocation reads are the ones that
     * matter: gross, currency and the rate.
     */
    private function aForeignInvoice(string $currency, string $gross, ?string $rate): object
    {
        $invoice = \App\Tenant\Sales\Models\Invoice::create([
            'entity_id' => $this->entityId,
            'series_key' => 'invoice',
            'document_type' => '380',
            'status' => 'issued',
            'party_id' => (string) $this->party?->getKey(),
            'issue_date' => '2026-05-01',
            'tax_point' => '2026-05-01',
            'currency' => $currency,
            'net_amount' => $gross,
            'tax_amount' => '0.0000',
            'gross_amount' => $gross,
            'payable_amount' => $gross,
            'presentation_exponent' => 2,
            'number' => 'FX-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
            'fx_rate' => $rate,
            'fx_rate_date' => $rate === null ? null : '2026-05-01',
            'fx_source' => $rate === null ? null : 'manual',
            /*
             | An issued invoice carries its snapshots, and the database
             | says so.
             |
             | The constraint is right: a document somebody sent is a
             | record of who the parties were and what was decided about
             | tax at the time, and one without them cannot be defended
             | later. The fixture satisfies it rather than working round
             | it - a check standing on a state the system refuses is a
             | check standing on nothing.
             */
            'issued_at' => '2026-05-01 09:00:00',
            'supplier_snapshot' => ['legal_name' => 'Peakflow Trading Co.', 'country' => 'SA'],
            'customer_snapshot' => ['legal_name' => 'Acme Industrial LLC', 'country' => 'SA'],
            'tax_decision_snapshot' => ['treatment' => 'out_of_scope', 'why' => 'a settlement fixture, where the tax decision is not what is being checked'],
        ]);

        return $invoice;
    }

    private function aForeignAccount(string $currency): \App\Tenant\Banking\Models\BankAccount
    {
        return $this->openAccount(
            openingBalance: '0.0000',
            iban: self::DE_IBAN,
            glAccountId: $this->secondBankGlAccountId(),
            institutionCountry: 'DE',
            currency: $currency,
            bic: 'DEUTDEFF',
            label: $currency.' account',
        );
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
