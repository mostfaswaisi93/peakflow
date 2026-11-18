<?php

declare(strict_types=1);

namespace App\Tenant\Inventory\Services;

use Illuminate\Support\Facades\DB;

/**
 * Which count is open over a shelf - PF-253.
 *
 * ## Why this is a class and not a method on either side
 *
 * Two callers need the answer and they cannot reach each other.
 * {@see Stock} tags every movement it writes with the open count, so a
 * variance on a line whose goods moved mid-count is explainable;
 * {@see StockCounts} needs it to refuse a second count over one shelf.
 * StockCounts already depends on Stock, so Stock cannot depend back.
 *
 * The alternative was the same query written twice, which is a second
 * answer to one question - and the day the two disagree is the day a
 * movement is tagged with a count that is not the one refusing the
 * second count.
 */
final class OpenCount
{
    /**
     * The id of the count open over this location, if one is.
     *
     * `posted` and `cancelled` are not open. A posted count's lines are
     * history and tagging new movements with it would put movements
     * after the fact against a document that is closed.
     */
    public function over(string $entityId, string $locationId): ?string
    {
        $id = DB::connection('tenant')->table('stock_counts')
            ->where('entity_id', $entityId)
            ->where('location_id', $locationId)
            ->whereIn('state', ['draft', 'counting', 'counted'])
            ->value('id');

        return $id === null ? null : (string) $id;
    }
}
