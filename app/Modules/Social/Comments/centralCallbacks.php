<?php

declare(strict_types=1);

use App\Modules\Social\Comments\Http\Controllers\FacebookWebhookController;
use Illuminate\Support\Facades\Route;

/*
| The one Facebook webhook URL a Meta app can have, on the central host - stateless (`central.callback`). The
| workspace is the one that claimed the Page named in each event, never anything the request says about itself.
*/
Route::post('social/webhooks/facebook', [FacebookWebhookController::class, 'receiveCentral'])->name('central.social.comments.webhook.receive');
Route::get('social/webhooks/facebook', [FacebookWebhookController::class, 'verify'])->name('central.social.comments.webhook.verify');
