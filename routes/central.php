<?php

use App\Core\Http\Controllers\LocaleController;
use App\Core\Http\Controllers\WelcomeController;
use App\Core\Registration\Http\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central site - APP_DOMAIN
|--------------------------------------------------------------------------
|
| Loaded inside the `web` + `central` middleware groups with the `central.`
| name prefix. No tenant is active here and nobody is signed in: sessions
| belong to a workspace's own host.
|
*/

Route::get('/', [WelcomeController::class, 'show'])->name('home');

Route::post('workspace', [WelcomeController::class, 'find'])
    ->middleware('throttle:20,1')
    ->name('workspace.find');

Route::post('locale', LocaleController::class)->name('locale.update');

// Signing up for a workspace. Answers 404 while registration is closed.
Route::get('register', [RegistrationController::class, 'create'])->name('register');
Route::post('register', [RegistrationController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('register.store');
Route::get('register/sent', [RegistrationController::class, 'sent'])->name('register.sent');

// The emailed link: a page first, so that only a person pressing the button creates anything.
Route::get('register/confirm/{token}', [RegistrationController::class, 'confirm'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:30,1')
    ->name('register.confirm');
Route::post('register/confirm/{token}', [RegistrationController::class, 'complete'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('register.complete');
