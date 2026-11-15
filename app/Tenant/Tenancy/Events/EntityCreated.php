<?php

declare(strict_types=1);

namespace App\Tenant\Tenancy\Events;

use App\Tenant\Tenancy\Models\Entity;

/**
 * A legal entity was created - the first domain event, ahead of PF-048.
 *
 * ## Why an event rather than a call
 *
 * A new entity needs a chart of accounts, and the chart belongs to `Ledger`.
 * `Tenancy` sits BELOW `Ledger` in the module graph - Ledger depends on
 * Tenancy, not the reverse - so Tenancy calling the chart template directly
 * would invert the dependency and make the tenancy layer unusable without
 * the ledger.
 *
 * An event points the other way: Tenancy announces what happened, and
 * whichever modules care subscribe. The ledger opens a chart; later phases
 * will add their own listeners without Tenancy learning they exist.
 */
final readonly class EntityCreated
{
    public function __construct(public Entity $entity) {}
}
