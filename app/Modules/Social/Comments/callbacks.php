<?php

declare(strict_types=1);

use App\Modules\Social\Comments\Http\Controllers\FacebookWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Facebook Page webhooks on the workspace host - stateless (no session, cookies or CSRF), loaded through
| `loadTenantCallbackRoutes()` so a workspace without Social answers 404. Nothing is trusted before the
| `X-Hub-Signature-256` check; see docs/SOCIAL.md.
*/
Route::post('social/webhooks/facebook', [FacebookWebhookController::class, 'receive'])->name('social.comments.webhook.receive');
Route::get('social/webhooks/facebook', [FacebookWebhookController::class, 'verify'])->name('social.comments.webhook.verify');
