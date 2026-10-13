<?php

use App\Modules\Social\Http\Controllers\OAuthBrokerController;
use Illuminate\Support\Facades\Route;

/*
 * The OAuth redirect broker: one URI on the central host for every workspace. Loaded in the
 * stateless `central.callback` group (no session, no cookies, throttled). See docs/SOCIAL.md.
 */
Route::get('social/oauth/callback/{platform}', OAuthBrokerController::class)
    ->where('platform', '[a-z0-9_]{1,32}')
    ->name('central.social.oauth.callback');
