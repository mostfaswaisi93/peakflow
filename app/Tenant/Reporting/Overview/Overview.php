<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Overview;

/**
 * What the Overview shows for one entity, on one day - UX-032.
 *
 * Every amount is a decimal STRING in the entity's functional currency, at
 * the amount scale, exactly as PostgreSQL summed it. Nothing here is a float:
 * the sparkline turns the series into coordinates at the last moment, and no
 * drawn value is ever shown as a figure.
 *
 * Receivables and payables count documents in the functional currency only,
 * and say how many open documents are in another currency rather than
 * converting them. A converted figure would need a rate choice (the day's?
 * the document's?) that a dashboard tile cannot state, and a number whose
 * basis is invisible is the kind this product refuses to show.
 */
final readonly class Overview
{
    /**
     * @param  list<ActivityItem>  $activity
     */
    public function __construct(
        public string $currency,
        public CashPosition $cash,
        public Ageing $receivables,
        public PayablesDue $payables,
        public ReconciliationProgress $reconciliation,
        public int $draftInvoices,
        public int $draftBills,
        public int $draftJournals,
        public ?TaxPeriodDue $tax,
        public array $activity,
    ) {}

    /**
     * Nothing financial has happened in this entity yet: the page shows a
     * welcome and the way in, not a grid of zeros that reads as "you have no
     * money".
     */
    public function isEmpty(): bool
    {
        return ! $this->cash->hasActivity
            && $this->receivables->documents === 0
            && $this->payables->documents === 0
            && $this->reconciliation->total() === 0
            && $this->draftInvoices + $this->draftBills + $this->draftJournals === 0;
    }

    public function drafts(): int
    {
        return $this->draftInvoices + $this->draftBills + $this->draftJournals;
    }
}
