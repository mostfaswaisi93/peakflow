<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Lang\NumberingSystem;
use App\Tenant\Tenancy\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Applies the tenant's digit shape to this request's DISPLAY - PF-202.
 *
 * ## Why a middleware, and why only in the web group
 *
 * PF-202 asks for two things that pull against each other: the digit shape is
 * a per-tenant setting, and shaping applies to display only - stored values,
 * exports, XML payloads and API responses are always Western digits.
 *
 * The single place that knows how to ask ICU for a numbering system is
 * {@see NumberingSystem}, and it reads `money.display.digits`. So the whole
 * question is WHERE the tenant's value is allowed to reach that: this
 * middleware is registered in the **web** group, which renders HTML and
 * nothing else. The API group never runs it, a queued job never runs it, and
 * the Exchange plane has no routes at all - so a serializer cannot see a
 * shaped locale even in a request where the screen is full of Arabic-Indic
 * digits.
 *
 * That is the seam, and it is the reason this is not done in `SetLocale`:
 * `SetLocale` is also registered globally, ahead of routing, so it runs for
 * API requests too.
 *
 * ## It must never be able to break a page
 *
 * The setting lives in the tenant database, which is resolved by a middleware
 * ahead of this one - and on the pages that explain why a tenant could NOT be
 * resolved there is no database to ask. A display preference that could throw
 * would take down the page explaining the failure, so anything that goes
 * wrong here leaves the platform default in place and the figures Western.
 */
final class ApplyDisplayDigits
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $chosen = $this->settings->get('display.digits');

            if (\is_string($chosen) && $chosen !== '') {
                config(['money.display.digits' => NumberingSystem::normalise($chosen)]);
            }
        } catch (Throwable) {
            // No tenant, no settings table, nothing resolved yet: the
            // platform default stands, which is Western digits.
        }

        return $next($request);
    }
}
