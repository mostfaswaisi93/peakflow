<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OpenApiDocumentController;
use App\Http\Controllers\Api\SignInController;
use App\Tenant\Identity\Http\Controllers\Api\ApiTokenController;
use App\Tenant\Identity\Http\Controllers\Api\DeviceSessionController;
use App\Tenant\Ledger\Http\Controllers\Api\AccountApiController;
use App\Tenant\Ledger\Http\Controllers\Api\JournalEntryApiController;
use App\Tenant\Banking\Http\Controllers\Api\BankAccountApiController;
use App\Tenant\Parties\Http\Controllers\Api\PartyApiController;
use App\Tenant\Purchasing\Http\Controllers\Api\BillApiController;
use App\Tenant\Reporting\Http\Controllers\Api\OverviewApiController;
use App\Tenant\Reporting\Http\Controllers\Api\ReportApiController;
use App\Tenant\Sales\Http\Controllers\Api\InvoiceApiController;
use App\Tenant\Settlement\Http\Controllers\Api\PaymentApiController;
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
    | The Overview - PF-261. The browser's home page, as data: the same
    | query, so a phone and a desk never disagree about the cash.
    */
    Route::get('/overview', OverviewApiController::class)
        ->middleware('api.scope:report.view')
        ->name('api.overview');

    /*
    | The documents and the people they are with - PF-262. Read-only, and
    | under the permission each browser page gates its figures on: the
    | invoice and bill pages on `report.view`, parties on `party.manage`,
    | the company's own bank accounts on `bank.manage`.
    */
    Route::get('/invoices', [InvoiceApiController::class, 'index'])
        ->middleware('api.scope:report.view')
        ->name('api.invoices.index');

    Route::get('/invoices/{id}', [InvoiceApiController::class, 'show'])
        ->middleware('api.scope:report.view')
        ->name('api.invoices.show');

    /*
    | Drafting and issuing a sales invoice - PF-266. A draft books nothing;
    | issuing numbers it, posts it and freezes it, so that route carries
    | `api.idempotent`.
    */
    Route::get('/invoice-options', [InvoiceApiController::class, 'options'])
        ->middleware('api.scope:invoice.draft')
        ->name('api.invoices.options');

    Route::post('/invoices', [InvoiceApiController::class, 'store'])
        ->middleware('api.scope:invoice.draft')
        ->name('api.invoices.store');

    Route::post('/invoices/{id}/issue', [InvoiceApiController::class, 'issue'])
        ->middleware(['api.scope:invoice.issue', 'api.idempotent'])
        ->name('api.invoices.issue');

    Route::get('/bills', [BillApiController::class, 'index'])
        ->middleware('api.scope:report.view')
        ->name('api.bills.index');

    Route::get('/bills/{id}', [BillApiController::class, 'show'])
        ->middleware('api.scope:report.view')
        ->name('api.bills.show');

    /*
    | A bill's way through approval - PF-265. Submitting opens the chain;
    | the approval that clears its last step also books the bill, so that
    | route posts to the ledger and carries `api.idempotent`. A rejection
    | books nothing and returns the bill to draft.
    */
    Route::post('/bills/{id}/submit', [BillApiController::class, 'submit'])
        ->middleware('api.scope:bill.create')
        ->name('api.bills.submit');

    Route::post('/bills/{id}/approve', [BillApiController::class, 'approve'])
        ->middleware(['api.scope:bill.approve', 'api.idempotent'])
        ->name('api.bills.approve');

    Route::post('/bills/{id}/reject', [BillApiController::class, 'reject'])
        ->middleware('api.scope:bill.approve')
        ->name('api.bills.reject');

    Route::get('/parties', [PartyApiController::class, 'index'])
        ->middleware('api.scope:party.manage')
        ->name('api.parties.index');

    Route::get('/parties/{id}', [PartyApiController::class, 'show'])
        ->middleware('api.scope:party.manage')
        ->name('api.parties.show');

    Route::get('/bank-accounts', BankAccountApiController::class)
        ->middleware('api.scope:bank.manage')
        ->name('api.bank-accounts.index');

    /*
    | Recording a payment, and applying it to documents - PF-264. It posts to
    | the ledger, so it carries `api.idempotent`: a retried receipt without a
    | key is a second receipt.
    */
    Route::post('/payments', [PaymentApiController::class, 'store'])
        ->middleware(['api.scope:payment.record', 'api.idempotent'])
        ->name('api.payments.store');

    /*
    | The financial statements - PF-263. The same services as the browser's
    | report pages, so a figure on a phone is the figure on the desk.
    */
    Route::get('/periods', [ReportApiController::class, 'periods'])
        ->middleware('api.scope:report.view')
        ->name('api.periods.index');

    Route::get('/reports/trial-balance', [ReportApiController::class, 'trialBalance'])
        ->middleware('api.scope:report.view')
        ->name('api.reports.trial-balance');

    Route::get('/reports/profit-or-loss', [ReportApiController::class, 'profitOrLoss'])
        ->middleware('api.scope:report.view')
        ->name('api.reports.profit-or-loss');

    Route::get('/reports/financial-position', [ReportApiController::class, 'financialPosition'])
        ->middleware('api.scope:report.view')
        ->name('api.reports.financial-position');

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
