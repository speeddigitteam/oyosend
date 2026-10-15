<?php

declare(strict_types=1);

use App\Modules\Social\Http\Controllers\PublicMediaController;
use Illuminate\Support\Facades\Route;

/*
| Public, expiring media URLs on the workspace host - stateless (no session, cookies or CSRF), loaded through
| `loadTenantCallbackRoutes()` so a workspace without Social answers 404, throttled by the group. Only a
| temporary signed URL issued by this workspace opens a file; anything else is 404. See docs/SOCIAL.md and
| ADR 0033.
*/
Route::get('social/public-media/{media}', PublicMediaController::class)
    ->where('media', '[0-9A-Za-z]{26}')
    ->name('social.media.public');
