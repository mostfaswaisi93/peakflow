<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching;

use App\Support\Money\Money;
use App\Tenant\Banking\Matching\Passes\AmountPartyWindowPass;
use App\Tenant\Banking\Matching\Passes\ExactReferencePass;
use App\Tenant\Banking\Matching\Passes\FuzzyPartyPass;
use App\Tenant\Banking\Matching\Passes\MatchingPass;
use App\Tenant\Banking\Matching\Passes\RuleEnginePass;
use App\Tenant\Banking\Matching\Passes\StructuredRemittancePass;
use App\Tenant\Banking\Matching\Passes\SubsetSumPass;
use App\Tenant\Banking\Models\BankTransaction;
use App\Tenant\Banking\Models\MatchProposal;
use App\Tenant\Banking\Services\TransactionRouting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The passes, run in order, and what comes out - PF-156 to PF-161.
 *
 * ## The engine decides nothing about a single candidate
 *
 * Each pass proposes; this ranks, deduplicates and decides what may be
 * applied without a person. That separation is what makes ambiguity
 * detectable at all - a pass cannot know that another pass found something
 * equally good, and a pass that applied its own best answer could never be
 * told that two of them agree and that is the problem.
 *
 * ## Order matters, and skipping is recorded
 *
 * Passes run cheapest and most certain first. Once a candidate reaches the
 * auto-apply threshold, the **expensive** passes are skipped: a subset-sum
 * search is bounded precisely because it is costly, and running it after
 * pass 1 read the invoice number off the remittance field spends that
 * budget on a worse answer to a question already answered.
 *
 * The skip is reported on the result. "No fuzzy proposal" and "fuzzy was
 * not run" are different facts, and only the second has an explanation -
 * somebody asking why a payer was not recognised deserves the second answer
 * rather than silence.
 *
 * ## Ambiguity is a property of the set
 *
 * Two candidates within `ambiguity_margin` of each other, pointing at
 * different documents, are a **choice**. Neither is applied automatically,
 * both are shown, and both carry the flag - so a proposal that was
 * ambiguous when made still reads as ambiguous after the others are
 * decided. PF-157 asks for exactly this, and the alternative is an engine
 * that silently picks whichever sorted first.
 *
 * ## Nothing below the threshold is hidden
 *
 * Every candidate is stored and shown, down to the weakest. A hidden
 * proposal is worse than a wrong one: a wrong proposal gets corrected and
 * teaches the engine something, and a hidden one leaves a person matching
 * by hand while the answer sits in a table nobody renders.
 */
final class MatchingEngine
{
    /** @var list<MatchingPass> */
    private array $passes;

    public function __construct(
        private readonly TransactionRouting $routing,
        private readonly OpenDocuments $documents = new OpenDocuments(),
        private readonly ReferenceExtraction $references = new ReferenceExtraction(),
        private readonly PartyIdentifiers $parties = new PartyIdentifiers(),
        ?array $passes = null,
    ) {
        $this->passes = $passes ?? [
            new ExactReferencePass(),
            new StructuredRemittancePass(),
            new AmountPartyWindowPass(),
            new SubsetSumPass(),
            new RuleEnginePass(),
            new FuzzyPartyPass(),
        ];
    }

    /**
     * Every pass the engine holds, in the order it runs them.
     *
     * Public because "which passes exist" is the only thing that tells a
     * pass skipped silently from one that was never there. {@see consider()}
     * reports what ran and what was skipped; without the full set, neither
     * a caller nor a check can notice a pass that answered with nothing at
     * all - which is the one failure that looks exactly like success.
     *
     * @return list<string>
     */
    public function passKeys(): array
    {
        return array_map(static fn (MatchingPass $pass): string => $pass->key(), $this->passes);
    }

    /**
     * Everything the engine thinks this transaction might be.
     *
     * @return array{
     *     candidates: list<Candidate>,
     *     strategy: string,
     *     ran: list<string>,
     *     skipped: array<string, string>,
     *     ambiguous: bool,
     *     auto_appliable: Candidate|null,
     *     why: string,
     * }
     */
    public function consider(BankTransaction $transaction): array
    {
        $context = new MatchingContext(
            transaction: $transaction,
            entityId: (string) $transaction->entity_id,
            amount: Money::of((string) $transaction->amount, (string) $transaction->currency),
            openDocuments: $this->documents,
            references: $this->references,
            parties: $this->parties,
        );

        $strategy = $this->routing->strategyForTransaction($transaction);
        $wanted = $this->routing->passesFor($strategy);
        $expensive = (array) config('banking.matching.expensive_passes', []);
        $threshold = $this->threshold();

        $candidates = [];
        $ran = [];
        $skipped = [];
        $best = 0.0;

        foreach ($this->passes as $pass) {
            $key = $pass->key();

            if (! \in_array($key, $wanted, true)) {
                $skipped[$key] = sprintf(
                    'the transaction routes to the %s strategy, which does not use this pass',
                    str_replace('_', ' ', $strategy),
                );

                continue;
            }

            if (\in_array($key, $expensive, true) && $best >= $threshold) {
                $skipped[$key] = sprintf(
                    'a candidate already scored %.2f, at or above the %.2f threshold, and this pass '.
                    'is expensive enough that running it would spend its budget on a worse answer '.
                    'to a question already answered',
                    $best,
                    $threshold,
                );

                continue;
            }

            if (! $pass->appliesTo($context)) {
                $skipped[$key] = $this->whyItDoesNotApply($key, $context);

                continue;
            }

            $ran[] = $key;

            foreach ($pass->run($context) as $candidate) {
                $candidates[] = $candidate;
                $best = max($best, $candidate->confidence);
            }
        }

        $candidates = $this->rank($this->deduplicate($candidates));
        $ambiguous = $this->isAmbiguous($candidates);

        return [
            'candidates' => $candidates,
            'strategy' => $strategy,
            'ran' => $ran,
            'skipped' => $skipped,
            'ambiguous' => $ambiguous,
            'auto_appliable' => $this->autoAppliable($candidates, $ambiguous, $threshold),
            'why' => $this->summarise($candidates, $ambiguous, $threshold, $ran, $skipped),
        ];
    }

    /**
     * Consider a transaction and store what came of it.
     *
     * @return list<MatchProposal>
     */
    public function propose(BankTransaction $transaction): array
    {
        $considered = $this->consider($transaction);

        return DB::connection('tenant')->transaction(function () use ($transaction, $considered): array {
            /*
             | Previous undecided proposals are superseded, not deleted.
             |
             | Re-running the engine after an invoice is issued is ordinary,
             | and the old set is how somebody answers "it suggested
             | something else yesterday". Decided ones are left alone: they
             | are the accuracy report's evidence.
             */
            MatchProposal::query()
                ->where('bank_transaction_id', (string) $transaction->getKey())
                ->where('status', 'proposed')
                ->update([
                    'status' => 'superseded',
                    'decided_at' => now(),
                    'decided_by' => '00000000000000000000000000',
                ]);

            $stored = [];

            foreach ($considered['candidates'] as $rank => $candidate) {
                $stored[] = MatchProposal::create([
                    'id' => (string) Str::ulid(),
                    'entity_id' => (string) $transaction->entity_id,
                    'bank_transaction_id' => (string) $transaction->getKey(),
                    'booking_date' => $transaction->booking_date->toDateString(),
                    'matched_type' => $candidate->matchedType,
                    'matched_id' => $candidate->matchedId,
                    'amount' => (string) $candidate->amount->amount,
                    'currency' => $candidate->amount->currency,
                    'pass' => $candidate->pass,
                    /*
                     | Written as a string at the stored scale.
                     |
                     | The column is `decimal(5,4)` and a runtime guard
                     | refuses a float binding outright - rightly, because
                     | whatever produced it has already lost precision.
                     | The score is a float in the engine, where it is a
                     | proportion being compared; it becomes exact the
                     | moment it is stored.
                     */
                    'confidence' => number_format($candidate->confidence, 4, '.', ''),
                    'reason' => $candidate->reason,
                    'factors' => $candidate->factors,
                    'rank' => $rank + 1,
                    'was_ambiguous' => $considered['ambiguous'],
                    'status' => 'proposed',
                    'matching_rule_id' => $candidate->ruleId,
                ]);
            }

            return $stored;
        });
    }

    /**
     * One candidate per target, keeping the strongest.
     *
     * The same invoice routinely comes back from pass 1 and pass 3 - the
     * payer typed the number AND the amount agrees. Proposing it twice
     * would make a screen read as though the engine found two reasons when
     * it found one document, and would make the ambiguity check fire on a
     * document agreeing with itself.
     *
     * @param  list<Candidate>  $candidates
     * @return list<Candidate>
     */
    private function deduplicate(array $candidates): array
    {
        $best = [];

        foreach ($candidates as $candidate) {
            $target = $candidate->target();

            if (! isset($best[$target]) || $candidate->confidence > $best[$target]->confidence) {
                $best[$target] = $candidate;
            }
        }

        return array_values($best);
    }

    /**
     * @param  list<Candidate>  $candidates
     * @return list<Candidate>
     */
    private function rank(array $candidates): array
    {
        usort(
            $candidates,
            /*
             | Confidence, then pass order, then label.
             |
             | The tie-breaks are there so the order is the same on every
             | run: a ranking that depends on the order a query returned
             | rows in is one nobody can check, and two proposals swapping
             | places between page loads reads as the engine changing its
             | mind.
             */
            static fn (Candidate $a, Candidate $b): int => [$b->confidence, $a->pass, $a->label]
                <=> [$a->confidence, $b->pass, $b->label],
        );

        return $candidates;
    }

    /**
     * @param  list<Candidate>  $candidates
     */
    private function isAmbiguous(array $candidates): bool
    {
        if (\count($candidates) < 2) {
            return false;
        }

        $margin = (float) config('banking.matching.ambiguity_margin', 0.05);  // @arch-allow: a confidence is a proportion, not money - see the class docblock.

        [$first, $second] = $candidates;

        /*
         | Only a different target is ambiguity.
         |
         | Two passes agreeing about one invoice is agreement, and
         | deduplication has already collapsed it. Two candidates within
         | the margin pointing at DIFFERENT documents is a question the
         | engine cannot answer.
         */
        return $first->target() !== $second->target()
            && abs($first->confidence - $second->confidence) <= $margin;
    }

    /**
     * @param  list<Candidate>  $candidates
     */
    private function autoAppliable(array $candidates, bool $ambiguous, float $threshold): ?Candidate  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
    {
        if ($candidates === [] || $ambiguous) {
            return null;
        }

        $best = $candidates[0];

        if ($best->proposalOnly || $best->confidence < $threshold) {
            return null;
        }

        return $best;
    }

    /**
     * The tenant's threshold, under the operator's ceiling.
     *
     * A tenant may lower it freely - caution is theirs to choose. The
     * ceiling is the operator's and cannot be raised past, because a tenant
     * setting it to 0.5 would be auto-applying circumstantial matches
     * across their whole ledger and the first they would hear of it is a
     * reconciliation that will not tie.
     */
    public function threshold(): float
    {
        $tenant = (float) config('banking.matching.auto_apply_threshold', 0.90);  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
        $ceiling = (float) config('banking.matching.auto_apply_ceiling', 0.95);  // @arch-allow: a confidence is a proportion, not money - see the class docblock.

        return min($tenant, $ceiling);
    }

    /**
     * @param  list<Candidate>  $candidates
     * @param  array<string, string>  $skipped
     * @param  list<string>  $ran
     */
    private function summarise(
        array $candidates,
        bool $ambiguous,
        float $threshold,  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
        array $ran,
        array $skipped,
    ): string {
        if ($candidates === []) {
            return sprintf(
                'Nothing matched. %d pass%s ran (%s)%s. An unmatched transaction is not a failure - '.
                'it is money that needs a person, and it stays visible rather than being forced '.
                'into a document that does not fit.',
                \count($ran),
                \count($ran) === 1 ? '' : 'es',
                $ran === [] ? 'none' : implode(', ', $ran),
                $skipped === [] ? '' : sprintf(' and %d did not', \count($skipped)),
            );
        }

        if ($ambiguous) {
            return sprintf(
                '%d candidates, and the top two are within %.2f of each other while pointing at '.
                'different documents: %s at %.2f and %s at %.2f. That is a choice rather than a '.
                'ranking, so neither is applied and both are shown.',
                \count($candidates),
                (float) config('banking.matching.ambiguity_margin', 0.05),  // @arch-allow: a confidence is a proportion, not money - see the class docblock.
                $candidates[0]->label,
                $candidates[0]->confidence,
                $candidates[1]->label,
                $candidates[1]->confidence,
            );
        }

        $best = $candidates[0];

        if ($best->proposalOnly) {
            return sprintf(
                '%s at %.2f, which is the best answer and is never applied without somebody looking '.
                'at it. %d candidate%s in all.',
                $best->label,
                $best->confidence,
                \count($candidates),
                \count($candidates) === 1 ? '' : 's',
            );
        }

        return $best->confidence >= $threshold
            ? sprintf(
                '%s at %.2f, at or above the %.2f threshold. %d candidate%s in all, all of them '.
                'shown.',
                $best->label,
                $best->confidence,
                $threshold,
                \count($candidates),
                \count($candidates) === 1 ? '' : 's',
            )
            : sprintf(
                '%s at %.2f, below the %.2f threshold, so it is a suggestion rather than a match. '.
                '%d candidate%s in all - nothing is hidden for scoring low, because a hidden '.
                'proposal is worse than a wrong one.',
                $best->label,
                $best->confidence,
                $threshold,
                \count($candidates),
                \count($candidates) === 1 ? '' : 's',
            );
    }

    private function whyItDoesNotApply(string $pass, MatchingContext $context): string
    {
        return match ($pass) {
            'exact_reference' => 'the transaction carries no remittance text, reference or counterparty name to find a document number in',
            'structured_remittance' => 'the transaction carries no structured remittance, which most payers never fill in',
            'subset_sum' => \count($context->candidates()) < 2
                ? 'there are fewer than two open documents, so there is no combination to search for'
                : 'a single open document already settles this amount exactly, so a combination adding to the same figure would be a worse answer',
            'fuzzy_party' => $context->identifiedParty() !== null
                ? 'the counterparty is already identified by their account number, which is better evidence than their name'
                : 'the transaction carries no counterparty name to compare',
            default => 'the pass had nothing to work with',
        };
    }
}
