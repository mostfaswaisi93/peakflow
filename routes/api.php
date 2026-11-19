<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OpenApiDocumentController;
use App\Http\Controllers\Api\SignInController;
use App\Tenant\Identity\Http\Controllers\Api\ApiTokenController;
use App\Tenant\Identity\Http\Controllers\Api\DeviceSessionController;
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

/*
|------------------------------------------------------------------------------
| Signing a person in on a device - PF-260
|------------------------------------------------------------------------------
|
| Outside every guard, because these are what a client calls to OBTAIN a
| credential. They are also outside `tenant.header`: the person types their
| workspace, and an unknown one must be refused exactly like a wrong password
| - which a middleware answering `400 tenant-required` cannot do. The
| controller resolves it and gives one answer for every failure.
|
| Throttled inside the controller, per account AND per address, by the same
| `LoginThrottle` the browser's form uses - so a password cannot be guessed
| five times in the browser and five more times here.
*/
Route::post('/auth/sign-in', [SignInController::class, 'signIn'])
    ->name('api.auth.sign-in');

Route::post('/auth/sign-in/second-factor', [SignInController::class, 'secondFactor'])
    ->name('api.auth.second-factor');

Route::post('/auth/refresh', [SignInController::class, 'refresh'])
    ->name('api.auth.refresh');

/*
| A person's own sessions, which need a token and no entity: signing out of
| the phone is not an act IN a company, and refusing it for want of an
| `X-Entity` would be a way to stay signed in.
*/
Route::middleware([
    'tenant.header',
    'auth:sanctum',
    'api.actor',
    'api.throttle',
])->group(static function (): void {
    Route::post('/auth/sign-out', [DeviceSessionController::class, 'signOut'])
        ->name('api.auth.sign-out');

    Route::get('/auth/sessions', [DeviceSessionController::class, 'index'])
        ->name('api.auth.sessions.index');

    Route::delete('/auth/sessions/{id}', [DeviceSessionController::class, 'destroy'])
        ->name('api.auth.sessions.destroy');
});

Route::middleware([
    'tenant.header',
    'auth:sanctum',
    'api.actor',
    'api.entity',
    'api.throttle',
])->group(static function (): void {

    /*
    | Who this token is, where it acts and what it may do - PF-260.
    |
    | No scope: a person may always ask who they are. Inside the entity
    | group because "what may I do" only has an answer in a company.
    */
    Route::get('/me', [DeviceSessionController::class, 'me'])
        ->name('api.me');

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
