<?php

declare(strict_types=1);

use App\Modules\Communication\Http\Controllers\UnsubscribeController;
use App\Modules\Communication\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Communication callbacks - {tenant}.APP_DOMAIN, stateless
|--------------------------------------------------------------------------
|
| Loaded through `loadTenantCallbackRoutes()`: tenant resolution, throttling
| and the module's entitlement - no session, no cookies, no CSRF, no Inertia.
| Neither route trusts anything it is sent without verifying it first. See
| docs/COMMUNICATION.md.
|
*/

Route::post('communication/webhooks/{provider}', [WebhookController::class, 'receive'])
    ->where('provider', '[a-z][a-z0-9_]{0,39}')
    ->name('communication.webhooks.receive');

// The subscription handshake (WhatsApp Cloud API `hub.challenge`), answered only for a connected provider.
Route::get('communication/webhooks/{provider}', [WebhookController::class, 'verify'])
    ->where('provider', '[a-z][a-z0-9_]{0,39}')
    ->name('communication.webhooks.verify');

Route::get('communication/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{1,64}')
    ->name('communication.unsubscribe.show');

Route::post('communication/unsubscribe/{token}', [UnsubscribeController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{1,64}')
    ->name('communication.unsubscribe.store');
