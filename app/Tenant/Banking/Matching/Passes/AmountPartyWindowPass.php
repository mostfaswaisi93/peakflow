<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching\Passes;

use App\Tenant\Banking\Matching\Candidate;
use App\Tenant\Banking\Matching\Confidence;
use App\Tenant\Banking\Matching\MatchingContext;
use App\Tenant\Banking\Matching\OpenDocument;

/**
 * Pass 3: the amount, the party and a date window - PF-157.
 *
 * ## Circumstantial, and it says so
 *
 * Nobody told us anything here. The amount equals what a document is owed,
 * the payer is on file as that document's party, and the dates are close.
 * That is right far more often than not, and it is never evidence on its
 * own - which is why it starts at 0.60 rather than 0.80.
 *
 * ## The party has to be IDENTIFIED, not guessed
 *
 * From the counterparty's account number, learned from a previous confirmed
 * match. Not from the name: a name match is pass 7's job and scores as
 * such, and letting it feed this pass would smuggle a guess into a figure
 * that reads as circumstantial-but-solid.
 *
 * A transaction whose party cannot be identified still runs through this
 * pass, on amount alone - and scores much lower for it, because an amount
 * that matches one open invoice among forty is a coincidence waiting to
 * happen.
 *
 * ## Two documents at the same amount is the interesting case
 *
 * It happens constantly: a monthly retainer, a standing charge, two
 * identical orders. Both are proposed and both carry a penalty, because
 * the engine cannot tell them apart and saying so is the whole value of
 * the pass here. PF-157 asks for ambiguity to be surfaced as a choice and
 * never resolved silently; this is where that starts.
 */
final class AmountPartyWindowPass implements MatchingPass
{
    public function key(): string
    {
        return 'amount_party_window';
    }

    public function appliesTo(MatchingContext $context): bool
    {
        return ! $context->amount->isZero();
    }

    /** @return list<Candidate> */
    public function run(MatchingContext $context): array
    {
        $window = (int) config('banking.matching.date_window_days', 5);
        $party = $context->identifiedParty();
        $amount = $context->amount->abs();

        $exact = [];

        foreach ($context->candidates() as $document) {
            if ($document->isSettledBy($amount)) {
                $exact[] = $document;
            }
        }

        if ($exact === []) {
            return [];
        }

        /*
         | How many documents this amount could be.
         |
         | Computed before scoring any of them, because the penalty belongs
         | to every candidate equally: with three documents open at the same
         | figure, no one of them is the answer and the engine must not
         | report one as though it were.
         */
        $competing = \count($exact);
        $candidates = [];

        foreach ($exact as $document) {
            $candidates[] = $this->score($context, $document, $party, $window, $competing);
        }

        return $candidates;
    }

    private function score(
        MatchingContext $context,
        OpenDocument $document,
        ?string $party,
        int $window,
        int $competing,
    ): Candidate {
        $days = abs($document->documentDate->diffInDays($context->bookingDate()));
        $partyAgrees = $party !== null && $party === $document->partyId;

        $confidence = Confidence::for($this->key())
            ->because('amount_settles_it', 0.10,
                'the amount settles the document exactly')
            ->when($partyAgrees, 'party_is_identified', 0.15,
                'the counterparty account is on file as this document\'s party, so who paid is known rather than guessed')
            ->when($party !== null && ! $partyAgrees, 'party_disagrees', -0.35,
                'the counterparty account is on file as a different party, so the amount agreeing is a coincidence')
            ->when($party === null, 'party_is_unknown', -0.15,
                'the counterparty is not on file, so only the amount connects this to the document')
            ->when($days <= $window, 'dates_are_close', 0.05,
                sprintf('the document is %d day%s old, within the %d-day window', $days, $days === 1 ? '' : 's', $window))
            ->when($days > 90, 'document_is_old', -0.10,
                sprintf('the document is %d days old, so an amount agreeing means less', $days))
            ->when($competing > 1, 'several_documents_at_this_amount', -0.25,
                sprintf(
                    '%d open documents are owed exactly this amount, so the amount does not identify any of them',
                    $competing,
                ));

        return new Candidate(
            pass: $this->key(),
            matchedType: $document->type,
            matchedId: $document->id,
            label: $document->label(),
            amount: $document->outstanding->abs(),
            confidence: $confidence->score(),
            reason: sprintf(
                '%s is owed exactly %s%s. %s',
                ucfirst($document->label()),
                (string) $document->outstanding->abs()->toPresentation(),
                $partyAgrees ? sprintf(' and the payer is on file as %s', $document->partyName ?? 'its party') : '',
                $confidence->summary(),
            ),
            factors: $confidence->factors(),
            partyId: $document->partyId,
        );
    }
}
