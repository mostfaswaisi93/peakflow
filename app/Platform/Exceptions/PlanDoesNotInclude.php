<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use App\Platform\Enums\PlanFeature;
use App\Platform\Models\Plan;
use App\Platform\Models\Tenant;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant's plan does not include a feature the route needs - PF-022.
 *
 * Carries the cheapest plan that DOES include it, computed from the catalogue.
 * A hardcoded "upgrade to Business" goes stale the first time the packaging
 * changes, and nobody notices because the message still reads plausibly.
 */
final class PlanDoesNotInclude extends DomainException
{
    private function __construct(
        string $message,
        public readonly ?string $feature = null,
        public readonly ?string $currentPlan = null,
        public readonly ?string $cheapestPlanWithIt = null,
    ) {
        parent::__construct($message);
    }

    public static function feature(PlanFeature $feature, Plan $current): self
    {
        $upgrade = self::cheapestPlanIncluding($feature);

        return new self(
            $upgrade === null
                ? sprintf(
                    '%s is not included in any plan currently offered. If you need it, please contact us - this '.
                    'is not something you can switch on yourself.',
                    $feature->label(),
                )
                : sprintf(
                    '%s is not included in your %s plan. It is available on %s. Nothing you have done is wrong '.
                    'and no data is affected - the feature is simply not switched on for this subscription.',
                    $feature->label(),
                    $current->name(),
                    $upgrade->name(),
                ),
            $feature->value,
            $current->key,
            $upgrade?->key,
        );
    }

    public static function noSubscription(Tenant $tenant): self
    {
        return new self(sprintf(
            'The account "%s" has no subscription, so no features are enabled. This is an account setup problem '.
            'rather than something you can resolve by signing in again.',
            $tenant->name,
        ));
    }

    /**
     * The least expensive plan that includes the feature.
     *
     * `sort_order` is the packaging order, which is the only ranking the
     * catalogue actually asserts - comparing prices would require a currency
     * and a billing period that a plan row does not carry.
     */
    private static function cheapestPlanIncluding(PlanFeature $feature): ?Plan
    {
        return Plan::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->get()
            ->first(fn (Plan $plan): bool => $plan->has($feature));
    }

    public function render(Request $request): Response
    {
        return Problem::of(ProblemType::SubscriptionInactive)
            ->withDetail($this->getMessage())
            ->withExtensions([
                'feature' => $this->feature,
                'current_plan' => $this->currentPlan,
                'available_on_plan' => $this->cheapestPlanWithIt,
            ])
            ->toResponse($request);
    }
}
