<?php

use App\Platform\Http\Controllers\Auth\LoginController;
use App\Platform\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Platform\Http\Controllers\Auth\TwoFactorSetupController;
use App\Platform\Http\Controllers\DashboardController;
use App\Platform\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super Admin panel - admin.APP_DOMAIN
|--------------------------------------------------------------------------
|
| Loaded inside the `web` + `platform` middleware groups with the `platform.`
| name prefix. Everything here runs in the central context with the
| `platform` guard; no tenant is ever active.
|
*/

Route::middleware('guest:platform')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.verify');
});

Route::middleware('auth:platform')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Enrolment is the only thing an account without two-factor can reach.
    Route::get('two-factor/setup', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
    Route::post('two-factor/setup', [TwoFactorSetupController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('two-factor.confirm');

    Route::middleware('platform.2fa')->group(function () {
        Route::get('/', DashboardController::class)
            ->middleware('can:platform.dashboard.view')
            ->name('dashboard');

        Route::get('tenants', [TenantController::class, 'index'])
            ->middleware('can:platform.tenants.view')
            ->name('tenants.index');
    });
});
