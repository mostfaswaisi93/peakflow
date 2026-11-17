<?php

declare(strict_types=1);

namespace App\Tenant\Reporting\Overview;

use Carbon\CarbonImmutable;

/** One line of the audit trail, as the Overview shows it - UX-032. */
final readonly class ActivityItem
{
    public function __construct(
        public string $actor,
        public string $action,
        public ?string $subject,
        public CarbonImmutable $at,
    ) {}
}
