<?php

declare(strict_types=1);

use App\Modules\Social\LeadAds\Http\LeadWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Social Lead Ads callbacks - {tenant}.APP_DOMAIN, stateless
|--------------------------------------------------------------------------
|
| Loaded through `loadTenantCallbackRoutes()`: tenant resolution, throttling and the
| module's entitlement - no session, no cookies, no CSRF, no Inertia. The POST verifies
| the Meta app signature over the raw body before it parses anything. See docs/SOCIAL.md.
|
*/

Route::get('social/webhooks/facebook/leads', [LeadWebhookController::class, 'verify'])
    ->name('social.leadads.webhook.verify');

Route::post('social/webhooks/facebook/leads', [LeadWebhookController::class, 'receive'])
    ->name('social.leadads.webhook.receive');
