<?php

declare(strict_types=1);

use App\Tenant\Identity\Http\Controllers\EntitySwitchController;
use App\Tenant\Identity\Http\Controllers\LoginController;
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
});

Route::middleware(['tenant.session', 'auth', 'entity.scope'])->group(static function (): void {
    Route::view('/overview', 'app.home')->name('overview');
});
