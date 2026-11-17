<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Overview;

/**
 * Bank lines in the window, by what has happened to them - UX-032.
 *
 * A LINE is counted once however many matches split it, which is why this is
 * an EXISTS per line rather than a join that would count a three-way split
 * three times. Provisional and superseded lines are left out: they are not
 * what the bank finally said.
 */
final readonly class ReconciliationProgress
{
    public function __construct(
        public int $matched,
        public int $suggested,
        public int $waiting,
        public int $days,
    ) {}

    public function total(): int
    {
        return $this->matched + $this->suggested + $this->waiting;
    }

    /** Whole percent matched, rounded DOWN: 99.6% matched is not done. */
    public function percentMatched(): int
    {
        return $this->total() === 0 ? 0 : intdiv($this->matched * 100, $this->total());
    }
}
