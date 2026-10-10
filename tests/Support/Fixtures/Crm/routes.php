<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Loaded through ModuleServiceProvider::loadTenantRoutes(), which puts every
 * route here behind `web`, `tenant` and - because the module is not core -
 * `entitled:test_crm`.
 */

Route::middleware(['auth:web', 'auth.session', 'member'])->group(function () {
    Route::get('test-crm/leads', fn () => response()->json(['leads' => []]))
        ->middleware('can:test_crm.leads.view')
        ->name('test_crm.leads.index');
});

// A module route that asks for nothing but the entitlement.
Route::get('test-crm/ping', fn () => response()->json(['pong' => true]))->name('test_crm.ping');
