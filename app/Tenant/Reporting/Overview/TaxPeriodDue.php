<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Overview;

use Carbon\CarbonImmutable;

/**
 * The open tax return period that ends first - UX-032.
 *
 * There is no "overdue" state on a return period, so it is derived here:
 * open, with a due date that has passed.
 */
final readonly class TaxPeriodDue
{
    public function __construct(
        public string $label,
        public ?CarbonImmutable $dueOn,
        public CarbonImmutable $today,
    ) {}

    public function isOverdue(): bool
    {
        return $this->dueOn !== null && $this->dueOn->lessThan($this->today);
    }

    public function daysLeft(): ?int
    {
        return $this->dueOn === null ? null : (int) $this->today->diffInDays($this->dueOn, false);
    }
}
