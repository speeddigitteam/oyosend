<?php

use App\Core\Http\Controllers\LocaleController;
use App\Core\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central site - APP_DOMAIN
|--------------------------------------------------------------------------
|
| Loaded inside the `web` + `central` middleware groups with the `central.`
| name prefix. No tenant is active here. Tenant self-registration and the
| public marketing pages arrive in Phase 2.
|
*/

Route::get('/', [WelcomeController::class, 'show'])->name('home');

Route::post('workspace', [WelcomeController::class, 'find'])
    ->middleware('throttle:20,1')
    ->name('workspace.find');

Route::post('locale', LocaleController::class)->name('locale.update');
