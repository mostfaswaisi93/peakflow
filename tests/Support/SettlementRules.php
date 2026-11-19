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
use App\Tenant\Settlement\Services\DuplicatePaymentDetector;
use App\Tenant\Settlement\Services\PaymentRuns;
use App\Tenant\Settlement\Services\Statements;
use App\Tenant\Settlement\Services\Payments;
use App\Tenant\Settlement\Services\Refunds;
use App\Tenant\Settlement\Services\ReleaseReview;
use App\Tenant\Settlement\Services\UnappliedCash;
use App\Tenant\Settlement\Services\ExpectedCreditLoss;
use App\Tenant\Settlement\Services\WriteOffs;
use App\Tenant\Parties\Models\Party;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    use \Tests\Support\Concerns\PreparesPaymentRuns;
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
                'allocating to a bill closes the payable and washes the clearing' => fn (): array => $this->allocationClosesThePayable(),
                'a paid bill leaves the payables ageing' => fn (): array => $this->paidBillLeavesTheAgeing(),
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

                // ---- Expected credit loss - PF-173 ---------------------
                'the matrix is data, per bucket and per grade' => fn (): array => $this->matrixIsData(),
                'a rate outside nought to one is refused' => fn (): array => $this->rateIsAProportion(),
                'the provision is the matrix applied to the ageing' => fn (): array => $this->provisionIsComputed(),
                'ageing runs from the due date the document froze' => fn (): array => $this->ageingFromTheDueDate(),
                'a graded customer falls back to the ungraded rate' => fn (): array => $this->gradeFallsBack(),
                'each run reverses the one before it in full' => fn (): array => $this->runReversesTheLast(),
                'the same period cannot be computed twice' => fn (): array => $this->periodComputedOnce(),
                'a provision with no matrix is refused' => fn (): array => $this->noMatrix(),
                'a write-off releases the provision it used up' => fn (): array => $this->writeOffReleasesProvision(),
                'a provision and a write-off live in different accounts' => fn (): array => $this->estimateAndConclusionAreApart(),
                'the roll-forward ties, and names all five figures' => fn (): array => $this->rollForwardTies(),
                'a shrinking provision is a release, not a negative charge' => fn (): array => $this->shrinkingIsARelease(),

                // ---- The payment run - PF-174 --------------------------
                'a run moves draft to prepared to released, and nowhere else' => fn (): array => $this->runMovesLawfully(),
                'a released run is frozen, header and lines alike' => fn (): array => $this->releasedIsFrozen(),
                'a prepared run names who prepared it, a released one both' => fn (): array => $this->runsAreAttributable(),
                'a line\'s own figures agree with each other' => fn (): array => $this->linesAreCoherent(),
                'only approved payables are selected' => fn (): array => $this->selectionTakesApprovedOnly(),
                'a settled payable is not paid again' => fn (): array => $this->settledPayablesAreNotPaidAgain(),
                'a payable in a live run is not selected into a second' => fn (): array => $this->aPayableIsNotRunTwice(),
                'verified and needing verification are one fact, not two' => fn (): array => $this->verificationStateIsCoherent(),
                'a discount inside its window is taken' => fn (): array => $this->discountInsideTheWindow(),
                'a discount whose window closed is not taken' => fn (): array => $this->discountOutsideTheWindow(),
                'a discount pulls a bill that is not yet due into the run' => fn (): array => $this->discountPullsABillForward(),
                'a preview shows the cash impact and writes nothing' => fn (): array => $this->previewWritesNothing(),
                'several bills to one supplier become one transfer' => fn (): array => $this->grouping(),
                'a supplier who reconciles per invoice gets one each' => fn (): array => $this->groupingIsTheBeneficiarysCall(),
                'a limit splits the instruction, and the split says why' => fn (): array => $this->splittingAtALimit(),
                'a supplier with nowhere to pay is reported, not dropped' => fn (): array => $this->noVerifiedAccountIsReported(),
                'details changed and never re-verified take them out' => fn (): array => $this->unverifiedDetailsTakeThemOut(),
                'whoever prepared a run does not release it' => fn (): array => $this->preparerDoesNotRelease(),
                'a release by nobody is refused, not waved through' => fn (): array => $this->aReleaseByNobodyIsRefused(),
                'a run with nothing to pay is not prepared' => fn (): array => $this->emptyRunIsRefused(),
                'the stored totals are the ones the instructions carry' => fn (): array => $this->totalsTie(),
                'what was prepared is what is released' => fn (): array => $this->preparationIsFrozen(),
                'withdrawing a preparation needs a reason, and blocks release' => fn (): array => $this->unprepare(),
                'the duty pair over outgoing funds now fires' => fn (): array => $this->paymentRunSod(),

                // ---- Maker-checker and step-up - PF-175 ----------------
                'a stolen session is not enough to send money' => fn (): array => $this->releaseNeedsAFreshFactor(),
                'the audit trail enforces the duty, not the column' => fn (): array => $this->theTrailEnforcesTheDuty(),
                'whoever changed the bank details does not release it' => fn (): array => $this->theBeneficiaryPairFires(),
                'a beneficiary whose details changed blocks release' => fn (): array => $this->changedBeneficiaryBlocksRelease(),
                'changed and re-verified is still a change' => fn (): array => $this->reVerifiedIsStillAChange(),
                'a withdrawn account and a withdrawn approval block too' => fn (): array => $this->theOtherChangesBlockRelease(),
                'a payable that shrank under the run blocks release' => fn (): array => $this->aShrunkPayableBlocksRelease(),
                'a run nothing happened to still goes' => fn (): array => $this->anUnchangedRunReleases(),
                'the release records the hash of what it authorised' => fn (): array => $this->releaseIsHashed(),
                'the hash is stable, and covers the instruction' => fn (): array => $this->theHashIsStable(),
                'the duty pair cannot be overridden at all' => fn (): array => $this->thePairCannotBeOverridden(),
                'the screen answers before the button is pressed' => fn (): array => $this->theScreenAsksFirst(),

                // ---- The property that matters -------------------------
                // ---- Duplicate payments - PF-180 -----------------------
                'a second run paying the same party the same amount is flagged' => fn (): array => $this->duplicatesInTheWindow(),
                'and so is one in ANOTHER entity of the tenant' => fn (): array => $this->duplicatesAcrossEntities(),
                'and one that has already gone out' => fn (): array => $this->duplicatesAgainstPayments(),
                'a run with an unaccepted warning cannot be released' => fn (): array => $this->warningsBlockRelease(),
                'accepting one needs a name and a sentence, once' => fn (): array => $this->acceptanceIsAttributable(),
                'outside the window, nothing is flagged' => fn (): array => $this->theWindowBounds(),

                // ---- Statements - PF-181 ------------------------------
                'an open-item statement lists what is not settled' => fn (): array => $this->openItemsList(),
                'unapplied cash is labelled, never netted silently' => fn (): array => $this->cashIsLabelled(),
                'a disputed document stays on the statement, labelled' => fn (): array => $this->disputesAreLabelled(),
                'balance-forward opens, moves and closes, and the column adds up' => fn (): array => $this->balanceForwardAddsUp(),
                'the statement says whether it reconciles to the control account' => fn (): array => $this->statementsSayIfTheyReconcile(),
                'and says so when it does NOT' => fn (): array => $this->aBreakIsReported(),
                'a supplier statement reads the other side' => fn (): array => $this->supplierStatements(),

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

    /**
     * Paying a supplier must REDUCE what we owe - PF-281.
     *
     * ## The twin that was missing
     *
     * `allocationMovesTheBalance()` above covers a customer receipt. There
     * was no equivalent for a bill, and `AllocationPostedRule` treated the
     * payable case as "same shape, other control account" - swapping the
     * role and keeping the signs.
     *
     * So paying a supplier CREDITED accounts payable. The entry balanced,
     * every row was individually valid, and the company appeared to owe
     * more after paying. In the showcase fixture it left 524,860 in a
     * clearing account that must wash to zero - twice the 262,430 paid,
     * once for the sign on each leg.
     *
     * ## Two assertions, because one of them is weaker than it looks
     *
     * The control account moving the right way is the obvious one. The
     * clearing position returning to where it started is the one that
     * cannot be satisfied by accident: a disbursement debits it and the
     * allocation must credit it back, so any sign error on either leg
     * leaves a residue - in an account whose whole purpose is to be empty
     * once every payment has been told which document it was for.
     *
     * @return list<string>
     */
    private function allocationClosesThePayable(): array
    {
        $supplier = $this->aPayableSupplier();
        $billId = $this->anApprovedBill($supplier, '1000.0000', '2026-06-01');

        $payableBefore = $this->balanceOf(ControlRole::AccountsPayable);
        $clearingBefore = $this->balanceOf(ControlRole::UnappliedCash);

        // A disbursement and then an allocation, which is how a bill is paid.
        $this->settle($billId, '1000.0000');

        $payableAfter = $this->balanceOf(ControlRole::AccountsPayable);
        $clearingAfter = $this->balanceOf(ControlRole::UnappliedCash);

        $failures = [];

        $payableMovement = $payableAfter->minus($payableBefore)->amount;

        if (! $payableMovement->isEqualTo('1000.0000')) {
            $failures[] = sprintf(
                'paying 1000 moved the payable control by %s rather than +1000 (%s to %s). A payable is '.
                'a credit balance, so settling it is a DEBIT: a negative movement here means the '.
                'company is recorded as owing more after paying',
                (string) $payableMovement,
                (string) $payableBefore->amount,
                (string) $payableAfter->amount,
            );
        }

        $clearingMovement = $clearingAfter->minus($clearingBefore)->amount;

        if (! $clearingMovement->isZero()) {
            $failures[] = sprintf(
                'the clearing position kept %s of a payment that had been fully allocated (%s to %s). '.
                'A disbursement debits it and the allocation credits it back, so a residue means one '.
                'of the two legs has the wrong sign - and this account exists to be empty once every '.
                'payment has been told which document it belongs to',
                (string) $clearingMovement,
                (string) $clearingBefore->amount,
                (string) $clearingAfter->amount,
            );
        }

        return $failures;
    }

    /**
     * A bill that has been paid stops being owed - PF-281.
     *
     * ## Why this check is in the settlement suite
     *
     * Because paying is what it needs, and only a payment tells these two
     * definitions of "open" apart.
     *
     * `peakflow_document_open_balance` counts allocations.
     * `Bill::outstanding()` counts `bills.paid_amount`, a column **nothing
     * writes** - Settlement records an allocation in its own table and may
     * not reach into Purchasing's models to denormalise it. Both count
     * credit notes, so a credited bill looks settled under either and
     * proves nothing; a PAID bill is zero under one and fully open under
     * the other.
     *
     * `PayablesAging` asked the model, so every posted bill aged at its
     * full gross for ever. The showcase fixture paid 41 of 56 bills and
     * the report still showed all 56 in full - 367,683.75 against a
     * control account of 105,253.75.
     *
     * `BillListController` had already written down what that costs: the
     * screens read the function, the ageing read the model, so "which
     * bills are still open" had two answers and both looked right. A
     * payment run selecting from the wrong one pays a supplier twice.
     *
     * @return list<string>
     */
    private function paidBillLeavesTheAgeing(): array
    {
        $supplier = $this->aPayableSupplier();
        $billId = $this->anApprovedBill($supplier, '1000.0000', '2026-06-01');

        $before = $this->payablesTotalFor($supplier);

        $this->settle($billId, '1000.0000');

        $after = $this->payablesTotalFor($supplier);

        $movement = $before->minus($after);

        if (! $movement->isEqualTo('1000.0000')) {
            return [sprintf(
                'paying a 1,000 bill in full moved the payables ageing by %s rather than 1,000 (%s to '.
                '%s). A bill that has been paid is still being reported as owed, which is the figure a '.
                'payment run selects from',
                (string) $movement,
                (string) $before,
                (string) $after,
            )];
        }

        return [];
    }

    /**
     * This supplier's total in the payables ageing.
     *
     * Filtered to the one supplier, because the suite's tenant carries
     * whatever other checks in this savepoint have entered, and a total
     * over all of them would move for reasons this check is not about.
     */
    private function payablesTotalFor(Party $supplier): BigDecimal
    {
        $report = app(\App\Tenant\Purchasing\Services\PayablesAging::class)->report(
            $this->entityId,
            Carbon::parse('2026-06-30'),
            null,
            (string) $supplier->getKey(),
        );

        $total = BigDecimal::zero();

        foreach ($report['rows'] as $row) {
            $total = $total->plus(BigDecimal::of((string) $row['outstanding']));
        }

        return $total;
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

    // ---- Expected credit loss ---------------------------------------------

    /** @return list<string> */
    private function matrixIsData(): array
    {
        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.005', $this->actorId(), 'three years of loss history');
        app(ExpectedCreditLoss::class)->setRate($this->entityId, 'D', '90+', '0.600', $this->actorId(), 'graded D customers past ninety days');

        $matrix = app(ExpectedCreditLoss::class)->matrix($this->entityId);

        $failures = [];

        if (\count($matrix) !== 2) {
            $failures[] = sprintf('two rates were set and the matrix holds %d', \count($matrix));
        }

        $ungraded = null;
        $graded = null;

        foreach ($matrix as $row) {
            if ($row['risk_grade'] === null && $row['bucket'] === 'current') {
                $ungraded = $row;
            }

            if ($row['risk_grade'] === 'D' && $row['bucket'] === '90+') {
                $graded = $row;
            }
        }

        if ($ungraded === null) {
            $failures[] = 'the ungraded row is missing, and most customers in most businesses are ungraded - a matrix without it provisions nothing for the majority of the ledger';
        }

        if ($graded === null) {
            $failures[] = 'the per-grade row is missing, so the matrix cannot say that a D customer past ninety days is worse than an A one';
        }

        /*
         | The basis is kept beside the rate.
         |
         | A rate with no basis is a number somebody picked, and the
         | question an auditor asks about a provision matrix is where the
         | rates came from.
         */
        if (($ungraded['basis'] ?? null) === null) {
            $failures[] = 'the rate carries no basis, so where it came from is not recorded';
        }

        $bad = $this->refusal(fn () => app(ExpectedCreditLoss::class)->setRate(
            $this->entityId,
            null,
            'eventually',
            '0.100',
            $this->actorId(),
        ));

        if ($bad === null) {
            $failures[] = 'a rate was set against a bucket that does not exist, so it would never be applied to anything';
        } elseif (! str_contains($bad, 'fixed rather than configurable')) {
            /*
             | The service's refusal, not the column's.
             |
             | The table refuses an unknown bucket too, which is why it is
             | there. A person setting a matrix should be told which
             | buckets exist and why they are not theirs to choose, not
             | handed the name of a check constraint.
             */
            $failures[] = sprintf('the refusal came from the table rather than from setting the rate: %s', $bad);
        }

        return $failures;
    }

    /** @return list<string> */
    private function rateIsAProportion(): array
    {
        $failures = [];

        foreach (['-0.100', '1.500'] as $rate) {
            $refusal = $this->refusal(fn () => app(ExpectedCreditLoss::class)->setRate(
                $this->entityId,
                null,
                'current',
                $rate,
                $this->actorId(),
            ));

            if ($refusal === null) {
                $failures[] = sprintf(
                    'a loss rate of %s was accepted - above 1 provisions more than is owed, which '.
                    'puts a negative receivable on the balance sheet',
                    $rate,
                );
            } elseif (! str_contains($refusal, 'negative receivable')) {
                $failures[] = sprintf(
                    'the refusal for %s came from the table rather than from setting the rate: %s',
                    $rate,
                    $refusal,
                );
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function provisionIsComputed(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);

        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.100', $this->actorId());

        $result = app(ExpectedCreditLoss::class)->recompute(
            $this->entityId,
            '2026-05',
            $this->date->copy(),
            $this->actorId(),
        );

        $expected = $gross->multipliedBy('0.100')->toScale(4, \Brick\Math\RoundingMode::HalfUp);

        $failures = [];

        if (! $result['provision']->amount->isEqualTo($expected)) {
            $failures[] = sprintf(
                'a %s invoice at a 10%% rate provisioned %s rather than %s',
                (string) $gross,
                (string) $result['provision']->amount,
                (string) $expected,
            );
        }

        /*
         | And the line behind it, with the inputs.
         |
         | A provision total nobody can decompose is a number somebody has
         | to defend by re-deriving it, and by then the rates may have
         | moved.
         */
        $line = DB::connection('tenant')->table('ecl_provision_lines')
            ->where('run_id', (string) $result['id'])
            ->where('document_id', (string) $invoice->getKey())
            ->first();

        if ($line === null) {
            return [...$failures, 'the run recorded no line for the invoice, so the provision cannot be decomposed'];
        }

        foreach (['bucket', 'rate', 'open_balance', 'provision'] as $column) {
            if (($line->{$column} ?? null) === null) {
                $failures[] = sprintf('the line carries no %s', $column);
            }
        }

        $onProvision = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $result['journal_entry_id'])
            ->where('account_id', $this->accountFor(ControlRole::EclProvision))
            ->value('functional_amount');

        if ($onProvision === null) {
            $failures[] = 'nothing was posted to the provision account';
        } elseif (! BigDecimal::of((string) $onProvision)->isEqualTo($expected->negated())) {
            $failures[] = sprintf(
                'the provision account moved by %s, which is not a credit of %s - a provision is a '.
                'contra-asset and a debit there would increase what the ledger claims is owed',
                (string) $onProvision,
                (string) $expected,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function ageingFromTheDueDate(): array
    {
        /*
         | Issued six weeks ago, on terms that fall due in another month.
         |
         | Ageing from the issue date would put it in `1-30`; ageing from
         | the due date it froze puts it in `current`. That is the whole
         | point - a customer on ninety-day terms is not overdue on day
         | thirty-one - and the two buckets carry different rates here so
         | the wrong clock is visible rather than merely wrong.
         |
         | Created issued, with its terms, rather than issued and then
         | edited: an issued invoice cannot be changed, and the database
         | is right about that.
         */
        $invoice = $this->anIssuedInvoice(
            'SAR',
            '1000.0000',
            issuedOn: $this->date->copy()->subDays(42)->toDateString(),
            dueOn: $this->date->copy()->addDays(30)->toDateString(),
        );

        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.010', $this->actorId());
        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, '1-30', '0.200', $this->actorId());

        $result = app(ExpectedCreditLoss::class)->recompute(
            $this->entityId,
            '2026-05',
            $this->date->copy(),
            $this->actorId(),
        );

        $line = DB::connection('tenant')->table('ecl_provision_lines')
            ->where('run_id', (string) $result['id'])
            ->where('document_id', (string) $invoice->getKey())
            ->first();

        if ($line === null) {
            return ['nothing was provisioned for the invoice'];
        }

        return (string) $line->bucket === 'current'
            ? []
            : [sprintf(
                'an invoice due in thirty days landed in the %s bucket, so the ageing is running '.
                'from the issue date - which provisions the patient customers and excuses the late '.
                'ones',
                (string) $line->bucket,
            )];
    }

    /** @return list<string> */
    private function gradeFallsBack(): array
    {
        $invoice = $this->issue();

        DB::connection('tenant')->table('parties')
            ->where('id', (string) $invoice->party_id)
            ->update(['risk_grade' => 'B']);

        /*
         | A rate for the bucket but not for the grade.
         |
         | The graded customer falls back to the ungraded rate rather than
         | to zero: being graded is not a reason to provision less for
         | somebody.
         */
        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.050', $this->actorId());

        $result = app(ExpectedCreditLoss::class)->recompute(
            $this->entityId,
            '2026-05',
            $this->date->copy(),
            $this->actorId(),
        );

        $line = DB::connection('tenant')->table('ecl_provision_lines')
            ->where('run_id', (string) $result['id'])
            ->where('document_id', (string) $invoice->getKey())
            ->first();

        if ($line === null) {
            return ['a graded customer with no rate for their grade was provisioned at nothing, which is a rate nobody set reported as though they had'];
        }

        return BigDecimal::of((string) $line->rate)->isEqualTo('0.050000')
            ? []
            : [sprintf('the fallback rate was %s rather than the ungraded 0.05', (string) $line->rate)];
    }

    /** @return list<string> */
    private function runReversesTheLast(): array
    {
        /*
         | Due well after both runs, so the bucket does not move.
         |
         | Otherwise the second run would age the invoice into a bucket
         | the matrix has no rate for and provision nothing - and the
         | check would pass or fail for a reason that has nothing to do
         | with whether the first run was reversed.
         */
        $invoice = $this->anIssuedInvoice(
            'SAR',
            '1150.0000',
            issuedOn: $this->date->toDateString(),
            dueOn: $this->date->copy()->addDays(120)->toDateString(),
        );

        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.100', $this->actorId());

        $first = app(ExpectedCreditLoss::class)->recompute(
            $this->entityId,
            '2026-05',
            $this->date->copy(),
            $this->actorId(),
        );

        /*
         | The rate doubles, and the next run recomputes rather than tops up.
         |
         | Accumulating movements leaves a residue after every rate change,
         | and four quarters later the provision is the sum of four
         | approximations nobody can take apart.
         */
        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.200', $this->actorId());

        $this->openThePeriodFor($this->date->copy()->addDays(14)->toDateString());

        $second = app(ExpectedCreditLoss::class)->recompute(
            $this->entityId,
            '2026-06',
            $this->date->copy()->addDays(14),
            $this->actorId(),
        );

        $failures = [];

        if (($second['reversal_entry_id'] ?? null) === null) {
            $failures[] = 'the second run posted no reversal, so the provision is accumulating rather than being recomputed';
        }

        $expected = BigDecimal::of((string) $invoice->gross_amount)
            ->multipliedBy('0.200')
            ->toScale(4, \Brick\Math\RoundingMode::HalfUp);

        if (! $second['provision']->amount->isEqualTo($expected)) {
            $failures[] = sprintf(
                'the second run provisioned %s where the matrix at 20%% gives %s - a run that '.
                'added to the first would give the sum of both',
                (string) $second['provision']->amount,
                (string) $expected,
            );
        }

        /*
         | And the account holds the new figure, not the sum.
         |
         | This is the assertion that actually catches accumulation: the
         | run's own total can be right while the ledger carries both
         | entries.
         */
        $balance = $this->balanceOf(ControlRole::EclProvision);

        if (! $balance->amount->isEqualTo($expected->negated())) {
            $failures[] = sprintf(
                'the provision account holds %s after two runs, where the current estimate is %s - '.
                'so the reversal did not happen or did not cover the first run in full',
                (string) $balance->amount,
                (string) $expected->negated(),
            );
        }

        if (($first['id'] ?? null) === null || ($second['id'] ?? null) === null) {
            $failures[] = 'a run was not recorded';
        }

        return $failures;
    }

    /** @return list<string> */
    private function periodComputedOnce(): array
    {
        $this->issue();
        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.100', $this->actorId());

        app(ExpectedCreditLoss::class)->recompute($this->entityId, '2026-05', $this->date->copy(), $this->actorId());

        $refusal = $this->refusal(fn () => app(ExpectedCreditLoss::class)->recompute(
            $this->entityId,
            '2026-05',
            $this->date->copy(),
            $this->actorId(),
        ));

        if ($refusal === null) {
            return ['one period carries two provisions, so nothing says which is its figure'];
        }

        /*
         | The service's refusal, not the unique index's.
         |
         | The index refuses it too. A person recomputing a period should
         | be told when it was first computed and what to do instead -
         | correct the matrix and compute the period after it - rather
         | than being handed a constraint name.
         */
        return str_contains($refusal, 'already computed')
            ? []
            : [sprintf('the refusal came from the index rather than from recomputing: %s', $refusal)];
    }

    /** @return list<string> */
    private function noMatrix(): array
    {
        $this->issue();

        $refusal = $this->refusal(fn () => app(ExpectedCreditLoss::class)->recompute(
            $this->entityId,
            '2026-05',
            $this->date->copy(),
            $this->actorId(),
        ));

        if ($refusal === null) {
            return ['a provision of nothing was computed with no matrix at all, which reports a ledger with no expected losses rather than one nobody has estimated'];
        }

        return str_contains($refusal, 'statement rather than an absence')
            ? []
            : [sprintf('the refusal does not say why provisioning nothing is worse than refusing: %s', $refusal)];
    }

    /** @return list<string> */
    private function writeOffReleasesProvision(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);

        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.400', $this->actorId());

        app(ExpectedCreditLoss::class)->recompute($this->entityId, '2026-05', $this->date->copy(), $this->actorId());

        $provisioned = $gross->multipliedBy('0.400')->toScale(4, \Brick\Math\RoundingMode::HalfUp);
        $before = $this->balanceOf(ControlRole::EclProvision);

        $result = $this->writeOff($invoice, (string) $gross);

        $failures = [];

        /*
         | The provision is used up, not left standing.
         |
         | Without this the period carries both the estimate and the
         | conclusion: bad debt takes the full amount while the provision
         | still claims part of it is coming, and the impairment line
         | reads as twice the loss that happened.
         */
        if (($result['provision_released'] ?? null) === null) {
            return ['writing off a provisioned invoice released nothing, so the period double-charges the loss'];
        }

        if (! $result['provision_released']->amount->isEqualTo($provisioned)) {
            $failures[] = sprintf(
                '%s was provisioned for this invoice and %s was released',
                (string) $provisioned,
                (string) $result['provision_released']->amount,
            );
        }

        $after = $this->balanceOf(ControlRole::EclProvision);

        if (! $after->isZero()) {
            $failures[] = sprintf(
                'the provision account holds %s after its only document was written off',
                (string) $after->amount,
            );
        }

        if ($before->isZero()) {
            $failures[] = 'the provision was zero before the write-off, so this check is not reading what it thinks it is';
        }

        return $failures;
    }

    /** @return list<string> */
    private function estimateAndConclusionAreApart(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);

        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.400', $this->actorId());
        app(ExpectedCreditLoss::class)->recompute($this->entityId, '2026-05', $this->date->copy(), $this->actorId());

        $this->writeOff($invoice, (string) $gross);

        $failures = [];

        /*
         | Bad debt carries the conclusion, in full.
         |
         | Netting the release against it would leave the write-off
         | reported at less than it was - and that figure must stay exact,
         | because it is what the matrix is calibrated against.
         */
        $badDebt = $this->balanceOf(ControlRole::BadDebt);

        if (! $badDebt->amount->isEqualTo($gross)) {
            $failures[] = sprintf(
                'bad debt holds %s after a %s write-off, so the release has been netted against '.
                'the conclusion',
                (string) $badDebt->amount,
                (string) $gross,
            );
        }

        /*
         | And the charge account carries the estimate, net of the release.
         |
         | Charged 40%, released the same 40%, so it nets to nothing - and
         | that is right: the estimate was made and then used, and the
         | loss itself is in bad debt.
         */
        $charge = $this->balanceOf(ControlRole::EclCharge);

        if (! $charge->isZero()) {
            $failures[] = sprintf(
                'the impairment charge holds %s after a provision was charged and released in '.
                'full, so the period reports an estimate that has already been concluded',
                (string) $charge->amount,
            );
        }

        if ($this->accountFor(ControlRole::EclCharge) === $this->accountFor(ControlRole::BadDebt)) {
            $failures[] = 'the estimate and the conclusion share one account, so "how much of last year\'s estimate turned out to be real" is unanswerable';
        }

        return $failures;
    }

    /** @return list<string> */
    private function rollForwardTies(): array
    {
        $first = $this->anIssuedInvoice(
            'SAR',
            '1000.0000',
            issuedOn: $this->date->toDateString(),
            dueOn: $this->date->copy()->addDays(120)->toDateString(),
        );

        $second = $this->anIssuedInvoice(
            'SAR',
            '2000.0000',
            issuedOn: $this->date->toDateString(),
            dueOn: $this->date->copy()->addDays(120)->toDateString(),
        );

        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.100', $this->actorId());
        app(ExpectedCreditLoss::class)->recompute($this->entityId, '2026-05', $this->date->copy(), $this->actorId());

        /*
         | One written off, then the next period computed.
         |
         | So the roll-forward has something in every line: an opening
         | figure, a utilization from the write-off, and a movement from
         | the document that is left.
         */
        $this->writeOff($first, (string) $first->gross_amount);

        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.300', $this->actorId());
        $this->openThePeriodFor($this->date->copy()->addDays(14)->toDateString());
        app(ExpectedCreditLoss::class)->recompute(
            $this->entityId,
            '2026-06',
            $this->date->copy()->addDays(14),
            $this->actorId(),
        );

        $roll = app(ExpectedCreditLoss::class)->rollForward($this->entityId, '2026-06');

        $failures = [];

        foreach (['opening', 'charge', 'release', 'utilisation', 'closing'] as $figure) {
            if (! isset($roll[$figure])) {
                $failures[] = sprintf('the roll-forward has no %s figure', $figure);
            }
        }

        /*
         | It ties, which is the only thing that makes it a roll-forward.
         |
         | Five figures that do not reconcile are five figures, and a
         | reader cannot tell which of them is wrong.
         */
        if (($roll['ties'] ?? false) !== true) {
            $failures[] = sprintf(
                'the roll-forward does not tie: %s opening, %s used, %s charged, %s released, %s '.
                'closing',
                (string) $roll['opening']->amount,
                (string) $roll['utilisation']->amount,
                (string) $roll['charge']->amount,
                (string) $roll['release']->amount,
                (string) $roll['closing']->amount,
            );
        }

        if (! $roll['utilisation']->isPositive()) {
            $failures[] = 'the write-off does not appear as utilization, so a provision used up looks like one released';
        }

        if (! $roll['opening']->isPositive()) {
            $failures[] = 'the opening figure is nil, so this check is not reading a second period';
        }

        return $failures;
    }

    /** @return list<string> */
    private function shrinkingIsARelease(): array
    {
        $invoice = $this->anIssuedInvoice(
            'SAR',
            '1000.0000',
            issuedOn: $this->date->toDateString(),
            dueOn: $this->date->copy()->addDays(120)->toDateString(),
        );

        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.300', $this->actorId());
        app(ExpectedCreditLoss::class)->recompute($this->entityId, '2026-05', $this->date->copy(), $this->actorId());

        /*
         | The rate falls, so the provision shrinks.
         |
         | Nothing else in this suite produced a downward movement, so
         | "charge" and "release" were the same figure in every scenario
         | and a report that called the whole movement a charge passed.
         | A negative charge and a release mean opposite things about the
         | ledger, and a reader cannot tell them apart from one number.
         */
        app(ExpectedCreditLoss::class)->setRate($this->entityId, null, 'current', '0.100', $this->actorId());
        $this->openThePeriodFor($this->date->copy()->addDays(14)->toDateString());
        app(ExpectedCreditLoss::class)->recompute(
            $this->entityId,
            '2026-06',
            $this->date->copy()->addDays(14),
            $this->actorId(),
        );

        $roll = app(ExpectedCreditLoss::class)->rollForward($this->entityId, '2026-06');

        $failures = [];

        if (! $roll['release']->isPositive()) {
            $failures[] = sprintf(
                'the provision fell from %s to %s and the release is %s, so a shrinking estimate '.
                'is being reported as a charge of less',
                (string) $roll['opening']->amount,
                (string) $roll['closing']->amount,
                (string) $roll['release']->amount,
            );
        }

        if ($roll['charge']->isPositive()) {
            $failures[] = sprintf(
                'a period whose provision shrank reports a charge of %s',
                (string) $roll['charge']->amount,
            );
        }

        if (($roll['ties'] ?? false) !== true) {
            $failures[] = 'the roll-forward does not tie on a downward movement';
        }

        return $failures;
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
        return $this->anIssuedInvoice($currency, $gross, rate: $rate);
    }

    /**
     * An issued invoice written directly, with the columns a check needs.
     *
     * Through the model rather than the issuer, because the issuer posts -
     * and what these checks read is a later entry. The columns that matter
     * are the ones the settlement path reads: gross, currency, the rate it
     * was booked at, and the due dates its terms froze.
     */
    private function anIssuedInvoice(
        string $currency,
        string $gross,
        ?string $rate = null,
        ?string $issuedOn = null,
        ?string $dueOn = null,
    ): object {
        $issuedOn ??= '2026-05-01';

        $invoice = \App\Tenant\Sales\Models\Invoice::create([
            'entity_id' => $this->entityId,
            'series_key' => 'invoice',
            'document_type' => '380',
            'status' => 'issued',
            'party_id' => (string) $this->party?->getKey(),
            'issue_date' => $issuedOn,
            'tax_point' => $issuedOn,
            'currency' => $currency,
            'net_amount' => $gross,
            'tax_amount' => '0.0000',
            'gross_amount' => $gross,
            'payable_amount' => $gross,
            'presentation_exponent' => 2,
            'number' => 'FX-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
            'fx_rate' => $rate,
            'fx_rate_date' => $rate === null ? null : $issuedOn,
            'fx_source' => $rate === null ? null : 'manual',
            'terms_snapshot' => $dueOn === null
                ? null
                : ['due_dates' => [['date' => $dueOn, 'amount' => $gross]]],
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
            'issued_at' => $issuedOn.' 09:00:00',
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


    // ---- The payment run - PF-174 -----------------------------------------

    /**
     * Three states, and exactly three transitions.
     *
     * @return list<string>
     */
    private function runMovesLawfully(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $failures = [];

        if ($run['state'] !== 'prepared') {
            $failures[] = 'preparing a run did not leave it prepared, so nothing can be released';
        }

        /*
         | The three the state machine allows, and everything else.
         |
         | Straight at the table, because the service is one code path and
         | an import, a retried job or a repaired row is another. A state
         | machine that lives only in the service is a state machine a
         | single UPDATE walks past.
         |
         | Each attempt is its own savepoint, so a refusal rolls back to a
         | prepared run rather than leaving the next attempt to fail with
         | "current transaction is aborted" and the report blaming the
         | wrong transition.
         */
        foreach ([
            ['prepared', 'released', true],
            ['prepared', 'draft', true],
            ['draft', 'prepared', true],
            ['prepared', 'cancelled', false],
            ['prepared', 'sent', false],
            ['draft', 'released', false],
        ] as [$from, $to, $allowed]) {
            $refusal = $this->refusal(function () use ($run, $from, $to): void {
                if ($from !== 'prepared') {
                    DB::connection('tenant')->table('payment_runs')->where('id', $run['id'])->update([
                        'state' => $from,
                        'prepared_by' => null,
                        'prepared_at' => null,
                    ]);
                }

                DB::connection('tenant')->table('payment_runs')->where('id', $run['id'])->update([
                    'state' => $to,
                    /*
                     | Attributable either way, so the state machine is what
                     | is being exercised.
                     |
                     | A run moved to `prepared` with no preparer is refused
                     | by a different constraint, and a check that tripped
                     | that one would report the state machine as broken
                     | when it is the attribution talking.
                     */
                    'prepared_by' => \in_array($to, ['prepared', 'released'], true) ? $this->actorId() : null,
                    'prepared_at' => \in_array($to, ['prepared', 'released'], true) ? $this->date->copy() : null,
                    'released_by' => $to === 'released' ? $this->secondActorId() : null,
                    'released_at' => $to === 'released' ? $this->date->copy() : null,
                ]);
            });

            if ($allowed && $refusal !== null) {
                $failures[] = sprintf(
                    'the database refused %s to %s, which a run is supposed to do: %s',
                    $from,
                    $to,
                    $refusal,
                );
            }

            if (! $allowed && $refusal === null) {
                $failures[] = sprintf(
                    'the database moved a run from %s to %s, which is not one of the three transitions '.
                    'a run has - so an import or a repaired row can put a run in a state nothing in the '.
                    'product knows how to read',
                    $from,
                    $to,
                );
            }

            /*
             | And it is refused in the state machine's own words.
             |
             | A constraint name is not an answer: the person who hits this
             | through an import needs to be told which move was attempted
             | and what the three legal ones are, which is what the trigger
             | says and what a column constraint cannot.
             */
            if (! $allowed && $refusal !== null
                && ! str_contains($refusal, sprintf('cannot go from %s to %s', $from, $to))) {
                $failures[] = sprintf(
                    'moving %s to %s was refused by something that does not name the move it refused: %s',
                    $from,
                    $to,
                    $refusal,
                );
            }
        }

        return $failures;
    }

    /**
     * A released run is frozen, header and lines alike.
     *
     * @return list<string>
     */
    private function releasedIsFrozen(): array
    {
        $run = $this->aReleasedRun();
        $failures = [];

        foreach ([
            [['net_total' => '1.0000'], 'the amount a released run paid'],
            [['pay_on' => '2026-06-01'], 'the date a released run paid on'],
            [['bank_account_id' => (string) $this->bank()->getKey()], 'the account a released run paid from'],
        ] as [$change, $what]) {
            $refusal = $this->refusal(fn () => DB::connection('tenant')
                ->table('payment_runs')
                ->where('id', $run['id'])
                ->update($change));

            if ($refusal === null) {
                $failures[] = sprintf(
                    '%s can be changed after release, so the record can be made to describe a payment '.
                    'the bank never made',
                    $what,
                );
            } elseif (! str_contains($refusal, 'released')) {
                $failures[] = sprintf('changing %s was refused for some other reason: %s', $what, $refusal);
            }
        }

        /*
         | And the lines, which is where the beneficiary lives.
         |
         | A frozen header with editable lines is the worse of the two: the
         | total still reads as approved while the account the money went
         | to has been changed underneath it.
         */
        foreach ([
            ['payment_run_instructions', ['amount' => '1.0000'], 'an instruction'],
            ['payment_run_items', ['net_amount' => '1.0000'], 'an item'],
        ] as [$table, $change, $what]) {
            $id = (string) DB::connection('tenant')->table($table)->where('run_id', $run['id'])->value('id');

            $changed = $this->refusal(fn () => DB::connection('tenant')
                ->table($table)
                ->where('id', $id)
                ->update($change));

            if ($changed === null) {
                $failures[] = sprintf(
                    'changing %s after release was accepted, so the instruction can be rewritten while '.
                    'the total still reads as the one somebody approved',
                    $what,
                );
            } elseif (! str_contains($changed, 'released')) {
                $failures[] = sprintf('changing %s was refused for some other reason: %s', $what, $changed);
            }

            $deleted = $this->refusal(fn () => DB::connection('tenant')
                ->table($table)
                ->where('id', $id)
                ->delete());

            if ($deleted === null) {
                $failures[] = sprintf(
                    '%s of a released run can be deleted, so a payment can be made to disappear from '.
                    'the run that sent it',
                    $what,
                );
            } elseif (! str_contains($deleted, 'released')) {
                $failures[] = sprintf('deleting %s was refused for some other reason: %s', $what, $deleted);
            }
        }

        return $failures;
    }

    /**
     * Only approved payables are selected.
     *
     * @return list<string>
     */
    private function selectionTakesApprovedOnly(): array
    {
        $supplier = $this->aPayableSupplier();
        $approved = $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');
        $awaiting = $this->anApprovedBill($supplier, '7000.0000', '2026-05-18');

        DB::connection('tenant')->table('bills')->where('id', $awaiting)->update([
            'status' => 'awaiting_approval',
            'approved_at' => null,
            'approved_by' => null,
        ]);

        $later = $this->anApprovedBill($supplier, '9000.0000', '2026-06-30');

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $selected = array_column($preview['items'], 'bill_id');
        $failures = [];

        if (! \in_array($approved, $selected, true)) {
            $failures[] = 'an approved payable due before the pay date was not selected, so the run pays nothing';
        }

        if (\in_array($awaiting, $selected, true)) {
            $failures[] = 'a payable nobody has approved was selected for payment, which pays what was never checked - '.
                'and the approval is the whole control on the purchasing side';
        }

        /*
         | And the horizon, which is the pay date unless asked otherwise.
         |
         | A stated default rather than an accident: a run pays what is due
         | by the day it pays. Paying ahead of the due date is an ordinary
         | thing to want and is asked for by name, because a default
         | horizon of a fortnight would move cash out early on every run
         | nobody thought about.
         */
        if (\in_array($later, $selected, true)) {
            $failures[] = 'a payable due at the end of June was selected by a run paying on 19 May with no '.
                'horizon asked for, so cash leaves six weeks before it had to';
        }

        $reaching = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
            ['horizon_days' => 60],
        );

        if (! \in_array($later, array_column($reaching['items'], 'bill_id'), true)) {
            $failures[] = 'asking for a 60-day horizon did not reach a payable due at the end of June, so a '.
                'run cannot be made to cover the period until the next one';
        }

        return $failures;
    }

    /**
     * The invariant the run's one condition leans on.
     *
     * `beneficiaryFor()` excludes an account on `requires_reverification`
     * alone, because the table holds that flag and `verified_at` to a
     * single meaning. That is the table's invariant rather than
     * Settlement's - but the payment run is what would pay a stranger if
     * it stopped holding, so it is asserted beside the code that trusts
     * it rather than left as a comment pointing at a migration.
     *
     * @return list<string>
     */
    private function verificationStateIsCoherent(): array
    {
        $supplier = $this->aPayableSupplier();
        $accountId = (string) DB::connection('tenant')->table('party_bank_accounts')
            ->where('party_id', (string) $supplier->getKey())
            ->value('id');

        $failures = [];

        foreach ([
            [
                ['requires_reverification' => true],
                'an account that needs verifying kept the timestamp that says it was verified',
            ],
            [
                ['requires_reverification' => false, 'verified_at' => null],
                'an account was marked as needing no verification having never been verified, which is '.
                'what somebody clearing the flag by hand to get a payment out of the door would leave',
            ],
        ] as [$change, $what]) {
            $refusal = $this->refusal(fn () => DB::connection('tenant')
                ->table('party_bank_accounts')
                ->where('id', $accountId)
                ->update($change));

            if ($refusal === null) {
                $failures[] = $what.' - so the payment run\'s one condition is not enough and two '.
                    'readers of this row can reach different answers';
            } elseif (! str_contains($refusal, 'verification_state_is_coherent')) {
                $failures[] = 'the incoherent row was refused by something else: '.$refusal;
            }
        }

        return $failures;
    }

    /**
     * A prepared run names who prepared it, and a released one both.
     *
     * "Released by nobody" is the state an import leaves behind, and it
     * is the one state that makes the maker-checker comparison vacuous:
     * there is no preparer to compare the releaser against, so the pair
     * that can never be overridden has nothing to work on.
     *
     * @return list<string>
     */
    private function runsAreAttributable(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $failures = [];

        foreach ([
            [
                ['prepared_by' => null],
                'payment_runs_prepared_is_attributable',
                'a prepared run with no preparer, so the release check has nobody to compare against',
            ],
            [
                ['prepared_at' => null],
                'payment_runs_prepared_is_attributable',
                'a prepared run with no time of preparation, so "what changed since it was prepared" has no clock',
            ],
            [
                ['state' => 'released', 'released_at' => $this->date->copy()],
                'payment_runs_released_is_attributable',
                'a released run with no releaser, which is money out of the door attributable to nobody',
            ],
            [
                ['state' => 'released', 'released_by' => $this->secondActorId()],
                'payment_runs_released_is_attributable',
                'a released run with no time of release, so it cannot be matched to a bank line by date',
            ],
        ] as [$change, $constraint, $what]) {
            $refusal = $this->refusal(fn () => DB::connection('tenant')
                ->table('payment_runs')
                ->where('id', $run['id'])
                ->update($change));

            if ($refusal === null) {
                $failures[] = 'the database accepted '.$what;
            } elseif (! str_contains($refusal, $constraint)) {
                $failures[] = sprintf('%s was refused by something other than %s: %s', $what, $constraint, $refusal);
            }
        }

        return $failures;
    }

    /**
     * A line's own figures agree with each other.
     *
     * The run's total is the sum of its lines, so a line whose net
     * disagrees with its gross and its discount makes the total
     * unexplainable - and the explanation is what somebody releasing it
     * is being asked to approve.
     *
     * @return list<string>
     */
    private function linesAreCoherent(): array
    {
        $supplier = $this->aPayableSupplier(limit: '3000.0000');
        $this->anApprovedBill($supplier, '7000.0000', '2026-06-15', '2.000', '2026-05-25');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $itemId = (string) DB::connection('tenant')->table('payment_run_items')
            ->where('run_id', $run['id'])->value('id');
        /*
         | The last part of the split, so `of = 1` really does contradict
         | it.
         |
         | Part 1 of 3 is still a valid part 1 of 1, and a check that
         | picked whichever row came first would have asserted nothing -
         | which is what the bite sweep reported.
         */
        $instructionId = (string) DB::connection('tenant')->table('payment_run_instructions')
            ->where('run_id', $run['id'])
            ->orderByDesc('sequence')
            ->value('id');

        $failures = [];

        foreach ([
            [
                'payment_run_items', $itemId,
                ['discount_amount' => '50.0000'],
                'payment_run_items_net_is_gross_less_discount',
                'an item whose net no longer equals its gross less its discount',
            ],
            [
                'payment_run_items', $itemId,
                ['discount_amount' => '100.0000', 'net_amount' => '6900.0000', 'discount_percent' => null, 'discount_until' => null],
                'payment_run_items_discounts_name_their_terms',
                'a deduction with no terms behind it, which is money kept from a supplier for no stated reason',
            ],
            [
                'payment_run_items', $itemId,
                ['gross_amount' => '0.0000', 'net_amount' => '0.0000', 'discount_amount' => '0.0000'],
                'payment_run_items_amounts_are_not_negative',
                'an item for nothing, which is a bill the run claims to settle with no money',
            ],
            [
                'payment_run_instructions', $instructionId,
                ['of' => 1],
                'payment_run_instructions_sequence_is_within_its_set',
                'a part 3 of 1, so the supplier cannot tell how many transfers to expect',
            ],
            [
                'payment_run_instructions', $instructionId,
                ['amount' => '0.0000'],
                'payment_run_instructions_amount_is_positive',
                'an instruction for nothing, which is a transfer fee and no payment',
            ],
        ] as [$table, $id, $change, $constraint, $what]) {
            $refusal = $this->refusal(fn () => DB::connection('tenant')
                ->table($table)
                ->where('id', $id)
                ->update($change));

            if ($refusal === null) {
                $failures[] = 'the database accepted '.$what;
            } elseif (! str_contains($refusal, $constraint)) {
                $failures[] = sprintf('%s was refused by something other than %s: %s', $what, $constraint, $refusal);
            }
        }

        /*
         | And a split with no reason, which is the one of these that a
         | reader asks about.
         |
         | A supplier who received three transfers instead of one wants to
         | know why, and `of > 1` with nothing beside it is the run
         | admitting it does not know either.
         */
        $reasonless = $this->refusal(fn () => DB::connection('tenant')
            ->table('payment_run_instructions')
            ->where('id', $instructionId)
            ->update(['split_reason' => null]));

        if ($reasonless === null) {
            $failures[] = 'the database accepted a split instruction with no reason recorded, so a supplier '.
                'who received three transfers asks a question the run cannot answer';
        } elseif (! str_contains($reasonless, 'payment_run_instructions_splits_say_why')) {
            $failures[] = 'a reasonless split was refused by something else: '.$reasonless;
        }

        return $failures;
    }

    /**
     * A payable in a live run is not selected into a second one.
     *
     * A payment run does not reduce a bill's open balance - the payment
     * does, and that is PF-176's business. So without this, a bill
     * selected into Monday's released run is selected again by Tuesday's,
     * both can be released, and the supplier is paid twice with the
     * second debit looking as ordinary as the first.
     *
     * Found by a matching fixture that built two runs and got one worth
     * twice what it asked for.
     *
     * @return list<string>
     */
    private function aPayableIsNotRunTwice(): array
    {
        $supplier = $this->aPayableSupplier();
        $bill = $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $failures = [];

        $selected = fn (): bool => $this->itemFor(
            app(PaymentRuns::class)->preview(
                $this->entityId,
                (string) $this->bank()->getKey(),
                Carbon::parse('2026-05-20'),
            ),
            $bill,
        ) !== null;

        /*
         | Prepared is enough to hold it.
         |
         | A bill in a prepared run is one somebody is about to release,
         | and two runs that both hold it is the same duplicate arriving
         | a day earlier.
         */
        if ($selected()) {
            $failures[] = 'a payable already in a prepared run was selected into a second run, so two runs '.
                'hold the same bill and releasing both pays the supplier twice';
        }

        /*
         | A draft does not hold it, and must not.
         |
         | Nothing has been checked and nothing can be released, so
         | blocking on a draft would let an abandoned one hold payables
         | hostage with nobody able to see why they are missing. Checked
         | before the release, because a released run cannot walk back.
         */
        app(PaymentRuns::class)->unprepare(
            $run['id'],
            $this->actorId(),
            'withdrawn so the draft case can be checked against the selection',
        );

        if (! $selected()) {
            $failures[] = 'a payable sitting in an abandoned draft was kept out of a new run, so a draft '.
                'nobody will ever release holds the bill hostage and nothing on the screen says why';
        }

        $again = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        app(PaymentRuns::class)->release($again['id'], $this->secondActorId());

        if ($selected()) {
            $failures[] = 'a payable already paid by a released run was selected again, so the next run pays '.
                'it a second time - and the second debit looks as ordinary as the first';
        }

        return $failures;
    }

    /**
     * A settled payable is not paid again; a part-settled one for what is left.
     *
     * @return list<string>
     */
    private function settledPayablesAreNotPaidAgain(): array
    {
        $supplier = $this->aPayableSupplier();
        $settled = $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');
        $partly = $this->anApprovedBill($supplier, '6000.0000', '2026-05-18');

        $this->settle($settled, '4000.0000');
        $this->settle($partly, '2000.0000');

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $failures = [];

        if ($this->itemFor($preview, $settled) !== null) {
            $failures[] = 'a payable that has already been paid in full was selected for payment again, '.
                'which is how a supplier is paid twice and nobody notices until they say so';
        }

        $item = $this->itemFor($preview, $partly);

        if ($item === null) {
            return [...$failures, 'a payable with 4,000 still owed on it was not selected, so a part payment '.
                'leaves the rest unpayable by a run'];
        }

        if (! $item['gross']->equals(Money::of('4000.0000', 'SAR'))) {
            $failures[] = sprintf(
                'a 6,000 bill with 2,000 already paid was selected for %s rather than the 4,000 still '.
                'owed, so the run pays what has been paid once already',
                (string) $item['gross']->amount,
            );
        }

        return $failures;
    }

    /**
     * A discount inside its window is taken; one outside it is not.
     *
     * @return list<string>
     */
    private function discountInsideTheWindow(): array
    {
        $supplier = $this->aPayableSupplier();
        $bill = $this->anApprovedBill($supplier, '10000.0000', '2026-06-15', '2.000', '2026-05-25');

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $item = $this->itemFor($preview, $bill);

        if ($item === null) {
            return [
                'a bill offering 2% until 25 May was not selected for a run paying on 19 May, so the '.
                'run misses the decision it exists to make - paying early to take the discount',
            ];
        }

        $failures = [];

        if (! $item['discount']->equals(Money::of('200.0000', 'SAR'))) {
            $failures[] = sprintf(
                '2%% of 10,000 was evaluated as %s rather than 200',
                (string) $item['discount']->amount,
            );
        }

        if (! $item['net']->equals(Money::of('9800.0000', 'SAR'))) {
            $failures[] = sprintf('the net to pay is %s rather than 9,800', (string) $item['net']->amount);
        }

        if (! str_contains((string) $item['why'], '2.000%') || ! str_contains((string) $item['why'], '2026-05-25')) {
            $failures[] = 'the item does not say what discount was taken or until when, so the figure '.
                'cannot be checked against the supplier\'s terms: '.(string) $item['why'];
        }

        return $failures;
    }

    /** @return list<string> */
    private function discountOutsideTheWindow(): array
    {
        $supplier = $this->aPayableSupplier();
        $bill = $this->anApprovedBill($supplier, '10000.0000', '2026-05-18', '2.000', '2026-05-10');

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $item = $this->itemFor($preview, $bill);

        if ($item === null) {
            return ['a payable due on 20 May was not selected for a run paying on 19 May'];
        }

        $failures = [];

        /*
         | Nothing deducted, and a sentence saying why.
         |
         | Taking a discount whose window closed is the mistake that turns
         | a supplier relationship into a dispute over a figure nobody can
         | explain - and the supplier is right.
         */
        if (! $item['discount']->isZero()) {
            $failures[] = sprintf(
                'a discount that expired on 10 May was deducted anyway on a run paying 19 May (%s), '.
                'so the supplier is short-paid and will be right to dispute it',
                (string) $item['discount']->amount,
            );
        }

        if (! $item['net']->equals(Money::of('10000.0000', 'SAR'))) {
            $failures[] = sprintf('the net to pay is %s rather than the full 10,000', (string) $item['net']->amount);
        }

        if (! str_contains((string) $item['why'], 'expired')) {
            $failures[] = 'the item does not say the discount window had closed, so a reader sees a '.
                'full payment on terms that offered 2% and cannot tell whether it was missed: '.(string) $item['why'];
        }

        return $failures;
    }

    /**
     * A discount opportunity is selected even though the bill is not due.
     *
     * @return list<string>
     */
    private function discountPullsABillForward(): array
    {
        $supplier = $this->aPayableSupplier();
        $notDue = $this->anApprovedBill($supplier, '6000.0000', '2026-08-31', '1.500', '2026-05-22');
        $neither = $this->anApprovedBill($supplier, '3000.0000', '2026-08-31');

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $selected = array_column($preview['items'], 'bill_id');
        $failures = [];

        if (! \in_array($notDue, $selected, true)) {
            $failures[] = 'a bill not due until August, offering 1.5% until 22 May, was not selected for a '.
                'run paying on 19 May - so the run would have to be built by hand to take the discount, '.
                'which is the highest-return decision a finance team makes in a week';
        }

        if (\in_array($neither, $selected, true)) {
            $failures[] = 'a bill not due until August and offering nothing was selected anyway, so the run '.
                'pays early for no reason and the cash leaves months before it had to';
        }

        if (\in_array($notDue, $selected, true) && ! $preview['discount']->equals(Money::of('90.0000', 'SAR'))) {
            $failures[] = sprintf(
                '1.5%% of 6,000 came out as %s rather than 90',
                (string) $preview['discount']->amount,
            );
        }

        return $failures;
    }

    /**
     * A preview writes nothing.
     *
     * @return list<string>
     */
    private function previewWritesNothing(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');

        $before = [
            DB::connection('tenant')->table('payment_runs')->count(),
            DB::connection('tenant')->table('payment_run_instructions')->count(),
            DB::connection('tenant')->table('payment_run_items')->count(),
        ];

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $after = [
            DB::connection('tenant')->table('payment_runs')->count(),
            DB::connection('tenant')->table('payment_run_instructions')->count(),
            DB::connection('tenant')->table('payment_run_items')->count(),
        ];

        $failures = [];

        if ($before !== $after) {
            $failures[] = 'previewing a run wrote rows, so somebody asking "what would this pay?" has '.
                'created a run that another person can release';
        }

        if ($preview['items'] === []) {
            $failures[] = 'the preview found nothing to pay, so it is not previewing the run that would be prepared';
        }

        if (! str_contains((string) $preview['why'], 'leaves the account')) {
            $failures[] = 'the preview does not state the cash impact in a sentence, which is the thing '.
                'somebody has to see before committing to the largest payment of the week';
        }

        return $failures;
    }

    /**
     * Several bills to one supplier become one instruction.
     *
     * @return list<string>
     */
    private function grouping(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');
        $this->anApprovedBill($supplier, '2500.0000', '2026-05-18');
        $this->anApprovedBill($supplier, '1500.0000', '2026-05-18');

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $mine = array_values(array_filter(
            $preview['instructions'],
            static fn (array $i): bool => $i['party_id'] === (string) $supplier->getKey(),
        ));

        if (\count($mine) !== 1) {
            return [sprintf(
                'three bills to one supplier produced %d instructions rather than one, so the supplier '.
                'pays three transfer fees and receives three lines to match against three invoices',
                \count($mine),
            )];
        }

        return $mine[0]['amount']->equals(Money::of('8000.0000', 'SAR'))
            ? []
            : [sprintf(
                'the grouped instruction is %s rather than the 8,000 the three bills total',
                (string) $mine[0]['amount']->amount,
            )];
    }

    /**
     * A supplier who reconciles per invoice gets one transfer each.
     *
     * @return list<string>
     */
    private function groupingIsTheBeneficiarysCall(): array
    {
        $supplier = $this->aPayableSupplier(groups: false);
        $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');
        $this->anApprovedBill($supplier, '2500.0000', '2026-05-18');

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $mine = array_values(array_filter(
            $preview['instructions'],
            static fn (array $i): bool => $i['party_id'] === (string) $supplier->getKey(),
        ));

        if (\count($mine) !== 2) {
            return [sprintf(
                'a supplier whose settings say not to group received %d instruction(s) for two bills, so '.
                'a lump sum arrives at a counterparty whose system cannot match it to anything',
                \count($mine),
            )];
        }

        $amounts = array_map(static fn (array $i): string => (string) $i['amount']->amount, $mine);
        sort($amounts);

        return $amounts === ['2500.0000', '4000.0000']
            ? []
            : ['the two ungrouped instructions are '.implode(' and ', $amounts).' rather than 2,500 and 4,000'];
    }

    /**
     * A limit splits the instruction, and the split says why.
     *
     * @return list<string>
     */
    private function splittingAtALimit(): array
    {
        $supplier = $this->aPayableSupplier(limit: '3000.0000');
        $this->anApprovedBill($supplier, '7000.0000', '2026-05-18');

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $mine = array_values(array_filter(
            $preview['instructions'],
            static fn (array $i): bool => $i['party_id'] === (string) $supplier->getKey(),
        ));

        if (\count($mine) !== 3) {
            return [sprintf(
                '7,000 to a beneficiary capped at 3,000 produced %d instruction(s) rather than three, so '.
                'the bank returns the file and the supplier is paid nothing - which is discovered when they chase',
                \count($mine),
            )];
        }

        $failures = [];
        $total = Money::zero('SAR');

        foreach ($mine as $instruction) {
            $total = $total->plus($instruction['amount']);

            if ($instruction['amount']->amount->isGreaterThan('3000.0000')) {
                $failures[] = sprintf(
                    'a split instruction is %s, above the 3,000 limit that caused the split',
                    (string) $instruction['amount']->amount,
                );
            }

            if ($instruction['of'] !== 3) {
                $failures[] = 'a split instruction does not say how many parts it is one of, so the supplier '.
                    'cannot tell whether they have received all of them';
            }

            if ($instruction['split_reason'] === null) {
                $failures[] = 'a split instruction records no reason, so a supplier who received three transfers '.
                    'instead of one asks a question the run cannot answer';
            }
        }

        if (! $total->equals(Money::of('7000.0000', 'SAR'))) {
            $failures[] = sprintf(
                'the split instructions total %s rather than the 7,000 owed, so splitting changed what is paid',
                (string) $total->amount,
            );
        }

        $sequences = array_map(static fn (array $i): int => $i['sequence'], $mine);
        sort($sequences);

        if ($sequences !== [1, 2, 3]) {
            $failures[] = 'the split parts are not numbered 1, 2, 3, so two transfers could claim to be the same part';
        }

        return $failures;
    }

    /**
     * A supplier with nowhere to pay is reported, not dropped.
     *
     * @return list<string>
     */
    private function noVerifiedAccountIsReported(): array
    {
        $supplier = $this->aPayableSupplier(withAnAccount: false);
        $bill = $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $failures = [];

        if (\in_array($bill, array_column($preview['items'], 'bill_id'), true)) {
            return ['a payable to a supplier with no bank account was selected for payment, so the run '.
                'carries an instruction with nowhere to send the money'];
        }

        $blocked = array_column($preview['blocked'], 'bill_id');

        if (! \in_array($bill, $blocked, true)) {
            $failures[] = 'a payable that could not be paid was left out of the run silently, so its author '.
                'discovers the omission when the supplier chases';
        }

        if (! str_contains((string) $preview['why'], 'verified account')) {
            $failures[] = 'the run\'s summary does not mention that payables were left out for want of an account';
        }

        return $failures;
    }

    /**
     * Details that changed and were never re-verified take the supplier out.
     *
     * @return list<string>
     */
    private function unverifiedDetailsTakeThemOut(): array
    {
        $supplier = $this->aPayableSupplier();
        $bill = $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');

        /*
         | The account changed, and nobody confirmed the change.
         |
         | This is the fraud the whole area is built against: an invoice
         | arrives with new bank details, somebody updates the supplier,
         | and the next run pays a stranger. So it is an exclusion, not a
         | warning on a screen somebody may not read.
         */
        DB::connection('tenant')->table('party_bank_accounts')
            ->where('party_id', (string) $supplier->getKey())
            ->update(['bic' => 'SABBSARI']);

        $preview = app(PaymentRuns::class)->preview(
            $this->entityId,
            (string) $this->bank()->getKey(),
            Carbon::parse('2026-05-19'),
        );

        $failures = [];

        if (\in_array($bill, array_column($preview['items'], 'bill_id'), true)) {
            return ['a supplier whose bank details changed without re-verification was still paid, which '.
                'is the invoice-fraud path the verification exists to close'];
        }

        $reason = (string) ($preview['blocked'][0]['why'] ?? '');

        if (! str_contains($reason, 're-verif')) {
            $failures[] = 'the payable was left out, but not for a reason that names the unverified change - so '.
                'whoever reads the run cannot tell this from a supplier with no account at all: '.$reason;
        }

        return $failures;
    }

    /**
     * A release whose user cannot be named is refused - PF-249.
     *
     * ## What the engine used to do
     *
     *     if ($user === null) {
     *         // No actor: a console command or a system job. There is no
     *         // person whose duties could conflict, so there is nothing
     *         // to assert.
     *         return;
     *     }
     *
     * `release()` takes the releasing user's id as an ARGUMENT and the
     * engine resolved its own actor from `auth()->guard('web')`. On a
     * browser request the two agree, so nothing looked wrong. This method's
     * own comment says it "is reached from a console command, a queued job
     * and an API endpoint" - and on two of those three there is no session,
     * so the engine found no actor and returned.
     *
     * `beneficiary_then_release` and `prepare_then_release` are the two
     * rules the seeder marks `overridable = false`, because "nothing in the
     * application can grant an exception". Returning early IS granting it,
     * silently, on the paths nobody is watching.
     *
     * ## Driven with an id that names nobody
     *
     * Which is the same thing from the engine's side: it cannot say who
     * acted. A ULID that resolves to no user is also what a deleted
     * account, a bad import or a mistyped job argument produces.
     *
     * @return list<string>
     */
    private function aReleaseByNobodyIsRefused(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4400.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $nobody = (string) \Illuminate\Support\Str::ulid();

        $refusal = $this->refusal(fn () => app(PaymentRuns::class)->release($run['id'], $nobody));

        if ($refusal === null) {
            return ['a payment run was released by an id that names no user. The two rules on '.
                'payment.release are the ones marked overridable = false because nothing in the '.
                'application can grant an exception - and an unnameable actor granted it to both'];
        }

        if (! str_contains($refusal, 'no identifiable user')) {
            return [sprintf(
                'the release was refused for a different reason: "%s". The one under test is that '.
                'the actor could not be named, and a check that accepted any refusal would pass '.
                'against a run that was simply stale',
                \Illuminate\Support\Str::limit($refusal, 160),
            )];
        }

        return [];
    }

    /**
     * The person who prepared a run does not release it.
     *
     * @return list<string>
     */
    private function preparerDoesNotRelease(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $failures = [];

        try {
            app(PaymentRuns::class)->release($run['id'], $this->actorId());

            $failures[] = 'the person who prepared a run released it, so one person chose where the money '.
                'goes and then sent it - which is the highest-value fraud in the product';
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'cannot release it')) {
                $failures[] = 'self-release was refused by something that does not explain the duty: '.$e->getMessage();
            }
        }

        /*
         | And the database, which the service is only one door to.
         |
         | A maker-checker rule that lives in a service is a rule an
         | import or a repaired row walks past.
         */
        $refusal = $this->refusal(fn () => DB::connection('tenant')
            ->table('payment_runs')
            ->where('id', $run['id'])
            ->update([
                'state' => 'released',
                'released_by' => $this->actorId(),
                'released_at' => $this->date->copy(),
            ]));

        if ($refusal === null) {
            $failures[] = 'the database accepted a release by the preparer, so a single UPDATE walks past '.
                'the one duty pair that can never be overridden';
        } elseif (! str_contains($refusal, 'self')) {
            $failures[] = 'the database refused a self-release without naming the duty, so whoever hits it '.
                'through an import sees a constraint name rather than the reason: '.$refusal;
        }

        $second = app(PaymentRuns::class)->release($run['id'], $this->secondActorId());

        if ($second['state'] !== 'released') {
            $failures[] = 'a second person could not release the run either, so nothing can ever be paid';
        }

        return $failures;
    }

    /**
     * An empty run is not prepared.
     *
     * @return list<string>
     */
    private function emptyRunIsRefused(): array
    {
        try {
            app(PaymentRuns::class)->prepare(
                entityId: $this->entityId,
                bankAccountId: (string) $this->bank()->getKey(),
                payOn: Carbon::parse('2026-01-02'),
                userId: $this->actorId(),
            );

            return [
                'a run with nothing to pay was prepared, and somebody will release it - an instruction '.
                'for nothing that still needs two people and a place in the audit trail',
            ];
        } catch (\Throwable $e) {
            return str_contains($e->getMessage(), 'nothing to pay')
                ? []
                : ['an empty run was refused for some other reason: '.$e->getMessage()];
        }
    }

    /**
     * The stored totals are the ones the instructions carry.
     *
     * @return list<string>
     */
    private function totalsTie(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '10000.0000', '2026-06-15', '2.000', '2026-05-25');
        $this->anApprovedBill($supplier, '5000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $shown = app(PaymentRuns::class)->show($run['id']);
        $failures = [];

        if (! $shown['gross']->minus($shown['discount'])->equals($shown['net'])) {
            $failures[] = sprintf(
                'the run stored %s gross less %s discount as a net of %s, which does not subtract',
                (string) $shown['gross']->amount,
                (string) $shown['discount']->amount,
                (string) $shown['net']->amount,
            );
        }

        $instructed = Money::zero('SAR');

        foreach ($shown['instructions'] as $instruction) {
            $instructed = $instructed->plus($instruction['amount']);
        }

        if (! $instructed->equals($shown['net'])) {
            $failures[] = sprintf(
                'the instructions total %s and the run says it pays %s, so the file and the figure '.
                'somebody approved disagree',
                (string) $instructed->amount,
                (string) $shown['net']->amount,
            );
        }

        $items = Money::zero('SAR');

        foreach ($shown['items'] as $item) {
            $items = $items->plus($item['net']);
        }

        if (! $items->equals($shown['net'])) {
            $failures[] = sprintf(
                'the items net to %s and the run pays %s, so a payable was counted twice or not at all',
                (string) $items->amount,
                (string) $shown['net']->amount,
            );
        }

        /*
         | And every item belongs to an instruction.
         |
         | An item with no instruction is a bill the run claims to settle
         | with money it never sent - which is found by the supplier, not
         | by the run.
         */
        $orphans = DB::connection('tenant')->table('payment_run_items')
            ->where('run_id', $run['id'])
            ->whereNull('instruction_id')
            ->count();

        if ($orphans > 0) {
            $failures[] = sprintf(
                '%d item(s) belong to no instruction, so the run says it settles a bill with money it never sent',
                $orphans,
            );
        }

        return $failures;
    }

    /**
     * What was prepared is what is released, even if the terms move.
     *
     * @return list<string>
     */
    private function preparationIsFrozen(): array
    {
        $supplier = $this->aPayableSupplier();
        $bill = $this->anApprovedBill($supplier, '10000.0000', '2026-06-15', '2.000', '2026-05-25');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $prepared = app(PaymentRuns::class)->show($run['id'])['net'];

        /*
         | The terms change between preparation and release.
         |
         | A supplier renegotiates, an import corrects a snapshot, somebody
         | fixes a typo. If release recomputed, the releaser would send an
         | amount nobody approved - which is the one thing maker-checker
         | exists to prevent.
         */
        DB::connection('tenant')->table('bills')->where('id', $bill)->update([
            'terms_snapshot' => json_encode([
                'due_dates' => [['date' => '2026-06-15', 'amount' => '10000.0000']],
                'discount' => ['percent' => '9.000', 'until' => '2026-05-25'],
            ], JSON_THROW_ON_ERROR),
        ]);

        app(PaymentRuns::class)->release($run['id'], $this->secondActorId());

        $released = app(PaymentRuns::class)->show($run['id'])['net'];
        $failures = [];

        if (! $released->equals($prepared)) {
            $failures[] = sprintf(
                'the run paid %s having been prepared at %s, so changing the terms after preparation '
                .'changed what the releaser sent without their knowing',
                (string) $released->amount,
                (string) $prepared->amount,
            );
        }

        if (! $prepared->equals(Money::of('9800.0000', 'SAR'))) {
            $failures[] = sprintf('the prepared net was %s rather than 9,800', (string) $prepared->amount);
        }

        return $failures;
    }

    /**
     * Withdrawing a preparation needs a reason, and clears the preparer.
     *
     * @return list<string>
     */
    private function unprepare(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $failures = [];

        try {
            app(PaymentRuns::class)->unprepare($run['id'], $this->actorId(), 'wrong');

            $failures[] = 'a preparation was withdrawn with no reason, so the releaser is shown a different '.
                'selection with nothing saying what changed';
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'reason')) {
                $failures[] = 'a reasonless withdrawal was refused for some other reason: '.$e->getMessage();
            }
        }

        app(PaymentRuns::class)->unprepare(
            $run['id'],
            $this->actorId(),
            'the wrong bank account was selected and the whole run has to be rebuilt',
        );

        $row = DB::connection('tenant')->table('payment_runs')->where('id', $run['id'])->first();

        if ((string) $row->state !== 'draft') {
            $failures[] = 'withdrawing a preparation left the run '.(string) $row->state.', so it can still be released';
        }

        if ($row->prepared_by !== null) {
            $failures[] = 'the withdrawn run still names a preparer, so the release check would compare '.
                'against a preparation that no longer stands';
        }

        try {
            app(PaymentRuns::class)->release($run['id'], $this->secondActorId());

            $failures[] = 'a run whose preparation was withdrawn was released anyway, so withdrawing it '.
                'protects nothing';
        } catch (\Throwable $e) {
            /*
             | In the service's own words, not the database's.
             |
             | The state machine also refuses draft to released, and its
             | message happens to contain the word "draft" - so a check
             | that looked for that passed with the service's guard
             | removed. What the person needs is the sentence that says
             | which states can be released and why, which is this one.
             */
            if (! str_contains($e->getMessage(), 'Only a prepared run can be released')) {
                $failures[] = 'releasing a draft was refused, but not by the operation explaining itself - '.
                    'so whoever hits it is shown a trigger\'s complaint rather than a reason: '.$e->getMessage();
            }
        }

        return $failures;
    }

    /**
     * The duty pair that can never be overridden, and it now fires.
     *
     * @return list<string>
     */
    private function paymentRunSod(): array
    {
        $failures = [];
        $map = (array) config('sod.performed_as');

        foreach ([
            ['payment.prepare', 'payment_run.prepared'],
            ['payment.release', 'payment_run.released'],
        ] as [$duty, $action]) {
            if (! \in_array($action, (array) ($map[$duty] ?? []), true)) {
                $failures[] = sprintf(
                    '%s maps to no audit action for %s, so prepare_then_release sees nothing happen and '.
                    'the pair that can never be overridden enforces nothing',
                    $duty,
                    $action,
                );
            }

            if (\array_key_exists($duty, (array) config('sod.declared_gaps'))) {
                $failures[] = sprintf(
                    '%s is still recorded as a declared gap although PF-174 records it, so the coverage '.
                    'report excuses a rule that now works',
                    $duty,
                );
            }
        }

        $rule = \App\Tenant\Identity\Models\SodRule::query()->where('key', 'prepare_then_release')->first();

        if ($rule === null) {
            return [...$failures, 'nothing pairs preparing a payment run against releasing it'];
        }

        if ($rule->overridable) {
            $failures[] = 'prepare_then_release is overridable, so a one-person finance function can send '.
                'money it alone selected - and this is the pair where that can never be allowed';
        }

        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $recorded = DB::connection('tenant')->table('audit_log')
            ->where('action', 'payment_run.prepared')
            ->where('subject_id', $run['id'])
            ->exists();

        if (! $recorded) {
            $failures[] = 'preparing a run wrote no audit event, so the rule has nothing to compare a '.
                'release against however it is configured';
        }

        return $failures;
    }

    // ---- Maker-checker and step-up - PF-175 -------------------------------

    /**
     * A stolen session is not enough to send money.
     *
     * The highest-consequence control in the product, and the one that
     * cannot be asserted without a real factor: a check that called a
     * back door into the guard would be proving that the back door
     * works.
     *
     * @return list<string>
     */
    private function releaseNeedsAFreshFactor(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $before = auth()->guard('web')->user();
        $failures = [];

        try {
            $releaser = $this->aUserWithRole('controller');
            auth()->guard('web')->setUser($releaser);

            /*
             | A real TOTP enrolment, and codes from explicit steps.
             |
             | Confirming the enrolment consumes the code for the current
             | step, and a used code is refused - so the assertion is
             | recorded with the next step's code, which the drift window
             | still accepts.
             */
            $enrolment = app(\App\Tenant\Identity\Services\MfaService::class)
                ->beginTotpEnrolment($releaser, 'Check authenticator');
            $secret = (string) $enrolment['secret'];
            $step = \App\Support\Mfa\Totp::stepAt(time());

            app(\App\Tenant\Identity\Services\MfaService::class)->confirmTotpEnrolment(
                $enrolment['authenticator'],
                \App\Support\Mfa\Totp::at($secret, $step * 30),
            );

            auth()->guard('web')->setUser($releaser->refresh());
            session(['factor_asserted_at' => now()->toIso8601String()]);

            /*
             | No assertion yet, so the release must be refused.
             |
             | This is the whole criterion: the session is legitimate, the
             | user holds the permission, and it is still not enough.
             */
            $refusal = $this->refusal(fn () => app(PaymentRuns::class)->release(
                $run['id'],
                (string) $releaser->getKey(),
            ));

            if ($refusal === null) {
                $failures[] = 'a payment run was released with no fresh factor, so a stolen session carries '.
                    'every payment the account could ever make - and the password that protected it may '.
                    'have been phished weeks ago';
            } elseif (! str_contains(mb_strtolower($refusal), 'step')
                && ! str_contains(mb_strtolower($refusal), 'factor')
                && ! str_contains(mb_strtolower($refusal), 'تحقّق')) {
                $failures[] = 'the release was refused for some other reason, so nothing here is testing '.
                    'step-up: '.$refusal;
            }

            /*
             | Now with an assertion for THIS run, and another run still refused.
             |
             | The half that gets dropped. An unscoped assertion makes the
             | first case pass and leaves a five-minute licence to release
             | anything, which no test would notice.
             */
            $guard = app(\App\Tenant\Identity\Services\StepUpGuard::class);

            $recorded = $guard->recordAssertion(
                $releaser->refresh(),
                'payment.release',
                $run['id'],
                \App\Support\Mfa\Totp::at($secret, ($step + 1) * 30),
            );

            if ($recorded === null) {
                return [...$failures, 'a valid code from the next time step was not accepted, so nothing '.
                    'below is testing a satisfied assertion'];
            }

            auth()->guard('web')->setUser($releaser->refresh());
            session(['factor_asserted_at' => now()->toIso8601String()]);

            if (! $guard->isSatisfied('payment.release', $run['id'])) {
                $failures[] = 'an assertion recorded for this run does not satisfy releasing it, so the '.
                    'subject is not being matched at all';
            }

            $other = $this->aPayableSupplier();
            $this->anApprovedBill($other, '3000.0000', '2026-05-18');

            $second = app(PaymentRuns::class)->prepare(
                entityId: $this->entityId,
                bankAccountId: (string) $this->bank()->getKey(),
                payOn: Carbon::parse('2026-05-19'),
                userId: $this->actorId(),
            );

            if ($guard->isSatisfied('payment.release', $second['id'])) {
                $failures[] = 'an assertion recorded for one run also authorises releasing another, which '.
                    'is a five-minute licence to send any payment rather than a factor re-proved for one act';
            }

            /*
             | And the assertion actually lets this run go.
             |
             | Without this the check would pass with a guard that refused
             | everything, which is the failure that looks like security.
             */
            $released = app(PaymentRuns::class)->release($run['id'], (string) $releaser->getKey());

            if (($released['state'] ?? '') !== 'released') {
                $failures[] = 'a run could not be released even with a factor re-proved for it, so nothing '.
                    'can ever be paid';
            }

            return $failures;
        } finally {
            $this->signInAs($before);
        }
    }

    /**
     * The preparer is refused by the audit trail, not only by the column.
     *
     * @return list<string>
     */
    private function theTrailEnforcesTheDuty(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $before = auth()->guard('web')->user();

        try {
            /*
             | A real signed-in preparer, because the trail records the
             | actor from the auth guard.
             |
             | The service takes a `$userId` and writes it to
             | `prepared_by`; the audit trail asks who is signed in. In
             | production they are the same person - the controller passes
             | the authenticated user's id - and a check that only passed
             | the id would leave the trail naming nobody, which is how
             | the first draft of this check ended up proving that
             | step-up fires rather than that the trail does.
             */
            $preparer = $this->aUserWithRole('controller');
            $this->signInAs($preparer);

            $run = app(PaymentRuns::class)->prepare(
                entityId: $this->entityId,
                bankAccountId: (string) $this->bank()->getKey(),
                payOn: Carbon::parse('2026-05-19'),
                userId: (string) $preparer->getKey(),
            );

            /*
             | The column pointed elsewhere, as a repaired row or a
             | restored backup would leave it.
             |
             | `prepared_by` is one column on one row. The audit trail is
             | a hash chain nobody can quietly edit, and it is the record
             | that says what actually happened - so it is what the
             | control reads.
             */
            DB::connection('tenant')->table('payment_runs')->where('id', $run['id'])->update([
                'prepared_by' => $this->secondActorId(),
            ]);

            $refusal = $this->refusal(fn () => app(PaymentRuns::class)->release(
                $run['id'],
                (string) $preparer->getKey(),
            ));

            if ($refusal === null) {
                return [
                    'with `prepared_by` pointing at somebody else, the person the audit trail records as '.
                    'having prepared the run released it - so repairing one column is enough to defeat the '.
                    'pair that can never be overridden',
                ];
            }

            return str_contains($refusal, 'audit trail records you preparing')
                ? []
                : ['the release was refused, but not by the trail - so the control is still the column: '.$refusal];
        } finally {
            $this->signInAs($before);
        }
    }

    /**
     * Whoever changed the supplier's bank details does not release the run.
     *
     * `beneficiary_then_release` - the other pair that can never be
     * overridden, and the one PF-061 exists for. A person who can point a
     * supplier's account at their own and then send the payment needs no
     * accomplice.
     *
     * Found by signing a real user in: `release()` asserted the duty once
     * for the run with no party id, and the engine refused outright
     * because a party-scoped rule cannot be evaluated without one. It was
     * right to refuse. A run pays many beneficiaries, so the assertion
     * belongs per beneficiary.
     *
     * @return list<string>
     */
    private function theBeneficiaryPairFires(): array
    {
        $before = auth()->guard('web')->user();

        try {
            $meddler = $this->aUserWithRole('controller');
            $failures = [];

            /*
             | The supplier's account entered by the person who will try
             | to release.
             |
             | `record()` is what writes `supplier.bank_details.update`
             | to the trail, so signing them in is what makes the trail
             | name them.
             */
            $this->signInAs($meddler);
            $supplier = $this->aPayableSupplier();
            $this->signInAs($before);

            $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

            $run = app(PaymentRuns::class)->prepare(
                entityId: $this->entityId,
                bankAccountId: (string) $this->bank()->getKey(),
                payOn: Carbon::parse('2026-05-19'),
                userId: $this->actorId(),
            );

            $this->signInAs($meddler);

            $refusal = $this->refusal(fn () => app(PaymentRuns::class)->release(
                $run['id'],
                (string) $meddler->getKey(),
            ));

            if ($refusal === null) {
                $failures[] = 'the person who entered this supplier\'s bank details released the payment to '.
                    'them, so one person can point an account at their own and send the money - which needs '.
                    'no accomplice and is the pair PF-061 exists for';
            } elseif (! str_contains(mb_strtolower($refusal), 'bank details')
                && ! str_contains(mb_strtolower($refusal), 'segregation')
                && ! str_contains(mb_strtolower($refusal), 'duties')
                && ! str_contains(mb_strtolower($refusal), 'فصل')) {
                $failures[] = 'the release was refused, but not for the conflict - so nothing here is '.
                    'testing the beneficiary pair: '.$refusal;
            }

            /*
             | And it is scoped to the party, not to everything.
             |
             | Somebody who entered one supplier's details must still be
             | able to release a run paying a different supplier. A rule
             | that barred them from every run would be turned off within
             | a week.
             */
            $this->signInAs($before);
            $other = $this->aPayableSupplier();
            $this->anApprovedBill($other, '3000.0000', '2026-05-18');

            $clean = app(PaymentRuns::class)->prepare(
                entityId: $this->entityId,
                bankAccountId: (string) $this->bank()->getKey(),
                payOn: Carbon::parse('2026-05-19'),
                userId: $this->actorId(),
            );

            $this->signInAs($meddler);
            $second = $this->refusal(fn () => app(PaymentRuns::class)->release(
                $clean['id'],
                (string) $meddler->getKey(),
            ));

            if ($second !== null
                && (str_contains(mb_strtolower($second), 'bank details')
                    || str_contains(mb_strtolower($second), 'segregation'))) {
                $failures[] = 'somebody who entered one supplier\'s details cannot release a run paying a '.
                    'different supplier, so the rule is scoped to the person rather than to the party and '.
                    'would be turned off within a week: '.$second;
            }

            return $failures;
        } finally {
            $this->signInAs($before);
        }
    }

    /**
     * A beneficiary whose details changed blocks the release.
     *
     * @return list<string>
     */
    private function changedBeneficiaryBlocksRelease(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        /*
         | The invoice-fraud path, exactly.
         |
         | New bank details arrive on an invoice, somebody updates the
         | supplier, and the pending run pays whoever sent them. PF-174
         | keeps an unverified account out of a run at selection; this is
         | the hole after selection.
         */
        DB::connection('tenant')->table('party_bank_accounts')
            ->where('party_id', (string) $supplier->getKey())
            ->update(['bic' => 'SABBSARI']);

        $failures = [];
        $review = app(ReleaseReview::class)->of($run['id']);

        if (! $review['blocks']) {
            $failures[] = 'a beneficiary whose bank details changed after the run was prepared does not '.
                'block the release, which is the invoice-fraud path this control exists for';
        }

        $what = implode(' ', array_column($review['changes'], 'why'));

        /*
         | The wording of THIS case, not of any case.
         |
         | The fingerprint comparison also catches a changed account, and
         | its message mentions re-verification too - so a check that
         | looked for the word passed with this branch removed. What the
         | releaser needs is the actionable sentence: nobody has verified
         | the change, so somebody must.
         */
        if (! str_contains($what, 'nobody has re-verified them')) {
            $failures[] = 'the review does not say that nobody has re-verified the change, so the releaser '.
                'is told something moved and not the one thing they can act on: '.$what;
        }

        if (! str_contains($what, 'invoice-fraud')) {
            $failures[] = 'the review does not name what this is - new details arrive, the supplier record '.
                'is updated, the pending run pays whoever sent them - so it reads as a process obstacle '.
                'rather than as the fraud it is: '.$what;
        }

        $refusal = $this->refusal(fn () => app(PaymentRuns::class)->release($run['id'], $this->secondActorId()));

        if ($refusal === null) {
            $failures[] = 'the review reported the change and the release went through anyway, so the '.
                'review is a screen rather than a control';
        }

        return $failures;
    }

    /**
     * A beneficiary account changed and re-verified still invalidates it.
     *
     * @return list<string>
     */
    private function reVerifiedIsStillAChange(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $account = \App\Tenant\Parties\Models\PartyBankAccount::query()
            ->where('party_id', (string) $supplier->getKey())
            ->firstOrFail();

        DB::connection('tenant')->table('party_bank_accounts')
            ->where('id', (string) $account->getKey())
            ->update(['bic' => 'SABBSARI']);

        /*
         | And a colleague re-verifies it, knowing nothing of this run.
         |
         | Everybody did their job: the change was made, a second person
         | confirmed it. And the money would go to an account the person
         | who prepared the run never saw.
         */
        app(\App\Tenant\Parties\Services\BankDetailRegistry::class)->verify(
            $account->fresh(),
            str_repeat('2', 26),
            'callback',
        );

        $review = app(ReleaseReview::class)->of($run['id']);

        if (! $review['blocks']) {
            return [
                'a beneficiary account changed and re-verified after preparation does not invalidate the '.
                'preparation, so the run was built against the old account and whoever re-verified the new '.
                'one knew nothing of this pending payment',
            ];
        }

        $what = implode(' ', array_column($review['changes'], 'why'));

        return str_contains($what, 'knew nothing of this pending payment')
            ? []
            : ['the review blocks, but does not explain why a re-verified change still matters: '.$what];
    }

    /**
     * The other two ways the world moves under a prepared run.
     *
     * A beneficiary account deactivated, and a payable whose approval was
     * withdrawn. Both were branches in the review that nothing exercised
     * - which a bite sweep reported by removing each and watching every
     * check stay green.
     *
     * @return list<string>
     */
    private function theOtherChangesBlockRelease(): array
    {
        $failures = [];

        /*
         | The account deactivated after the run was prepared.
         |
         | Not a fraud case - a supplier closing an account, or somebody
         | tidying a duplicate. The transfer still has nowhere to go, and
         | a bank returns the whole file rather than the one line.
         */
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        DB::connection('tenant')->table('party_bank_accounts')
            ->where('party_id', (string) $supplier->getKey())
            ->update(['active' => false]);

        $review = app(ReleaseReview::class)->of($run['id']);

        if (! $review['blocks']) {
            $failures[] = 'a beneficiary account deactivated after the run was prepared does not block the '.
                'release, so the file goes out with a transfer that has nowhere to go - and the bank '.
                'returns the whole batch rather than the one line';
        } elseif (! str_contains(implode(' ', array_column($review['changes'], 'why')), 'deactivated')) {
            $failures[] = 'the review blocks on a deactivated account without saying that is what happened: '
                .implode(' ', array_column($review['changes'], 'why'));
        }

        /*
         | And a payable whose approval was withdrawn.
         |
         | The approval is the whole control on the purchasing side. A run
         | that paid past a withdrawn one would pay what nobody currently
         | stands behind - and the person who withdrew it would have no
         | way to know the payment was already in flight.
         */
        $other = $this->aPayableSupplier();
        $bill = $this->anApprovedBill($other, '5000.0000', '2026-05-18');

        $second = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        DB::connection('tenant')->table('bills')->where('id', $bill)->update([
            'approved_at' => null,
            'approved_by' => null,
        ]);

        $withdrawn = app(ReleaseReview::class)->of($second['id']);

        if (! $withdrawn['blocks']) {
            $failures[] = 'a payable whose approval was withdrawn after the run was prepared does not block '.
                'the release, so the run pays what nobody currently stands behind and whoever withdrew the '.
                'approval never learns the payment was in flight';
        } elseif (! str_contains(implode(' ', array_column($withdrawn['changes'], 'why')), 'approval has gone')) {
            $failures[] = 'the review blocks on an unapproved payable without saying the approval went: '
                .implode(' ', array_column($withdrawn['changes'], 'why'));
        }

        $refusal = $this->refusal(fn () => app(PaymentRuns::class)->release($second['id'], $this->secondActorId()));

        if ($refusal === null) {
            $failures[] = 'the run was released although a payable under it was no longer approved';
        }

        return $failures;
    }

    /**
     * A payable worth less than when it was selected invalidates it.
     *
     * @return list<string>
     */
    private function aShrunkPayableBlocksRelease(): array
    {
        $supplier = $this->aPayableSupplier();
        $bill = $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        /*
         | Somebody else settles part of it in between.
         |
         | A credit note, a write-off, or a payment made from another
         | screen. Paying the prepared figure would over-settle the bill
         | and leave the supplier holding money against nothing.
         */
        $this->settle($bill, '2000.0000');

        $review = app(ReleaseReview::class)->of($run['id']);
        $failures = [];

        if (! $review['blocks']) {
            $failures[] = 'a payable part-settled between preparation and release does not invalidate the '.
                'preparation, so the run over-pays it and the supplier holds money against nothing';
        }

        $refusal = $this->refusal(fn () => app(PaymentRuns::class)->release($run['id'], $this->secondActorId()));

        if ($refusal === null) {
            $failures[] = 'the run was released although a payable had shrunk under it';
        }

        return $failures;
    }

    /**
     * Nothing changed means nothing is reported, and the run goes.
     *
     * @return list<string>
     */
    private function anUnchangedRunReleases(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $review = app(ReleaseReview::class)->of($run['id']);
        $failures = [];

        /*
         | The one that stops this being security theatre.
         |
         | A review that reported a change on every run would block every
         | release, and the first thing a business would do is turn it
         | off. The ordinary case has to pass.
         */
        if ($review['changes'] !== []) {
            $failures[] = 'a run nothing has happened to reports changes anyway, so every release is blocked '.
                'and the control is the first thing a business turns off: '
                .implode(' ', array_column($review['changes'], 'why'));
        }

        if (! str_contains((string) $review['why'], 'Nothing has moved')) {
            $failures[] = 'the review does not say plainly that nothing moved, which is what a releaser '.
                'needs to read before sending the largest payment of the week';
        }

        $released = app(PaymentRuns::class)->release($run['id'], $this->secondActorId());

        if (($released['state'] ?? '') !== 'released') {
            $failures[] = 'an unchanged run could not be released, so nothing can ever be paid';
        }

        return $failures;
    }

    /**
     * The release records the hash of what was authorised.
     *
     * @return list<string>
     */
    private function releaseIsHashed(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $expected = app(ReleaseReview::class)->hash($run['id']);
        app(PaymentRuns::class)->release($run['id'], $this->secondActorId());

        $stored = (string) DB::connection('tenant')->table('payment_runs')
            ->where('id', $run['id'])
            ->value('released_hash');

        $failures = [];

        if ($stored === '') {
            return ['a released run records no hash, so the file generated from it cannot be held against '.
                'what was authorised'];
        }

        if ($stored !== $expected) {
            $failures[] = 'the stored hash is not the hash of the run\'s own instruction set, so it '.
                'identifies something other than what was released';
        }

        if (mb_strlen($stored) !== 64) {
            $failures[] = 'the stored hash is not a sha-256 digest: '.$stored;
        }

        /*
         | And it is in the audit trail, not only on the row.
         |
         | The row is frozen, which is good - and the trail is the record
         | that survives a restore, so the figure belongs in both.
         */
        $audited = DB::connection('tenant')->table('audit_log')
            ->where('action', 'payment_run.released')
            ->where('subject_id', $run['id'])
            ->value('after_state');

        if ($audited === null || ! str_contains((string) $audited, $stored)) {
            $failures[] = 'the hash is on the run and not in the audit trail, so a restore from backup '.
                'loses the only record of what was authorised';
        }

        /*
         | Changing an instruction changes the hash.
         |
         | A hash that did not would identify every run equally, which is
         | the failure that looks exactly like success.
         */
        $other = $this->aPayableSupplier();
        $this->anApprovedBill($other, '7000.0000', '2026-05-18');

        $second = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        if (app(ReleaseReview::class)->hash($second['id']) === $expected) {
            $failures[] = 'two runs paying different beneficiaries different amounts hash the same, so the '.
                'hash identifies nothing';
        }

        return $failures;
    }

    /**
     * The hash is stable across reads and independent of row order.
     *
     * @return list<string>
     */
    private function theHashIsStable(): array
    {
        $first = $this->aPayableSupplier(limit: '3000.0000');
        $this->anApprovedBill($first, '7000.0000', '2026-05-18');
        $second = $this->aPayableSupplier();
        $this->anApprovedBill($second, '2000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $once = app(ReleaseReview::class)->hash($run['id']);
        $failures = [];

        if ($once !== app(ReleaseReview::class)->hash($run['id'])) {
            $failures[] = 'hashing the same run twice gave two answers, so the hash depends on something '.
                'other than the run - and nothing can ever be held against it';
        }

        $instructions = DB::connection('tenant')->table('payment_run_instructions')
            ->where('run_id', $run['id'])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (\count($instructions) < 3) {
            return [...$failures, sprintf(
                'the fixture produced %d instruction(s), so neither the ordering nor the content of the '.
                'hash input is being exercised',
                \count($instructions),
            )];
        }

        /*
         | The LAST row touched, so a timestamp ordering really differs.
         |
         | Two drafts got this wrong. The first rewrote every instruction
         | in one statement, which gave them all the same timestamp and
         | left any ordering agreeing by accident. The second touched the
         | first row by id - which a descending timestamp order also puts
         | first, so the two orderings still agreed and the bite slipped
         | through again.
         |
         | Moving the row that is LAST by id to the front of a timestamp
         | order is what tells them apart. A hash that moved here would
         | stop matching the file after a vacuum, an index rebuild or a
         | replica read, for reasons nobody could explain.
         */
        DB::connection('tenant')->table('payment_run_instructions')
            ->where('id', $instructions[\count($instructions) - 1])
            ->update(['updated_at' => now()->addHour()]);

        if (app(ReleaseReview::class)->hash($run['id']) !== $once) {
            $failures[] = 'rewriting one row without changing what it says moved the hash, so the hash '.
                'covers bookkeeping columns or an unstable row order rather than the instruction';
        }

        /*
         | And changing an instruction MUST move it.
         |
         | The header alone - number, totals, pay date - already differs
         | between any two runs, so a hash that covered only the header
         | would look like it was working. This is the half that proves
         | the instructions are in it: same run, same header, one amount
         | changed.
         */
        DB::connection('tenant')->table('payment_run_instructions')
            ->where('id', $instructions[0])
            ->update(['amount' => '2999.0000']);

        if (app(ReleaseReview::class)->hash($run['id']) === $once) {
            $failures[] = 'changing what an instruction pays did not change the hash, so the hash covers '.
                'the run\'s header and not where the money is going - which is the half that matters';
        }

        /*
         | And so must the beneficiary, with the amount put back.
         |
         | An amount is the obvious field. The account is the one a
         | fraudulent change would move, and a hash that covered the
         | figures and not the destination would sign off a redirected
         | payment as unchanged.
         */
        DB::connection('tenant')->table('payment_run_instructions')
            ->where('id', $instructions[0])
            ->update(['amount' => '3000.0000', 'party_bank_account_id' => null]);

        if (app(ReleaseReview::class)->hash($run['id']) === $once) {
            $failures[] = 'changing the account an instruction pays into did not change the hash, so a '.
                'redirected payment would be signed off as the one that was authorised';
        }

        return $failures;
    }

    /**
     * The duty pair cannot be overridden, and granting one is refused.
     *
     * @return list<string>
     */
    private function thePairCannotBeOverridden(): array
    {
        $rule = \App\Tenant\Identity\Models\SodRule::query()->where('key', 'prepare_then_release')->first();

        if ($rule === null) {
            return ['nothing pairs preparing a payment run against releasing it'];
        }

        $failures = [];

        if ($rule->overridable) {
            $failures[] = 'prepare_then_release is overridable, so a one-person finance function can send '.
                'money it alone selected';
        }

        $before = auth()->guard('web')->user();

        try {
            $beneficiary = $this->aUserWithRole('controller');
            $approver = $this->aUserWithRole('controller');

            $refusal = $this->refusal(fn () => app(\App\Tenant\Identity\Services\SodEngine::class)->grantOverride(
                $rule,
                $beneficiary,
                $approver,
                'a check asking for the one exception that must not exist',
                Carbon::now()->addDay(),
            ));

            if ($refusal === null) {
                $failures[] = 'an override was granted for prepare_then_release, so the pair that can never '.
                    'be overridden can be - and the control is a setting rather than a rule';
            }

            return $failures;
        } finally {
            $this->signInAs($before);
        }
    }

    /**
     * The releaser's screen answers the question before they commit.
     *
     * @return list<string>
     */
    private function theScreenAsksFirst(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '9000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        $asThePreparer = app(PaymentRuns::class)->beforeRelease($run['id'], $this->actorId());
        $asTheReleaser = app(PaymentRuns::class)->beforeRelease($run['id'], $this->secondActorId());

        $failures = [];

        if (($asThePreparer['is_the_preparer'] ?? false) !== true) {
            $failures[] = 'the screen does not tell the preparer that they prepared it, so they find out by '.
                'pressing the button - and a refusal at that point reads as an obstacle rather than as the '.
                'control working';
        }

        if (($asThePreparer['releasable'] ?? true) !== false) {
            $failures[] = 'the screen offers the preparer a release it will refuse';
        }

        if (($asTheReleaser['releasable'] ?? false) !== true) {
            $failures[] = 'the screen tells a second person an unchanged, prepared run is not releasable, so '.
                'nothing would ever be sent';
        }

        if (($asTheReleaser['hash'] ?? '') === '') {
            $failures[] = 'the screen does not show what would be authorised, so nobody can record it before '.
                'pressing the button';
        }

        return $failures;
    }

    // ---- Duplicate payments - PF-180 --------------------------------------

    /** @return list<string> */
    private function duplicatesInTheWindow(): array
    {
        $supplier = $this->aPayableSupplier();

        /*
         | Two bills, two runs, the same amount, four days apart.
         |
         | Which is the ordinary duplicate: the same invoice entered
         | twice, a week apart, by two people who each paid it.
         */
        $this->anApprovedBill($supplier, '4200.0000', '2026-05-18');
        $first = $this->aRunOn('2026-05-18');

        $this->anApprovedBill($supplier, '4200.0000', '2026-05-22');
        $second = $this->aRunOn('2026-05-22');

        $warnings = app(DuplicatePaymentDetector::class)->unacknowledged($second['id']);
        $failures = [];

        if ($warnings === []) {
            return ['two runs paying one supplier 4,200 four days apart raised nothing - the same '.
                'invoice entered twice is the ordinary duplicate'];
        }

        $warning = $warnings[0];

        if (($warning['days_apart'] ?? null) !== 4) {
            $failures[] = sprintf('the warning says %s days apart rather than 4', (string) ($warning['days_apart'] ?? '-'));
        }

        if (! str_contains((string) $warning['detail'], (string) $first['number'])) {
            $failures[] = 'the warning does not name the other run, so somebody has to go and find it: '.
                (string) $warning['detail'];
        }

        /*
         | And it says what it might innocently be.
         |
         | A warning that only says "duplicate" is one somebody dismisses
         | without thinking, because most of them are retainers.
         */
        if (! str_contains((string) $warning['detail'], 'retainer')) {
            $failures[] = 'the warning does not say what it might innocently be, so it reads as an '.
                'accusation and gets dismissed';
        }

        return $failures;
    }

    /** @return list<string> */
    private function duplicatesAcrossEntities(): array
    {
        $supplier = $this->aPayableSupplier();

        $this->anApprovedBill($supplier, '7300.0000', '2026-05-18');
        $ours = $this->aRunOn('2026-05-18');

        /*
         | A second entity of the same tenant, paying the same supplier
         | the same amount.
         |
         | THE case this task exists for. A group shares suppliers, the
         | same invoice reaches two of them, and neither run looks wrong
         | from inside its own entity - both bank accounts reconcile
         | perfectly and the supplier says nothing.
         |
         | Every other query in this product is scoped to one entity by
         | row policy. This one cannot be, which is why the scan is a
         | definer function.
         */
        $other = \App\Tenant\Tenancy\Models\Entity::create([
            'code' => 'DUP'.Str::upper(Str::random(5)),
            'legal_name' => 'A second company in the same group',
            'country' => 'SA',
            'functional_currency' => 'SAR',
            'active' => true,
        ]);

        $context = app(\App\Tenant\Tenancy\Services\EntityContext::class);
        $failures = [];

        /*
         | Written from the OTHER entity's context, because the row
         | policy refuses it otherwise.
         |
         | Which is the policy doing its job: the fixture's first draft
         | inserted from this entity and was refused, and that refusal is
         | the same one that makes the definer function necessary. A
         | check that worked round it with the owner's credentials would
         | be standing on a state the system refuses.
         |
         | Written directly rather than prepared properly, because
         | setting a second entity up far enough to prepare a run is a
         | second fixture for the whole phase - and what is asserted
         | here is the SCAN.
         */
        $theirRun = (string) Str::ulid();
        $thisEntity = \App\Tenant\Tenancy\Models\Entity::query()->findOrFail($this->entityId);

        /*
         | In a `finally`, because an exception must not leave the
         | session pointed at the other entity.
         |
         | The first draft switched, inserted and switched back in a
         | straight line. The insert threw - a column it had not filled -
         | and every check after it in this suite failed with "that bank
         | account does not belong to this entity", which says nothing
         | about what went wrong. A fixture that can leak the entity
         | context is one whose failures are unreadable.
         */
        try {
            $context->use($other);

            DB::connection('tenant')->table('payment_runs')->insert([
                'id' => $theirRun,
                'entity_id' => (string) $other->getKey(),
                'number' => 'RUN-OTHER-'.Str::upper(Str::random(5)),
                'bank_account_id' => (string) $this->bank()->getKey(),
                'currency' => 'SAR',
                'pay_on' => '2026-05-20',
                'state' => 'prepared',
                'criteria' => json_encode(['horizon_days' => 0], JSON_THROW_ON_ERROR),
                'gross_total' => '7300.0000',
                'discount_total' => '0.0000',
                'net_total' => '7300.0000',
                'prepared_by' => $this->actorId(),
                'prepared_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('tenant')->table('payment_run_instructions')->insert([
                'id' => (string) Str::ulid(),
                'entity_id' => (string) $other->getKey(),
                'run_id' => $theirRun,
                'party_id' => (string) $supplier->getKey(),
                'amount' => '7300.0000',
                'currency' => 'SAR',
                'sequence' => 1,
                'of' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $context->use($thisEntity);
        }

        /*
         | And our own entity cannot SEE their run, which is the point.
         |
         | If it could, the detector would not need a definer function -
         | and the row policy would not be doing its job.
         */
        $visible = (int) DB::connection('tenant')->table('payment_runs')
            ->where('id', $theirRun)
            ->count();

        if ($visible !== 0) {
            $failures[] = 'another entity\'s payment run is visible from this one, so the row policy '.
                'is not scoping payment runs at all';
        }

        app(DuplicatePaymentDetector::class)->examine($ours['id']);
        $warnings = app(DuplicatePaymentDetector::class)->unacknowledged($ours['id']);

        $crossEntity = array_values(array_filter(
            $warnings,
            static fn (array $w): bool => $w['reason'] === 'same_amount_another_entity',
        ));

        if ($crossEntity === []) {
            $failures[] = sprintf(
                'a second entity of the same tenant is paying the same supplier the same amount and '.
                'nothing was raised as a cross-entity duplicate - which is the one that actually gets '.
                'paid, because neither run looks wrong from inside its own entity. Raised instead: %s',
                json_encode(array_column($warnings, 'reason'), JSON_THROW_ON_ERROR) ?: 'nothing',
            );

            return $failures;
        }

        if (! str_contains((string) $crossEntity[0]['detail'], (string) $crossEntity[0]['other_reference'])) {
            $failures[] = 'the cross-entity warning does not name the other run in its sentence, so '.
                'somebody has to go and find it: '.(string) $crossEntity[0]['detail'];
        }

        if (($crossEntity[0]['other_entity'] ?? '') !== (string) $other->code) {
            $failures[] = sprintf(
                'the warning names %s rather than the entity that is paying - and "somebody else is '.
                'paying this too" is useless without which',
                (string) ($crossEntity[0]['other_entity'] ?? '-'),
            );
        }

        /*
         | And the projection is narrow.
         |
         | The definer function reads past the row policy, so what it
         | hands back is the access decision. Anything beyond the entity
         | code, the reference and the dates would be another entity's
         | data arriving through a path nobody audits.
         */
        $row = DB::connection('tenant')->select(
            'SELECT * FROM peakflow_similar_payments(?, ?, ?, ?, ?, ?, ?)',
            [$this->entityId, (string) $supplier->getKey(), '7300.0000', 'SAR', '2026-05-18', 10, null],
        );

        $columns = $row === [] ? [] : array_keys((array) $row[0]);
        $permitted = ['source', 'other_entity_code', 'other_reference', 'other_date', 'days_apart'];

        if (array_diff($columns, $permitted) !== []) {
            $failures[] = sprintf(
                'the cross-entity scan returns %s - anything beyond %s is another entity\'s data '.
                'arriving through a path nobody audits',
                implode(', ', array_diff($columns, $permitted)),
                implode(', ', $permitted),
            );
        }

        unset($context);

        return $failures;
    }

    /** @return list<string> */
    private function duplicatesAgainstPayments(): array
    {
        $supplier = $this->aPayableSupplier();

        /*
         | A payment that has already gone out, by hand.
         |
         | The half a run-only check misses: the first payment was
         | recorded a week ago, its run is archived or it was entered
         | directly, and the second run has nothing to compare against.
         */
        DB::connection('tenant')->table('payments')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'number' => 'PMT-DUP-'.Str::upper(Str::random(5)),
            'direction' => 'disbursement',
            /*
             | UNCL 4461 code 30: a credit transfer.
             |
             | The column holds the standard's code rather than a word,
             | which is what an e-invoice carries - and the fixture using
             | "transfer" was refused by a varchar(4), which is the
             | column telling the truth about its own contents.
             */
            'method' => '30',
            'bank_account_id' => (string) $this->bank()->getKey(),
            'party_id' => (string) $supplier->getKey(),
            'amount' => '1900.0000',
            'currency' => 'SAR',
            'value_date' => '2026-05-15',
            'recorded_by' => $this->actorId(),
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->anApprovedBill($supplier, '1900.0000', '2026-05-18');
        $run = $this->aRunOn('2026-05-18');

        $warnings = app(DuplicatePaymentDetector::class)->unacknowledged($run['id']);

        $alreadyPaid = array_values(array_filter(
            $warnings,
            static fn (array $w): bool => $w['reason'] === 'same_amount_already_paid',
        ));

        if ($alreadyPaid === []) {
            return [sprintf(
                'a payment of the same amount to the same supplier three days earlier raised nothing '.
                '- a check that only looks at open runs misses the one that was entered by hand. '.
                'Raised instead: %s',
                json_encode(array_column($warnings, 'reason'), JSON_THROW_ON_ERROR) ?: 'nothing',
            )];
        }

        $failures = str_contains((string) $alreadyPaid[0]['detail'], 'already gone out')
            ? []
            : ['the warning does not say that the money has already left: '.(string) $alreadyPaid[0]['detail']];

        /*
         | And a REVERSED payment is not a duplicate.
         |
         | A payment that was recorded and then reversed did not happen:
         | the money came back. Counting it would warn about every
         | correction, and a warning that fires on corrections is one
         | people learn to accept without reading - which is how the
         | real duplicate goes through.
         */
        $reversedSupplier = $this->aPayableSupplier();

        DB::connection('tenant')->table('payments')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'number' => 'PMT-REV-'.Str::upper(Str::random(5)),
            'direction' => 'disbursement',
            'method' => '30',
            'bank_account_id' => (string) $this->bank()->getKey(),
            'party_id' => (string) $reversedSupplier->getKey(),
            'amount' => '2600.0000',
            'currency' => 'SAR',
            'value_date' => '2026-05-15',
            'recorded_by' => $this->actorId(),
            'recorded_at' => now(),
            'reversed_at' => now(),
            'reversed_by' => $this->secondActorId(),
            'reversal_reason' => 'the transfer was recalled by the bank the same afternoon',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->anApprovedBill($reversedSupplier, '2600.0000', '2026-05-18');
        $afterReversal = $this->aRunOn('2026-05-18');

        $raised = app(DuplicatePaymentDetector::class)->unacknowledged($afterReversal['id']);

        if ($raised !== []) {
            $failures[] = sprintf(
                'a payment that was reversed still counts as one that went out, so every correction '.
                'raises a warning - and a warning that fires on corrections is one people accept '.
                'without reading. Raised: %s',
                json_encode(array_column($raised, 'reason'), JSON_THROW_ON_ERROR),
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function warningsBlockRelease(): array
    {
        $supplier = $this->aPayableSupplier();

        $this->anApprovedBill($supplier, '5500.0000', '2026-05-18');
        $this->aRunOn('2026-05-18');

        $this->anApprovedBill($supplier, '5500.0000', '2026-05-20');
        $second = $this->aRunOn('2026-05-20');

        $failures = [];

        $refusal = $this->refusal(fn () => app(PaymentRuns::class)->release(
            $second['id'],
            $this->secondActorId(),
        ));

        if ($refusal === null) {
            return ['a run with an unaccepted duplicate warning was released - a warning that did not '.
                'block would be read after the supplier was paid twice'];
        }

        /*
         | And the refusal LISTS what was found.
         |
         | "Three warnings" sends somebody to a screen. The sentence that
         | names the other run, the amount and the days apart is one they
         | can act on without leaving the error.
         */
        if (! str_contains($refusal, 'day(s) apart')) {
            $failures[] = 'the refusal counts the warnings rather than saying what they are: '.$refusal;
        }

        /*
         | And accepting them lets it through.
         |
         | Blocked-and-unblockable would be worked around by changing an
         | amount by a penny, and then nothing is checked at all.
         */
        foreach (app(DuplicatePaymentDetector::class)->unacknowledged($second['id']) as $warning) {
            app(DuplicatePaymentDetector::class)->acknowledge(
                (string) $warning['id'],
                'this is the monthly retainer, not the invoice we paid on the 18th',
                $this->secondActorId(),
            );
        }

        if (app(DuplicatePaymentDetector::class)->unacknowledged($second['id']) !== []) {
            $failures[] = 'accepting every warning left some unaccepted';
        }

        /*
         | And re-examining does NOT ask again.
         |
         | `release()` looks a second time, because the world moves
         | between preparing a run and releasing it. An examination that
         | cleared accepted warnings would ask the same person the same
         | question twice - which loses the signature they already gave
         | and trains them to click through.
         */
        $accepted = (int) DB::connection('tenant')->table('payment_duplicate_warnings')
            ->where('run_id', $second['id'])
            ->whereNotNull('acknowledged_at')
            ->count();

        app(DuplicatePaymentDetector::class)->examine($second['id']);

        $afterwards = (int) DB::connection('tenant')->table('payment_duplicate_warnings')
            ->where('run_id', $second['id'])
            ->whereNotNull('acknowledged_at')
            ->count();

        if ($afterwards !== $accepted || $accepted === 0) {
            $failures[] = sprintf(
                're-examining the run left %d accepted warning(s) of %d - an examination that clears '.
                'them asks the same person the same question twice, loses the signature they gave and '.
                'trains them to click through',
                $afterwards,
                $accepted,
            );
        }

        if (app(DuplicatePaymentDetector::class)->unacknowledged($second['id']) !== []) {
            $failures[] = 're-examining raised the accepted warnings again as new ones';
        }

        return $failures;
    }

    /** @return list<string> */
    private function acceptanceIsAttributable(): array
    {
        $supplier = $this->aPayableSupplier();

        $this->anApprovedBill($supplier, '3100.0000', '2026-05-18');
        $this->aRunOn('2026-05-18');

        $this->anApprovedBill($supplier, '3100.0000', '2026-05-19');
        $second = $this->aRunOn('2026-05-19');

        $warning = app(DuplicatePaymentDetector::class)->unacknowledged($second['id'])[0] ?? null;

        if ($warning === null) {
            return ['the fixture raised no warning, so this check is not testing what it claims'];
        }

        $detector = app(DuplicatePaymentDetector::class);
        $failures = [];

        $thin = $this->refusal(fn () => $detector->acknowledge((string) $warning['id'], 'checked', $this->secondActorId()));

        if ($thin === null) {
            $failures[] = 'a duplicate warning was accepted with "checked" - it is what an '.
                'investigation reads six months later when the supplier has been paid twice';
        } elseif (! str_contains($thin, 'six months later')) {
            /*
             | And the sentence is the service's, not the column's.
             |
             | The constraint refuses a short acknowledgement too, which
             | is the control - but "violates check constraint
             | payment_duplicate_warnings_acknowledgement_is_attributable"
             | is read by somebody about to release a payment run.
             */
            $failures[] = 'a thin acknowledgement is refused by the column rather than explained by '.
                'the service: '.$thin;
        }

        $detector->acknowledge(
            (string) $warning['id'],
            'this is the monthly retainer, not the invoice we paid on the 18th',
            $this->secondActorId(),
        );

        $row = DB::connection('tenant')->table('payment_duplicate_warnings')
            ->where('id', (string) $warning['id'])
            ->first();

        if (($row->acknowledged_by ?? null) !== $this->secondActorId()) {
            $failures[] = 'the acceptance does not name who made it';
        }

        /*
         | And it cannot be accepted twice.
         |
         | Replacing the name against a payment that may already have
         | gone out is how an investigation finds the wrong person.
         */
        $again = $this->refusal(fn () => $detector->acknowledge(
            (string) $warning['id'],
            'on reflection it was a duplicate after all',
            $this->actorId(),
        ));

        if ($again === null) {
            $failures[] = 'a warning was accepted twice, so the name against a payment that already '.
                'went out can be replaced';
        } elseif (! str_contains($again, 'may already have gone out')) {
            $failures[] = 'a second acceptance is refused by the trigger rather than explained by the '.
                'service: '.$again;
        }

        $direct = $this->refusal(fn () => DB::connection('tenant')->table('payment_duplicate_warnings')
            ->where('id', (string) $warning['id'])
            ->update(['acknowledged_by' => $this->actorId()]));

        if ($direct === null) {
            $failures[] = 'the database lets an acceptance be rewritten, so the signature against a '.
                'payment is not evidence of anything';
        }

        /*
         | And the acceptance is in the audit trail.
         */
        $recorded = DB::connection('tenant')->table('audit_log')
            ->where('action', 'duplicate_payment.accepted')
            ->where('subject_id', (string) $warning['id'])
            ->first();

        if ($recorded === null) {
            $failures[] = 'accepting a duplicate warning is not in the audit trail, so the only record '.
                'of who took the risk is a column somebody can repair';
        }

        return $failures;
    }

    /** @return list<string> */
    private function theWindowBounds(): array
    {
        $supplier = $this->aPayableSupplier();

        /*
         | The same amount, well outside the ten-day window.
         |
         | A window of sixty days would warn about every monthly
         | retainer, and a warning that fires every time is a warning
         | nobody reads. So this has to NOT fire - a check that only
         | proved detection would pass with a detector that flags
         | everything.
         */
        $this->anApprovedBill($supplier, '8800.0000', '2026-03-01');
        $this->aRunOn('2026-03-01');

        $this->anApprovedBill($supplier, '8800.0000', '2026-05-18');
        $later = $this->aRunOn('2026-05-18');

        $warnings = app(DuplicatePaymentDetector::class)->unacknowledged($later['id']);

        return $warnings === []
            ? []
            : [sprintf(
                'two payments 78 days apart raised %d warning(s) against a %d-day window - a detector '.
                'that flags every monthly retainer is one nobody reads',
                \count($warnings),
                (int) config('settlement.duplicate_window_days'),
            )];
    }

    // ---- Statements - PF-181 ----------------------------------------------

    /** @return list<string> */
    private function openItemsList(): array
    {
        $customer = $this->aBilledCustomer();
        $statements = app(Statements::class);

        $statement = $statements->openItems(
            entityId: $this->entityId,
            partyId: (string) $customer->getKey(),
            currency: 'SAR',
            asAt: Carbon::parse('2026-06-30'),
        );

        $failures = [];
        $documents = array_values(array_filter(
            $statement['items'],
            static fn (array $i): bool => $i['kind'] === 'document',
        ));

        if ($documents === []) {
            return ['an open-item statement lists nothing for a customer with an unpaid invoice - it '.
                'is the only form that can be reconciled line by line against their own ledger'];
        }

        $line = $documents[0];

        foreach (['reference', 'issued_on', 'gross', 'open'] as $field) {
            if (trim((string) ($line[$field] ?? '')) === '') {
                $failures[] = sprintf('the line carries no %s', $field);
            }
        }

        /*
         | The OPEN balance, not the gross.
         |
         | A statement that listed the gross of a part-paid invoice
         | would ask the customer for money they have already sent, and
         | the telephone call that follows is the one this form exists
         | to prevent.
         */
        if (Money::of((string) $line['open'], 'SAR')->amount->compareTo('1150') !== 0) {
            $failures[] = sprintf(
                'the line shows %s open on a 1,150 invoice with nothing paid against it',
                (string) $line['open'],
            );
        }

        /*
         | And a PART-paid invoice shows what is left, not the gross.
         |
         | Which is the only way to tell the two apart: on an untouched
         | invoice they are the same number, so a check that stopped
         | above would pass on a statement listing the gross. The bite
         | sweep found exactly that.
         |
         | A statement that asked for the gross of a part-paid invoice
         | asks the customer for money they have already sent, and the
         | telephone call that follows is the one this form exists to
         | prevent.
         */
        $partPaid = $this->issue();
        $payment = $this->recordReceipt((string) $customer->getKey(), '400.0000', '2026-05-20');

        app(Allocations::class)->allocate(
            paymentId: $payment,
            documentType: 'invoice',
            documentId: (string) $partPaid->getKey(),
            amount: Money::of('400.0000', 'SAR'),
            userId: $this->actorId(),
            on: Carbon::parse('2026-05-20'),
        );

        $withPartPaid = $statements->openItems(
            entityId: $this->entityId,
            partyId: (string) $customer->getKey(),
            currency: 'SAR',
            asAt: Carbon::parse('2026-06-30'),
        );

        $partLine = null;

        foreach ($withPartPaid['items'] as $candidate) {
            if (($candidate['reference'] ?? null) === (string) $partPaid->number) {
                $partLine = $candidate;

                break;
            }
        }

        if ($partLine === null) {
            $failures[] = 'a part-paid invoice fell off the open-item statement entirely';
        } elseif (Money::of((string) $partLine['open'], 'SAR')->amount->compareTo('750') !== 0) {
            $failures[] = sprintf(
                'a 1,150 invoice with 400 paid against it shows %s open - a statement that lists the '.
                'gross asks the customer for money they have already sent',
                (string) $partLine['open'],
            );
        }

        /*
         | And a settled invoice is not on it.
         |
         | Which is the difference between an open-item statement and a
         | list of invoices: the customer is looking for what they still
         | have to pay.
         */
        $settled = $this->issue();
        $this->payInFull($settled, (string) $customer->getKey());

        $after = $statements->openItems(
            entityId: $this->entityId,
            partyId: (string) $customer->getKey(),
            currency: 'SAR',
            asAt: Carbon::parse('2026-06-30'),
        );

        $references = array_column(array_filter(
            $after['items'],
            static fn (array $i): bool => $i['kind'] === 'document',
        ), 'reference');

        if (\in_array((string) $settled->number, $references, true)) {
            $failures[] = 'a fully settled invoice is still on the open-item statement, so the '.
                'customer is asked for money they have already sent';
        }

        return $failures;
    }

    /** @return list<string> */
    private function cashIsLabelled(): array
    {
        $customer = $this->aBilledCustomer();

        /*
         | A payment received and applied to nothing.
         |
         | Which happens constantly: the customer pays a round figure, or
         | pays before the invoice is issued, or pays for something
         | nobody has matched yet.
         */
        $this->recordReceipt((string) $customer->getKey(), '400.0000', '2026-05-20');

        $statement = app(Statements::class)->openItems(
            entityId: $this->entityId,
            partyId: (string) $customer->getKey(),
            currency: 'SAR',
            asAt: Carbon::parse('2026-06-30'),
        );

        $failures = [];
        $cash = array_values(array_filter(
            $statement['items'],
            static fn (array $i): bool => $i['kind'] === 'unapplied_cash',
        ));

        if ($cash === []) {
            return ['400 received and applied to nothing does not appear on the statement at all, so '.
                'the total is short by it and the customer cannot see why'];
        }

        /*
         | Its own line, with its own label, reducing the total.
         |
         | Netting it against the oldest invoice is a decision the
         | system would be making on the customer's behalf, invisibly -
         | and then they telephone about an invoice they believe they
         | paid, and nobody can see which payment was used.
         */
        if (Money::of((string) $cash[0]['open'], 'SAR')->amount->compareTo('-400') !== 0) {
            $failures[] = sprintf(
                'the unapplied cash line reads %s rather than -400',
                (string) $cash[0]['open'],
            );
        }

        if (! str_contains((string) $cash[0]['note'], 'rather than netted')) {
            $failures[] = 'the line does not say why it is listed separately, so it reads as an error';
        }

        /*
         | And the invoice is still shown at its full open balance.
         |
         | Which is the half that proves nothing was netted.
         */
        $documents = array_values(array_filter(
            $statement['items'],
            static fn (array $i): bool => $i['kind'] === 'document',
        ));

        if (Money::of((string) ($documents[0]['open'] ?? '0'), 'SAR')->amount->compareTo('1150') !== 0) {
            $failures[] = sprintf(
                'the invoice shows %s open, so the unapplied payment was quietly netted against it '.
                'after all',
                (string) ($documents[0]['open'] ?? '-'),
            );
        }

        if (Money::of((string) $statement['total'], 'SAR')->amount->compareTo('750') !== 0) {
            $failures[] = sprintf(
                'the statement totals %s rather than 750 - the figure has to be the same whether the '.
                'cash is netted or labelled, which is the point',
                (string) $statement['total'],
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function disputesAreLabelled(): array
    {
        $customer = $this->aBilledCustomer();
        $invoice = DB::connection('tenant')->table('invoices')
            ->where('party_id', (string) $customer->getKey())
            ->where('status', 'issued')
            ->first();

        DB::connection('tenant')->table('document_disputes')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'document_type' => 'invoice',
            'document_id' => (string) $invoice->id,
            'party_id' => (string) $customer->getKey(),
            'reason_code' => 'quality',
            'detail' => 'the customer says two of the licences were never activated',
            'disputed_amount' => '500.0000',
            'currency' => 'SAR',
            'raised_by_user_id' => $this->actorId(),
            'raised_at' => now(),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $statement = app(Statements::class)->openItems(
            entityId: $this->entityId,
            partyId: (string) $customer->getKey(),
            currency: 'SAR',
            asAt: Carbon::parse('2026-06-30'),
        );

        $documents = array_values(array_filter(
            $statement['items'],
            static fn (array $i): bool => $i['kind'] === 'document',
        ));

        $failures = [];

        if ($documents === []) {
            return ['a disputed invoice was removed from the statement, which makes it disagree with '.
                'the control account and tells the customer we have forgotten their complaint'];
        }

        if (($documents[0]['disputed'] ?? false) !== true) {
            $failures[] = 'the disputed invoice is not labelled as one, so the customer reads a demand '.
                'for money they have already queried - and raises it again, to somebody else';
        }

        if (! str_contains((string) ($documents[0]['note'] ?? ''), 'never activated')) {
            $failures[] = 'the statement does not say what the dispute is about, so the person reading '.
                'it cannot tell whether it is the one they know about';
        }

        /*
         | And it still counts toward the total.
         |
         | A disputed invoice is still owed until somebody decides
         | otherwise. Removing it would make the statement disagree with
         | the control account, which is the one thing this form has to
         | do.
         */
        if (Money::of((string) $statement['total'], 'SAR')->amount->compareTo('1150') !== 0) {
            $failures[] = sprintf(
                'the disputed invoice was taken out of the total, which now reads %s - a statement '.
                'that drops disputes stops reconciling to the control account',
                (string) $statement['total'],
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function balanceForwardAddsUp(): array
    {
        $customer = $this->aBilledCustomer();
        $this->recordReceipt((string) $customer->getKey(), '400.0000', '2026-05-25', allocate: true);

        $statement = app(Statements::class)->balanceForward(
            entityId: $this->entityId,
            partyId: (string) $customer->getKey(),
            currency: 'SAR',
            from: Carbon::parse('2026-05-01'),
            until: Carbon::parse('2026-05-31'),
        );

        $failures = [];

        if (($statement['adds_up'] ?? false) !== true) {
            $failures[] = 'the column does not add up: '.(string) $statement['why'];
        }

        /*
         | And the check does the arithmetic ITSELF.
         |
         | `adds_up` is the service's own opinion of its own column, so
         | a product that hard-coded it true would pass the assertion
         | above - which the bite sweep demonstrated. Adding the
         | movements here is the only way to know.
         */
        $running = Money::of((string) $statement['opening'], 'SAR');

        foreach ($statement['movements'] as $movement) {
            $running = $running->plus(Money::of((string) $movement['amount'], 'SAR'));
        }

        if (! $running->equals(Money::of((string) $statement['closing'], 'SAR'))) {
            $failures[] = sprintf(
                'opening %s plus the movements comes to %s while the statement closes at %s - the '.
                'column does not add up, whatever the report says about itself',
                (string) $statement['opening'],
                (string) $running->amount,
                (string) $statement['closing'],
            );
        }

        if ($statement['movements'] === []) {
            $failures[] = 'a month with an invoice and a payment in it shows no movements, so nobody '.
                'can see how the balance got from one figure to the other';
        }

        /*
         | A running balance on every line.
         |
         | Which is the whole reason for this form: "how did we get from
         | last month's figure to this one" is answered by reading down
         | the column, not by adding the lines up yourself.
         */
        foreach ($statement['movements'] as $index => $movement) {
            if (trim((string) ($movement['running'] ?? '')) === '') {
                $failures[] = sprintf('movement %d carries no running balance', $index + 1);
            }
        }

        $closing = Money::of((string) $statement['closing'], 'SAR');

        if ($closing->amount->compareTo('750') !== 0) {
            $failures[] = sprintf(
                'a 1,150 invoice less a 400 payment closes at %s rather than 750',
                (string) $statement['closing'],
            );
        }

        /*
         | And a reversed period is refused.
         |
         | An opening balance later than its closing one makes the
         | movement between them read as its own negative.
         */
        $reversed = $this->refusal(fn () => app(Statements::class)->balanceForward(
            entityId: $this->entityId,
            partyId: (string) $customer->getKey(),
            currency: 'SAR',
            from: Carbon::parse('2026-05-31'),
            until: Carbon::parse('2026-05-01'),
        ));

        if ($reversed === null) {
            $failures[] = 'a statement period that ends before it starts was accepted';
        }

        return $failures;
    }

    /** @return list<string> */
    private function statementsSayIfTheyReconcile(): array
    {
        $this->aBilledCustomer();

        $reconciliation = app(Statements::class)->reconciliation($this->entityId, 'SAR');
        $failures = [];

        if (($reconciliation['reconciles'] ?? false) !== true) {
            $failures[] = 'the open items do not reconcile to the receivables control account in a '.
                'fixture that has only issued invoices: '.(string) $reconciliation['why'];
        }

        /*
         | And it SAYS so, in words, either way.
         |
         | A report that printed the figure only when the two agreed
         | would be silent exactly when it matters.
         */
        if (! str_contains((string) $reconciliation['why'], 'reconcile')) {
            $failures[] = 'the reconciliation does not say whether it reconciles, which is the '.
                'criterion: "rather than leaving it implied"';
        }

        foreach (['subsidiary_ledger', 'control_account', 'difference'] as $field) {
            if (trim((string) ($reconciliation[$field] ?? '')) === '') {
                $failures[] = sprintf('the reconciliation does not report the %s', $field);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function aBreakIsReported(): array
    {
        $this->aBilledCustomer();

        /*
         | A journal line against the receivables control that no
         | document explains.
         |
         | Which is what a break looks like: somebody posted a manual
         | entry to the control account, or an import went in sideways.
         | The statement has to say so rather than print a figure that
         | is quietly wrong.
         */
        $control = (string) DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', 'accounts_receivable')
            ->value('account_id');

        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('entity_id', $this->entityId)
            ->value('id');

        $existing = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', (string) $entry)
            ->first();

        DB::connection('tenant')->table('journal_lines')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'journal_entry_id' => (string) $entry,
            'accounting_date' => (string) $existing->accounting_date,
            'period_id' => (string) $existing->period_id,
            'account_id' => $control,
            'amount' => '250.0000',
            'currency' => 'SAR',
            'functional_amount' => '250.0000',
            'narration' => 'a manual entry nobody matched to a document',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reconciliation = app(Statements::class)->reconciliation($this->entityId, 'SAR');
        $failures = [];

        if (($reconciliation['reconciles'] ?? true) !== false) {
            return ['a 250 entry against the control account that no document explains was not '.
                'reported as a break - a statement that was right about one customer while the '.
                'control account disagreed sends somebody looking in the wrong place for a week'];
        }

        if (Money::of((string) $reconciliation['difference'], 'SAR')->amount->compareTo('-250') !== 0) {
            $failures[] = sprintf(
                'the difference reads %s rather than -250',
                (string) $reconciliation['difference'],
            );
        }

        /*
         | And it says what to look for.
         |
         | "Does not reconcile" sends somebody to a spreadsheet. Naming
         | the two shapes a break takes - a document with no allocation,
         | an allocation with no document - sends them to the query.
         */
        if (! str_contains((string) $reconciliation['why'], 'posted without an allocation')) {
            $failures[] = 'the break does not say what shape it usually takes: '.(string) $reconciliation['why'];
        }

        return $failures;
    }

    /** @return list<string> */
    private function supplierStatements(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '3400.0000', '2026-05-18');

        $statement = app(Statements::class)->openItems(
            entityId: $this->entityId,
            partyId: (string) $supplier->getKey(),
            currency: 'SAR',
            asAt: Carbon::parse('2026-06-30'),
            side: 'supplier',
        );

        $failures = [];

        if ($statement['items'] === []) {
            $failures[] = 'a supplier statement lists nothing for an approved unpaid bill';
        } elseif (($statement['items'][0]['label'] ?? '') !== 'Bill') {
            $failures[] = sprintf(
                'the supplier statement labels its lines "%s" - the two sides read different documents',
                (string) ($statement['items'][0]['label'] ?? '-'),
            );
        }

        /*
         | And the payables control is compared the right way round.
         |
         | A payables control carries a CREDIT balance, so comparing it
         | raw against a positive subsidiary ledger would report every
         | entity as out by TWICE the balance - a break nobody can find,
         | because there is not one.
         |
         | This fixture's bills are approved and not posted, so the
         | control is zero and the subsidiary ledger is not: what is
         | asserted is that the difference comes back as the subsidiary
         | figure itself rather than doubled, which is what a raw
         | comparison would give once anything IS posted.
         */
        /*
         | A credit against the payables control, so the sign matters.
         |
         | With nothing posted the control is zero, and zero negated is
         | zero - so a check without this passes whether the comparison
         | is the right way round or not. The bite sweep showed it.
         */
        $payables = (string) DB::connection('tenant')->table('account_roles')
            ->where('entity_id', $this->entityId)
            ->where('role', 'accounts_payable')
            ->value('account_id');

        $anyLine = DB::connection('tenant')->table('journal_lines')
            ->where('entity_id', $this->entityId)
            ->first();

        DB::connection('tenant')->table('journal_lines')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'journal_entry_id' => (string) $anyLine->journal_entry_id,
            'accounting_date' => (string) $anyLine->accounting_date,
            'period_id' => (string) $anyLine->period_id,
            'account_id' => $payables,
            'amount' => '-3400.0000',
            'currency' => 'SAR',
            'functional_amount' => '-3400.0000',
            'narration' => 'what an approved bill would credit once it posts',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reconciliation = app(Statements::class)->reconciliation($this->entityId, 'SAR', 'supplier');

        /*
         | 3,400 of bills against a control holding 3,400 credit.
         |
         | Compared the right way round they reconcile. Compared raw,
         | the difference is 6,800 - twice the balance, which is a break
         | nobody can find because there is not one.
         */
        if (($reconciliation['reconciles'] ?? false) !== true) {
            $failures[] = sprintf(
                'a 3,400 payables control against 3,400 of open bills does not reconcile (difference '.
                '%s) - a credit balance compared raw against a positive subsidiary ledger reports a '.
                'break of twice the balance, which nobody can find because there is not one',
                (string) $reconciliation['difference'],
            );
        }

        if (Money::of((string) $reconciliation['control_account'], 'SAR')->isNegative()) {
            $failures[] = sprintf(
                'the payables control is reported as %s - a credit balance compared raw against a '.
                'positive subsidiary ledger reports a break of twice the balance, which nobody can '.
                'find because there is not one',
                (string) $reconciliation['control_account'],
            );
        }

        if (! str_contains((string) $reconciliation['why'], 'accounts payable')) {
            $failures[] = 'the supplier reconciliation does not name the control account it compared '.
                'against: '.(string) $reconciliation['why'];
        }

        /*
         | And a side that is neither is refused.
         */
        if ($this->refusal(fn () => app(Statements::class)->openItems(
            entityId: $this->entityId,
            partyId: (string) $supplier->getKey(),
            currency: 'SAR',
            asAt: Carbon::parse('2026-06-30'),
            side: 'everyone',
        )) === null) {
            $failures[] = 'a statement was produced for a side that is neither a customer nor a '.
                'supplier, which reads from different documents against different control accounts';
        }

        return $failures;
    }

    // ---- Helpers ----------------------------------------------------------

    // ---- Statement fixtures - PF-181 --------------------------------------

    /**
     * A customer with one issued, unpaid invoice of 1,150.00.
     *
     * The shared draft's own figures - 1,000 net and 150 tax - because
     * a statement check that invented its own amounts would be asserting
     * arithmetic rather than the statement.
     */
    private function aBilledCustomer(): \App\Tenant\Parties\Models\Party
    {
        $this->issue();

        \assert($this->party !== null);

        return $this->party;
    }

    /**
     * Money received from a party, optionally applied to their invoice.
     *
     * Unapplied by default, because that is the case the statement has
     * to label rather than net.
     */
    private function recordReceipt(
        string $partyId,
        string $amount,
        string $on,
        bool $allocate = false,
    ): string {
        $payment = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Receipt,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of($amount, 'SAR'),
            valueDate: Carbon::parse($on),
            userId: $this->actorId(),
            bankAccountId: (string) $this->bank()->getKey(),
            partyId: $partyId,
        );

        if ($allocate) {
            $invoice = DB::connection('tenant')->table('invoices')
                ->where('party_id', $partyId)
                ->where('status', 'issued')
                ->orderBy('issue_date')
                ->first();

            /*
             | Dated in the period the fixture opened, not `now()`.
             |
             | `allocate()` defaults its date to today, and today is
             | outside the only open period - so the fixture's first
             | draft failed with "period 2026-11 is future", which is
             | the period machinery working and the fixture not.
             */
            app(Allocations::class)->allocate(
                paymentId: (string) $payment['id'],
                documentType: 'invoice',
                documentId: (string) $invoice->id,
                amount: Money::of($amount, 'SAR'),
                userId: $this->actorId(),
                on: Carbon::parse($on),
            );
        }

        return (string) $payment['id'];
    }

    /** Settle a document completely, so it leaves the open-item list. */
    private function payInFull(object $invoice, string $partyId): void
    {
        $payment = $this->recordReceipt($partyId, (string) $invoice->gross_amount, '2026-05-20');

        app(Allocations::class)->allocate(
            paymentId: $payment,
            documentType: 'invoice',
            documentId: (string) $invoice->getKey(),
            amount: Money::of((string) $invoice->gross_amount, 'SAR'),
            userId: $this->actorId(),
            on: Carbon::parse('2026-05-20'),
        );
    }

    /**
     * A prepared run on a date, from whatever is payable.
     *
     * @return array<string, mixed>
     */
    private function aRunOn(string $payOn): array
    {
        return app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse($payOn),
            userId: $this->actorId(),
        );
    }
    /**
     * Sign a user in, or sign out when there was nobody.
     *
     * `setUser(null)` is a TypeError, and the first draft of these checks
     * restored the guard that way in a `finally` - so the exception
     * replaced the check's own result AND left the releaser signed in for
     * every check after it. Three unrelated checks then failed demanding
     * a second factor, which is a confusing way to be told about a leak.
     */
    private function signInAs(?\App\Tenant\Identity\Models\User $user): void
    {
        if ($user === null) {
            auth()->guard('web')->logout();
            session()->forget('factor_asserted_at');

            return;
        }

        auth()->guard('web')->setUser($user);
    }

    /** A real user holding a role, for the checks that need a person. */
    private function aUserWithRole(string $role): \App\Tenant\Identity\Models\User
    {
        $user = \App\Tenant\Identity\Models\User::create([
            'name' => 'Check '.$role,
            'email' => $role.random_int(100000, 999999).'@check.test',
            'password' => 'x-not-a-real-password',
            'active' => true,
        ]);

        \App\Tenant\Identity\Models\UserEntityRole::create([
            'user_id' => $user->getKey(),
            'entity_id' => $this->entityId,
            'role_id' => \App\Tenant\Identity\Models\Role::query()->where('key', $role)->firstOrFail()->getKey(),
        ]);

        return $user;
    }

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

    /**
     * Open the period a date falls in, if it is not open already.
     *
     * A generated calendar's periods start `future`, and `future` does not
     * accept postings - which is right, and is what stops December being
     * posted into in March. A check that spans two periods opens the
     * second rather than pretending a posting can land in one nobody
     * opened.
     */
    private function openThePeriodFor(string $date): void
    {
        $period = app(\App\Tenant\Ledger\Services\FiscalCalendarGenerator::class)
            ->periodFor($this->entityId, Carbon::parse($date));

        if ($period === null || $period->state === \App\Tenant\Ledger\Enums\PeriodState::Open) {
            return;
        }

        app(\App\Tenant\Ledger\Services\PeriodStateMachine::class)->transition(
            $period,
            \App\Tenant\Ledger\Enums\PeriodState::Open,
            'opened so a second period can be computed in this check',
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

    // ---- The payment run - helpers ----------------------------------------

    /**
     * Settle part of a payable, so its open balance moves.
     *
     * Through a disbursement and an allocation rather than by writing
     * `paid_amount`: the open balance is one function over allocations,
     * credits, differences and write-offs, and a fixture that set a column
     * the function does not read would prove nothing.
     */
    private function settle(string $billId, string $amount): void
    {
        $payment = app(Payments::class)->record(
            entityId: $this->entityId,
            direction: PaymentDirection::Disbursement,
            method: PaymentMethod::CreditTransfer,
            amount: Money::of($amount, 'SAR'),
            valueDate: $this->date->copy(),
            userId: $this->actorId(),
            bankAccountId: (string) $this->bank()->getKey(),
            partyId: (string) DB::connection('tenant')->table('bills')->where('id', $billId)->value('party_id'),
        );

        app(Allocations::class)->allocate(
            (string) $payment['id'],
            'bill',
            $billId,
            Money::of($amount, 'SAR'),
            $this->actorId(),
            on: $this->date->copy(),
        );
    }

    /** A prepared and released run, for the immutability checks. @return array<string, mixed> */
    private function aReleasedRun(): array
    {
        $supplier = $this->aPayableSupplier();
        $this->anApprovedBill($supplier, '4000.0000', '2026-05-18');

        $run = app(PaymentRuns::class)->prepare(
            entityId: $this->entityId,
            bankAccountId: (string) $this->bank()->getKey(),
            payOn: Carbon::parse('2026-05-19'),
            userId: $this->actorId(),
        );

        app(PaymentRuns::class)->release($run['id'], $this->secondActorId());

        return $run;
    }

    /**
     * The previewed item for a bill, if the run selected it.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>|null
     */
    private function itemFor(array $preview, string $billId): ?array
    {
        foreach ($preview['items'] as $item) {
            if ($item['bill_id'] === $billId) {
                return $item;
            }
        }

        return null;
    }
}
