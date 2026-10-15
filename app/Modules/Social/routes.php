<?php

declare(strict_types=1);

use App\Modules\Social\Ai\AiAssistController;
use App\Modules\Social\Ai\SentimentSummaryController;
use App\Modules\Social\Http\Controllers\AccountAccessController;
use App\Modules\Social\Http\Controllers\AccountController;
use App\Modules\Social\Http\Controllers\AnalyticsController;
use App\Modules\Social\Http\Controllers\CalendarController;
use App\Modules\Social\Http\Controllers\CommentController;
use App\Modules\Social\Http\Controllers\ImportController;
use App\Modules\Social\Http\Controllers\InsightsDashboard;
use App\Modules\Social\Http\Controllers\LibraryController;
use App\Modules\Social\Http\Controllers\MediaController;
use App\Modules\Social\Http\Controllers\PostController;
use App\Modules\Social\Http\Controllers\PostOptionsController;
use App\Modules\Social\Http\Controllers\PreflightController;
use App\Modules\Social\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('social')->group(function (): void {
    // Calendar
    Route::get('calendar', [CalendarController::class, 'index'])
        ->middleware('can:social.posts.view')
        ->name('social.calendar.index');

    // Posts. Fixed paths are declared before the `{post}` routes.
    Route::get('posts', [PostController::class, 'index'])->middleware('can:social.posts.view')->name('social.posts.index');
    Route::get('posts/export', [PostController::class, 'export'])
        ->middleware(['can:social.posts.view', 'throttle:10,1'])
        ->name('social.posts.export');
    // AI suggestions for the composer (text only; permission and per-member limits apply).
    Route::post('posts/ai/{operation}', AiAssistController::class)
        ->middleware(['can:social.posts.ai_assist', 'throttle:30,1'])
        ->where('operation', 'caption|hashtags|rewrite|shorten|translate')
        ->name('social.posts.ai');
    Route::get('analytics/sentiment', SentimentSummaryController::class)
        ->middleware(['can:social.analytics.view', 'throttle:60,1'])
        ->name('social.analytics.sentiment');
    Route::post('posts/preflight', PreflightController::class)
        ->middleware(['can:social.posts.view', 'throttle:120,1'])
        ->name('social.posts.preflight');
    // The choices a platform offers one account (TikTok creator info) for the composer's per-account settings.
    // Also needs create or edit (checked in the controller); reads only, its own limit per member.
    Route::get('posts/creator-info/{account}', PostOptionsController::class)
        ->middleware(['can:social.posts.view', 'throttle:30,1'])
        ->name('social.posts.creator_info');
    Route::get('posts/create', [PostController::class, 'create'])->middleware('can:social.posts.create')->name('social.posts.create');
    Route::post('posts', [PostController::class, 'store'])->middleware('can:social.posts.create')->name('social.posts.store');
    Route::get('posts/{post}', [PostController::class, 'show'])->middleware('can:social.posts.view')->name('social.posts.show');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])->middleware('can:social.posts.edit')->name('social.posts.edit');
    Route::put('posts/{post}', [PostController::class, 'update'])->middleware('can:social.posts.edit')->name('social.posts.update');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->middleware('can:social.posts.delete')->name('social.posts.destroy');

    Route::middleware('can:social.posts.schedule')->group(function (): void {
        Route::post('posts/{post}/submit', [PostController::class, 'submit'])->name('social.posts.submit');
        Route::post('posts/{post}/schedule', [PostController::class, 'schedule'])->name('social.posts.schedule');
        Route::post('posts/{post}/reschedule', [PostController::class, 'reschedule'])->name('social.posts.reschedule');
        Route::post('posts/{post}/unschedule', [PostController::class, 'unschedule'])->name('social.posts.unschedule');
        Route::post('posts/{post}/targets/{target}/retry', [PostController::class, 'retry'])->name('social.posts.targets.retry');
    });

    Route::middleware('can:social.posts.approve')->group(function (): void {
        Route::post('posts/{post}/approve', [PostController::class, 'approve'])->name('social.posts.approve');
        Route::post('posts/{post}/reject', [PostController::class, 'reject'])->name('social.posts.reject');
    });

    Route::post('posts/{post}/comments', [CommentController::class, 'store'])
        ->middleware('can:social.posts.comment')
        ->name('social.posts.comments.store');
    Route::delete('posts/{post}/comments/{comment}', [CommentController::class, 'destroy'])
        ->middleware('can:social.posts.comment')
        ->name('social.posts.comments.destroy');

    // Media library. The file route streams privately; there is no public URL.
    Route::get('media', [MediaController::class, 'index'])->middleware('can:social.media.view')->name('social.media.index');
    Route::get('media/{media}/file', [MediaController::class, 'file'])
        ->middleware(['can:social.media.view', 'throttle:240,1'])
        ->name('social.media.file');
    Route::middleware('can:social.media.manage')->group(function (): void {
        Route::post('media', [MediaController::class, 'store'])->middleware('throttle:30,1')->name('social.media.store');
        Route::put('media/{media}', [MediaController::class, 'update'])->name('social.media.update');
        Route::delete('media/{media}', [MediaController::class, 'destroy'])->name('social.media.destroy');
    });

    // Connected accounts
    Route::get('accounts', [AccountController::class, 'index'])->middleware('can:social.accounts.view')->name('social.accounts.index');
    Route::middleware('can:social.accounts.manage')->group(function (): void {
        Route::post('accounts/connect/{platform}', [AccountController::class, 'connect'])
            ->middleware('throttle:10,1')
            ->where('platform', '[a-z0-9_]{1,32}')
            ->name('social.accounts.connect');
        Route::get('accounts/callback/{platform}', [AccountController::class, 'callback'])
            ->middleware('throttle:20,1')
            ->where('platform', '[a-z0-9_]{1,32}')
            ->name('social.accounts.callback');
        Route::get('accounts/select/{selection}', [AccountController::class, 'select'])->name('social.accounts.select');
        Route::post('accounts/select/{selection}', [AccountController::class, 'connectSelected'])->name('social.accounts.select.store');
        Route::delete('accounts/{account}', [AccountController::class, 'disconnect'])->name('social.accounts.destroy');
        Route::put('accounts/{account}/access', [AccountAccessController::class, 'update'])->name('social.accounts.access.update');
        Route::post('accounts/{account}/access/check', [AccountAccessController::class, 'check'])
            ->middleware('throttle:6,1')
            ->name('social.accounts.access.check');
    });

    // Campaigns, categories, hashtag sets
    Route::get('library', [LibraryController::class, 'index'])->middleware('can:social.library.view')->name('social.library.index');
    Route::middleware('can:social.library.manage')->group(function (): void {
        Route::post('library/campaigns', [LibraryController::class, 'storeCampaign'])->name('social.library.campaigns.store');
        Route::put('library/campaigns/{campaign}', [LibraryController::class, 'updateCampaign'])->name('social.library.campaigns.update');
        Route::delete('library/campaigns/{campaign}', [LibraryController::class, 'destroyCampaign'])->name('social.library.campaigns.destroy');
        Route::post('library/categories', [LibraryController::class, 'storeCategory'])->name('social.library.categories.store');
        Route::put('library/categories/{category}', [LibraryController::class, 'updateCategory'])->name('social.library.categories.update');
        Route::delete('library/categories/{category}', [LibraryController::class, 'destroyCategory'])->name('social.library.categories.destroy');
        Route::post('library/hashtag-sets', [LibraryController::class, 'storeHashtagSet'])->name('social.library.hashtag-sets.store');
        Route::put('library/hashtag-sets/{set}', [LibraryController::class, 'updateHashtagSet'])->name('social.library.hashtag-sets.update');
        Route::delete('library/hashtag-sets/{set}', [LibraryController::class, 'destroyHashtagSet'])->name('social.library.hashtag-sets.destroy');
    });

    // Bulk scheduling
    Route::middleware('can:social.imports.create')->group(function (): void {
        Route::get('imports', [ImportController::class, 'index'])->name('social.imports.index');
        Route::get('imports/template', [ImportController::class, 'template'])->name('social.imports.template');
        Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:10,1')->name('social.imports.store');
    });

    Route::get('analytics/export', [InsightsDashboard::class, 'download'])
        ->middleware(['can:social.analytics.view', 'throttle:10,1'])
        ->name('social.analytics.export');
    Route::get('analytics', [AnalyticsController::class, 'index'])
        ->middleware('can:social.analytics.view')
        ->name('social.analytics.index');

    Route::middleware('can:social.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('social.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('social.settings.update');
    });
});
