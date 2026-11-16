<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching\Passes;

use App\Support\Money\Money;
use App\Tenant\Banking\Matching\Candidate;
use App\Tenant\Banking\Matching\Confidence;
use App\Tenant\Banking\Matching\MatchingContext;
use App\Tenant\Banking\Matching\OpenDocument;
use Brick\Math\BigDecimal;

/**
 * Pass 5: one credit against a combination of open invoices - PF-158.
 *
 * ## This is how most customers actually pay
 *
 * A customer with four open invoices sends one transfer for the total. No
 * reference, no breakdown, one figure. Every pass before this one fails on
 * it, and without this pass every such payment lands in suspense - which
 * for some businesses is most of their receipts.
 *
 * ## And it is the one that hangs
 *
 * Subset-sum is NP-complete. The naive implementation on a customer with
 * four hundred open items does not return. Not slowly - it does not return,
 * and the request that called it holds a database connection while it does
 * not return.
 *
 * So it is bounded three ways, and all three are configuration:
 *
 * 1. **A candidate cap.** Only the N most plausible documents are
 *    considered. Beyond that the search is not attempted at all.
 * 2. **A time budget**, checked inside the loop. A search that exceeds it
 *    stops and reports what it found, rather than being killed by something
 *    further up that cannot say why.
 * 3. **A combination cap.** A combination of eleven invoices adding to a
 *    round figure is arithmetic, not evidence, and searching for one costs
 *    more than it is worth.
 *
 * ## Deterministic, oldest first
 *
 * Two different combinations can both add up. Which one the engine proposes
 * must not depend on the order a query happened to return rows in, because
 * a proposal that changes between two runs is one nobody can check. The
 * documents are sorted oldest first and the search takes the first
 * combination it finds at the smallest size - which is also how a customer
 * would have paid: the oldest invoices, as few as possible.
 *
 * ## It never auto-applies above a configured size
 *
 * A two-invoice combination is a reasonable thing to accept automatically.
 * A seven-invoice one that happens to add up is a coincidence more often
 * than it is a payment, so beyond the configured size the proposal is
 * marked for a human whatever it scores.
 */
final class SubsetSumPass implements MatchingPass
{
    public function key(): string
    {
        return 'subset_sum';
    }

    public function appliesTo(MatchingContext $context): bool
    {
        /*
         | Only worth trying when no single document matches.
         |
         | If one does, the amount pass has already proposed it and a
         | combination adding to the same figure is a worse answer to a
         | question already answered.
         */
        $amount = $context->amount->abs();

        foreach ($context->candidates() as $document) {
            if ($document->isSettledBy($amount)) {
                return false;
            }
        }

        return \count($context->candidates()) >= 2;
    }

    /** @return list<Candidate> */
    public function run(MatchingContext $context): array
    {
        $cap = (int) config('banking.matching.subset_sum.candidate_cap', 24);
        $budgetMs = (int) config('banking.matching.subset_sum.time_budget_ms', 500);
        $maxSize = (int) config('banking.matching.subset_sum.max_combination_size', 6);

        $target = $context->amount->abs();
        $party = $context->identifiedParty();

        $documents = $this->narrow($context->candidates(), $target, $party, $cap);

        if (\count($documents) < 2) {
            return [];
        }

        $started = hrtime(true);
        $found = $this->search($documents, $target, $maxSize, $started, $budgetMs);

        if ($found === null) {
            return [];
        }

        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);

        return [$this->score($context, $found, $party, $elapsedMs, \count($documents), $maxSize)];
    }

    /**
     * The documents worth searching, oldest first.
     *
     * Narrowed before the search because the cap is the only thing between
     * this pass and not returning. A document larger than the whole payment
     * cannot be part of any combination that sums to it, so it is dropped
     * first - which on a typical account removes most of them.
     *
     * @param  list<OpenDocument>  $documents
     * @return list<OpenDocument>
     */
    private function narrow(array $documents, Money $target, ?string $party, int $cap): array
    {
        $usable = [];

        foreach ($documents as $document) {
            if ($document->outstanding->abs()->amount->isGreaterThan($target->amount)) {
                continue;
            }

            /*
             | Where the party is known, only their documents.
             |
             | A combination drawn from several customers' invoices is not a
             | payment anybody made. It is also what turns a 12-document
             | search into a 400-document one.
             */
            if ($party !== null && $document->partyId !== null && $document->partyId !== $party) {
                continue;
            }

            $usable[] = $document;
        }

        usort(
            $usable,
            static fn (OpenDocument $a, OpenDocument $b): int => [$a->documentDate, $a->number]
                <=> [$b->documentDate, $b->number],
        );

        return \array_slice($usable, 0, $cap);
    }

    /**
     * The smallest combination that sums to the target, oldest first.
     *
     * Breadth by size: all pairs, then all triples, and so on. That is what
     * makes it deterministic and what makes it stop early on the common
     * case - a customer paying two invoices is found before any triple is
     * considered.
     *
     * @param  list<OpenDocument>  $documents
     * @return list<OpenDocument>|null
     */
    private function search(
        array $documents,
        Money $target,
        int $maxSize,
        int|float $started,
        int $budgetMs,
    ): ?array {
        $amounts = array_map(
            static fn (OpenDocument $document): BigDecimal => $document->outstanding->abs()->amount,
            $documents,
        );

        $goal = $target->amount;
        $count = \count($documents);

        for ($size = 2; $size <= min($maxSize, $count); $size++) {
            $indices = range(0, $size - 1);

            while (true) {
                if ($this->outOfTime($started, $budgetMs)) {
                    /*
                     | The budget is checked inside the loop.
                     |
                     | Checking only between sizes would let one size run
                     | for minutes: the combinations at size 6 over 24
                     | documents are 134,596, and the point of a budget is
                     | that it is never exceeded by much.
                     */
                    return null;
                }

                $sum = BigDecimal::zero();

                foreach ($indices as $index) {
                    $sum = $sum->plus($amounts[$index]);
                }

                if ($sum->isEqualTo($goal)) {
                    return array_map(static fn (int $i): OpenDocument => $documents[$i], $indices);
                }

                $position = $size - 1;

                while ($position >= 0 && $indices[$position] === $position + $count - $size) {
                    $position--;
                }

                if ($position < 0) {
                    break;
                }

                $indices[$position]++;

                for ($next = $position + 1; $next < $size; $next++) {
                    $indices[$next] = $indices[$next - 1] + 1;
                }
            }
        }

        return null;
    }

    private function outOfTime(int|float $started, int $budgetMs): bool
    {
        return (hrtime(true) - $started) / 1_000_000 > $budgetMs;
    }

    /**
     * @param  list<OpenDocument>  $combination
     */
    private function score(
        MatchingContext $context,
        array $combination,
        ?string $party,
        int $elapsedMs,
        int $searched,
        int $maxSize,
    ): Candidate {
        $size = \count($combination);
        $autoApplyAbove = (int) config('banking.matching.subset_sum.auto_apply_max_size', 3);
        $partyAgrees = $party !== null && array_reduce(
            $combination,
            static fn (bool $carry, OpenDocument $d): bool => $carry && $d->partyId === $party,
            true,
        );

        $confidence = Confidence::for($this->key())
            ->when($partyAgrees, 'party_is_identified', 0.20,
                'every document in the combination belongs to the identified payer')
            ->when($party === null, 'party_is_unknown', -0.10,
                'the payer is not on file, so only the arithmetic connects this to these documents')
            ->when($size === 2, 'two_documents', 0.10,
                'two documents, which is the commonest way a customer pays and the least likely to add up by chance')
            ->when($size > $autoApplyAbove, 'many_documents', -0.05 * ($size - $autoApplyAbove),
                sprintf('%d documents: a combination this size adding up is arithmetic more often than it is a payment', $size));

        $labels = array_map(static fn (OpenDocument $d): string => $d->label(), $combination);

        /*
         | Never auto-applied above the configured size, whatever it scores.
         |
         | PF-158 asks for this directly. A seven-invoice combination that
         | happens to total the payment is a coincidence often enough that
         | no score should be allowed to apply it unseen.
         */
        $proposalOnly = $size > $autoApplyAbove;

        return new Candidate(
            pass: $this->key(),
            matchedType: $combination[0]->type,
            matchedId: $combination[0]->id,
            label: implode(' + ', $labels),
            amount: $context->amount->abs(),
            confidence: $confidence->score(),
            reason: sprintf(
                'These %d documents add up to exactly %s: %s. The combination is shown in full '.
                'because a total agreeing is not the same as these being the documents the payer '.
                'meant. Found in %dms, searching %d of the open documents, smallest combination '.
                'first and oldest within it. %s',
                $size,
                (string) $context->amount->abs()->toPresentation(),
                implode(', ', $labels),
                $elapsedMs,
                $searched,
                $confidence->summary(),
            ),
            factors: $confidence->factors(),
            components: array_map(static fn (OpenDocument $d): string => $d->id, $combination),
            partyId: $partyAgrees ? $party : null,
            proposalOnly: $proposalOnly,
        );
    }
}
