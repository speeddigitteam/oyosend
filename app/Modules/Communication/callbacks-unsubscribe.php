<?php

declare(strict_types=1);

use App\Modules\Communication\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Communication unsubscribe - {tenant}.APP_DOMAIN, stateless, NOT entitlement-gated
|--------------------------------------------------------------------------
|
| Loaded with `tenant.callback` only (tenant resolution, throttling, no session). A person who got a message
| must always be able to opt out, including after the workspace's Communication app lapsed: refusing a
| recipient's opt-out would be worse than recording it. The route still only works for a workspace that exists
| (resolved from the hostname) and answers identically for every unknown, used or expired token; it shows the
| workspace name (already in the message) and nothing else. See docs/COMMUNICATION.md section 6.
|
*/

Route::get('communication/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{1,64}')
    ->name('communication.unsubscribe.show');

Route::post('communication/unsubscribe/{token}', [UnsubscribeController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{1,64}')
    ->name('communication.unsubscribe.store');
