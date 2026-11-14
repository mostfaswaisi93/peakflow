<?php

declare(strict_types=1);

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

Route::get('/', static fn () => redirect()->route('health'))->name('home');

Route::get('/health', static fn () => response()->json([
    'application' => config('app.name'),
    'status' => 'ok',
]))->name('health');
