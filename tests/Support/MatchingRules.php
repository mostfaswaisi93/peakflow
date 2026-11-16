<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Models\Tenant;
use App\Platform\Services\TenantConnectionResolver;
use App\Tenant\Banking\Matching\Confidence;
use App\Tenant\Banking\Matching\MatchingEngine;
use App\Tenant\Banking\Matching\OpenDocuments;
use App\Tenant\Banking\Matching\PartyIdentifiers;
use App\Tenant\Banking\Matching\ReferenceExtraction;
use App\Tenant\Banking\Models\MatchProposal;
use App\Tenant\Banking\Models\MatchingRule as RuleModel;

use App\Tenant\Banking\Models\PartyBankIdentifier;
use App\Tenant\Banking\Services\Matches;
use App\Tenant\Banking\Services\MatchingRules as RuleWriter;
use App\Tenant\Sales\Models\Invoice;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The matching engine - PF-156 to PF-161.
 *
 * ## The two failures this suite is built around
 *
 * **Matching the wrong document confidently.** Far more expensive than
 * matching nothing: an unmatched transaction is visible and somebody deals
 * with it, while a payment applied to the wrong invoice closes one customer's
 * balance and leaves another being chased for money they sent. Most of the
 * checks here are about the engine refusing to be certain.
 *
 * **Being unable to say why.** A score is useless on its own. "Why was this
 * applied to invoice 4471" is asked months later by somebody holding a bank
 * statement, and an engine that cannot answer is one whose matches cannot be
 * defended - so every proposal carries its factors and its reason, and the
 * checks read them rather than only the number.
 */
final class MatchingRules
{
    use \Tests\Support\Concerns\OpensBankAccounts;

    /** @return array<string, list<string>> */
    public function all(): array
    {
        $tenant = Tenant::query()->whereNotNull('provisioned_at')->orderBy('created_at')->first();

        if ($tenant === null) {
            return ['the matching engine (no tenant provisioned - skipped)' => []];
        }

        app(TenantConnectionResolver::class)->point($tenant);
        $this->connection = DB::connection('tenant');
        $this->connection->beginTransaction();

        try {
            $this->seedTheBankSide();
            $this->postTheOpeningEntry();

            return $this->runEachInASavepoint([
                // ---- Reference extraction - PF-156 ---------------------
                'a reference the bank mangled is still found' => fn (): array => $this->mangledReference(),
                'a reference too short to mean anything is refused' => fn (): array => $this->shortReference(),
                'a reference does not match inside a longer number' => fn (): array => $this->boundary(),
                'a reference standing alone scores above one in a sentence' => fn (): array => $this->isolation(),

                // ---- Pass 1 - PF-156 -----------------------------------
                'an invoice number in the remittance matches it' => fn (): array => $this->exactReference(),
                'a part payment proposes the part, not the whole' => fn (): array => $this->partPayment(),
                'an overpayment scores lower and says why' => fn (): array => $this->overpayment(),
                'the outstanding figure is recomputed, not stored' => fn (): array => $this->outstandingIsRecomputed(),
                'a settled document is not a candidate' => fn (): array => $this->settledIsNotOffered(),
                'every proposal carries a reason that says something' => fn (): array => $this->reasonsSaySomething(),

                // ---- Passes 3 and 4 - PF-157 ---------------------------
                'structured remittance scores above free text' => fn (): array => $this->structuredBeatsFreeText(),
                'a referenced document that is closed is reported' => fn (): array => $this->closedReferenceIsReported(),
                'amount plus an identified party proposes' => fn (): array => $this->amountAndParty(),
                'an unidentified payer scores lower for it' => fn (): array => $this->unidentifiedPayer(),
                'two documents at one amount are both proposed' => fn (): array => $this->twoAtTheSameAmount(),
                'and neither is applied, because that is a choice' => fn (): array => $this->ambiguityIsNotResolved(),

                // ---- Subset-sum - PF-158 -------------------------------
                'a combination of invoices is found' => fn (): array => $this->subsetSum(),
                'and is shown in full before it is applied' => fn (): array => $this->combinationIsShown(),
                'it does not run when one document already matches' => fn (): array => $this->subsetSumYieldsToASingle(),
                'a pathological input respects the time budget' => fn (): array => $this->timeBudget(),
                'a large combination is never applied unseen' => fn (): array => $this->largeCombination(),

                // ---- The rule engine - PF-159 --------------------------
                'a rule matches and says which conditions did' => fn (): array => $this->ruleMatches(),
                'the first matching rule wins, by priority' => fn (): array => $this->firstMatchWins(),
                'the tester reports before anything is saved' => fn (): array => $this->ruleTester(),
                'and says to widen a window that found nothing' => fn (): array => $this->ruleTesterWindow(),
                'and warns when a rule would claim real documents' => fn (): array => $this->ruleTesterWarns(),
                'an edit writes a version and supersedes the old' => fn (): array => $this->ruleVersioning(),
                'a past proposal still names the version that made it' => fn (): array => $this->ruleVersionIsKept(),
                'a rule that produces nothing is refused' => fn (): array => $this->ruleMustProduce(),
                'and the database refuses one that tests nothing' => fn (): array => $this->ruleMustTest(),

                // ---- Fuzzy party - PF-160 ------------------------------
                'a resembling counterparty is proposed' => fn (): array => $this->fuzzyProposes(),
                'and is never applied, whatever it scores' => fn (): array => $this->fuzzyNeverApplies(),
                'Arabic spelling variants normalise to one name' => fn (): array => $this->arabicNormalisation(),
                'confirming a match teaches the account number' => fn (): array => $this->learning(),
                'and the next transfer is identified exactly' => fn (): array => $this->learnedThenIdentified(),
                'the engine never learns from its own guess' => fn (): array => $this->noSelfTeaching(),

                // ---- Confidence - PF-161 -------------------------------
                'every pass has a documented base' => fn (): array => $this->basesAreDocumented(),
                'the factors account for the score' => fn (): array => $this->factorsAddUp(),
                'a tenant cannot raise the threshold past the ceiling' => fn (): array => $this->ceilingHolds(),
                'nothing below the threshold is hidden' => fn (): array => $this->nothingIsHidden(),
                'an automatic match is on the review list' => fn (): array => $this->reviewList(),
                'and is reversible on its own' => fn (): array => $this->reversible(),
                'accuracy counts out of decided, not proposed' => fn (): array => $this->accuracyIsHonest(),
                'a reversal counts as corrected' => fn (): array => $this->reversalIsCorrected(),

                // ---- Deciding - PF-156, PF-161 -------------------------
                'a proposal cannot be decided twice' => fn (): array => $this->decidedOnce(),
                'a stale proposal is refused' => fn (): array => $this->staleProposal(),
                'the engine records which passes it skipped' => fn (): array => $this->skipsAreRecorded(),
            ]);
        } finally {
            $this->connection->rollBack();
        }
    }

    // ---- Reference extraction ---------------------------------------------

    /** @return list<string> */
    private function mangledReference(): array
    {
        $extraction = new ReferenceExtraction();
        $failures = [];

        /*
         | Every mangling a bank actually applies.
         |
         | The payer typed INV-1001. A literal comparison finds none of
         | these, and all five are the same reference.
         */
        foreach ([
            'INV-1001' => 'the reference as typed',
            'INV1001' => 'punctuation stripped',
            'inv 1001' => 'case folded and a space inserted',
            '/REMI/INV-1001 THANKS' => 'wrapped in a SWIFT segment',
            'Payment re inv.1001, many thanks' => 'buried in a sentence',
        ] as $text => $what) {
            $found = $extraction->find($text, 'INV-1001');

            if (! $found['found']) {
                $failures[] = sprintf('%s ("%s") was not found', $what, $text);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function shortReference(): array
    {
        $found = (new ReferenceExtraction())->find('Payment for 7 and other things', '7');

        if ($found['found']) {
            return [
                'a one-character reference matched. A document numbered 7 is findable in every '.
                'statement ever written, and matching it is the difference between an engine that '.
                'is sometimes unhelpful and one that is sometimes confidently wrong',
            ];
        }

        return str_contains($found['why'], 'coincidence')
            ? []
            : [sprintf('the refusal does not say why short references are refused: %s', $found['why'])];
    }

    /** @return list<string> */
    private function boundary(): array
    {
        $extraction = new ReferenceExtraction();

        $failures = [];

        /*
         | INV1001 must not match inside INV10010.
         |
         | Normalising strips the hyphen that would have separated them, so
         | without a boundary check every invoice matches its own successor
         | for the first ten of them.
         */
        if ($extraction->find('Payment for INV-10010', 'INV-1001')['found']) {
            $failures[] =
                'INV-1001 matched inside INV-10010, so a payment for one invoice would be applied '.
                'to the one before it';
        }

        if (! $extraction->find('Payment for INV-1001 thanks', 'INV-1001')['found']) {
            $failures[] = 'a reference followed by a word was rejected as though it were a longer number';
        }

        return $failures;
    }

    /** @return list<string> */
    private function isolation(): array
    {
        $extraction = new ReferenceExtraction();

        $alone = $extraction->find('INV-1001', 'INV-1001');
        $buried = $extraction->find(
            'Transfer regarding our conversation last week about invoice INV-1001 and the rest',
            'INV-1001',
        );

        $failures = [];

        if (! $alone['exact']) {
            $failures[] = 'a remittance field that is nothing but the reference is not recognised as exact';
        }

        if ($buried['isolated']) {
            $failures[] =
                'a reference inside forty words is treated as standing alone. It is the same '.
                'characters and much weaker evidence, because the sentence may be about a '.
                'different invoice entirely';
        }

        if ($alone['coverage'] <= $buried['coverage']) {
            $failures[] = 'coverage does not distinguish a reference alone from one in a sentence';
        }

        return $failures;
    }

    // ---- Pass 1 -----------------------------------------------------------

    /** @return list<string> */
    private function exactReference(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-EXACT',
            'remittance_unstructured' => 'Payment for '.$invoice->number,
        ])]);

        $considered = app(MatchingEngine::class)->consider($this->transactionBy('REF-EXACT'));
        $candidates = $considered['candidates'];

        if ($candidates === []) {
            return ['an invoice number in the remittance field produced no candidate at all'];
        }

        $failures = [];
        $best = $candidates[0];

        if ($best->matchedId !== (string) $invoice->getKey()) {
            $failures[] = 'the top candidate is not the invoice whose number was in the remittance';
        }

        if ($best->pass !== 'exact_reference') {
            $failures[] = sprintf('the top candidate came from %s rather than the reference pass', $best->pass);
        }

        if ($considered['auto_appliable'] === null) {
            $failures[] = sprintf(
                'an exact reference with an exact amount is not confident enough to apply (%.2f against %.2f)',
                $best->confidence,
                app(MatchingEngine::class)->threshold(),
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function partPayment(): array
    {
        $invoice = $this->issue();
        $half = (string) BigDecimal::of((string) $invoice->gross_amount)->dividedBy(2, 4);

        $this->importLines([$this->line($half, [
            'bank_reference' => 'REF-PART',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-PART'))['candidates'];

        if ($candidates === []) {
            return [
                'a part payment quoting the invoice number produced nothing. Discarding it sends an '.
                'ordinary instalment to the unmatched pile',
            ];
        }

        $best = $candidates[0];
        $failures = [];

        /*
         | The part, not the whole.
         |
         | Proposing the full outstanding would settle an invoice that is
         | still two thirds unpaid, and the reference would make it look
         | certain.
         */
        if (! $best->amount->abs()->amount->isEqualTo($half)) {
            $failures[] = sprintf(
                'the proposal is for %s rather than the %s that was actually paid, so accepting it '.
                'would close an invoice that is still owed money',
                (string) $best->amount->amount,
                $half,
            );
        }

        if (! str_contains(strtolower($best->reason), 'part payment')) {
            $failures[] = sprintf('the reason does not say this is a part payment: %s', $best->reason);
        }

        return $failures;
    }

    /** @return list<string> */
    private function overpayment(): array
    {
        $invoice = $this->issue();
        $tooMuch = (string) BigDecimal::of((string) $invoice->gross_amount)->plus('500.0000');

        $this->importLines([$this->line($tooMuch, [
            'bank_reference' => 'REF-OVER',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-OVER'))['candidates'];

        if ($candidates === []) {
            return ['an overpayment quoting the invoice number produced nothing'];
        }

        $best = $candidates[0];

        $overFactor = array_filter(
            $best->factors,
            static fn (array $factor): bool => $factor['factor'] === 'amount_exceeds_it',
        );

        return $overFactor === []
            ? [
                'an amount larger than the invoice scored as though it settled it. Either this is '.
                'an overpayment or the reference belongs to another document, and both are worth '.
                'knowing before it is applied',
            ]
            : [];
    }

    /** @return list<string> */
    private function outstandingIsRecomputed(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;
        $half = (string) BigDecimal::of($gross)->dividedBy(2, 4);

        $documents = new OpenDocuments();

        $before = $this->find($documents->receivables($this->entityId, 'SAR'), (string) $invoice->getKey());

        if ($before === null) {
            return ['an issued invoice is not open'];
        }

        /*
         | A match applied, and the outstanding figure moves by itself.
         |
         | Nothing writes a `paid_amount`. If the figure came from a column
         | it would still read the full gross here, and the second half
         | could be matched against the whole invoice again.
         */
        $this->applyAMatchDirectly(
            'REF-HALF',
            $half,
            (string) $invoice->getKey(),
            'half of the invoice, applied for the purposes of this check',
        );

        $after = $this->find($documents->receivables($this->entityId, 'SAR'), (string) $invoice->getKey());

        if ($after === null) {
            return ['the invoice disappeared after a part payment, so a second instalment could not be matched'];
        }

        return $after->outstanding->amount->isEqualTo($half)
            ? []
            : [sprintf(
                'the outstanding figure is %s after half was matched, so it is being read from a '.
                'stored total rather than computed - which drifts the first time a match is '.
                'reversed or a credit note cancelled',
                (string) $after->outstanding->amount,
            )];
    }

    /** @return list<string> */
    private function settledIsNotOffered(): array
    {
        $invoice = $this->issue();

        $this->applyAMatchDirectly(
            'REF-SETTLED',
            (string) $invoice->gross_amount,
            (string) $invoice->getKey(),
            'settled in full for the purposes of this check',
            '0.9500',
        );

        $open = (new OpenDocuments())->receivables($this->entityId, 'SAR');

        return $this->find($open, (string) $invoice->getKey()) === null
            ? []
            : [
                'a fully settled invoice is still offered as a candidate, so a second payment could '.
                'be applied to it - a duplicate receipt against a document that cannot absorb it',
            ];
    }

    /** @return list<string> */
    private function reasonsSaySomething(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-REASON',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-REASON'))['candidates'];
        $failures = [];

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate->reason) <= 10) {
                $failures[] = sprintf('a %s proposal has no reason worth the name', $candidate->pass);
            }

            /*
             | The reason names the document, not the pass.
             |
             | "Pass 1" is what somebody reads when asked why a payment was
             | applied to an invoice it should not have been, and it tells
             | them nothing they can act on.
             */
            if (str_contains($candidate->reason, 'pass 1') || $candidate->reason === $candidate->pass) {
                $failures[] = sprintf('the %s reason names the pass rather than the evidence', $candidate->pass);
            }

            if ($candidate->factors === []) {
                $failures[] = sprintf(
                    'a %s proposal carries no factors, so its score cannot be taken apart',
                    $candidate->pass,
                );
            }
        }

        return $failures;
    }

    // ---- Passes 3 and 4 ---------------------------------------------------

    /** @return list<string> */
    private function structuredBeatsFreeText(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;

        $this->importLines([
            $this->line($gross, [
                'bank_reference' => 'REF-STRD',
                'remittance_structured' => ['referenced' => [[
                    'documents' => [['number' => (string) $invoice->number, 'type' => 'CINV']],
                    'due_amount' => $gross,
                ]]],
            ]),
        ]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-STRD'))['candidates'];

        if ($candidates === []) {
            return ['a structured remittance naming an open invoice produced nothing'];
        }

        $best = $candidates[0];
        $failures = [];

        if ($best->pass !== 'structured_remittance') {
            $failures[] = sprintf('the structured remittance did not produce the top candidate (%s did)', $best->pass);
        }

        /*
         | It outranks free text by design.
         |
         | The payer put the number in a field that exists for it, with its
         | amount beside it. Nothing else a bank line carries is this
         | deliberate.
         */
        if (Confidence::BASES['structured_remittance'] <= Confidence::BASES['exact_reference']) {
            $failures[] = 'structured remittance does not start above free text, although it is the stronger signal';
        }

        return $failures;
    }

    /** @return list<string> */
    private function closedReferenceIsReported(): array
    {
        $this->importLines([
            $this->line('500.0000', [
                'bank_reference' => 'REF-CLOSED',
                'remittance_structured' => ['referenced' => [[
                    'documents' => [['number' => 'INV-GONE-9999']],
                ]]],
            ]),
        ]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-CLOSED'))['candidates'];

        $reported = array_filter(
            $candidates,
            static fn (object $c): bool => str_contains($c->reason, 'INV-GONE-9999'),
        );

        return $reported === []
            ? [
                'the payer named a document that is not open and nothing said so. That is usually a '.
                'duplicate payment, a reference copied from a previous transfer, or a document '.
                'closed early - all three are worth knowing before this is matched to something else',
            ]
            : [];
    }

    /** @return list<string> */
    private function amountAndParty(): array
    {
        $invoice = $this->issue();
        $this->teachTheAccount($invoice, 'SA9999888877776666555544');

        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-AMT',
            'counterparty_account' => 'SA9999888877776666555544',
        ])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-AMT'))['candidates'];

        if ($candidates === []) {
            return ['an exact amount from an identified payer produced nothing'];
        }

        $best = $candidates[0];
        $failures = [];

        if ($best->matchedId !== (string) $invoice->getKey()) {
            $failures[] = 'the amount-and-party pass did not propose the invoice';
        }

        $identified = array_filter(
            $best->factors,
            static fn (array $f): bool => $f['factor'] === 'party_is_identified',
        );

        if ($identified === []) {
            $failures[] =
                'the payer was identified from their account number and the score does not say so, '.
                'so a known payer and an unknown one are being scored alike';
        }

        return $failures;
    }

    /** @return list<string> */
    private function unidentifiedPayer(): array
    {
        $invoice = $this->issue();

        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-NOPARTY',
            'counterparty_name' => 'Somebody Nobody Knows Ltd',
        ])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-NOPARTY'))['candidates'];

        if ($candidates === []) {
            return [];
        }

        $amountPass = null;

        foreach ($candidates as $candidate) {
            if ($candidate->pass === 'amount_party_window') {
                $amountPass = $candidate;

                break;
            }
        }

        if ($amountPass === null) {
            return [];
        }

        $unknown = array_filter(
            $amountPass->factors,
            static fn (array $f): bool => $f['factor'] === 'party_is_unknown',
        );

        $failures = $unknown === []
            ? ['an unidentified payer is not penalised, so only the amount connects the two and nothing says so']
            : [];

        /*
         | And it is not confident enough to apply by itself.
         |
         | An amount that matches one open invoice among forty is a
         | coincidence waiting to happen.
         */
        if ($amountPass->confidence >= app(MatchingEngine::class)->threshold()) {
            $failures[] = sprintf(
                'an amount alone, from a payer nobody knows, scores %.2f - at or above the threshold',
                $amountPass->confidence,
            );
        }

        return $failures;
    }

    /** @return list<string> */
    private function twoAtTheSameAmount(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $this->importLines([$this->line((string) $first->gross_amount, ['bank_reference' => 'REF-TWO'])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-TWO'))['candidates'];

        $proposed = array_filter(
            $candidates,
            static fn (object $c): bool => \in_array($c->matchedId, [(string) $first->getKey(), (string) $second->getKey()], true),
        );

        $failures = [];

        if (\count($proposed) < 2) {
            $failures[] = sprintf(
                'only %d of the two documents owed exactly this amount were proposed. Picking one '.
                'is a guess the engine presented as an answer',
                \count($proposed),
            );
        }

        foreach ($proposed as $candidate) {
            $competing = array_filter(
                $candidate->factors,
                static fn (array $f): bool => $f['factor'] === 'several_documents_at_this_amount',
            );

            if ($competing === []) {
                $failures[] = 'a candidate competing with another at the same amount is not penalised for it';
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function ambiguityIsNotResolved(): array
    {
        $this->issue();
        $this->issue();

        $invoices = Invoice::query()->where('status', 'issued')->get();

        if ($invoices->count() < 2) {
            return ['two issued invoices were expected'];
        }

        $this->importLines([$this->line((string) $invoices->first()->gross_amount, ['bank_reference' => 'REF-AMBIG'])]);

        $considered = app(MatchingEngine::class)->consider($this->transactionBy('REF-AMBIG'));

        $failures = [];

        if (! $considered['ambiguous']) {
            $failures[] =
                'two equally good candidates pointing at different documents were not reported as '.
                'ambiguous, so the engine picked whichever sorted first and called it an answer';
        }

        if ($considered['auto_appliable'] !== null) {
            $failures[] = 'an ambiguous set was applied automatically';
        }

        if (! str_contains((string) $considered['why'], 'choice rather than a ranking')) {
            $failures[] = sprintf('the explanation does not say it is a choice: %s', (string) $considered['why']);
        }

        return $failures;
    }

    // ---- Subset-sum -------------------------------------------------------

    /** @return list<string> */
    private function subsetSum(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        $this->importLines([$this->line($total, ['bank_reference' => 'REF-SUBSET'])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-SUBSET'))['candidates'];

        $combination = null;

        foreach ($candidates as $candidate) {
            if ($candidate->pass === 'subset_sum') {
                $combination = $candidate;

                break;
            }
        }

        if ($combination === null) {
            return [
                'a payment covering two open invoices exactly produced no combination. This is how '.
                'most customers actually pay, and without it every such payment lands in suspense',
            ];
        }

        return \count($combination->components) === 2
            ? []
            : [sprintf('the combination has %d parts rather than two', \count($combination->components))];
    }

    /** @return list<string> */
    private function combinationIsShown(): array
    {
        $first = $this->issue();
        $second = $this->issue();

        $total = (string) BigDecimal::of((string) $first->gross_amount)
            ->plus((string) $second->gross_amount);

        $this->importLines([$this->line($total, ['bank_reference' => 'REF-SHOWN'])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-SHOWN'))['candidates'];

        foreach ($candidates as $candidate) {
            if ($candidate->pass !== 'subset_sum') {
                continue;
            }

            $failures = [];

            /*
             | Both document numbers in the reason.
             |
             | A total agreeing is not the same as these being the documents
             | the payer meant, and "two invoices add up" is not something
             | anybody can check.
             */
            foreach ([(string) $first->number, (string) $second->number] as $number) {
                if (! str_contains($candidate->reason, $number)) {
                    $failures[] = sprintf('the combination does not name %s', $number);
                }
            }

            if (! str_contains($candidate->reason, 'shown in full')) {
                $failures[] = 'the reason does not say the combination is shown in full';
            }

            return $failures;
        }

        return ['no combination was found'];
    }

    /** @return list<string> */
    private function subsetSumYieldsToASingle(): array
    {
        $invoice = $this->issue();
        $this->issue();

        $this->importLines([$this->line((string) $invoice->gross_amount, ['bank_reference' => 'REF-SINGLE'])]);

        $considered = app(MatchingEngine::class)->consider($this->transactionBy('REF-SINGLE'));

        $ranSubsetSum = \in_array('subset_sum', $considered['ran'], true);

        return $ranSubsetSum
            ? [
                'the subset-sum search ran although a single document already settles the amount '.
                'exactly. A combination adding to the same figure is a worse answer to a question '.
                'already answered, and the search is bounded precisely because it is expensive',
            ]
            : [];
    }

    /** @return list<string> */
    private function timeBudget(): array
    {
        /*
         | Twenty-four invoices, no combination that sums to the payment.
         |
         | The worst case: the search cannot stop early because it never
         | finds anything, so it runs every combination up to the maximum
         | size. Without a budget checked INSIDE the loop this does not
         | return - the combinations at size 6 over 24 documents are
         | 134,596, and the next size up is 346,104.
         */
        for ($i = 0; $i < 24; $i++) {
            $this->issue();
        }

        $budget = (int) config('banking.matching.subset_sum.time_budget_ms', 500);

        $this->importLines([$this->line('99999.9900', ['bank_reference' => 'REF-SLOW'])]);

        $started = hrtime(true);
        app(MatchingEngine::class)->consider($this->transactionBy('REF-SLOW'));
        $elapsed = (int) ((hrtime(true) - $started) / 1_000_000);

        /*
         | Generous, because the budget bounds the SEARCH and the rest of
         | the engine runs too. What this catches is the search not being
         | bounded at all, which does not take 3x the budget - it takes
         | minutes.
         */
        $allowed = ($budget * 3) + 2000;

        return $elapsed <= $allowed
            ? []
            : [sprintf(
                'matching one transaction against 24 open invoices with no combination took %dms, '.
                'against a %dms search budget. An unbounded subset-sum search does not return '.
                'slowly - it does not return, while holding a database connection',
                $elapsed,
                $budget,
            )];
    }

    /** @return list<string> */
    private function largeCombination(): array
    {
        $maximum = (int) config('banking.matching.subset_sum.auto_apply_max_size', 3);
        $invoices = [];
        $total = BigDecimal::zero();

        for ($i = 0; $i <= $maximum; $i++) {
            $invoice = $this->issue();
            $invoices[] = $invoice;
            $total = $total->plus((string) $invoice->gross_amount);
        }

        $this->importLines([$this->line((string) $total, ['bank_reference' => 'REF-BIG'])]);

        $considered = app(MatchingEngine::class)->consider($this->transactionBy('REF-BIG'));

        foreach ($considered['candidates'] as $candidate) {
            if ($candidate->pass !== 'subset_sum') {
                continue;
            }

            return $candidate->proposalOnly && $considered['auto_appliable'] === null
                ? []
                : [sprintf(
                    'a combination of %d documents was applied without anybody looking. A '.
                    'combination that size adding up is a coincidence often enough that no score '.
                    'should apply it unseen',
                    \count($candidate->components),
                )];
        }

        return [];
    }

    // ---- The rule engine --------------------------------------------------

    /** @return list<string> */
    private function ruleMatches(): array
    {
        $rule = $this->writeRule(['counterparty_pattern' => 'Riyadh Utilities', 'direction' => 'debit']);

        $this->importLines([$this->line('-500.0000', [
            'bank_reference' => 'REF-RULE',
            'counterparty_name' => 'Riyadh Utilities',
            'code_domain' => 'PMNT',
            'code_family' => 'MDOP',
            'code_subfamily' => 'CHRG',
        ])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-RULE'))['candidates'];

        if ($candidates === []) {
            return ['a rule written for this traffic matched nothing'];
        }

        $best = $candidates[0];
        $failures = [];

        if ($best->pass !== 'rule_engine') {
            $failures[] = sprintf('a fee matched %s rather than the rule written for it', $best->pass);
        }

        if ($best->ruleId !== (string) $rule->getKey()) {
            $failures[] = 'the proposal does not point at the rule version that produced it';
        }

        /*
         | The reason names the conditions that matched.
         |
         | "The rule matched" is not an explanation; naming the conditions
         | is one somebody can check against the line in front of them.
         */
        if (! str_contains($best->reason, 'Riyadh Utilities')) {
            $failures[] = sprintf('the reason does not say which conditions matched: %s', $best->reason);
        }

        return $failures;
    }

    /** @return list<string> */
    private function firstMatchWins(): array
    {
        $general = $this->writeRule(['direction' => 'debit'], 'Anything going out', 200);
        $specific = $this->writeRule(['counterparty_pattern' => 'Riyadh Utilities'], 'Electricity', 10);

        $this->importLines([$this->line('-500.0000', [
            'bank_reference' => 'REF-ORDER',
            'counterparty_name' => 'Riyadh Utilities',
        ])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-ORDER'))['candidates'];

        $fromRules = array_values(array_filter(
            $candidates,
            static fn (object $c): bool => $c->pass === 'rule_engine',
        ));

        if ($fromRules === []) {
            return ['neither rule matched'];
        }

        $failures = [];

        if (\count($fromRules) > 1) {
            $failures[] = 'two rules produced candidates, so first-match-wins is not being applied';
        }

        if ($fromRules[0]->ruleId !== (string) $specific->getKey()) {
            $failures[] =
                'the lower-priority rule won. The order is the rule set: a rule added later is '.
                'routinely meant to run first, and priority is how that is said';
        }

        return $failures;
    }

    /** @return list<string> */
    private function ruleTester(): array
    {
        $this->importLines([
            $this->line('-500.0000', ['bank_reference' => 'T1', 'counterparty_name' => 'Riyadh Utilities']),
            $this->line('-60.0000', ['bank_reference' => 'T2', 'counterparty_name' => 'Al Rajhi Bank']),
            $this->line('900.0000', ['bank_reference' => 'T3', 'counterparty_name' => 'Gulf Retail LLC']),
        ]);

        /*
         | A window wide enough to reach the fixture's dates.
         |
         | The default is 180 days from today and the fixture books in May
         | against a November clock. That is a fixture artefact, and the
         | tester says so rather than reporting "no transactions" - which
         | is the behaviour the next check covers.
         */
        $report = app(RuleWriter::class)->test($this->entityId, [
            'counterparty_pattern' => 'Riyadh Utilities',
            'gl_account_id' => $this->expenseAccountId,
        ], overDays: 3650);

        $failures = [];

        if ((int) $report['matched'] !== 1) {
            $failures[] = sprintf('the tester reports %d matches rather than one', (int) $report['matched']);
        }

        if ((int) $report['considered'] < 3) {
            $failures[] = 'the tester did not consider the transactions already imported';
        }

        /*
         | The transactions, not a count.
         |
         | "This matches 412 transactions" is a number somebody argues with;
         | the first twenty of them, with their amounts and counterparties,
         | is a rule somebody rewrites.
         */
        if ($report['examples'] === []) {
            $failures[] = 'the tester reports a count and no examples';
        }

        if (! isset($report['examples'][0]['counterparty_name'])) {
            $failures[] = 'the examples do not show what would have matched';
        }

        /*
         | And nothing was written.
         |
         | A rule is a standing instruction. Testing it must not be a way
         | of accidentally creating one.
         */
        if (RuleModel::query()->where('entity_id', $this->entityId)->exists()) {
            $failures[] = 'testing a rule saved it, so a trial run becomes a standing instruction';
        }

        return $failures;
    }

    /**
     * A window that reaches nothing says so, and says what to do.
     *
     * "No transactions to test against" reads as "you have no data", and
     * somebody concludes the rule cannot be tested. If four hundred of them
     * sit a year outside the window, the answer is to widen it - and only
     * the count tells them that.
     *
     * @return list<string>
     */
    private function ruleTesterWindow(): array
    {
        $this->importLines([
            $this->line('-500.0000', ['bank_reference' => 'W1', 'counterparty_name' => 'Riyadh Utilities']),
        ]);

        $report = app(RuleWriter::class)->test($this->entityId, [
            'counterparty_pattern' => 'Riyadh Utilities',
            'gl_account_id' => $this->expenseAccountId,
        ], overDays: 1);

        $failures = [];

        if ((int) $report['considered'] !== 0) {
            return ['the one-day window unexpectedly reached the fixture'];
        }

        if ((int) $report['outside_the_window'] < 1) {
            $failures[] = 'the tester does not say how many transactions are outside the window';
        }

        if (! str_contains((string) $report['why'], 'Widen it')) {
            $failures[] = sprintf('the verdict does not say to widen the window: %s', (string) $report['why']);
        }

        return $failures;
    }

    /** @return list<string> */
    private function ruleTesterWarns(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-REAL',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $transaction = $this->transactionBy('REF-REAL');
        app(Matches::class)->settle($transaction, $this->actorId());

        $report = app(RuleWriter::class)->test($this->entityId, [
            'direction' => 'credit',
            'gl_account_id' => $this->expenseAccountId,
        ], overDays: 3650);

        $failures = [];

        if ((int) $report['already_matched_to_something_else'] < 1) {
            $failures[] =
                'a rule that would have claimed a transaction already matched to an invoice was not '.
                'flagged. That is the specific way a rule written for fees comes to swallow '.
                'receipts, and a count would hide it';
        }

        if (! str_contains((string) $report['why'], 'really an invoice')) {
            $failures[] = sprintf('the verdict does not say what the danger is: %s', (string) $report['why']);
        }

        return $failures;
    }

    /** @return list<string> */
    private function ruleVersioning(): array
    {
        $first = $this->writeRule(['counterparty_pattern' => 'Riyadh'], 'Electricity');

        $second = app(RuleWriter::class)->change(
            $first,
            ['counterparty_pattern' => 'Riyadh Utilities', 'gl_account_id' => $this->expenseAccountId],
            $this->actorId(),
        );

        $first = $first->fresh();

        $failures = [];

        if ((int) $second->version !== 2) {
            $failures[] = sprintf('the new version is %d rather than 2', (int) $second->version);
        }

        if ((string) $second->rule_key !== (string) $first->rule_key) {
            $failures[] = 'the new version has a different rule key, so the two are unrelated rules';
        }

        if ($first->is_active) {
            $failures[] = 'the old version is still active, so both would fire';
        }

        if ((string) $first->superseded_by_id !== (string) $second->getKey()) {
            $failures[] = 'the old version does not point at its successor';
        }

        $refusal = $this->refusal(fn () => app(RuleWriter::class)->change(
            $first,
            ['gl_account_id' => $this->expenseAccountId],
            $this->actorId(),
        ));

        if ($refusal === null) {
            $failures[] = 'a superseded version could be changed again, so the history is a tree rather than a line';
        }

        return $failures;
    }

    /** @return list<string> */
    private function ruleVersionIsKept(): array
    {
        $rule = $this->writeRule(['counterparty_pattern' => 'Riyadh Utilities'], 'Electricity');

        $this->importLines([$this->line('-500.0000', [
            'bank_reference' => 'REF-VER',
            'counterparty_name' => 'Riyadh Utilities',
        ])]);

        $transaction = $this->transactionBy('REF-VER');
        app(MatchingEngine::class)->propose($transaction);

        $proposal = MatchProposal::query()
            ->where('bank_transaction_id', (string) $transaction->getKey())
            ->where('pass', 'rule_engine')
            ->first();

        if ($proposal === null) {
            return ['the rule produced no stored proposal'];
        }

        app(RuleWriter::class)->change(
            $rule,
            ['counterparty_pattern' => 'Something else entirely', 'gl_account_id' => $this->expenseAccountId],
            $this->actorId(),
        );

        /*
         | The proposal still names version 1.
         |
         | A rule edited today must not change what it says about a match it
         | made last March, and a proposal pointing at a mutable row would
         | do exactly that.
         */
        $named = RuleModel::query()->whereKey((string) $proposal->matching_rule_id)->first();

        if ($named === null) {
            return ['the version the proposal names no longer exists'];
        }

        return (string) $named->counterparty_pattern === 'Riyadh Utilities'
            ? []
            : [sprintf(
                'the proposal now reads as though it was made by a rule that says "%s". A match '.
                'made last March must still be explainable by the rule as it was',
                (string) $named->counterparty_pattern,
            )];
    }

    /** @return list<string> */
    private function ruleMustProduce(): array
    {
        $refusal = $this->refusal(fn () => app(RuleWriter::class)->write(
            $this->entityId,
            'Identifies and does nothing',
            ['counterparty_pattern' => 'Riyadh'],
            $this->actorId(),
        ));

        if ($refusal === null) {
            return ['a rule that produces nothing was accepted'];
        }

        return str_contains($refusal, 'moved the work rather than done it')
            ? []
            : [sprintf('the refusal does not say what is wrong with it: %s', $refusal)];
    }

    /** @return list<string> */
    private function ruleMustTest(): array
    {
        $refusal = $this->refusal(fn () => DB::connection('tenant')->table('matching_rules')->insert([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'rule_key' => (string) Str::ulid(),
            'version' => 1,
            'name' => 'Matches everything',
            'priority' => 100,
            'gl_account_id' => $this->expenseAccountId,
            'matched_type' => 'journal_entry',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $refusal === null
            ? [
                'a rule with no conditions was stored. It matches everything, which is never what '.
                'somebody meant, and is how a rule written for bank charges comes to claim every '.
                'receipt in the account',
            ]
            : [];
    }

    // ---- Fuzzy party ------------------------------------------------------

    /** @return list<string> */
    private function fuzzyProposes(): array
    {
        /*
         | The fixture's own customer, written as its bank would.
         |
         | Legal form dotted, case shouted - which is what a statement
         | carries and what the register does not.
         */
        $customer = (string) $this->party?->legal_name;
        $asTheBankWritesIt = mb_strtoupper(str_replace('LLC', 'L.L.C.', $customer));

        $this->importLines([$this->line('777.0000', [
            'bank_reference' => 'REF-FUZZ',
            'counterparty_name' => $asTheBankWritesIt,
        ])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-FUZZ'))['candidates'];

        $fuzzy = array_values(array_filter(
            $candidates,
            static fn (object $c): bool => $c->pass === 'fuzzy_party',
        ));

        if ($fuzzy === []) {
            return [sprintf(
                '"%s" was not recognised as resembling "%s", although they differ only in case and '.
                'in how the legal form is punctuated - which is the difference between a bank '.
                'statement and a company register',
                $asTheBankWritesIt,
                $customer,
            )];
        }

        /*
         | The party it names has the same name, not necessarily the same row.
         |
         | A group with a trading company and a holding company registered
         | alike is ordinary, and any of them is a legitimate answer to
         | "who does this name resemble" - which is why the pass proposes
         | rather than decides.
         */
        $parties = new PartyIdentifiers();
        $proposed = \App\Tenant\Parties\Models\Party::query()
            ->whereKey((string) $fuzzy[0]->partyId)
            ->value('legal_name');

        return $parties->normaliseName((string) $proposed) === $parties->normaliseName($customer)
            ? []
            : [sprintf(
                'the fuzzy pass proposed "%s", which does not resemble "%s"',
                (string) $proposed,
                $customer,
            )];
    }

    /** @return list<string> */
    private function fuzzyNeverApplies(): array
    {
        $this->importLines([$this->line('777.0000', [
            'bank_reference' => 'REF-FUZZ2',
            'counterparty_name' => (string) $this->party?->legal_name,
        ])]);

        $considered = app(MatchingEngine::class)->consider($this->transactionBy('REF-FUZZ2'));

        $failures = [];

        foreach ($considered['candidates'] as $candidate) {
            if ($candidate->pass !== 'fuzzy_party') {
                continue;
            }

            if (! $candidate->proposalOnly) {
                $failures[] = 'a fuzzy-party candidate is not marked proposal-only';
            }

            /*
             | Capped below any threshold a tenant can set.
             |
             | Not configured low - structurally incapable of applying
             | itself, because the pass answers WHO and never WHAT.
             */
            if ($candidate->confidence > Confidence::CEILINGS['fuzzy_party']) {
                $failures[] = sprintf(
                    'a fuzzy candidate scored %.2f, above its own %.2f ceiling',
                    $candidate->confidence,
                    Confidence::CEILINGS['fuzzy_party'],
                );
            }
        }

        if (Confidence::CEILINGS['fuzzy_party'] >= (float) config('banking.matching.auto_apply_ceiling')) {
            $failures[] = 'the fuzzy ceiling is not below the platform threshold ceiling, so a tenant could auto-apply it';
        }

        return $failures;
    }

    /** @return list<string> */
    private function arabicNormalisation(): array
    {
        $parties = new PartyIdentifiers();
        $failures = [];

        /*
         | أ إ آ ا are one letter typed four ways.
         |
         | A comparison that kept them apart calls the same company two
         | payers depending on which keyboard the clerk used - which is the
         | single biggest improvement to Arabic name comparison and the one
         | most implementations skip.
         */
        $pairs = [
            ['شركة الأمل للتجارة', 'شركه الامل للتجاره'],
            ['مؤسسة النيل', 'موسسه النيل'],
            ['Gulf Retail L.L.C.', 'GULF RETAIL LLC'],
        ];

        foreach ($pairs as [$left, $right]) {
            if ($parties->normaliseName($left) !== $parties->normaliseName($right)) {
                $failures[] = sprintf(
                    '"%s" and "%s" do not reduce to the same name: "%s" against "%s"',
                    $left,
                    $right,
                    $parties->normaliseName($left),
                    $parties->normaliseName($right),
                );
            }
        }

        /*
         | And two genuinely different companies stay different.
         |
         | Normalising that collapsed everything would be worse than not
         | normalising at all.
         */
        if ($parties->normaliseName('شركة الأمل') === $parties->normaliseName('شركة النور')) {
            $failures[] = 'two different company names reduce to the same thing';
        }

        return $failures;
    }

    /** @return list<string> */
    private function learning(): array
    {
        $invoice = $this->issue();

        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-LEARN',
            'counterparty_account' => 'SA1111222233334444555566',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $transaction = $this->transactionBy('REF-LEARN');
        $proposals = app(MatchingEngine::class)->propose($transaction);

        if ($proposals === []) {
            return ['nothing was proposed to accept'];
        }

        $result = app(Matches::class)->accept($proposals[0], $this->actorId());

        $failures = [];

        if ((int) $result['identifiers_learned'] < 1) {
            $failures[] =
                'accepting a match taught the engine nothing. The point of confirming one is that '.
                'the next transfer from that payer matches exactly rather than being guessed at';
        }

        $learned = PartyBankIdentifier::query()
            ->where('identifier_type', 'counterparty_account')
            ->where('identifier', 'SA1111222233334444555566')
            ->first();

        if ($learned === null) {
            $failures[] = 'the counterparty account was not stored against the party';
        } elseif ($learned->confirmed_by === null) {
            $failures[] = 'the identifier does not record who confirmed it, so a guess and evidence look alike';
        }

        return $failures;
    }

    /** @return list<string> */
    private function learnedThenIdentified(): array
    {
        $invoice = $this->issue();

        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-L1',
            'counterparty_account' => 'SA7777666655554444333322',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $proposals = app(MatchingEngine::class)->propose($this->transactionBy('REF-L1'));

        if ($proposals === []) {
            return ['nothing was proposed on the first transfer'];
        }

        app(Matches::class)->accept($proposals[0], $this->actorId());

        $second = $this->issue();

        $this->importLines([$this->line((string) $second->gross_amount, [
            'bank_reference' => 'REF-L2',
            'counterparty_account' => 'SA7777666655554444333322',
        ])], hash('sha256', 'second-transfer'));

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-L2'))['candidates'];

        if ($candidates === []) {
            return ['the second transfer from a now-known payer matched nothing at all'];
        }

        /*
         | Identified exactly, with no name comparison.
         |
         | The engine learns by accumulating identifiers, never by lowering
         | a threshold - and this is what that buys.
         */
        $identified = false;

        foreach ($candidates as $candidate) {
            foreach ($candidate->factors as $factor) {
                if ($factor['factor'] === 'party_is_identified') {
                    $identified = true;
                }
            }
        }

        return $identified
            ? []
            : [
                'the second transfer from a payer whose account was learned is still being scored '.
                'as an unknown party, so confirming the first one bought nothing',
            ];
    }

    /** @return list<string> */
    private function noSelfTeaching(): array
    {
        $invoice = $this->issue();

        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-AUTO',
            'counterparty_account' => 'SA5555444433332222111100',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        app(Matches::class)->settle($this->transactionBy('REF-AUTO'), $this->actorId());

        $learned = PartyBankIdentifier::query()
            ->where('identifier', 'SA5555444433332222111100')
            ->exists();

        return $learned
            ? [
                'an automatic match taught the engine an identifier. That is the engine teaching '.
                'itself, and two confident mistakes later nobody can say where the belief came '.
                'from - identifiers come from a person confirming, never from an unreviewed guess',
            ]
            : [];
    }

    // ---- Confidence -------------------------------------------------------

    /** @return list<string> */
    private function basesAreDocumented(): array
    {
        $declared = array_values((array) config('banking.matching.passes', []));
        $failures = [];

        foreach ($declared as $pass) {
            if (! \array_key_exists((string) $pass, Confidence::BASES)) {
                $failures[] = sprintf(
                    'the pass "%s" has no documented base, so its scores come from nowhere anybody '.
                    'can argue with',
                    (string) $pass,
                );
            }
        }

        /*
         | And the order of the bases matches the order of the passes.
         |
         | "Cheapest and most certain first" is a claim about both, and a
         | pass that ran first while scoring lowest would be a contradiction
         | nobody notices until they read two files at once.
         */
        if (Confidence::BASES['rule_engine'] <= Confidence::BASES['fuzzy_party']) {
            $failures[] = 'a rule somebody wrote does not outrank a guess about a name';
        }

        if (Confidence::BASES['exact_reference'] <= Confidence::BASES['amount_party_window']) {
            $failures[] = 'being told the document number does not outrank an amount agreeing';
        }

        return $failures;
    }

    /** @return list<string> */
    private function factorsAddUp(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-FACTORS',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $candidates = app(MatchingEngine::class)->consider($this->transactionBy('REF-FACTORS'))['candidates'];

        if ($candidates === []) {
            return ['nothing was proposed'];
        }

        $failures = [];

        foreach ($candidates as $candidate) {
            $total = 0.0;

            foreach ($candidate->factors as $factor) {
                $total += (float) $factor['weight'];
            }

            /*
             | The factors ARE the score.
             |
             | A score with factors that do not add up to it is a score with
             | decoration, and the whole point of emitting them is that the
             | working can be checked.
             */
            if (abs($total - $candidate->confidence) > 0.0001) {
                $failures[] = sprintf(
                    'a %s proposal scores %.4f and its factors total %.4f, so the working does not '.
                    'account for the answer',
                    $candidate->pass,
                    $candidate->confidence,
                    $total,
                );
            }

            foreach ($candidate->factors as $factor) {
                if (trim((string) $factor['why']) === '') {
                    $failures[] = sprintf('a %s factor has no explanation', $candidate->pass);
                }
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function ceilingHolds(): array
    {
        $ceiling = (float) config('banking.matching.auto_apply_ceiling');
        $engine = app(MatchingEngine::class);

        $previous = config('banking.matching.auto_apply_threshold');

        try {
            config(['banking.matching.auto_apply_threshold' => 0.99]);

            $effective = $engine->threshold();

            $failures = $effective > $ceiling
                ? [sprintf(
                    'a tenant raised the threshold to 0.99 and it took effect at %.2f, above the '.
                    '%.2f ceiling. A tenant setting it high is harmless; the ceiling exists for the '.
                    'other direction',
                    $effective,
                    $ceiling,
                )]
                : [];

            /*
             | And lowering it is theirs to choose.
             |
             | Caution is a tenant's decision. The ceiling is the operator's
             | and runs one way.
             */
            config(['banking.matching.auto_apply_threshold' => 0.50]);

            if ($engine->threshold() !== 0.50) {
                $failures[] = 'a tenant cannot lower their own threshold, although caution is theirs to choose';
            }

            return $failures;
        } finally {
            config(['banking.matching.auto_apply_threshold' => $previous]);
        }
    }

    /** @return list<string> */
    private function nothingIsHidden(): array
    {
        /*
         | Two open invoices of the same amount, and a payment for one.
         |
         | A single candidate cannot show this: "store them all" and
         | "store the best" produce the same one-row list, and a check
         | that cannot tell them apart passed every time the engine was
         | changed to drop the weak ones. Two documents the engine finds
         | equally plausible are the smallest scenario where truncation
         | is visible - and they are both weak, because two invoices for
         | one amount is precisely the question a person has to answer.
         */
        $first = $this->issue();
        $this->issue();

        $this->importLines([$this->line((string) $first->gross_amount, [
            'bank_reference' => 'REF-WEAK',
            'counterparty_name' => 'Gulf Retail LLC',
        ])]);

        $transaction = $this->transactionBy('REF-WEAK');
        $considered = app(MatchingEngine::class)->consider($transaction);
        $stored = app(MatchingEngine::class)->propose($transaction);

        /*
         | The scenario states its own precondition.
         |
         | If a later change makes the engine return one candidate here,
         | this check stops proving anything - and it says so rather than
         | reporting a pass it did not earn.
         */
        if (\count($considered['candidates']) < 2) {
            return [sprintf(
                'this check needs two plausible documents to tell a complete set of proposals from '.
                'a truncated one, and the engine considered %d',
                \count($considered['candidates']),
            )];
        }

        $failures = [];

        if (\count($stored) !== \count($considered['candidates'])) {
            $failures[] = sprintf(
                '%d candidates were considered and %d stored, so some were dropped for scoring low '.
                '- a hidden proposal is worse than a wrong one, because a wrong one gets corrected '.
                'and teaches the engine something',
                \count($considered['candidates']),
                \count($stored),
            );
        }

        $threshold = app(MatchingEngine::class)->threshold();
        $below = array_filter($stored, static fn (MatchProposal $p): bool => $p->score() < $threshold);

        if ($below === []) {
            $failures[] = sprintf(
                'no proposal scored below the %.2f threshold, so this check no longer exercises what '.
                'it is named for: proving a weak proposal is kept needs a weak proposal',
                $threshold,
            );
        }

        foreach ($below as $proposal) {
            if ((string) $proposal->status !== 'proposed') {
                $failures[] = 'a below-threshold proposal was not stored as an open suggestion';
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function reviewList(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-REVIEW',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $result = app(Matches::class)->settle($this->transactionBy('REF-REVIEW'), $this->actorId());

        if ($result['applied'] !== true) {
            return ['an exact reference with an exact amount was not applied automatically'];
        }

        $review = app(Matches::class)->appliedAutomatically($this->entityId, 30);

        return \count($review['matches']) === 1
            ? []
            : [sprintf(
                '%d automatic matches are on the review list rather than one. Everything applied '.
                'without a person looking has to be reviewable, or "automatic" means "invisible"',
                \count($review['matches']),
            )];
    }

    /** @return list<string> */
    private function reversible(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-REV',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $result = app(Matches::class)->settle($this->transactionBy('REF-REV'), $this->actorId());

        $failures = [];

        $outstanding = $this->find(
            (new OpenDocuments())->receivables($this->entityId, 'SAR'),
            (string) $invoice->getKey(),
        );

        if ($outstanding !== null) {
            $failures[] = 'the invoice is still open after being matched in full';
        }

        app(Matches::class)->reverse(
            (string) $result['match_id'],
            $this->actorId(),
            'applied to the wrong invoice',
        );

        /*
         | The document comes back by itself.
         |
         | Because outstanding is recomputed from the applied matches. A
         | stored total would need a second write here, and the one that
         | fails leaves an invoice nobody can collect.
         */
        $after = $this->find(
            (new OpenDocuments())->receivables($this->entityId, 'SAR'),
            (string) $invoice->getKey(),
        );

        if ($after === null) {
            $failures[] = 'the invoice is still settled after its only match was reversed';
        }

        $match = DB::connection('tenant')->table('matches')->where('id', (string) $result['match_id'])->first();

        if ($match === null) {
            $failures[] =
                'the reversed match was deleted. "This was matched to invoice 4471 and somebody '.
                'changed it" is the question an auditor asks, and a deleted row cannot answer it';
        }

        return $failures;
    }

    /** @return list<string> */
    private function accuracyIsHonest(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-ACC',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        app(MatchingEngine::class)->propose($this->transactionBy('REF-ACC'));

        $report = app(Matches::class)->accuracy($this->entityId);

        if ($report['passes'] === []) {
            return ['the accuracy report shows nothing although proposals exist'];
        }

        $failures = [];

        foreach ($report['passes'] as $pass) {
            /*
             | Undecided proposals do not count against a pass.
             |
             | Dividing by the total would make a new pass look terrible on
             | its first day and a stale one look excellent.
             */
            if ((int) $pass['undecided'] > 0 && $pass['accuracy'] !== null && (int) $pass['decided'] === 0) {
                $failures[] = sprintf(
                    'the %s pass reports an accuracy with nothing decided',
                    (string) $pass['pass'],
                );
            }
        }

        if (! str_contains((string) $report['why'], 'out of decided')) {
            $failures[] = 'the report does not say what it is dividing by';
        }

        return $failures;
    }

    /** @return list<string> */
    private function reversalIsCorrected(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-CORR',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $result = app(Matches::class)->settle($this->transactionBy('REF-CORR'), $this->actorId());

        app(Matches::class)->reverse((string) $result['match_id'], $this->actorId(), 'wrong invoice');

        $report = app(Matches::class)->accuracy($this->entityId);
        $corrected = 0;

        foreach ($report['passes'] as $pass) {
            $corrected += (int) $pass['corrected'];
        }

        return $corrected === 1
            ? []
            : [
                'a reversed match is not counted as corrected. Counting it as never-decided hides '.
                'exactly the outcome the accuracy report exists to show',
            ];
    }

    // ---- Deciding ---------------------------------------------------------

    /** @return list<string> */
    private function decidedOnce(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-TWICE',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $proposals = app(MatchingEngine::class)->propose($this->transactionBy('REF-TWICE'));

        if ($proposals === []) {
            return ['nothing was proposed'];
        }

        app(Matches::class)->accept($proposals[0], $this->actorId());

        $refusal = $this->refusal(fn () => app(Matches::class)->accept($proposals[0]->fresh(), $this->actorId()));

        if ($refusal === null) {
            return [
                'a proposal was accepted twice, leaving two matches against one bank line with no '.
                'way to tell which is current - and the accuracy report counting a decision nobody '.
                'made',
            ];
        }

        return str_contains($refusal, 'already')
            ? []
            : [sprintf('the refusal does not say it was already decided: %s', $refusal)];
    }

    /** @return list<string> */
    private function staleProposal(): array
    {
        $invoice = $this->issue();
        $gross = (string) $invoice->gross_amount;

        $this->importLines([$this->line($gross, [
            'bank_reference' => 'REF-STALE',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $proposals = app(MatchingEngine::class)->propose($this->transactionBy('REF-STALE'));

        if ($proposals === []) {
            return ['nothing was proposed'];
        }

        /*
         | Something else settles the invoice while the proposal sits.
         |
         | Which is ordinary: a second person matched it from another
         | screen. Applying the first proposal anyway would over-settle the
         | document, and the outstanding figure would go negative with
         | nothing saying why.
         */
        $this->applyAMatchDirectly(
            'REF-RACE',
            $gross,
            (string) $invoice->getKey(),
            'somebody else matched it from another screen',
            '0.9500',
        );

        $refusal = $this->refusal(fn () => app(Matches::class)->accept($proposals[0], $this->actorId()));

        if ($refusal === null) {
            return ['a proposal was applied to a document that had since been settled'];
        }

        return str_contains($refusal, 'no longer open') || str_contains($refusal, 'since the proposal was made')
            ? []
            : [sprintf('the refusal does not say what changed: %s', $refusal)];
    }

    /** @return list<string> */
    private function skipsAreRecorded(): array
    {
        $invoice = $this->issue();
        $this->importLines([$this->line((string) $invoice->gross_amount, [
            'bank_reference' => 'REF-SKIP',
            'remittance_unstructured' => (string) $invoice->number,
        ])]);

        $engine = app(MatchingEngine::class);
        $considered = $engine->consider($this->transactionBy('REF-SKIP'));

        $failures = [];

        if ($considered['skipped'] === []) {
            $failures[] = 'no pass was recorded as skipped, although an exact reference should stop the expensive ones';
        }

        foreach ($considered['skipped'] as $pass => $why) {
            if (trim((string) $why) === '') {
                $failures[] = sprintf('%s was skipped with no reason given', $pass);
            }
        }

        /*
         | Every pass is accounted for, or the record is not a record.
         |
         | "Something was skipped" is satisfied by one routing decision
         | and says nothing about the other five - which is how a pass
         | that stopped explaining itself went unnoticed: the two cheap
         | reasons still filled the list while the common one, "this
         | pass does not apply to this transaction", said nothing.
         |
         | "No fuzzy proposal" and "fuzzy was not run" are different
         | facts, and only the second has an explanation. Somebody asking
         | why a payer was not recognised deserves it rather than
         | silence, and that holds for every pass, not the fuzzy one.
         */
        foreach ($engine->passKeys() as $key) {
            if (\in_array($key, $considered['ran'], true) || isset($considered['skipped'][$key])) {
                continue;
            }

            $failures[] = sprintf(
                'the %s pass neither ran nor was recorded as skipped, so it disappeared without a word',
                str_replace('_', ' ', $key),
            );
        }

        return $failures;
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

    /** @param array<string, mixed> $definition */
    private function writeRule(array $definition, string $name = 'A rule', int $priority = 100): RuleModel
    {
        return app(RuleWriter::class)->write(
            $this->entityId,
            $name,
            ['gl_account_id' => $this->expenseAccountId, ...$definition],
            $this->actorId(),
            $priority,
        );
    }

    private function teachTheAccount(Invoice $invoice, string $account): void
    {
        PartyBankIdentifier::create([
            'id' => (string) Str::ulid(),
            'entity_id' => $this->entityId,
            'party_id' => (string) $invoice->party_id,
            'identifier_type' => 'counterparty_account',
            'identifier' => $account,
            'confirmed_by' => $this->actorId(),
            'times_seen' => 1,
            'last_seen_at' => now(),
        ]);
    }
}
