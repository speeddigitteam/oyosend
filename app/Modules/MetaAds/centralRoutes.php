<?php

use App\Modules\MetaAds\Http\Controllers\OAuthBrokerController;
use Illuminate\Support\Facades\Route;

/*
 * The OAuth redirect broker: one URI on the central host for every workspace. Loaded in the
 * stateless `central.callback` group (no session, no cookies, throttled). See docs/META_ADS.md.
 */
Route::get('meta-ads/oauth/callback', OAuthBrokerController::class)
    ->name('central.meta_ads.oauth.callback');
