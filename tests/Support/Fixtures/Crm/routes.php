<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Loaded through ModuleServiceProvider::loadTenantRoutes(), which puts every
 * route here behind `web`, `tenant` and - because the module is not core -
 * `entitled:crm`.
 */

Route::middleware(['auth:web', 'auth.session', 'member'])->group(function () {
    Route::get('crm/leads', fn () => response()->json(['leads' => []]))
        ->middleware('can:crm.leads.view')
        ->name('crm.leads.index');
});

// A module route that asks for nothing but the entitlement.
Route::get('crm/ping', fn () => response()->json(['pong' => true]))->name('crm.ping');
