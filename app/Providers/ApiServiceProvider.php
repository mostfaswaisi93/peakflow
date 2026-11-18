<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenant\Identity\Models\ApiToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Wires the REST API - PF-217 to PF-222.
 *
 * ## The token model is replaced, not extended at the call site
 *
 * Sanctum resolves tokens through one globally configured class. Leaving it as
 * the package's own `PersonalAccessToken` would put token rows in the
 * `personal_access_tokens` table on the DEFAULT connection - which is the
 * platform database, where a tenant's users do not exist - and would accept
 * `*` as an ability, which is the second authorization model PF-218 exists to
 * prevent.
 *
 * So the replacement happens once, here, where it cannot be forgotten by a
 * call site.
 *
 * ## Sanctum's CSRF cookie route is switched off
 *
 * `config('sanctum.routes')` false removes `GET /sanctum/csrf-cookie`. It
 * exists for a first-party SPA authenticating by cookie, and this API
 * authenticates by bearer token and by nothing else - see the note in
 * `config/sanctum.php` on why the cookie fallback is off.
 *
 * Leaving the route registered would also fail the contract test, and
 * correctly: it is a route on the application that is in no OpenAPI document,
 * which is exactly what "no undocumented route exists" is there to catch. A
 * route that does nothing for this product is not worth documenting, so it
 * goes.
 */
final class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['sanctum.routes' => false]);

        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
    }
}
