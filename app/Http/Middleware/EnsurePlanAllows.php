<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Enums\PlanFeature;
use App\Platform\Exceptions\PlanDoesNotInclude;
use App\Platform\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on the tenant's plan - PF-022.
 *
 * ## A 403 is the wrong answer
 *
 * "Forbidden" tells a customer they are not allowed to do something, which is
 * what you say to someone attempting an intrusion. A customer on the starter
 * plan reaching a consolidation screen is not intruding; they are a person who
 * would like to buy something and cannot work out how.
 *
 * So the refusal is a 402 naming the feature, the plan they are on, and the
 * cheapest plan that includes it - which also means the upgrade path is
 * computed from the plan catalogue rather than hardcoded in a sales page that
 * goes stale.
 *
 * Usage: `->middleware('plan:consolidation')`.
 */
final class EnsurePlanAllows
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant instanceof Tenant) {
            // No tenant resolved. The tenant resolvers answer that, not this -
            // reporting a plan problem for a request that never had a tenant
            // would send the customer to the pricing page over a routing bug.
            return $next($request);
        }

        $plan = $tenant->currentSubscription()?->plan;

        if ($plan === null) {
            throw PlanDoesNotInclude::noSubscription($tenant);
        }

        foreach ($features as $feature) {
            $required = PlanFeature::tryFrom($feature)
                ?? throw new \LogicException(sprintf(
                    'The route requires plan feature "%s", which is not a PlanFeature case. A typo here would '.
                    'silently gate nothing, so it is refused at the point of use instead.',
                    $feature,
                ));

            if (! $plan->has($required)) {
                throw PlanDoesNotInclude::feature($required, $plan);
            }
        }

        return $next($request);
    }
}
