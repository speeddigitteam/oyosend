<?php

declare(strict_types=1);

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
| See docs/EMAIL_MARKETING.md.
|
*/

Route::get('email-marketing/t/c/{token}', [TrackingController::class, 'click'])
    ->where('token', '[A-Za-z0-9_.-]{20,1700}')
    ->name('email_marketing.track.click');

Route::get('email-marketing/v/{token}', [TrackingController::class, 'view'])
    ->where('token', '[A-Za-z0-9_.-]{20,1700}')
    ->name('email_marketing.web.show');
