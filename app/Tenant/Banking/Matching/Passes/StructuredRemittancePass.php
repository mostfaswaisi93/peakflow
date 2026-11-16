<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching\Passes;

use App\Support\Money\Money;
use App\Tenant\Banking\Matching\Candidate;
use App\Tenant\Banking\Matching\Confidence;
use App\Tenant\Banking\Matching\MatchingContext;

/**
 * Pass 4: `RmtInf/Strd` referenced documents - PF-157.
 *
 * ## The best signal there is, and the rarest
 *
 * A payer who filled in the structured remittance put the document number
 * in a field that exists for it, with its amount beside it. Nothing else a
 * bank line carries is this deliberate: free text can be mangled, typed
 * wrongly or about something else, and an amount alone is circumstantial.
 *
 * It scores highest for that reason, and it is still a proposal.
 *
 * ## It runs before the free-text pass would have mattered
 *
 * The same document often appears in both - the payer filled in the
 * structured field AND typed the number in the free text. The engine keeps
 * the stronger of the two rather than proposing the document twice, which
 * is what the deduplication by target is for.
 *
 * ## A referenced document that is not open is reported, not ignored
 *
 * The payer named invoice 1001 and invoice 1001 is settled. That is
 * information: a duplicate payment, a reference copied from last month, or
 * a document somebody closed early. Silently falling through to the
 * amount-based passes loses it, so it becomes a low-scoring candidate whose
 * reason says what happened.
 */
final class StructuredRemittancePass implements MatchingPass
{
    public function key(): string
    {
        return 'structured_remittance';
    }

    public function appliesTo(MatchingContext $context): bool
    {
        return $this->referenced($context) !== [];
    }

    /** @return list<Candidate> */
    public function run(MatchingContext $context): array
    {
        $referenced = $this->referenced($context);

        if ($referenced === []) {
            return [];
        }

        $candidates = [];
        $open = $context->candidates();

        foreach ($referenced as $entry) {
            $number = (string) $entry['number'];
            $stated = $entry['amount'];

            $document = null;

            foreach ($open as $candidate) {
                if ($context->references->normalise($candidate->number) === $context->references->normalise($number)) {
                    $document = $candidate;

                    break;
                }
            }

            if ($document === null) {
                $candidates[] = $this->notOpen($context, $number);

                continue;
            }

            $amount = $context->amount->abs();
            $outstanding = $document->outstanding->abs();
            $settles = $document->isSettledBy($amount);

            $confidence = Confidence::for($this->key())
                ->when($stated !== null, 'amount_was_stated', 0.03,
                    'the payer stated the amount against the document as well as its number')
                ->when(
                    $stated !== null && $stated->abs()->amount->isEqualTo($outstanding->amount),
                    'stated_amount_agrees', 0.04,
                    'the amount the payer stated is what is outstanding',
                )
                ->when($settles, 'amount_settles_it', 0.03,
                    'the amount settles the document exactly')
                ->when(! $settles, 'amount_is_partial', -0.10,
                    'the amount does not settle the document, so this is a part payment')
                ->when(
                    $document->partyId !== null && $context->identifiedParty() === $document->partyId,
                    'party_agrees', 0.02,
                    'the counterparty is on file as this document\'s party',
                );

            $candidates[] = new Candidate(
                pass: $this->key(),
                matchedType: $document->type,
                matchedId: $document->id,
                label: $document->label(),
                amount: $settles ? $outstanding : $amount,
                confidence: $confidence->score(),
                reason: sprintf(
                    'The payer named %s in the structured remittance field, which exists for exactly '.
                    'this and is the most deliberate thing a bank line can carry. %s',
                    $document->label(),
                    $confidence->summary(),
                ),
                factors: $confidence->factors(),
                partyId: $document->partyId,
            );
        }

        return $candidates;
    }

    /**
     * A referenced document that is not open.
     *
     * Proposed as `unidentified` rather than dropped: the payer told us
     * something and it does not fit, which is worth a person's attention.
     */
    private function notOpen(MatchingContext $context, string $number): Candidate
    {
        $confidence = Confidence::for($this->key())
            ->because('referenced_document_is_not_open', -0.55,
                'the document the payer named has nothing outstanding on it');

        return new Candidate(
            pass: $this->key(),
            matchedType: 'unidentified',
            matchedId: null,
            label: sprintf('document %s, which is not open', $number),
            amount: $context->amount,
            confidence: $confidence->score(),
            reason: sprintf(
                'The payer named document %s in the structured remittance field, and that document '.
                'has nothing outstanding on it. That is usually a duplicate payment, a reference '.
                'copied from a previous transfer, or a document somebody closed early - all three '.
                'are worth knowing before this is matched to something else.',
                $number,
            ),
            factors: $confidence->factors(),
            proposalOnly: true,
        );
    }

    /**
     * @return list<array{number: string, amount: Money|null}>
     */
    private function referenced(MatchingContext $context): array
    {
        $structured = $context->transaction->remittance_structured;

        if (! \is_array($structured)) {
            return [];
        }

        $found = [];

        foreach ((array) ($structured['referenced'] ?? []) as $entry) {
            $entry = (array) $entry;

            foreach ((array) ($entry['documents'] ?? []) as $document) {
                $document = (array) $document;
                $number = (string) ($document['number'] ?? '');

                if ($number === '') {
                    continue;
                }

                $found[] = [
                    'number' => $number,
                    'amount' => isset($entry['due_amount'])
                        ? Money::of((string) $entry['due_amount'], $context->amount->currency)
                        : null,
                ];
            }

            /*
             | A creditor reference with no referenced document.
             |
             | `CdtrRefInf/Ref` is an ISO 11649 structured reference, which
             | several payers send instead of a document list. It is still
             | the payer naming something deliberately, so it is treated as
             | a number to look for.
             */
            $reference = (string) ($entry['creditor_reference'] ?? '');

            if ($reference !== '' && ($entry['documents'] ?? []) === []) {
                $found[] = ['number' => $reference, 'amount' => null];
            }
        }

        return $found;
    }
}
