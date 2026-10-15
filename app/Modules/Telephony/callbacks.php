<?php

declare(strict_types=1);

use App\Modules\Telephony\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Telephony callbacks - {tenant}.APP_DOMAIN, stateless
|--------------------------------------------------------------------------
|
| Loaded through `loadTenantCallbackRoutes()`: tenant resolution and the module's entitlement - no session, no
| cookies, no CSRF. The PBX's HMAC signature over the timestamp and body is the only credential. See
| docs/TELEPHONY.md.
|
*/

Route::post('telephony/events', [WebhookController::class, 'receive'])
    ->middleware('throttle:600,1')
    ->name('telephony.events');
