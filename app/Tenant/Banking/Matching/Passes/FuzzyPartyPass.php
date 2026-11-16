<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching\Passes;

use App\Tenant\Banking\Matching\Candidate;
use App\Tenant\Banking\Matching\Confidence;
use App\Tenant\Banking\Matching\MatchingContext;
use App\Tenant\Banking\Models\PartyBankIdentifier;
use App\Tenant\Parties\Models\Party;

/**
 * Pass 7: the counterparty's name resembles a party's - PF-160.
 *
 * ## Proposal only, at any confidence, for ever
 *
 * Not configured low - structurally incapable of applying itself. The
 * ceiling in {@see Confidence} sits below any threshold a tenant can set,
 * and every candidate here carries `proposalOnly`.
 *
 * The reason is what the pass answers. It says **who**, and never **what**.
 * Applying it alone would settle a document nobody identified, against a
 * party whose name merely resembled one - and "Gulf Trading" against "Gulf
 * Trading Est." is a good match, while "Al Faisal Steel" against "Al Faisal
 * Steel Industries" may be two different companies owned by the same
 * family.
 *
 * ## Its real output is a question, and its real value is what follows
 *
 * When somebody confirms one, the counterparty's **account number** is
 * stored against the party. The next transfer from that payer is identified
 * exactly, by {@see \App\Tenant\Banking\Matching\PartyIdentifiers}, with no
 * name comparison at all.
 *
 * So this pass is how a payer gets onto the books, once. It is not how they
 * are matched afterwards, and an engine that leaned on it would be one that
 * never learned anything.
 *
 * ## The distance is normalised before it is measured
 *
 * Case, punctuation, legal-form suffixes, Arabic diacritics and the alef
 * and ta-marbuta variants. That last part matters more than it sounds:
 * أ إ آ ا are one letter typed four ways, and a comparison that kept them
 * apart calls the same company two payers depending on which keyboard the
 * clerk used.
 */
final class FuzzyPartyPass implements MatchingPass
{
    public function key(): string
    {
        return 'fuzzy_party';
    }

    public function appliesTo(MatchingContext $context): bool
    {
        /*
         | Nothing to compare, or nothing to learn.
         |
         | A transaction whose party is already identified has no use for a
         | name guess: the identifier is better evidence and is already in
         | hand.
         */
        return trim((string) $context->transaction->counterparty_name) !== ''
            && $context->identifiedParty() === null;
    }

    /** @return list<Candidate> */
    public function run(MatchingContext $context): array
    {
        $name = (string) $context->transaction->counterparty_name;
        $normalised = $context->parties->normaliseName($name);

        if (mb_strlen($normalised) < 4) {
            return [];
        }

        /*
         | A name already learned for a party is an exact hit here.
         |
         | Not identity - `PartyIdentifiers::identify()` deliberately
         | refuses to treat a name as one - but a name somebody confirmed
         | before is much stronger evidence than a name that merely looks
         | similar, and saying so is the difference between the second
         | transfer from a payer and the twentieth.
         */
        $seen = PartyBankIdentifier::query()
            ->where('entity_id', $context->entityId)
            ->where('identifier_type', 'normalised_name')
            ->where('identifier', $normalised)
            ->first();

        $parties = Party::query()
            ->where('active', true)
            ->get(['id', 'legal_name', 'code']);

        $scored = [];

        foreach ($parties as $party) {
            $candidateName = $context->parties->normaliseName((string) $party->legal_name);

            if ($candidateName === '') {
                continue;
            }

            $similarity = $this->similarity($normalised, $candidateName);

            if ($similarity < 0.72) {
                continue;
            }

            $scored[] = ['party' => $party, 'similarity' => $similarity, 'name' => $candidateName];
        }

        if ($scored === []) {
            return [];
        }

        usort(
            $scored,
            /*
             | Similarity, then the party's code.
             |
             | The tie-break matters more than it looks: two parties with
             | the same legal name is not a hypothetical - a group with a
             | trading company and a holding company registered alike is
             | ordinary - and without it the order depends on whatever the
             | query returned, so the proposal changes between runs.
             */
            static fn (array $a, array $b): int => [$b['similarity'], (string) $a['party']->code]
                <=> [$a['similarity'], (string) $b['party']->code],
        );

        /*
         | How many parties are indistinguishable by name.
         |
         | The same reasoning as two documents owed one amount: with three
         | parties sharing a name, no one of them is the answer and the
         | engine must not report one as though it were.
         */
        $identical = \count(array_filter(
            $scored,
            static fn (array $entry): bool => $entry['name'] === $normalised,
        ));

        $top = \array_slice($scored, 0, 3);
        $candidates = [];

        foreach ($top as $index => $entry) {
            $candidates[] = $this->candidate(
                $context,
                $entry,
                $index,
                \count($scored),
                $seen !== null && (string) $seen->party_id === (string) $entry['party']->getKey(),
                $identical,
            );
        }

        return $candidates;
    }

    /**
     * @param  array{party: Party, similarity: float, name: string}  $entry
     */
    private function candidate(
        MatchingContext $context,
        array $entry,
        int $index,
        int $total,
        bool $confirmedBefore,
        int $identical = 1,
    ): Candidate {
        $similarity = $entry['similarity'];

        $confidence = Confidence::for($this->key())
            ->when($similarity >= 0.99, 'names_are_the_same', 0.18,
                'the names are identical once legal forms and spelling variants are set aside')
            ->when($similarity < 0.99 && $similarity >= 0.88, 'names_are_close', 0.10,
                sprintf('the names are %d%% alike once legal forms and spelling variants are set aside', (int) round($similarity * 100)))
            ->when($similarity < 0.88, 'names_merely_resemble', -0.05,
                sprintf('the names are only %d%% alike, which two unrelated companies routinely are', (int) round($similarity * 100)))
            ->when($confirmedBefore, 'name_was_confirmed_before', 0.15,
                'somebody has confirmed this exact name for this party before')
            ->when($total > 1 && $index === 0, 'other_parties_are_close', -0.08,
                sprintf('%d parties resemble this name, so the closest is not necessarily the right one', $total))
            ->when($index > 0, 'not_the_closest', -0.15,
                'a different party resembles the name more closely')
            ->when($identical > 1, 'several_parties_share_this_name', -0.20,
                sprintf(
                    '%d parties have this exact name once legal forms are set aside, so the name '.
                    'does not identify any of them',
                    $identical,
                ));

        return new Candidate(
            pass: $this->key(),
            matchedType: 'unidentified',
            matchedId: null,
            label: (string) $entry['party']->legal_name,
            amount: $context->amount,
            confidence: $confidence->score(),
            reason: sprintf(
                'The counterparty "%s" resembles %s. This is a guess about WHO paid and says nothing '.
                'about WHICH document, so it is never applied on its own however it scores. '.
                'Confirming it stores the counterparty account against %s, and the next transfer '.
                'from them matches exactly. %s',
                (string) $context->transaction->counterparty_name,
                (string) $entry['party']->legal_name,
                (string) $entry['party']->legal_name,
                $confidence->summary(),
            ),
            factors: $confidence->factors(),
            partyId: (string) $entry['party']->getKey(),
            proposalOnly: true,
        );
    }

    /**
     * How alike two normalised names are, between 0 and 1.
     *
     * Word overlap first, because company names differ by whole words far
     * more often than by letters - "Gulf Trading" against "Gulf Trading
     * Est." is one word apart and character distance understates that
     * badly. The character distance then breaks ties and catches
     * transliteration differences within a word.
     */
    private function similarity(string $left, string $right): float
    {
        if ($left === $right) {
            return 1.0;
        }

        $leftWords = array_filter(explode(' ', $left));
        $rightWords = array_filter(explode(' ', $right));

        $shared = \count(array_intersect($leftWords, $rightWords));
        $total = \count(array_unique([...$leftWords, ...$rightWords]));

        $byWord = $total === 0 ? 0.0 : $shared / $total;

        /*
         | `levenshtein` is byte-based and these names may be Arabic.
         |
         | Measuring bytes on UTF-8 reports two identical Arabic names as
         | 40% alike, because each letter is two bytes and the function
         | counts them separately. `similar_text` is also byte-based but
         | measures common substrings, which degrades gracefully instead of
         | being wrong - and the word overlap above is doing most of the
         | work regardless.
         */
        similar_text($left, $right, $percent);
        $byCharacter = $percent / 100;

        return round(max($byWord, ($byWord * 0.6) + ($byCharacter * 0.4)), 4);
    }
}
