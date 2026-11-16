<?php

declare(strict_types=1);

namespace App\Tenant\Banking\Matching;

/**
 * Finding a document number in what the bank sent - PF-156.
 *
 * ## Banks mangle references, and the mangling is the problem
 *
 * The payer types `INV-1001`. By the time it reaches a statement it may be
 * `INV1001`, `inv 1001`, `/REMI/INV-1001 THANKS`, `INV-1001 ...` truncated
 * at 35 characters, or buried in a sentence somebody typed by hand. All five
 * are the same reference and a literal comparison finds none of them.
 *
 * So comparison is on a **normalised** form: upper case, letters and digits
 * only. `INV-1001`, `inv 1001` and `Inv.1001` all become `INV1001`.
 *
 * ## And the opposite failure is worse
 *
 * Normalising makes everything more findable, including things that are not
 * there. `1001` appears in an account number, a date, a phone number and
 * half the amounts on a statement - and an engine that matched it would
 * apply a payment to invoice 1001 confidently, which is a far more expensive
 * mistake than failing to match at all.
 *
 * Three rules keep that from happening:
 *
 * 1. **A minimum length** (`banking.matching.min_reference_match_length`).
 *    A three-character match against a six-digit invoice number is noise.
 * 2. **The whole reference, or nothing.** The document's number must appear
 *    in full. A reference that matched its first five characters would match
 *    every invoice issued the same month.
 * 3. **Boundaries.** `INV1001` must not match inside `INV10010` - the next
 *    invoice in the series. Normalising removes the punctuation that would
 *    have separated them, so the boundary is checked on the digits: a match
 *    whose last character is a digit may not be followed by another.
 *
 * ## What it reports
 *
 * Not a boolean. A caller scoring a match needs to know whether the
 * reference stood alone or was found inside a sentence, and how much of the
 * remittance it accounted for - a remittance that is nothing but the
 * reference is a stronger signal than one where it appears in passing.
 */
final class ReferenceExtraction
{
    /**
     * Whether `$reference` appears in `$text`, and how strongly.
     *
     * @return array{found: bool, exact: bool, isolated: bool, coverage: float, why: string}
     */
    public function find(?string $text, string $reference): array
    {
        $absent = ['found' => false, 'exact' => false, 'isolated' => false, 'coverage' => 0.0, 'why' => ''];

        $needle = $this->normalise($reference);
        $haystack = $this->normalise((string) $text);

        if ($needle === '' || $haystack === '') {
            return $absent;
        }

        $minimum = (int) config('banking.matching.min_reference_match_length', 5);

        if (mb_strlen($needle) < $minimum) {
            /*
             | Too short to be evidence of anything.
             |
             | A document numbered `7` is findable in every statement ever
             | written. Refusing to match it is not a limitation: it is the
             | difference between an engine that is sometimes unhelpful and
             | one that is sometimes confidently wrong.
             */
            return [...$absent, 'why' => sprintf(
                'the reference "%s" is shorter than %d characters, which matches by coincidence more '.
                'often than by intent',
                $reference,
                $minimum,
            )];
        }

        $at = mb_strpos($haystack, $needle);

        if ($at === false) {
            return $absent;
        }

        /*
         | `INV1001` must not match inside `INV10010`.
         |
         | Normalising strips the hyphen that would have separated them, so
         | the boundary is checked here: a numeric run must end where the
         | reference ends. Without this, every invoice matches its own
         | successor for the first ten of them.
         */
        if (! $this->endsAtABoundary($haystack, $needle, $at)) {
            return [...$absent, 'why' => sprintf(
                '"%s" appears inside a longer run of digits, so it is part of another number rather '.
                'than this reference',
                $reference,
            )];
        }

        $exact = $haystack === $needle;
        $coverage = mb_strlen($needle) / max(1, mb_strlen($haystack));

        return [
            'found' => true,
            'exact' => $exact,
            /*
             | Isolated: the reference is most of what the field says.
             |
             | `INV-1001` alone is a payer answering the question. The same
             | reference inside forty words of a hand-typed sentence is the
             | same characters and much weaker evidence, because the
             | sentence may be about a different invoice entirely.
             */
            'isolated' => $coverage >= 0.5,
            'coverage' => round($coverage, 4),
            'why' => $exact
                ? sprintf('the remittance field is exactly "%s"', $reference)
                : sprintf(
                    'the remittance field contains "%s"%s',
                    $reference,
                    $coverage >= 0.5 ? '' : ', among other text',
                ),
        ];
    }

    /**
     * The candidate references a remittance field appears to contain.
     *
     * Used to narrow which documents are worth comparing at all: looking up
     * every open invoice to test it against the text is the query that makes
     * the engine slow on the accounts that have most of them.
     *
     * Deliberately generous - it over-suggests, and {@see find()} is what
     * decides. A token here is a reason to look, not a reason to match.
     *
     * @return list<string>
     */
    public function tokens(?string ...$fields): array
    {
        $minimum = (int) config('banking.matching.min_reference_match_length', 5);
        $tokens = [];

        foreach ($fields as $field) {
            if ($field === null || trim($field) === '') {
                continue;
            }

            /*
             | Runs of letters and digits, and runs that mix them.
             |
             | `INV-1001` splits into `INV` and `1001` on punctuation, and
             | neither alone is the reference - so the joined form is kept
             | too. A document number is almost always a prefix and a
             | number with something between them.
             */
            if (preg_match_all('/[A-Za-z0-9]+(?:[-_\/.][A-Za-z0-9]+)*/u', $field, $found) === 0) {
                continue;
            }

            foreach ($found[0] as $candidate) {
                $normalised = $this->normalise($candidate);

                if (mb_strlen($normalised) >= $minimum) {
                    $tokens[$normalised] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    /** Upper case, letters and digits only. */
    public function normalise(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value));
    }

    private function endsAtABoundary(string $haystack, string $needle, int $at): bool
    {
        $end = $at + mb_strlen($needle);
        $lastOfNeedle = mb_substr($needle, -1);
        $nextInHaystack = mb_substr($haystack, $end, 1);

        if ($nextInHaystack === '') {
            return true;
        }

        /*
         | Only digit-against-digit is a boundary violation.
         |
         | `INV1001` followed by `THANKS` is fine: letters after a number
         | are the rest of the sentence. `INV1001` followed by `0` is
         | invoice 10010, and taking it would apply a payment to the wrong
         | document while reporting a perfect reference match.
         */
        return ! (ctype_digit($lastOfNeedle) && ctype_digit($nextInHaystack));
    }
}
