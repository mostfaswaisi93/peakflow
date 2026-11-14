<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the locale and the text direction for the request.
 *
 * Order: the signed-in user's own preference, then the tenant default, then
 * `Accept-Language`, then the application default - which is `ar`.
 *
 * `en` is the FALLBACK, never the default. PeakFlow is Arabic-first, and that
 * is a product decision rather than a configuration accident.
 *
 * The direction is set alongside the locale because a template needs both, and
 * deriving `dir` at the point of use means deriving it in forty places.
 *
 * ## It runs twice, and must tolerate that
 *
 * Registered globally AND in the web group. Globally it runs before routing,
 * so a request that never matches a route - every 404 - still answers in the
 * right language; there is no session that early, so only Accept-Language is
 * available and the user/tenant branches fall through. In the web group it
 * runs again with the session present and refines the choice.
 *
 * Every lookup here is therefore written to be safe when the thing it reads
 * does not exist yet, and the whole middleware is idempotent.
 */
final class SetLocale
{
    /** @var array<string, string> */
    private const DIRECTIONS = ['ar' => 'rtl', 'en' => 'ltr'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);

        // Shared with every view so that `dir` and `lang` are one decision.
        view()->share('locale', $locale);
        view()->share('direction', self::DIRECTIONS[$locale] ?? 'ltr');

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $supported = array_keys(self::DIRECTIONS);

        // 1. The user's own preference. The tenant guard is used rather than
        //    the default one because the user record lives in the tenant
        //    database, resolved by the middleware pinned ahead of this one.
        $user = $request->user();

        if ($user !== null && isset($user->locale) && \in_array($user->locale, $supported, true)) {
            return (string) $user->locale;
        }

        // 2. The tenant's default, where a tenant has been resolved.
        if (app()->bound('currentTenant')) {
            $tenant = app('currentTenant');

            if (isset($tenant->default_locale) && \in_array($tenant->default_locale, $supported, true)) {
                return (string) $tenant->default_locale;
            }
        }

        // 3. Accept-Language. Deliberately last among the signals: a browser
        //    set to English does not mean a Jordanian accountant wants English
        //    invoices, and the tenant's own choice outranks it.
        $preferred = $request->getPreferredLanguage($supported);

        if (\is_string($preferred) && \in_array($preferred, $supported, true)) {
            return $preferred;
        }

        return (string) config('app.locale', 'ar');
    }
}
