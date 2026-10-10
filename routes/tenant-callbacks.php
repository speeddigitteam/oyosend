<?php

use App\Core\Billing\Http\Controllers\GatewayCallbackController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment gateway callbacks - {tenant}.APP_DOMAIN, stateless
|--------------------------------------------------------------------------
|
| Loaded by CoreServiceProvider on the workspace host with tenant resolution
| and throttling only: no session, no cookies, no CSRF token, no Inertia.
|
| A gateway sends the payer back with a cross-site POST, which carries no
| session cookie. Under the `web` group that request would start a new
| session and replace the one the payer is signed in with. So these two
| routes keep no state at all and trust nothing they receive: each looks the
| payment up by our own reference and asks the gateway what happened.
|
| They also answer for a suspended workspace: money that was taken has to be
| recorded whatever state the workspace is in.
|
*/

Route::match(['get', 'post'], 'billing/payments/{reference}/return', [GatewayCallbackController::class, 'returned'])
    ->where('reference', '[0-9A-Za-z]{26}')
    ->name('core.billing.payments.return');

Route::post('billing/gateways/{gateway}/notify', [GatewayCallbackController::class, 'notify'])
    ->where('gateway', '[a-z0-9_]{1,40}')
    ->name('core.billing.gateways.notify');
