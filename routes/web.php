<?php

declare(strict_types=1);

use App\Platform\Http\Controllers\OpsLoginController;
use App\Platform\Http\Controllers\OpsSettingsController;
use App\Platform\Http\Controllers\OpsTenantController;
use App\Tenant\Banking\Http\Controllers\StatementImportController;
use App\Tenant\Identity\Http\Controllers\EntitySwitchController;
use App\Tenant\Identity\Http\Controllers\ThemePreferenceController;
use App\Tenant\Banking\Http\Controllers\BankAccountListController;
use App\Tenant\Catalog\Http\Controllers\CurrencyListController;
use App\Tenant\Catalog\Http\Controllers\ItemListController;
use App\Tenant\Ledger\Http\Controllers\AccountListController;
use App\Tenant\Parties\Http\Controllers\PartyDetailController;
use App\Tenant\Parties\Http\Controllers\PartyListController;
use App\Tenant\Purchasing\Http\Controllers\BillDetailController;
use App\Tenant\Purchasing\Http\Controllers\BillListController;
use App\Tenant\Reporting\Http\Controllers\FinancialPositionController;
use App\Tenant\Reporting\Http\Controllers\OverviewController;
use App\Tenant\Reporting\Http\Controllers\TrialBalanceController;
use App\Tenant\Sales\Http\Controllers\InvoiceDetailController;
use App\Tenant\Sales\Http\Controllers\InvoiceListController;
use App\Tenant\Settlement\Http\Controllers\PaymentRunListController;
use App\Tenant\Settlement\Http\Controllers\StatementController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\PublicQuoteController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Web routes
|------------------------------------------------------------------------------
|
| Three planes, and a route belongs to exactly ONE of them:
|
|   /ops/*   the operator console   auth:platform, never touches a tenant
|   /*       the application        tenant.session -> entity.scope -> auth:tenant
|   (none)   the Exchange plane has no routes at all - it runs on queues, so
|            that a government endpoint being down is a queue depth rather
|            than a 500 on someone's screen
|
| The tenant and entity resolvers are pinned AHEAD of the auth middleware in
| bootstrap/app.php. That ordering is load-bearing: the users table lives in
| the tenant's own database, so a guard that ran first would query the wrong
| one - and the symptom, intermittent authentication against the wrong
| database, is one of the worst to diagnose.
|
| Routes arrive with the phases that own them. Phase 01 brings the operator
| console and tenant authentication; the shell lands with PF-204.
|
*/

/*
| `/` asks the auth guard whether anyone is signed in, and the guard reads the
| users table - which lives in the TENANT database. So this route needs the
| tenant resolver, exactly like every other route that touches the guard.
|
| Without it this answered 500 to every signed-in visitor, which is the precise
| failure the middleware priority pinning exists to prevent, reintroduced in a
| one-line closure that never went through the priority list at all.
*/
Route::middleware(['tenant.session'])->get('/', static fn () => auth()->guard('web')->check()
    ? redirect()->route('overview')
    : redirect()->route('login'))->name('home');

/*
| The page a user lands on when their workspace stopped being usable while they
| had the application open - PF-021.
|
| Outside every tenant middleware ON PURPOSE. It is reached precisely when the
| tenant could not be resolved, so a route that tried to resolve one would
| refuse to render the explanation of why the resolution failed, and the user
| would get a redirect loop instead of a sentence.
*/
Route::get('/workspace-unavailable', static function (Illuminate\Http\Request $request) {
    return response()
        ->view('workspace.unavailable', [
            // Flashed by the tenant resolver as it tears the session down.
            // Absent on a direct visit, and the view has a generic fallback -
            // a blank space where the reason should be is worse than a vague
            // reason.
            'reason' => $request->session()->get('tenant_unavailable'),
        ])
        // The condition is per-session and not cacheable by anything in front
        // of the application.
        ->header('Cache-Control', 'no-store');
})->name('workspace.unavailable');

/*
| The customer-facing quote link - PF-089.
|
| Signed, rate-limited, and outside every authentication middleware on
| purpose: the person who has to answer is the customer, who has no login
| here, and the number of quotes that go unanswered because somebody could
| not remember a password for a portal they use twice a year is not small.
|
| The tenant and the entity are in the path because there is nowhere else for
| them to come from - no session, no cookie, and row-level security that reads
| nothing at all without an entity established. Both are inside the
| signature, so a visitor who edits either gets a 403 from Laravel's
| signature check before anything is resolved.
|
| `tenant.path` runs AFTER `signed`: resolving a tenant named in an unsigned
| URL would let anybody probe which slugs exist by watching which ones took
| longer.
*/
Route::middleware(['signed', 'throttle:30,1', 'tenant.path'])
    ->prefix('q/{tenant}/{entity}/{quote}')
    ->name('public.quote.')
    ->group(static function (): void {
        Route::get('/', [PublicQuoteController::class, 'show'])->name('show');
        Route::post('/accept', [PublicQuoteController::class, 'accept'])->name('accept');
        Route::post('/decline', [PublicQuoteController::class, 'decline'])->name('decline');
    });

/*
| The attachment download - PF-073.
|
| `signed` first, for the same reason as the public quote: the signature
| covers the path and the query, so a visitor who edits either gets a 403
| before a tenant is resolved and cannot probe which slugs exist by watching
| which ones take longer.
|
| It is served from its own origin where one is configured, so a stored file
| cannot execute against the application's origin and reach a session cookie.
| There is no permission check: the signature is the authorization, and a
| permission check would need a cookie on the download origin - which is the
| thing a separate origin exists to avoid.
*/
Route::middleware(['signed', 'throttle:60,1', 'tenant.path'])
    ->get('d/{tenant}/{entity}/{attachment}', AttachmentDownloadController::class)
    ->name('attachments.download');

Route::get('/health', static fn () => response()->json([
    'application' => config('app.name'),
    'status' => 'ok',
]))->name('health');

/*
| Tenant authentication - PF-029.
|
| `tenant.session` runs first because the users table lives in the tenant's
| own database; the guard would otherwise query the platform one. The entity
| scope does NOT apply here: a user signing in has no entity yet, and which
| entities they may act in is read from the roles this sign-in establishes.
*/
Route::middleware(['tenant.session'])->group(static function (): void {
    Route::get('/login', [LoginController::class, 'show'])
        ->middleware('guest')
        ->name('login');

    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('guest')
        ->name('login.store');

    Route::post('/logout', [LoginController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');
});

/*
| The application itself - behind the tenant resolver, the auth guard and the
| entity scope, in that order. The ordering is pinned in bootstrap/app.php
| rather than here, because route order does not survive a framework upgrade
| and the priority list does.
*/
Route::middleware(['tenant.session', 'auth'])->group(static function (): void {
    /*
    | The chooser and the switch sit OUTSIDE `entity.scope`, deliberately.
    |
    | Both exist to establish an entity, so a middleware that requires one
    | already established would refuse to render the page whose job is to
    | establish it - a redirect loop in place of a question.
    */
    Route::get('/entity/choose', static fn () => view('app.choose-entity', [
        'entities' => auth()->user()?->entitiesForSwitcher() ?? collect(),
    ]))->name('entity.choose');

    Route::post('/entity/switch', EntitySwitchController::class)->name('entity.switch');

    // Outside `entity.scope` too: a person choosing an entity may need the
    // high-contrast theme to read the chooser at all - UX-004.
    Route::post('/preferences/theme', ThemePreferenceController::class)->name('preferences.theme');
});

Route::middleware(['tenant.session', 'auth', 'entity.scope'])->group(static function (): void {
    Route::get('/overview', OverviewController::class)->name('overview');

    /*
    | The reading pages - PF-204.
    |
    | Every one of them is a GET and writes nothing. The invoice and bill
    | lists carry no `can:`, deliberately: they are open to anybody with a
    | role in the entity and gate their FIGURES on `report.view` inside the
    | handler, because an auditor who may read the list should not have to
    | hold a permission that drafts documents.
    |
    | The rest state their permission twice - here and in the handler. Both,
    | because this is the half that shows up in a route listing, and the
    | handler is the half that still holds when somebody adds a second route
    | to this group and forgets the middleware. The permission is the same
    | one `Navigation` uses to decide whether to offer the page, and a check
    | asserts the two agree in both directions.
    |
    | A detail page resolves its document under the entity scope, so an id
    | from another company is a 404 rather than a 403 - indistinguishable
    | from one that never existed, so a numbering series cannot be walked
    | from outside.
    */
    Route::get('/sales/invoices', InvoiceListController::class)->name('invoices.index');
    Route::get('/sales/invoices/{invoice}', InvoiceDetailController::class)->name('invoices.show');

    Route::get('/purchasing/bills', BillListController::class)->name('bills.index');
    Route::get('/purchasing/bills/{bill}', BillDetailController::class)->name('bills.show');

    Route::get('/masters/parties', PartyListController::class)
        ->middleware('can:party.manage')->name('parties.index');
    Route::get('/masters/parties/{party}', PartyDetailController::class)
        ->middleware('can:party.manage')->name('parties.show');
    Route::get('/masters/items', ItemListController::class)
        ->middleware('can:item.manage')->name('items.index');
    Route::get('/masters/currencies', CurrencyListController::class)
        ->middleware('can:report.view')->name('currencies.index');

    Route::get('/ledger/trial-balance', TrialBalanceController::class)
        ->middleware('can:report.view')->name('trial-balance.index');
    Route::get('/ledger/accounts', AccountListController::class)
        ->middleware('can:account.manage')->name('accounts.index');
    Route::get('/ledger/financial-position', FinancialPositionController::class)
        ->middleware('can:report.view')->name('financial-position.index');

    Route::get('/banking/accounts', BankAccountListController::class)
        ->middleware('can:bank.manage')->name('bank-accounts.index');
    Route::get('/settlement/payment-runs', PaymentRunListController::class)
        ->middleware('can:payment.prepare')->name('payment-runs.index');
    Route::get('/settlement/statements', StatementController::class)
        ->middleware('can:report.view')->name('statements.index');

    /*
    | Importing a statement - PF-154.
    |
    | Three steps, because the middle one is the whole point: nothing is
    | written until somebody has seen the rows as they will be interpreted.
    | A CSV's decimal separator cannot be settled from the file alone -
    | `1.234` is a thousand in Frankfurt and one in London - and a detector
    | that guessed silently imports a year off by a factor of a thousand
    | with every row looking plausible.
    |
    | `preview` and `remap` write nothing and say so in their handlers;
    | `store` checks `bank.import`, because it is the step that does.
    */
    Route::prefix('banking/accounts/{account}')->name('banking.import.')->group(static function (): void {
        Route::get('/import', [StatementImportController::class, 'create'])->name('create');
        Route::post('/import/preview', [StatementImportController::class, 'preview'])->name('preview');
        Route::post('/import/remap', [StatementImportController::class, 'remap'])->name('remap');

        /*
        | The permission is on the route, not only in the handler.
        |
        | Both, in fact - but stating it here is what makes it visible
        | in a route listing, and what stops a second handler being
        | added to this group without one.
        */
        Route::post('/import', [StatementImportController::class, 'store'])
            ->middleware('can:bank.import')
            ->name('store');
    });
});

/*
| The operator console - PF-036.
|
| `auth:ops`, never `auth`. These routes must never touch a tenant connection:
| an operator reading customer data from here would read it unattributably,
| because nothing in that tenant's own audit log would say they had. Reading
| requires impersonation, which requires the tenant's prior consent.
|
| Every route in this group is guarded, and a check asserts it - an
| unguarded operator page is not a smaller version of this problem, it is the
| whole of it.
*/
Route::prefix('ops')->name('ops.')->group(static function (): void {
    Route::middleware('guest:ops')->group(static function (): void {
        Route::get('/login', [OpsLoginController::class, 'show'])->name('login');
        Route::post('/login', [OpsLoginController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth:ops')->group(static function (): void {
        Route::post('/logout', [OpsLoginController::class, 'destroy'])->name('logout');

        Route::get('/tenants', [OpsTenantController::class, 'index'])->name('tenants');
        Route::post('/tenants', [OpsTenantController::class, 'store'])->name('tenants.store');
        Route::post('/tenants/{tenant}/status', [OpsTenantController::class, 'setStatus'])->name('tenants.status');
        Route::post('/tenants/{tenant}/expire', [OpsTenantController::class, 'expire'])->name('tenants.expire');

        Route::get('/settings', [OpsSettingsController::class, 'show'])->name('settings');
        Route::post('/settings/{setting}', [OpsSettingsController::class, 'update'])->name('settings.update');
    });
});
