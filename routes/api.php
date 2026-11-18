<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OpenApiDocumentController;
use App\Tenant\Identity\Http\Controllers\Api\ApiTokenController;
use App\Tenant\Ledger\Http\Controllers\Api\AccountApiController;
use App\Tenant\Ledger\Http\Controllers\Api\JournalEntryApiController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| The REST API, version 1 - PF-217 to PF-222
|------------------------------------------------------------------------------
|
| Mounted at `/api/v1` by bootstrap/app.php. Every route here is in
| `docs/api/openapi.yaml`, and `ApiContractRules` asserts that in BOTH
| directions: an operation in the document with no route is an endpoint a
| client will write code against and get a 404 from, and a route with no
| operation is an endpoint nobody can discover and nobody reviewed.
|
| That is also why this file is short. The document is written first, and an
| operation appears in it when its route exists - so the resource surface
| arrives with the tasks that implement it rather than all at once as a
| promise.
|
|------------------------------------------------------------------------------
| The middleware order is load-bearing, exactly as it is on the web
|------------------------------------------------------------------------------
|
|   tenant.header     the users table is in the TENANT database, so the
|                     connection must be pointed before any guard reads it
|   auth:sanctum      the bearer token, and nothing else - config/sanctum.php
|                     removes the cookie fallback and says why
|   api.actor         the token's plane, and who the application is acting as
|   api.entity        X-Entity, checked against the token and then the grants
|   api.scope:<key>   the scope this route needs, which is a permission key
|   api.idempotent    on the writes that post to the ledger, and only those
|
| `tenant.header` before the guard is pinned in bootstrap/app.php's priority
| list rather than relying on this order, because route order does not survive
| a framework upgrade and the priority list does. Listing it here as well is
| what makes it visible in a route listing.
|
*/

/*
| The contract itself, outside every guard.
|
| An integrator reads it BEFORE they have a token - that is what they are
| reading it in order to obtain - and it describes shapes rather than data:
| no tenant, no entity, no figure. It is the one route with no `X-Tenant`,
| because there is nothing tenant-specific in it to resolve.
*/
Route::get('/openapi.yaml', OpenApiDocumentController::class)->name('api.openapi');

Route::middleware([
    'tenant.header',
    'auth:sanctum',
    'api.actor',
    'api.entity',
])->group(static function (): void {

    /*
    | The chart of accounts.
    |
    | `account.manage` and not a read-only permission, which is the same
    | decision the chart SCREEN takes: the chart names every control account
    | in the entity, which is a map of where money lands.
    */
    Route::get('/accounts', AccountApiController::class)
        ->middleware('api.scope:account.manage')
        ->name('api.accounts.index');

    /*
    | Journal entries.
    |
    | Reading is `report.view`; posting is `journal.post`, which the
    | permission catalogue marks as requiring an enrolled second factor on
    | the person whose token this is.
    |
    | The POST carries `api.idempotent` because it posts to the ledger. A
    | retry without a key posts the entry twice, and the second one is
    | indistinguishable from a real transaction - same amounts, same date,
    | same party, a different number - so the key is required rather than
    | honoured if present.
    */
    Route::get('/journal-entries', [JournalEntryApiController::class, 'index'])
        ->middleware('api.scope:report.view')
        ->name('api.journal-entries.index');

    Route::get('/journal-entries/{id}', [JournalEntryApiController::class, 'show'])
        ->middleware('api.scope:report.view')
        ->name('api.journal-entries.show');

    Route::post('/journal-entries', [JournalEntryApiController::class, 'store'])
        ->middleware(['api.scope:journal.post', 'api.idempotent'])
        ->name('api.journal-entries.store');

    /*
    | The tenant's own tokens - PF-218.
    |
    | Creation, last-used, rotation and revocation, visible and audited. The
    | FIRST token is not issued here: this endpoint needs one to call it, and
    | a bootstrap endpoint that mints a credential from no credential is the
    | hole every API with one eventually finds.
    |
    | No `api.idempotent` on these: none of them posts to the ledger, and a
    | duplicate POST here produces a second token with its own id that the
    | listing shows and a human revokes. Requiring a key on every POST in the
    | API would train clients to generate one per call, which is the same as
    | not having one at all.
    */
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])
        ->middleware('api.scope:user.manage')
        ->name('api.api-tokens.index');

    Route::post('/api-tokens', [ApiTokenController::class, 'store'])
        ->middleware('api.scope:user.manage')
        ->name('api.api-tokens.store');

    Route::post('/api-tokens/{id}/rotate', [ApiTokenController::class, 'rotate'])
        ->middleware('api.scope:user.manage')
        ->name('api.api-tokens.rotate');

    Route::delete('/api-tokens/{id}', [ApiTokenController::class, 'destroy'])
        ->middleware('api.scope:user.manage')
        ->name('api.api-tokens.destroy');
});
