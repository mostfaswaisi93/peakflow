<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching\Passes;

use App\Tenant\Banking\Matching\Candidate;
use App\Tenant\Banking\Matching\Confidence;
use App\Tenant\Banking\Matching\MatchingContext;
use App\Tenant\Banking\Matching\OpenDocument;

/**
 * Pass 1: the document number is in the remittance field - PF-156.
 *
 * ## The cheapest and most certain, which is why it runs first
 *
 * A payer who typed the invoice number has answered the question. Nothing
 * the later passes do is as good as being told, and most B2B receipts carry
 * it.
 *
 * ## An exact reference does not excuse a wrong amount
 *
 * The reference says which document; it says nothing about whether this
 * payment settles it. A transfer quoting INV-1001 for a third of its value
 * is a part payment, and proposing it as a full settlement would close an
 * invoice two thirds unpaid.
 *
 * So the amount is scored separately: exactly outstanding raises the score,
 * less than outstanding lowers it and the candidate carries the part amount,
 * and MORE than outstanding lowers it further - because that is either an
 * overpayment or the reference belonging to a different document.
 */
final class ExactReferencePass implements MatchingPass
{
    public function key(): string
    {
        return 'exact_reference';
    }

    public function appliesTo(MatchingContext $context): bool
    {
        foreach ($context->remittanceFields() as $field) {
            if ($field !== null && trim($field) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return list<Candidate> */
    public function run(MatchingContext $context): array
    {
        $candidates = [];

        foreach ($context->candidates() as $document) {
            $found = $this->findIn($context, $document);

            if ($found === null) {
                continue;
            }

            $candidates[] = $this->score($context, $document, $found);
        }

        return $candidates;
    }

    /** @return array{found: bool, exact: bool, isolated: bool, coverage: float, why: string}|null */
    private function findIn(MatchingContext $context, OpenDocument $document): ?array
    {
        $best = null;

        foreach ($context->remittanceFields() as $field) {
            $found = $context->references->find($field, $document->number);

            if (! $found['found']) {
                continue;
            }

            /*
             | The strongest field wins.
             |
             | The same reference can appear in the remittance and in the
             | end-to-end id, and the second is the more deliberate place -
             | so whichever gave the higher coverage is the one scored.
             */
            if ($best === null || $found['coverage'] > $best['coverage']) {
                $best = $found;
            }
        }

        return $best;
    }

    private function score(MatchingContext $context, OpenDocument $document, array $found): Candidate
    {
        $amount = $context->amount->abs();
        $outstanding = $document->outstanding->abs();
        $settles = $document->isSettledBy($amount);
        $over = $amount->amount->isGreaterThan($outstanding->amount);

        $confidence = Confidence::for($this->key())
            ->when($found['exact'], 'reference_is_the_whole_field', 0.10,
                'the remittance field is nothing but the document number, which is a payer answering the question')
            ->when(! $found['exact'] && $found['isolated'], 'reference_stands_out', 0.05,
                'the document number is most of what the remittance field says')
            ->when($settles, 'amount_settles_it', 0.08,
                'the amount settles the document exactly')
            ->when(! $settles && ! $over, 'amount_is_partial', -0.12,
                'the amount is less than what is outstanding, so this is a part payment rather than a settlement')
            ->when($over, 'amount_exceeds_it', -0.20,
                'the amount is more than what is outstanding, so either this is an overpayment or the reference belongs to another document')
            ->when(
                $document->partyId !== null && $context->identifiedParty() === $document->partyId,
                'party_agrees', 0.07,
                'the counterparty is on file as this document\'s party',
            );

        /*
         | A part payment proposes the part, not the whole.
         |
         | Discarding it would send a perfectly ordinary instalment to the
         | unmatched pile; proposing the full amount would settle a document
         | that is still owed money.
         */
        $proposed = $settles || $over ? $outstanding : $amount;

        return new Candidate(
            pass: $this->key(),
            matchedType: $document->type,
            matchedId: $document->id,
            label: $document->label(),
            amount: $proposed,
            confidence: $confidence->score(),
            reason: sprintf(
                '%s, and %s is open for %s. %s',
                ucfirst($found['why']),
                $document->label(),
                (string) $outstanding->toPresentation(),
                $confidence->summary(),
            ),
            factors: $confidence->factors(),
            partyId: $document->partyId,
        );
    }
}
