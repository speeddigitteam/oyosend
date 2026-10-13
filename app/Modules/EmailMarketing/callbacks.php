<?php

declare(strict_types=1);

use App\Modules\EmailMarketing\Http\Controllers\SignupController;
use App\Modules\EmailMarketing\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Email Marketing callbacks - {tenant}.APP_DOMAIN, stateless
|--------------------------------------------------------------------------
|
| Loaded through `loadTenantCallbackRoutes()`: tenant resolution, a throttle and the module's entitlement - no
| session, no cookies, no CSRF, no Inertia. The signed token in the URL is the only credential (HMAC over the
| tenant, the delivery and, for a click, the destination). Every invalid value is the same bare 404.
| The public sign-up form (list token, honeypot, time trap, throttles) and its single-use confirmation link: a GET
| only shows a button, the POST subscribes. See docs/EMAIL_MARKETING.md.
|
*/

Route::get('email-marketing/t/c/{token}', [TrackingController::class, 'click'])
    ->where('token', '[A-Za-z0-9_.-]{20,1700}')
    ->name('email_marketing.track.click');

Route::get('email-marketing/v/{token}', [TrackingController::class, 'view'])
    ->where('token', '[A-Za-z0-9_.-]{20,1700}')
    ->name('email_marketing.web.show');

// Public sign-up form with double opt-in. The list token is not a credential; the confirmation token is (256 bits,
// stored as SHA-256, single use, 3 days).
Route::get('email-marketing/join/confirm/{token}', [SignupController::class, 'confirmShow'])
    ->where('token', '[a-f0-9]{64}')
    ->name('email_marketing.signup.confirm.show');

Route::post('email-marketing/join/confirm/{token}', [SignupController::class, 'confirmStore'])
    ->middleware('throttle:20,1')
    ->where('token', '[a-f0-9]{64}')
    ->name('email_marketing.signup.confirm.store');

Route::get('email-marketing/join/{form}', [SignupController::class, 'show'])
    ->where('form', '[a-f0-9]{32}')
    ->name('email_marketing.signup.show');

Route::post('email-marketing/join/{form}', [SignupController::class, 'store'])
    ->middleware('throttle:30,1')
    ->where('form', '[a-f0-9]{32}')
    ->name('email_marketing.signup.store');
