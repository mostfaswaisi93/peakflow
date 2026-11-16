<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Banking\Matching\MatchingEngine;
use App\Tenant\Banking\Matching\OpenDocuments;
use App\Tenant\Banking\Models\MatchProposal;
use App\Tenant\Banking\Services\Matches;
use App\Tenant\Banking\Services\Splits;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Splitting a bank line, and taking the split back - PF-162.
 *
 * ## The two directions, and what a system without them forces
 *
 * One transfer settling four invoices, and one invoice settled by three
 * transfers. Both are ordinary. A system that models matching as one link
 * between one line and one document forces the first into a suspense
 * account and the second into a write-off - and both of those end with
 * somebody keeping the real figures in a spreadsheet beside the accounting
 * system, which is the failure this whole phase exists to prevent.
 *
 * ## What these checks are actually guarding
 *
 * **Over-application.** The expensive failure, because of where it
 * surfaces: every individual match looks right, both invoices read as paid,
 * and the only symptom is a reconciliation that will not balance. It is
 * checked here through the service, which explains itself, and directly
 * against the database, which is what holds when a future import or
 * correction script does not go through the service.
 *
 * **A half-applied split.** Three allocations applied one at a time is
 * three states the system can be found in, two of which are wrong - and the
 * second allocation can fail on its own, leaving a line a third applied
 * with no record of what the whole was meant to be.
 *
 * **A remainder nobody can see.** A part-applied line looks matched to
 * every screen asking "is there a match" rather than "is it explained". So
 * the remainder is asserted on the line, on the document, and in what the
 * engine does next.
 */
final class SplitRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['splits (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();
            $this->postTheOpeningEntry();

            return $this->runEachInASavepoint([
                // ---- One line, many documents --------------------------
                'one line settles several documents in one operation' => fn (): array => $this->oneLineManyDocuments(),
                'a split states a reason' => fn (): array => $this->splitNeedsAReason(),
                'a split of one allocation is refused' => fn (): array => $this->splitOfOne(),
                'an allocation for nothing is refused' => fn (): array => $this->zeroAllocation(),

                // ---- Atomic, or nothing --------------------------------
                'a split that over-applies the line applies none of it' => fn (): array => $this->overApplyingSplit(),
                'an allocation a document cannot absorb refuses the whole split' => fn (): array => $this->tooLargeAllocation(),
                'two allocations against one document are added together' => fn (): array => $this->sameDocumentTwice(),
                'an allocation naming a settled document refuses the whole split' => fn (): array => $this->settledInASplit(),
                'the database refuses over-application on its own' => fn (): array => $this->databaseRefusesOverApplication(),
                'a match in another currency is refused' => fn (): array => $this->wrongCurrency(),

                // ---- The remainder, on both sides ----------------------
                'a part-applied line reports what is left' => fn (): array => $this->remainderOnTheLine(),
                'a part-settled document reports what is outstanding' => fn (): array => $this->remainderOnTheDocument(),
                'one document settled by several lines shows all of them' => fn (): array => $this->manyLinesOneDocument(),
                'the engine proposes against the remainder, not the line' => fn (): array => $this->engineUsesTheRemainder(),
                'a fully applied line says there is nothing left to propose' => fn (): array => $this->fullyAppliedSaysSo(),
                'a part-applied line keeps its other proposals alive' => fn (): array => $this->partialKeepsProposals(),

                // ---- Unsplitting ---------------------------------------
                'unsplitting reverses every match and keeps them all' => fn (): array => $this->unsplitReverses(),
                'unsplitting states a reason' => fn (): array => $this->unsplitNeedsAReason(),
                'unsplitting leaves the line unexplained in full' => fn (): array => $this->unsplitRestoresTheLine(),
                'unsplitting nothing is refused' => fn (): array => $this->unsplitNothing(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- One line, many documents -----------------------------------------

    /** @return list<string> */
    private function oneLineManyDocuments(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        $this->importLines([$this->line($total, ['bank_reference' => 'REF-SPLIT'])]);
        $transaction = $this->transactionBy('REF-SPLIT');

        $result = app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $first->getKey(), 'amount' => (string) $first->gross_amount],
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => (string) $second->gross_amount],
        ], $this->actorId(), 'the customer paid both invoices with one transfer');

        $failures = [];

        if (\count($result['match_ids']) !== 2) {
            $failures[] = sprintf('a two-way split wrote %d matches', \count($result['match_ids']));
        }

        if (! $result['remainder']->amount->isZero()) {
            $failures[] = sprintf(
                'the line has %s left over after a split that covers it exactly',
                (string) $result['remainder']->amount,
            );
        }

        /*
         | Both documents are settled, not one.
         |
         | The whole point of the operation: a split that settles the first
         | and leaves the second open is two matches reported as one and a
         | customer chased for an invoice they paid.
         */
        $open = (new OpenDocuments())->receivables($this->entityId, 'SAR');

        foreach ([$first, $second] as $invoice) {
            if ($this->find($open, (string) $invoice->getKey()) !== null) {
                $failures[] = sprintf('invoice %s is still open after the split settled it', $invoice->number);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function splitNeedsAReason(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, ['bank_reference' => 'REF-NOREASON'])]);

        $refusal = $this->refusal(fn () => app(Splits::class)->split(
            $this->transactionBy('REF-NOREASON'),
            [
                ['type' => 'invoice', 'id' => (string) $invoice->getKey(), 'amount' => '1.0000'],
                ['type' => 'invoice', 'id' => (string) $invoice->getKey(), 'amount' => '1.0000'],
            ],
            $this->actorId(),
            'x',
        ));

        return $refusal !== null && str_contains($refusal, 'reason')
            ? []
            : ['a split was accepted with no reason, so nobody can say later why the line was divided that way'];
    }

    /** @return list<string> */
    private function splitOfOne(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, ['bank_reference' => 'REF-ONE'])]);

        $refusal = $this->refusal(fn () => app(Splits::class)->split(
            $this->transactionBy('REF-ONE'),
            [['type' => 'invoice', 'id' => (string) $invoice->getKey(), 'amount' => (string) $invoice->gross_amount]],
            $this->actorId(),
            'one allocation is an ordinary match',
        ));

        return $refusal !== null
            ? []
            : ['a single allocation went through the split path, which exists to keep several of them consistent'];
    }

    /** @return list<string> */
    private function zeroAllocation(): array
    {
        $first = $this->issue();
        $second = $this->issue();
        $this->importLines([$this->line((string) $first->gross_amount, ['bank_reference' => 'REF-ZERO'])]);

        $refusal = $this->refusal(fn () => app(Splits::class)->split(
            $this->transactionBy('REF-ZERO'),
            [
                ['type' => 'invoice', 'id' => (string) $first->getKey(), 'amount' => (string) $first->gross_amount],
                ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => '0'],
            ],
            $this->actorId(),
            'the second allocation settles nothing',
        ));

        if ($refusal === null) {
            return ['an allocation for nothing was recorded, which makes the split read as having more parts than it has'];
        }

        /*
         | Named by position, because that is what a person can act on.
         |
         | A zero amount also fails a constraint on the table, so the data
         | is safe without this - but "violates check constraint
         | matches_amount_is_not_zero" does not tell somebody which of
         | their four allocations to fix.
         */
        return str_contains($refusal, 'Allocation 2')
            ? []
            : [sprintf('the refusal does not say which allocation is empty: %s', $refusal)];
    }

    // ---- Atomic, or nothing -----------------------------------------------

    /** @return list<string> */
    private function overApplyingSplit(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        /*
         | A line worth one invoice, split across two.
         |
         | Each allocation fits its own document, which is exactly why this
         | is the failure worth guarding: nothing about either one is
         | wrong on its own.
         */
        $this->importLines([$this->line((string) $first->gross_amount, ['bank_reference' => 'REF-OVER'])]);
        $transaction = $this->transactionBy('REF-OVER');

        $refusal = $this->refusal(fn () => app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $first->getKey(), 'amount' => (string) $first->gross_amount],
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => (string) $second->gross_amount],
        ], $this->actorId(), 'both invoices against a line worth one of them'));

        if ($refusal === null) {
            return ['a line was applied to more than it is worth'];
        }

        $failures = [];

        /*
         | The service's own refusal, not the database's.
         |
         | The trigger catches this too and the data stays correct either
         | way - which is why this assertion is on the wording. A person
         | splitting a line wrongly should be told the two figures and
         | that nothing was applied; a check that accepts any refusal
         | passes just as happily when what reaches them is a PostgreSQL
         | exception, and that is a defect in everything but the data.
         */
        if (! str_contains($refusal, 'unexplained')) {
            $failures[] = sprintf(
                'the refusal is not the one the split itself gives, so what reached the person is '.
                'a lower layer saying no: %s',
                $refusal,
            );
        }

        /*
         | And nothing was applied.
         |
         | The first allocation fitted. A split that wrote it and then
         | refused the second would leave a line part-applied by an
         | operation somebody was told had failed.
         */
        if (! app(Splits::class)->remainder($transaction)->equals(
            \App\Support\Money\Money::of((string) $first->gross_amount, 'SAR')
        )) {
            $failures[] = 'the refused split left part of itself applied, so a failed operation is a state the system can be in';
        }

        return $failures;
    }

    /** @return list<string> */
    private function tooLargeAllocation(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        $this->importLines([$this->line($total, ['bank_reference' => 'REF-TOOBIG'])]);
        $transaction = $this->transactionBy('REF-TOOBIG');

        $refusal = $this->refusal(fn () => app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $first->getKey(), 'amount' => (string) $first->gross_amount],
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => $total],
        ], $this->actorId(), 'the second allocation is larger than the invoice it names'));

        if ($refusal === null) {
            return ['a document was settled for more than it is worth, so its outstanding figure goes negative'];
        }

        return app(Splits::class)->remainder($transaction)->amount->isEqualTo($total)
            ? []
            : ['the refused split applied its first allocation anyway'];
    }

    /** @return list<string> */
    private function sameDocumentTwice(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;
        $twice = (string) BigDecimal::of($gross)->multipliedBy(2);

        $this->importLines([$this->line($twice, ['bank_reference' => 'REF-TWICE'])]);

        /*
         | Two allocations, each fitting, adding to twice the invoice.
         |
         | The line can absorb the total, so the line-side guard says
         | nothing. Checked per document rather than per allocation, or a
         | split silently over-settles the one document it names twice.
         */
        $refusal = $this->refusal(fn () => app(Splits::class)->split(
            $this->transactionBy('REF-TWICE'),
            [
                ['type' => 'invoice', 'id' => (string) $invoice->getKey(), 'amount' => $gross],
                ['type' => 'invoice', 'id' => (string) $invoice->getKey(), 'amount' => $gross],
            ],
            $this->actorId(),
            'the same invoice named twice',
        ));

        return $refusal !== null
            ? []
            : ['one invoice was settled twice by one split, because each allocation was checked on its own'];
    }

    /** @return list<string> */
    private function settledInASplit(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        /*
         | The second invoice is settled while the split is being prepared.
         |
         | Which is how allocation 2 fails in practice: somebody matched it
         | from another screen between the person opening the split and
         | submitting it. The first allocation is still perfectly good, and
         | writing it would leave a line part-applied by an operation the
         | person was told had failed.
         */
        $this->applyAMatchDirectly(
            'REF-GONE-A',
            (string) $second->gross_amount,
            (string) $second->getKey(),
            'settled from another screen while the split was being prepared',
        );

        $this->importLines([$this->line($total, ['bank_reference' => 'REF-GONE'])]);
        $transaction = $this->transactionBy('REF-GONE');

        $refusal = $this->refusal(fn () => app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $first->getKey(), 'amount' => (string) $first->gross_amount],
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => (string) $second->gross_amount],
        ], $this->actorId(), 'both invoices, one of which has since been settled'));

        if ($refusal === null) {
            return ['a split settled a document that was no longer open'];
        }

        $failures = [];

        if (! str_contains($refusal, 'not open')) {
            $failures[] = sprintf('the refusal does not say the document has gone: %s', $refusal);
        }

        if (! app(Splits::class)->remainder($transaction)->amount->isEqualTo($total)) {
            $failures[] = 'the first allocation was applied although the split was refused, so a '.
                'failed operation left the line part-applied';
        }

        return $failures;
    }

    /** @return list<string> */
    private function databaseRefusesOverApplication(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;

        $this->importLines([$this->line($gross, ['bank_reference' => 'REF-RAW'])]);
        $transaction = $this->transactionBy('REF-RAW');

        DB::connection('tenant')->table('matches')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'bank_transaction_id' => (string) $transaction->getKey(),
            'booking_date' => $transaction->booking_date->toDateString(),
            'matched_type' => 'invoice',
            'matched_id' => (string) $invoice->getKey(),
            'amount' => $gross,
            'currency' => 'SAR',
            'pass' => 'exact_reference',
            'confidence' => '0.9500',
            'reason' => 'the line applied in full, for the purposes of this check',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         | Written straight to the table, past every service.
         |
         | An import, a correction script or a future bulk tool reaches
         | this table without passing a service, and "the service is
         | careful" is not an invariant.
         */
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('matches')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'bank_transaction_id' => (string) $transaction->getKey(),
            'booking_date' => $transaction->booking_date->toDateString(),
            'matched_type' => 'invoice',
            'matched_id' => (string) $invoice->getKey(),
            'amount' => $gross,
            'currency' => 'SAR',
            'pass' => 'exact_reference',
            'confidence' => '0.9000',
            'reason' => 'a second match against a line that is already fully applied',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        if ($refusal === null) {
            return ['the database accepted a line applied to twice what it is worth'];
        }

        return str_contains($refusal, 'already applied') || str_contains($refusal, 'worth')
            ? []
            : [sprintf('the database refusal does not say what is wrong: %s', $refusal)];
    }

    /** @return list<string> */
    private function wrongCurrency(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, ['bank_reference' => 'REF-CCY'])]);
        $transaction = $this->transactionBy('REF-CCY');

        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('matches')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'bank_transaction_id' => (string) $transaction->getKey(),
            'booking_date' => $transaction->booking_date->toDateString(),
            'matched_type' => 'invoice',
            'matched_id' => (string) $invoice->getKey(),
            'amount' => '1.0000',
            'currency' => 'EUR',
            'pass' => 'exact_reference',
            'confidence' => '0.9000',
            'reason' => 'a match in a currency the line is not in',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $refusal !== null && str_contains($refusal, 'currency')
            ? []
            : ['a match in another currency was accepted, so the remainder is arithmetic between two units'];
    }

    // ---- The remainder, on both sides -------------------------------------

    /** @return list<string> */
    private function remainderOnTheLine(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);
        $line = (string) $gross->multipliedBy(2);

        $this->importLines([$this->line($line, ['bank_reference' => 'REF-REMAIN'])]);
        $transaction = $this->transactionBy('REF-REMAIN');

        $splits = app(Splits::class);

        /*
         | Before anything is applied, the whole line is unexplained.
         |
         | Asserted first because the figure after the split only means
         | something against a starting point - and "unexplained" defaulting
         | to zero would make every later assertion pass for the wrong
         | reason.
         */
        if (! $splits->remainder($transaction)->amount->isEqualTo($line)) {
            return ['a line with nothing applied to it does not report its whole amount as unexplained'];
        }

        app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $invoice->getKey(), 'amount' => (string) $gross->dividedBy(4, 4)],
            ['type' => 'invoice', 'id' => (string) $invoice->getKey(), 'amount' => (string) $gross->dividedBy(4, 4)],
        ], $this->actorId(), 'half of one invoice, paid against a larger transfer');

        $expected = $gross->dividedBy(2, 4);
        $remainder = $splits->remainder($transaction->refresh());

        $failures = [];

        if (! $remainder->amount->isEqualTo(BigDecimal::of($line)->minus($expected))) {
            $failures[] = sprintf(
                'the line reports %s unexplained after %s of %s was applied',
                (string) $remainder->amount,
                (string) $expected,
                $line,
            );
        }

        if ($splits->isFullyApplied($transaction)) {
            $failures[] = 'a part-applied line reports itself as fully applied, which is how it drops off the unreconciled list';
        }

        return $failures;
    }

    /** @return list<string> */
    private function remainderOnTheDocument(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);
        $half = (string) $gross->dividedBy(2, 4);

        $this->applyAMatchDirectly('REF-HALFDOC', $half, (string) $invoice->getKey(), 'the first instalment');

        $open = $this->find(
            (new OpenDocuments())->receivables($this->entityId, 'SAR'),
            (string) $invoice->getKey(),
        );

        if ($open === null) {
            return ['a part-settled invoice is no longer open, so the second instalment has nothing to match'];
        }

        return $open->outstanding->amount->isEqualTo($half)
            ? []
            : [sprintf(
                'the invoice reports %s outstanding after half of it was settled - a figure read '.
                'from a column rather than computed from the matches',
                (string) $open->outstanding->amount,
            )];
    }

    /** @return list<string> */
    private function manyLinesOneDocument(): array
    {
        $invoice = $this->issue();
        $gross = BigDecimal::of((string) $invoice->gross_amount);
        $third = $gross->dividedBy(4, 4);

        $this->applyAMatchDirectly('REF-I1', (string) $third, (string) $invoice->getKey(), 'the first instalment');
        $this->applyAMatchDirectly('REF-I2', (string) $third, (string) $invoice->getKey(), 'the second instalment');

        $view = app(Splits::class)->appliedTo($this->entityId, 'invoice', (string) $invoice->getKey(), 'SAR');

        $failures = [];

        if (\count($view['lines']) !== 2) {
            $failures[] = sprintf(
                'an invoice paid by two transfers shows %d of them, so "where did the rest come '.
                'from" has no answer',
                \count($view['lines']),
            );
        }

        if (! $view['settled']->amount->isEqualTo($third->multipliedBy(2))) {
            $failures[] = sprintf('the settled total is %s across two instalments', (string) $view['settled']->amount);
        }

        if ($view['outstanding'] === null
            || ! $view['outstanding']->amount->isEqualTo($gross->minus($third->multipliedBy(2)))) {
            $failures[] = 'the document side does not report what is still outstanding beside what was settled';
        }

        foreach ($view['lines'] as $line) {
            if (($line['bank_reference'] ?? null) === null) {
                $failures[] = 'a contributing line is listed without the bank reference that identifies it on a statement';
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function engineUsesTheRemainder(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        $this->importLines([$this->line($total, ['bank_reference' => 'REF-ENGINE'])]);
        $transaction = $this->transactionBy('REF-ENGINE');

        /*
         | The second invoice is settled from this line; the first is left.
         |
         | So the remainder has a document behind it that is still open,
         | which is the case worth checking: an engine looking at the whole
         | line finds no combination, and one looking at the remainder
         | finds the obvious answer.
         */
        app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => (string) BigDecimal::of((string) $second->gross_amount)->dividedBy(2, 4)],
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => (string) BigDecimal::of((string) $second->gross_amount)->dividedBy(2, 4)],
        ], $this->actorId(), 'the second invoice settled against part of the transfer');

        /*
         | The engine is asked again, and the question has moved.
         |
         | What is left is the first invoice's amount, not the whole
         | transfer. An engine still looking for the total finds nothing -
         | and subset-sum spends its budget searching for a combination
         | that cannot exist, because part of the line is spoken for.
         */
        $considered = app(MatchingEngine::class)->consider($transaction->refresh());

        $expected = BigDecimal::of($total)->minus((string) $second->gross_amount);

        foreach ($considered['candidates'] as $candidate) {
            if ($candidate->amount->amount->isEqualTo($expected)) {
                return [];
            }
        }

        return [sprintf(
            'the engine proposed nothing for the %s left of this line (it considered %d candidates), '.
            'so a part-applied line can never be finished by the engine that part-applied it',
            (string) $expected,
            \count($considered['candidates']),
        )];
    }

    /** @return list<string> */
    private function fullyAppliedSaysSo(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;

        $this->importLines([$this->line($gross, ['bank_reference' => 'REF-FULL'])]);
        $transaction = $this->transactionBy('REF-FULL');

        $this->applyAMatchDirectly('REF-FULL-A', $gross, (string) $invoice->getKey(), 'settled by its own line');

        DB::connection('tenant')->table('matches')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'entity_id' => $this->entityId,
            'bank_transaction_id' => (string) $transaction->getKey(),
            'booking_date' => $transaction->booking_date->toDateString(),
            'matched_type' => 'unidentified',
            'matched_id' => null,
            'amount' => $gross,
            'currency' => 'SAR',
            'pass' => 'manual',
            'confidence' => '1.0000',
            'reason' => 'the whole line parked as unidentified for the purposes of this check',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $considered = app(MatchingEngine::class)->consider($transaction->refresh());

        $failures = [];

        if ($considered['candidates'] !== []) {
            $failures[] = 'a fully applied line was given proposals, which would over-apply it if any were accepted';
        }

        /*
         | "Found nothing" and "nothing left to find" are different facts.
         |
         | Only the second is good news, and somebody looking at a line
         | with no proposals deserves to be told which one they have.
         */
        if (! str_contains((string) $considered['why'], 'fully applied')) {
            $failures[] = sprintf(
                'a fully applied line reads as an engine that found nothing: %s',
                (string) $considered['why'],
            );
        }

        if ($considered['skipped'] === []) {
            $failures[] = 'no pass was recorded as skipped on a line with nothing left to explain';
        }

        return $failures;
    }

    /** @return list<string> */
    private function partialKeepsProposals(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        $this->importLines([$this->line($total, [
            'bank_reference' => 'REF-KEEP',
            'remittance_unstructured' => (string) $first->number,
        ])]);

        $transaction = $this->transactionBy('REF-KEEP');
        $proposals = app(MatchingEngine::class)->propose($transaction);

        if (\count($proposals) < 2) {
            return [sprintf(
                'this check needs two proposals to tell rejection from supersession, and the engine '.
                'made %d',
                \count($proposals),
            )];
        }

        $chosen = $proposals[0];
        app(Matches::class)->accept($chosen, $this->actorId());

        $others = MatchProposal::query()
            ->where('bank_transaction_id', (string) $transaction->getKey())
            ->where('id', '!=', (string) $chosen->getKey())
            ->get();

        $failures = [];

        foreach ($others as $other) {
            if ((string) $other->status === 'rejected') {
                /*
                 | Rejected means somebody judged it wrong.
                 |
                 | These answered "what is this whole transfer", and the
                 | question is now "what is the rest of it". Marking them
                 | rejected charges six passes with being wrong about a
                 | question nobody asked them, and the accuracy report
                 | believes it.
                 */
                $failures[] = sprintf(
                    'a proposal on a part-applied line was rejected rather than superseded, so the '.
                    'accuracy report counts a wrong answer the %s pass never gave',
                    (string) $other->pass,
                );
            }
        }

        return $failures;
    }

    // ---- Unsplitting ------------------------------------------------------

    /** @return list<string> */
    private function unsplitReverses(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        $this->importLines([$this->line($total, ['bank_reference' => 'REF-UNDO'])]);
        $transaction = $this->transactionBy('REF-UNDO');

        app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $first->getKey(), 'amount' => (string) $first->gross_amount],
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => (string) $second->gross_amount],
        ], $this->actorId(), 'both invoices paid with one transfer');

        $result = app(Splits::class)->unsplit($transaction, $this->actorId(), 'the customer says the second invoice was not theirs');

        $failures = [];

        if (\count($result['reversed']) !== 2) {
            $failures[] = sprintf('unsplitting a two-way split reversed %d matches', \count($result['reversed']));
        }

        /*
         | The rows are kept, stamped.
         |
         | "It was split four ways and somebody undid it" is a question an
         | auditor asks, and a table that deletes cannot answer it.
         */
        $rows = DB::connection('tenant')->table('matches')
            ->where('bank_transaction_id', (string) $transaction->getKey())
            ->get();

        if ($rows->count() !== 2) {
            $failures[] = sprintf(
                'the reversal left %d rows where the split wrote 2, so the history of the split is gone',
                $rows->count(),
            );
        }

        foreach ($rows as $row) {
            if ($row->reversed_at === null) {
                $failures[] = 'a match survived the unsplit unreversed';
            }

            if (trim((string) ($row->reversal_reason ?? '')) === '') {
                $failures[] = 'a reversed match carries no reason, so the correction cannot be explained';
            }
        }

        foreach ([$first, $second] as $invoice) {
            $open = $this->find(
                (new OpenDocuments())->receivables($this->entityId, 'SAR'),
                (string) $invoice->getKey(),
            );

            if ($open === null) {
                $failures[] = sprintf('invoice %s is still settled after the split was taken back', $invoice->number);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function unsplitNeedsAReason(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        $this->importLines([$this->line($total, ['bank_reference' => 'REF-UNDO2'])]);
        $transaction = $this->transactionBy('REF-UNDO2');

        app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $first->getKey(), 'amount' => (string) $first->gross_amount],
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => (string) $second->gross_amount],
        ], $this->actorId(), 'both invoices paid with one transfer');

        $refusal = $this->refusal(fn () => app(Splits::class)->unsplit($transaction, $this->actorId(), 'x'));

        if ($refusal === null) {
            return ['a split was taken back with no reason, which is the reversal an auditor stops on'];
        }

        /*
         | Refused by the unsplit, before any match is touched.
         |
         | Reversing one match refuses an empty reason too, so without
         | this the first reversal would be written and the second would
         | refuse - a half-undone split, which is the state this whole
         | operation exists to make impossible.
         */
        return str_contains($refusal, 'Unsplitting')
            ? []
            : [sprintf(
                'the refusal came from reversing a match rather than from the unsplit, so the '.
                'reason was checked after the first one had already been taken back: %s',
                $refusal,
            )];
    }

    /** @return list<string> */
    private function unsplitRestoresTheLine(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        $this->importLines([$this->line($total, ['bank_reference' => 'REF-UNDO3'])]);
        $transaction = $this->transactionBy('REF-UNDO3');

        app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $first->getKey(), 'amount' => (string) $first->gross_amount],
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => (string) $second->gross_amount],
        ], $this->actorId(), 'both invoices paid with one transfer');

        app(Splits::class)->unsplit($transaction, $this->actorId(), 'the allocation was wrong and is being redone');

        $remainder = app(Splits::class)->remainder($transaction->refresh());

        if (! $remainder->amount->isEqualTo($total)) {
            return [sprintf(
                'the line reports %s unexplained after every match on it was reversed, so a reversed '.
                'match still counts against it and the line can never be rematched',
                (string) $remainder->amount,
            )];
        }

        /*
         | And it can be split again.
         |
         | Which is the point of reversing rather than blocking: the moment
         | somebody needs to rematch a line is the moment after they
         | discovered the first match was wrong.
         */
        $again = $this->refusal(fn () => app(Splits::class)->split($transaction, [
            ['type' => 'invoice', 'id' => (string) $first->getKey(), 'amount' => (string) $first->gross_amount],
            ['type' => 'invoice', 'id' => (string) $second->getKey(), 'amount' => (string) $second->gross_amount],
        ], $this->actorId(), 'redone correctly'));

        return $again === null
            ? []
            : [sprintf('a line could not be split again after being unsplit: %s', $again)];
    }

    /** @return list<string> */
    private function unsplitNothing(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, ['bank_reference' => 'REF-NOTHING'])]);

        $refusal = $this->refusal(fn () => app(Splits::class)->unsplit(
            $this->transactionBy('REF-NOTHING'),
            $this->actorId(),
            'there is nothing applied to this line',
        ));

        return $refusal !== null
            ? []
            : ['unsplitting a line with nothing applied reported success, which reads as having undone something'];
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * @param  list<\App\Tenant\Banking\Matching\OpenDocument>  $documents
     */
    private function find(array $documents, string $id): ?\App\Tenant\Banking\Matching\OpenDocument
    {
        foreach ($documents as $document) {
            if ($document->id === $id) {
                return $document;
            }
        }

        return null;
    }
}
