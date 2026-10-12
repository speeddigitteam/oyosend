<?php

declare(strict_types=1);

use App\Modules\Automation\Http\Controllers\InboundWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbound workflow webhooks - {tenant}.APP_DOMAIN, stateless
|--------------------------------------------------------------------------
|
| Loaded by AutomationServiceProvider inside the `tenant.callback` group: tenant resolution and a throttle,
| no session, no cookies, no CSRF token. The caller proves itself with an HMAC signature, never a cookie.
|
*/

Route::post('automation/hooks/{token}', [InboundWebhookController::class, 'receive'])
    ->where('token', 'whk_[A-Za-z0-9]{40}')
    ->name('automation.hooks.receive');
