<?php

declare(strict_types=1);

use App\Modules\Support\Http\Controllers\CsatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Support callbacks - {tenant}.APP_DOMAIN, stateless
|--------------------------------------------------------------------------
|
| Loaded through `loadTenantCallbackRoutes()`: tenant resolution, a throttle and the module's entitlement - no
| session, no cookies, no CSRF, no Inertia. The satisfaction survey link is a single-use token that is looked
| up by its SHA-256 and answered with the same 404 page for every invalid value. See docs/SUPPORT.md.
|
*/

Route::get('support-survey/{token}', [CsatController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->name('support.csat.show');

Route::post('support-survey/{token}', [CsatController::class, 'store'])
    ->middleware('throttle:10,1')
    ->where('token', '[a-f0-9]{64}')
    ->name('support.csat.store');
