<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Manual;

use App\Support\Money\Money;

/**
 * Everything a manual journal screen needs to render - PF-055.
 *
 * ## The imbalance is a figure, not a boolean
 *
 * "This entry does not balance" is useless while somebody is typing. The
 * amount it is out BY is what tells them whether they have a missing line, a
 * transposed figure, or a sign the wrong way round - and the shape of the
 * number says which: 90 is a transposition, twice the amount is a sign, and
 * a round figure is a missing line.
 *
 * So the imbalance is reported as a signed amount with the side it falls on,
 * and it is reported on every keystroke rather than on submit.
 *
 * ## Problems and the imbalance are separate
 *
 * A line with an unknown account code is a problem with that line. An entry
 * whose lines are each fine and do not sum to zero has no problem with any
 * line - and a screen that highlighted them all would be pointing at four
 * correct rows.
 */
final readonly class DraftVerdict
{
    /**
     * @param  list<LineProblem>  $problems
     * @param  list<array<string, string>>  $lines  the lines as the screen shows them
     * @param  list<\App\Tenant\Ledger\Posting\PostingLine>  $postingLines
     *     the same lines as the Poster will take them, carrying the
     *     translation and its provenance. Kept separate from `$lines`
     *     because one is for a person to read and the other is what gets
     *     written - and a screen that rebuilt the second from the first
     *     would lose the rate, which is the thing a foreign line cannot be
     *     posted without.
     */
    public function __construct(
        public bool $postable,
        public array $problems,
        public array $lines,
        public Money $debits,
        public Money $credits,
        public Money $imbalance,
        public int $blankLines,
        public array $postingLines = [],
    ) {}

    /**
     * Which side the entry is short on, in words.
     *
     * A signed figure needs reading; "short 450.00 on the credit side" does
     * not. The sign convention here is the storage one - debits positive -
     * and nobody should have to remember that to read a form.
     */
    public function imbalanceSide(): ?string
    {
        if ($this->imbalance->isZero()) {
            return null;
        }

        return $this->imbalance->isPositive() ? 'credit' : 'debit';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'postable' => $this->postable,
            'problems' => array_map(static fn (LineProblem $p): array => $p->toArray(), $this->problems),
            'lines' => $this->lines,
            'total_debit' => (string) $this->debits->amount,
            'total_credit' => (string) $this->credits->amount,
            'imbalance' => (string) $this->imbalance->amount,
            'imbalance_side' => $this->imbalanceSide(),
            'blank_lines' => $this->blankLines,
            'currency' => $this->debits->currency,
        ];
    }
}
