<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Support\Carbon;

/**
 * Translating an amount into another currency, from any module.
 *
 * ## Why this interface is in Support
 *
 * The implementation is `Catalog\Services\ExchangeRates`, which is right: the
 * rate table, the provider precedence and the staleness rules are the
 * catalog's business.
 *
 * But a module that is not allowed to depend on `Catalog` can still have a
 * legitimate need to state an amount in the functional currency - a credit
 * exposure that includes orders in three currencies is a figure in one, and
 * `Parties` is not permitted to reach into `Catalog` for it. The same
 * decision and the same reason as {@see \App\Support\Audit\AuditTrail}.
 *
 * ## What it deliberately does not expose
 *
 * The rate. `Catalog`'s `Translation` carries the rate, the set, the
 * provider and the date it came from, and a posting that translates money
 * MUST record all of that - so a posting uses `ExchangeRates` directly and
 * is in a module allowed to. This seam is for the callers that need a
 * comparable number and will not store it: a limit check, an exposure
 * figure, a dashboard.
 *
 * Returning `null` for "already in that currency" rather than the same
 * amount, so a caller cannot accidentally treat a no-op as a conversion that
 * happened at a rate of one.
 */
interface TranslatesMoney
{
    /**
     * The amount in `$to`, or null when it is already in it.
     *
     * Throws when no rate is on file. A caller that would rather count the
     * nominal amount than drop it says so in its own catch, where the
     * reasoning is visible - a seam that silently fell back to 1:1 would put
     * that decision somewhere nobody reads.
     */
    public function to(Money $money, string $to, Carbon $date): ?Money;
}
