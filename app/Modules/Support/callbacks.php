<?php

declare(strict_types=1);

use App\Modules\Support\Http\Controllers\CsatController;
use App\Modules\Support\Http\Controllers\LiveChatPublicController;
use App\Modules\Support\Support\LiveChatLimits;
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

// Website live chat (ADR 0032): the loader script, the framed chat page and the frame's same-origin JSON API. The
// visitor token (`X-Chat-Visitor`, stored as SHA-256) reaches one conversation; a missing, unknown or foreign token
// and a switched-off chat are a 404, a request from another origin a 403. Each API route adds a named limiter (per
// address and per token, see LiveChatLimits) to the group throttle.
Route::get('support/chat/widget.js', [LiveChatPublicController::class, 'widget'])->name('support.live_chat.widget');

Route::get('support/chat/frame', [LiveChatPublicController::class, 'frame'])->name('support.live_chat.frame');

Route::post('support/chat/api/conversations', [LiveChatPublicController::class, 'start'])
    ->middleware('throttle:'.LiveChatLimits::START)
    ->name('support.live_chat.api.start');

Route::post('support/chat/api/messages', [LiveChatPublicController::class, 'send'])
    ->middleware('throttle:'.LiveChatLimits::SEND)
    ->name('support.live_chat.api.send');

Route::get('support/chat/api/messages', [LiveChatPublicController::class, 'poll'])
    ->middleware('throttle:'.LiveChatLimits::POLL)
    ->name('support.live_chat.api.poll');
