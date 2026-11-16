<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Support\Closing\ClosingChecks;
use App\Support\Money\Money;
use App\Support\Verification\LedgerChecks;
use App\Tenant\Banking\Closing\SuspenseCheck;
use App\Tenant\Banking\Services\Suspense;
use App\Tenant\Banking\Services\SuspenseSignOff;
use App\Tenant\Identity\Models\SodRule;
use App\Tenant\Ledger\Enums\ControlRole;
use App\Tenant\Ledger\Models\AccountRole;
use App\Tenant\Ledger\Models\Period;
use App\Tenant\Banking\Services\BankBalances;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The suspense account - PF-163.
 *
 * ## The question this is built around
 *
 * Not "where do we put unexplained money". It is **what does an unexplained
 * item cost while it sits there**, and the answer is a reconciliation a
 * month: a line the bank has and the ledger does not makes the bank account
 * disagree with the bank, and every statement after it carries the same
 * difference until somebody re-derives the same conclusion about the same
 * item.
 *
 * So parking is not filing it away. It posts the movement - the bank really
 * did move that money - and puts the uncertainty in one named place where
 * it can be aged, reported, and where a period close refuses to walk past
 * it.
 *
 * ## The three failures these checks exist for
 *
 * **Suspense becoming a number.** A balance no list of items accounts for
 * cannot be aged, assigned or cleared, because clearing an item means
 * knowing what it was. It sits for years.
 *
 * **An item getting younger.** Age is the measure that makes somebody act
 * on suspense. If part-clearing an item reset its clock, the oldest and
 * worst items would report as the newest the moment anybody made progress.
 *
 * **Closing over it silently.** Anything in suspense could be a sale, and a
 * sale in suspense is revenue missing from the accounts and output tax
 * missing from the return. The close is refused, or somebody's name is
 * against the figure.
 */
final class SuspenseRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;
    use \Tests\Support\Concerns\ProvesTheLedger;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['suspense (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();
            $this->postTheOpeningEntry();

            return $this->withTheLedgerProof($this->runEachInASavepoint([
                // ---- Parking -------------------------------------------
                'an unexplained line is posted, not left' => fn (): array => $this->parkingPosts(),
                'a parked line is no longer an unrecorded bank item' => fn (): array => $this->parkedIsRecorded(),
                'parking states a reason' => fn (): array => $this->parkingNeedsAReason(),
                'parking a fully applied line is refused' => fn (): array => $this->parkingAnAppliedLine(),
                'only the unexplained part of a line is parked' => fn (): array => $this->parksTheRemainder(),
                'money out parks on the other side' => fn (): array => $this->parkingMoneyOut(),
                'an entity with no suspense account is refused' => fn (): array => $this->noSuspenseAccount(),

                // ---- Clearing ------------------------------------------
                'clearing reclassifies and leaves the original alone' => fn (): array => $this->clearingIsAReclassification(),
                'clearing part leaves the rest parked' => fn (): array => $this->partialClear(),
                'a part-cleared item does not get younger' => fn (): array => $this->ageSurvivesAPartialClear(),
                'clearing more than is parked is refused' => fn (): array => $this->clearingTooMuch(),
                'clearing the same item twice is refused' => fn (): array => $this->clearingTwice(),
                'clearing something that is not parked is refused' => fn (): array => $this->clearingADocumentMatch(),
                'clearing states a reason' => fn (): array => $this->clearingNeedsAReason(),

                // ---- The report ----------------------------------------
                'suspense is aged from the bank\'s date' => fn (): array => $this->ageingBuckets(),
                'the parked items account for the balance' => fn (): array => $this->parkedAccountForTheBalance(),
                'money posted to suspense by hand is reported' => fn (): array => $this->manualSuspenseIsReported(),
                'the assertion is registered with ledger:verify' => fn (): array => $this->verifierIsWired(),

                // ---- The close -----------------------------------------
                'a period does not close over unexplained money' => fn (): array => $this->closeIsRefused(),
                'a sign-off lets the period close' => fn (): array => $this->signOffAllowsTheClose(),
                'a sign-off is for the figure it was given against' => fn (): array => $this->signOffIsForTheFigure(),
                'a sign-off states a real reason' => fn (): array => $this->signOffNeedsAReason(),
                'signing off nothing is refused' => fn (): array => $this->signingOffNothing(),
                'the check is registered with the period close' => fn (): array => $this->closingCheckIsWired(),
                'whoever reconciles does not sign off' => fn (): array => $this->sodPairExists(),
            ]));
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- Parking ----------------------------------------------------------

    /** @return list<string> */
    private function parkingPosts(): array
    {
        $this->importLines([$this->line('1500.0000', ['bank_reference' => 'SUS-1'])]);
        $transaction = $this->transactionBy('SUS-1');

        $result = app(Suspense::class)->park($transaction, $this->actorId(), 'nobody can say who sent this');

        $lines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $result['journal_entry_id'])
            ->get();

        $failures = [];

        if ($lines->count() !== 2) {
            $failures[] = sprintf('parking posted %d lines where it should post two', $lines->count());
        }

        $suspenseId = $this->suspenseAccountId();
        $onSuspense = $lines->firstWhere('account_id', $suspenseId);
        $onBank = $lines->firstWhere('account_id', $this->bankGlAccountId);

        if ($onBank === null) {
            $failures[] = 'nothing was posted to the bank account, so the bank still does not agree with the bank';
        }

        if ($onSuspense === null) {
            return [...$failures, 'nothing was posted to suspense, so the other side went somewhere unnamed'];
        }

        /*
         | The two sides are exact opposites.
         |
         | Which is the only form of this entry that cannot be out: not a
         | subtraction somewhere that could be wrong, two lines where the
         | second is the negation of the first.
         */
        $bank = BigDecimal::of((string) $onBank?->functional_amount);
        $suspense = BigDecimal::of((string) $onSuspense->functional_amount);

        if (! $bank->plus($suspense)->isZero()) {
            $failures[] = sprintf(
                'the bank side is %s and suspense took %s, which do not cancel',
                (string) $bank,
                (string) $suspense,
            );
        }

        if (! $bank->isEqualTo('1500.0000')) {
            $failures[] = sprintf('a 1500 receipt debited the bank account by %s', (string) $bank);
        }

        return $failures;
    }

    /** @return list<string> */
    private function parkedIsRecorded(): array
    {
        $this->importLines([$this->line('900.0000', ['bank_reference' => 'SUS-REC'])]);
        $transaction = $this->transactionBy('SUS-REC');

        $before = app(BankBalances::class)->unrecordedBankItems($this->bank(), $this->date->copy());

        app(Suspense::class)->park($transaction, $this->actorId(), 'unidentified receipt');

        $after = app(BankBalances::class)->unrecordedBankItems($this->bank(), $this->date->copy());

        /*
         | This is the whole argument for parking at all.
         |
         | An unrecorded bank item is a line the bank has and the ledger
         | does not, and while it sits there the bank account does not
         | reconcile. Parking it makes the account agree with the bank -
         | the item is not less unexplained, it is unexplained in one
         | place.
         */
        $beforeTotal = $before->abs()->amount;
        $afterTotal = $after->abs()->amount;

        if (! $afterTotal->isLessThan($beforeTotal)) {
            return [sprintf(
                'unrecorded bank items were %s before parking and %s after, so parking did not '.
                'bring the bank account into agreement with the bank - which is the only reason '.
                'to post an item nobody can explain',
                (string) $beforeTotal,
                (string) $afterTotal,
            )];
        }

        return [];
    }

    /** @return list<string> */
    private function parkingNeedsAReason(): array
    {
        $this->importLines([$this->line('100.0000', ['bank_reference' => 'SUS-NR'])]);

        $refusal = $this->refusal(fn () => app(Suspense::class)->park(
            $this->transactionBy('SUS-NR'),
            $this->actorId(),
            'x',
        ));

        return $refusal !== null && str_contains($refusal, 'reason')
            ? []
            : ['a line was parked with no note, so whoever comes back to it months later learns nothing'];
    }

    /** @return list<string> */
    private function parkingAnAppliedLine(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;

        $this->importLines([$this->line($gross, [
            'bank_reference' => 'SUS-APPLIED',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $transaction = $this->transactionBy('SUS-APPLIED');

        $applied = app(\App\Tenant\Banking\Services\Matches::class)->settle($transaction, $this->actorId());

        if ($applied['applied'] !== true) {
            return ['the setup for this check did not apply the line, so there is nothing to prove about parking one'];
        }

        $refusal = $this->refusal(fn () => app(Suspense::class)->park(
            $transaction,
            $this->actorId(),
            'parking a line that is already explained',
        ));

        if ($refusal === null) {
            return ['an explained line was parked in suspense, which posts the same money twice'];
        }

        /*
         | Refused by parking, not by something downstream.
         |
         | A zero-amount match fails a column constraint and a zero-amount
         | entry fails the posting rule, so the data is safe either way -
         | and the check passed while what reached the person had become a
         | constraint name. "Every part of this line is already applied"
         | tells them to reverse a match; "violates check constraint
         | matches_amount_is_not_zero" tells them to call somebody.
         */
        return str_contains($refusal, 'already applied')
            ? []
            : [sprintf(
                'the refusal is not the one parking gives, so a lower layer said no and the person '.
                'is told about a constraint instead of about their line: %s',
                $refusal,
            )];
    }

    /** @return list<string> */
    private function parksTheRemainder(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);
        $line = (string) $gross->multipliedBy(2);

        $this->importLines([$this->line($line, ['bank_reference' => 'SUS-PART'])]);
        $transaction = $this->transactionBy('SUS-PART');

        app(\App\Tenant\Banking\Services\Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $invoice->getKey(), 'amount' => (string) $gross->dividedBy(2, 4)],
            ['type' => 'invoice', 'id' => (string) $invoice->getKey(), 'amount' => (string) $gross->dividedBy(2, 4)],
        ], $this->actorId(), 'part of the transfer settles this invoice');

        $result = app(Suspense::class)->park($transaction, $this->actorId(), 'the rest of the transfer is unexplained');

        $expected = BigDecimal::of($line)->minus($gross);

        return $result['amount']->amount->isEqualTo($expected)
            ? []
            : [sprintf(
                'parking a part-applied line parked %s where only %s of it is unexplained - '.
                'posting the whole line again would duplicate what is already matched',
                (string) $result['amount']->amount,
                (string) $expected,
            )];
    }

    /** @return list<string> */
    private function parkingMoneyOut(): array
    {
        $this->importLines([$this->line('-640.0000', ['bank_reference' => 'SUS-OUT'])]);
        $transaction = $this->transactionBy('SUS-OUT');

        $result = app(Suspense::class)->park($transaction, $this->actorId(), 'a payment nobody recognises');

        $bank = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $result['journal_entry_id'])
            ->where('account_id', $this->bankGlAccountId)
            ->value('functional_amount');

        /*
         | Signed as the bank moved it.
         |
         | Taking the absolute value and deciding the direction from
         | somewhere else is how a refund ends up increasing the bank
         | balance - and the reconciliation then disagrees by twice the
         | amount, which reads as two separate errors.
         */
        return BigDecimal::of((string) $bank)->isEqualTo('-640.0000')
            ? []
            : [sprintf('a payment out moved the bank account by %s rather than -640', (string) $bank)];
    }

    /** @return list<string> */
    private function noSuspenseAccount(): array
    {
        $this->importLines([$this->line('200.0000', ['bank_reference' => 'SUS-NOACC'])]);
        $transaction = $this->transactionBy('SUS-NOACC');

        AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', ControlRole::Suspense->value)
            ->delete();

        $refusal = $this->refusal(fn () => app(Suspense::class)->park(
            $transaction,
            $this->actorId(),
            'there is nowhere to park this',
        ));

        if ($refusal === null) {
            return ['an item was parked with no suspense account assigned, so it went to whatever account seemed closest'];
        }

        return str_contains($refusal, 'suspense control account')
            ? []
            : [sprintf('the refusal does not say what is missing: %s', $refusal)];
    }

    // ---- Clearing ---------------------------------------------------------

    /** @return list<string> */
    private function clearingIsAReclassification(): array
    {
        $this->importLines([$this->line('1200.0000', ['bank_reference' => 'SUS-CLR'])]);
        $transaction = $this->transactionBy('SUS-CLR');

        $parked = app(Suspense::class)->park($transaction, $this->actorId(), 'unidentified at the time');

        $originalLines = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $parked['journal_entry_id'])
            ->orderBy('id')
            ->get()
            ->map(static fn (object $l): string => $l->account_id.':'.$l->functional_amount)
            ->all();

        $cleared = app(Suspense::class)->clear(
            matchId: $parked['match_id'],
            accountId: $this->secondBankGlAccountId(),
            userId: $this->actorId(),
            reason: 'it was a transfer from the other account all along',
            on: $this->date->copy(),
        );

        $failures = [];

        if ($cleared['journal_entry_id'] === $parked['journal_entry_id']) {
            $failures[] = 'clearing reused the parked entry, so the ledger no longer says this was unexplained when it was';
        }

        /*
         | The original entry is byte-for-byte what it was.
         |
         | Editing it would be tidier and would destroy the one thing
         | suspense exists to record: that on the 18th nobody knew what
         | this was. "How long was it unexplained" has no answer in a
         | ledger that rewrites its own history.
         */
        $after = DB::connection('tenant')->table('journal_lines')
            ->where('journal_entry_id', $parked['journal_entry_id'])
            ->orderBy('id')
            ->get()
            ->map(static fn (object $l): string => $l->account_id.':'.$l->functional_amount)
            ->all();

        if ($after !== $originalLines) {
            $failures[] = 'the parked entry changed when the item was cleared, so the record of it being unexplained is gone';
        }

        $balance = app(Suspense::class)->balance($this->entityId, 'SAR');

        if (! $balance->isZero()) {
            $failures[] = sprintf(
                'suspense holds %s after its only item was cleared, so the reclassification did not '.
                'cancel what was parked',
                (string) $balance->amount,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function partialClear(): array
    {
        $this->importLines([$this->line('1000.0000', ['bank_reference' => 'SUS-HALF'])]);
        $parked = app(Suspense::class)->park(
            $this->transactionBy('SUS-HALF'),
            $this->actorId(),
            'two things in one transfer, one of them identified later',
        );

        $result = app(Suspense::class)->clear(
            matchId: $parked['match_id'],
            accountId: $this->secondBankGlAccountId(),
            userId: $this->actorId(),
            reason: 'four hundred of it was the transfer from the deposit account',
            amount: '400.0000',
            on: $this->date->copy(),
        );

        $failures = [];

        if (! $result['still_parked']->amount->isEqualTo('600.0000')) {
            $failures[] = sprintf(
                '%s was left parked after clearing 400 of 1000',
                (string) $result['still_parked']->amount,
            );
        }

        $balance = app(Suspense::class)->balance($this->entityId, 'SAR');

        if (! $balance->abs()->amount->isEqualTo('600.0000')) {
            $failures[] = sprintf(
                'the suspense account holds %s after 400 of 1000 was reclassified',
                (string) $balance->abs()->amount,
            );
        }

        $ageing = app(Suspense::class)->ageing($this->entityId, 'SAR', $this->date->toDateString());

        if (\count($ageing['items']) !== 1) {
            $failures[] = sprintf(
                'the report lists %d items after a partial clear, where the remainder is one item',
                \count($ageing['items']),
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function ageSurvivesAPartialClear(): array
    {
        $this->importLines([$this->line('800.0000', ['bank_reference' => 'SUS-AGE'])]);
        $parked = app(Suspense::class)->park(
            $this->transactionBy('SUS-AGE'),
            $this->actorId(),
            'unidentified, and it will stay that way for a while',
        );

        $asOf = $this->date->copy()->addDays(120)->toDateString();
        $before = app(Suspense::class)->ageing($this->entityId, 'SAR', $asOf);

        app(Suspense::class)->clear(
            matchId: $parked['match_id'],
            accountId: $this->secondBankGlAccountId(),
            userId: $this->actorId(),
            reason: 'half of it turned out to be the deposit account transfer',
            amount: '400.0000',
            on: $this->date->copy(),
        );

        $after = app(Suspense::class)->ageing($this->entityId, 'SAR', $asOf);

        /*
         | Progress does not make an item younger.
         |
         | Age is the measure that makes anybody act on suspense. If
         | part-clearing reset the clock, the oldest and worst items would
         | report as the newest the moment somebody made progress on them -
         | and the ones nobody touched would be the only ones ever
         | escalated.
         */
        if ($after['oldest_days'] !== $before['oldest_days']) {
            return [sprintf(
                'the item was %d days old and became %d after part of it was cleared, so working on '.
                'an item is what makes it disappear from the escalation report',
                $before['oldest_days'],
                $after['oldest_days'],
            )];
        }

        return $before['oldest_days'] >= 120
            ? []
            : [sprintf('an item booked 120 days before the report date is aged at %d days', $before['oldest_days'])];
    }

    /** @return list<string> */
    private function clearingTooMuch(): array
    {
        $this->importLines([$this->line('300.0000', ['bank_reference' => 'SUS-OVER'])]);
        $parked = app(Suspense::class)->park(
            $this->transactionBy('SUS-OVER'),
            $this->actorId(),
            'unidentified receipt',
        );

        $refusal = $this->refusal(fn () => app(Suspense::class)->clear(
            matchId: $parked['match_id'],
            accountId: $this->secondBankGlAccountId(),
            userId: $this->actorId(),
            reason: 'clearing more than was ever parked',
            amount: '500.0000',
            on: $this->date->copy(),
        ));

        return $refusal !== null
            ? []
            : ['suspense gave back more than was put into it, which leaves a control account negative for no stated reason'];
    }

    /** @return list<string> */
    private function clearingTwice(): array
    {
        $this->importLines([$this->line('250.0000', ['bank_reference' => 'SUS-TWICE'])]);
        $parked = app(Suspense::class)->park(
            $this->transactionBy('SUS-TWICE'),
            $this->actorId(),
            'unidentified receipt',
        );

        app(Suspense::class)->clear(
            matchId: $parked['match_id'],
            accountId: $this->secondBankGlAccountId(),
            userId: $this->actorId(),
            reason: 'it was the transfer from the deposit account',
            on: $this->date->copy(),
        );

        $refusal = $this->refusal(fn () => app(Suspense::class)->clear(
            matchId: $parked['match_id'],
            accountId: $this->secondBankGlAccountId(),
            userId: $this->actorId(),
            reason: 'clearing it a second time',
            on: $this->date->copy(),
        ));

        return $refusal !== null
            ? []
            : ['an item was reclassified twice, posting money out of suspense that is no longer there'];
    }

    /** @return list<string> */
    private function clearingADocumentMatch(): array
    {
        $invoice = $this->issue();
        $matchId = $this->applyAMatchDirectly(
            'SUS-DOC',
            (string) $invoice->gross_amount,
            (string) $invoice->getKey(),
            'an ordinary match against an invoice',
        );

        $refusal = $this->refusal(fn () => app(Suspense::class)->clear(
            matchId: $matchId,
            accountId: $this->secondBankGlAccountId(),
            userId: $this->actorId(),
            reason: 'clearing something that was never in suspense',
            on: $this->date->copy(),
        ));

        return $refusal !== null
            ? []
            : ['a match against a document was cleared through the suspense path, posting a reclassification of money that never went to suspense'];
    }

    /** @return list<string> */
    private function clearingNeedsAReason(): array
    {
        $this->importLines([$this->line('180.0000', ['bank_reference' => 'SUS-CNR'])]);
        $parked = app(Suspense::class)->park(
            $this->transactionBy('SUS-CNR'),
            $this->actorId(),
            'unidentified receipt',
        );

        $refusal = $this->refusal(fn () => app(Suspense::class)->clear(
            matchId: $parked['match_id'],
            accountId: $this->secondBankGlAccountId(),
            userId: $this->actorId(),
            reason: 'ok',
            on: $this->date->copy(),
        ));

        return $refusal !== null
            ? []
            : ['suspense was cleared with no statement of what the money turned out to be'];
    }

    // ---- The report -------------------------------------------------------

    /** @return list<string> */
    private function ageingBuckets(): array
    {
        $this->importLines([
            $this->line('100.0000', ['bank_reference' => 'SUS-B1']),
            $this->line('200.0000', ['bank_reference' => 'SUS-B2']),
        ]);

        foreach (['SUS-B1', 'SUS-B2'] as $reference) {
            app(Suspense::class)->park($this->transactionBy($reference), $this->actorId(), 'unidentified receipt');
        }

        $failures = [];

        $young = app(Suspense::class)->ageing(
            $this->entityId,
            'SAR',
            $this->date->copy()->addDays(10)->toDateString(),
        );

        if (! $young['buckets']['0-30']->amount->isEqualTo('300.0000')) {
            $failures[] = sprintf(
                'two items booked ten days earlier total %s in the 0-30 bucket',
                (string) $young['buckets']['0-30']->amount,
            );
        }

        $old = app(Suspense::class)->ageing(
            $this->entityId,
            'SAR',
            $this->date->copy()->addDays(200)->toDateString(),
        );

        if (! $old['buckets']['90+']->amount->isEqualTo('300.0000')) {
            $failures[] = sprintf(
                'the same items at 200 days total %s in the 90+ bucket rather than 300',
                (string) $old['buckets']['90+']->amount,
            );
        }

        /*
         | An item is in exactly one bucket.
         |
         | A report whose buckets overlap double-counts, and a suspense
         | total that does not match the control account is the failure
         | this whole design is built to prevent.
         */
        $sum = Money::zero('SAR');

        foreach ($old['buckets'] as $bucket) {
            $sum = $sum->plus($bucket);
        }

        if (! $sum->amount->isEqualTo($old['total']->amount)) {
            $failures[] = sprintf(
                'the buckets add to %s and the total is %s, so an item is counted twice or not at all',
                (string) $sum->amount,
                (string) $old['total']->amount,
            );
        }

        if ($old['oldest_days'] < 190) {
            $failures[] = sprintf('the oldest item reports as %d days old at 200 days', $old['oldest_days']);
        }

        return $failures;
    }

    /** @return list<string> */
    private function parkedAccountForTheBalance(): array
    {
        $this->importLines([
            $this->line('450.0000', ['bank_reference' => 'SUS-T1']),
            $this->line('-120.0000', ['bank_reference' => 'SUS-T2']),
        ]);

        foreach (['SUS-T1', 'SUS-T2'] as $reference) {
            app(Suspense::class)->park($this->transactionBy($reference), $this->actorId(), 'unidentified movement');
        }

        /*
         | One in and one out, deliberately.
         |
         | A receipt credits suspense and a payment debits it, so a report
         | that added magnitudes and compared them with the control
         | account would call every entity with movements both ways
         | broken - and a check that only ever parks receipts would never
         | have noticed.
         */
        $result = app(Suspense::class)->reconciles($this->entityId, 'SAR');

        return $result['agrees']
            ? []
            : [sprintf('the parked items do not account for the suspense balance: %s', $result['why'])];
    }

    /** @return list<string> */
    private function manualSuspenseIsReported(): array
    {
        $this->importLines([$this->line('500.0000', ['bank_reference' => 'SUS-M1'])]);
        app(Suspense::class)->park($this->transactionBy('SUS-M1'), $this->actorId(), 'unidentified receipt');

        /*
         | Money put into suspense by something that is not parking.
         |
         | A manual journal, a correction, an import that did not know
         | where else to put something. This is how a suspense balance
         | stops being a list of items and becomes a number - and a number
         | cannot be aged, assigned or cleared, so it sits there.
         */
        $entry = DB::connection('tenant')->table('journal_entries')
            ->where('entity_id', $this->entityId)
            ->orderByDesc('created_at')
            ->first();

        DB::connection('tenant')->table('journal_lines')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'journal_entry_id' => $entry->id,
            'accounting_date' => $this->date->toDateString(),
            'period_id' => $entry->period_id,
            'account_id' => $this->suspenseAccountId(),
            'amount' => '777.0000',
            'currency' => 'SAR',
            'functional_amount' => '777.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(Suspense::class)->reconciles($this->entityId, 'SAR');

        if ($result['agrees']) {
            return ['777 appeared in suspense with no item behind it and the report says everything is traceable'];
        }

        $findings = app(\App\Tenant\Banking\Verification\SuspenseIsTraceable::class)->findings($this->entityId);

        return $findings === []
            ? ['ledger:verify reports nothing about a suspense balance no list of items accounts for']
            : [];
    }

    /** @return list<string> */
    private function verifierIsWired(): array
    {
        return app(LedgerChecks::class)->has('suspense')
            ? []
            : ['the suspense assertion is not registered with ledger:verify, so it runs only when a check calls it directly'];
    }

    // ---- The close --------------------------------------------------------

    /** @return list<string> */
    private function closeIsRefused(): array
    {
        $this->importLines([$this->line('333.0000', ['bank_reference' => 'SUS-CLOSE'])]);
        app(Suspense::class)->park($this->transactionBy('SUS-CLOSE'), $this->actorId(), 'unidentified receipt');

        $period = $this->periodForTheFixtureDate();

        if ($period === null) {
            return ['the fixture has no period covering its own date, so this check cannot run'];
        }

        $reasons = app(SuspenseCheck::class)->reasonsToRefuse(
            $this->entityId,
            (string) $period->label,
            $period->starts_on->toDateString(),
            $period->ends_on->copy()->addDay()->toDateString(),
        );

        if ($reasons === []) {
            return ['a period with 333 unexplained in suspense was allowed to close with nothing said'];
        }

        /*
         | The reason says what it could cost, not just what it is.
         |
         | "Suspense is not zero" is a fact somebody dismisses. "Any of
         | this could be a sale, which would mean revenue missing from
         | these accounts and output tax missing from the return" is the
         | same fact with the consequence attached, and that is what makes
         | somebody look.
         */
        return str_contains($reasons[0], 'sale') || str_contains($reasons[0], 'tax')
            ? []
            : [sprintf('the refusal does not say what closing over it would cost: %s', $reasons[0])];
    }

    /** @return list<string> */
    private function signOffAllowsTheClose(): array
    {
        $this->importLines([$this->line('410.0000', ['bank_reference' => 'SUS-SIGN'])]);
        app(Suspense::class)->park($this->transactionBy('SUS-SIGN'), $this->actorId(), 'unidentified receipt');

        $period = $this->periodForTheFixtureDate();

        if ($period === null) {
            return ['the fixture has no period covering its own date, so this check cannot run'];
        }

        app(SuspenseSignOff::class)->sign(
            $period,
            $this->actorId(),
            'The bank cannot tell us who sent this and the customer has not claimed it; accepted for '.
            'this period and chased again next month.',
        );

        $reasons = app(SuspenseCheck::class)->reasonsToRefuse(
            $this->entityId,
            (string) $period->label,
            $period->starts_on->toDateString(),
            $period->ends_on->copy()->addDay()->toDateString(),
        );

        if ($reasons !== []) {
            return [sprintf('a signed-off balance still blocks the close: %s', $reasons[0])];
        }

        $report = app(SuspenseSignOff::class)->report($this->entityId);

        return $report !== [] && $report[0]['items'] === 1
            ? []
            : ['the sign-off is not on the report, so nobody can see who accepted what'];
    }

    /** @return list<string> */
    private function signOffIsForTheFigure(): array
    {
        $this->importLines([$this->line('410.0000', ['bank_reference' => 'SUS-F1'])]);
        app(Suspense::class)->park($this->transactionBy('SUS-F1'), $this->actorId(), 'unidentified receipt');

        $period = $this->periodForTheFixtureDate();

        if ($period === null) {
            return ['the fixture has no period covering its own date, so this check cannot run'];
        }

        app(SuspenseSignOff::class)->sign(
            $period,
            $this->actorId(),
            'Accepted for this period: one item the bank will not identify, chased again next month.',
        );

        /*
         | And then more lands in suspense.
         |
         | What was accepted is not what is there. A flag on the period
         | would let a decision made about four hundred riyals carry a
         | close over forty thousand, because the thing that changed was
         | not the flag.
         */
        $this->importLines([$this->line('9000.0000', ['bank_reference' => 'SUS-F2'])]);
        app(Suspense::class)->park($this->transactionBy('SUS-F2'), $this->actorId(), 'another unidentified receipt');

        $reasons = app(SuspenseCheck::class)->reasonsToRefuse(
            $this->entityId,
            (string) $period->label,
            $period->starts_on->toDateString(),
            $period->ends_on->copy()->addDay()->toDateString(),
        );

        if ($reasons === []) {
            return ['9000 landed in suspense after the sign-off and the close went ahead on a decision made about 410'];
        }

        return str_contains($reasons[0], 'sign-off')
            ? []
            : [sprintf('the refusal does not say the sign-off no longer covers the balance: %s', $reasons[0])];
    }

    /** @return list<string> */
    private function signOffNeedsAReason(): array
    {
        $this->importLines([$this->line('120.0000', ['bank_reference' => 'SUS-SR'])]);
        app(Suspense::class)->park($this->transactionBy('SUS-SR'), $this->actorId(), 'unidentified receipt');

        $period = $this->periodForTheFixtureDate();

        if ($period === null) {
            return ['the fixture has no period covering its own date, so this check cannot run'];
        }

        $refusal = $this->refusal(fn () => app(SuspenseSignOff::class)->sign($period, $this->actorId(), 'agreed'));

        if ($refusal === null) {
            return ['a period closed over unexplained money on the strength of the word "agreed"'];
        }

        /*
         | The service's refusal, not the column's.
         |
         | The table refuses a short reason too, which is why it is there -
         | but a person signing off a balance should be told that a
         | sentence is wanted and why, not handed the name of a check
         | constraint.
         */
        return str_contains($refusal, 'at least a sentence')
            ? []
            : [sprintf('the refusal came from the table rather than from the sign-off: %s', $refusal)];
    }

    /** @return list<string> */
    private function signingOffNothing(): array
    {
        $period = $this->periodForTheFixtureDate();

        if ($period === null) {
            return ['the fixture has no period covering its own date, so this check cannot run'];
        }

        $refusal = $this->refusal(fn () => app(SuspenseSignOff::class)->sign(
            $period,
            $this->actorId(),
            'Signing off a balance that does not exist, to see whether the service notices.',
        ));

        if ($refusal === null) {
            return ['a sign-off was recorded against an empty balance, which would carry a later close over whatever lands before it'];
        }

        return str_contains($refusal, 'nothing in suspense')
            ? []
            : [sprintf(
                'the refusal came from the table rather than from the sign-off, so what reached the '.
                'person is a constraint name: %s',
                $refusal,
            )];
    }

    /** @return list<string> */
    private function closingCheckIsWired(): array
    {
        return app(ClosingChecks::class)->has('suspense')
            ? []
            : ['the suspense check is not registered, so a period closes over unexplained money however well the check works'];
    }

    /** @return list<string> */
    private function sodPairExists(): array
    {
        $rule = SodRule::query()->where('key', 'reconcile_then_sign_off')->first();

        if ($rule === null) {
            return ['nothing stops the person who decided what could not be explained from signing off that it is acceptable'];
        }

        $duties = [(string) $rule->duty_a, (string) $rule->duty_b];
        sort($duties);

        $failures = [];

        if ($duties !== ['bank.reconcile', 'suspense.sign_off']) {
            $failures[] = sprintf('the rule pairs %s rather than reconciling against signing off', implode(' and ', $duties));
        }

        $scope = $rule->scope instanceof \BackedEnum ? (string) $rule->scope->value : (string) $rule->scope;

        if ($scope !== 'period') {
            $failures[] = sprintf(
                'the rule is scoped to %s - signing off March must be a different act from signing off December',
                $scope,
            );
        }

        /*
         | Both duties have to be visible in the audit trail.
         |
         | A rule naming a duty nothing records is a control that cannot
         | fire: it passes every test that writes its own audit record and
         | does nothing at all in production.
         */
        $map = (array) config('sod.performed_as');

        foreach (['bank.reconcile', 'suspense.sign_off'] as $duty) {
            if (! isset($map[$duty]) || $map[$duty] === []) {
                $failures[] = sprintf('"%s" maps to no audit action, so the rule cannot see it happen', $duty);
            }
        }

        return $failures;
    }

    // ---- Helpers ----------------------------------------------------------

    private function suspenseAccountId(): string
    {
        return (string) AccountRole::query()
            ->where('entity_id', $this->entityId)
            ->where('role', ControlRole::Suspense->value)
            ->value('account_id');
    }

    private function periodForTheFixtureDate(): ?Period
    {
        return Period::query()
            ->where('entity_id', $this->entityId)
            ->where('starts_on', '<=', $this->date->toDateString())
            ->where('ends_on', '>=', $this->date->toDateString())
            ->first();
    }
}
