<?php

declare(strict_types=1);

namespace App\Tenant\Ledger\Events;

use App\Support\Events\FinancialEvent;

/**
 * Monetary items were restated at a closing rate - PF-048, PF-070.
 *
 * Carries what the run decided rather than a reference to the run, so a
 * listener does not have to read the workings back out of the database to
 * know whether anything moved - and so the payload is the same whether the
 * listener runs now or off a queue tomorrow.
 */
final readonly class RevaluationRun implements FinancialEvent
{
    /**
     * @param  list<string>  $missingRates  pairs with no closing rate, left alone
     * @param  list<string>  $unclassified  accounts holding a foreign balance with no IAS 21 classification
     */
    public function __construct(
        private string $entityId,
        public string $periodId,
        public string $runId,
        public string $rateSet,
        public string $revaluedAt,
        public ?string $entryId,
        public int $accountsRestated,
        public array $missingRates = [],
        public array $unclassified = [],
    ) {}

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function eventKey(): string
    {
        return 'ledger.revaluation.run';
    }

    /**
     * Whether a human needs to look.
     *
     * A run that left balances alone for want of a rate, or found an
     * unclassified account, has done the right thing and is incomplete -
     * and the difference between "incomplete and reported" and "complete"
     * is the whole value of reporting it.
     */
    public function needsAttention(): bool
    {
        return $this->missingRates !== [] || $this->unclassified !== [];
    }
}
