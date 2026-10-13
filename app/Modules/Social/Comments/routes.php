<?php

declare(strict_types=1);

use App\Modules\Social\Comments\Http\Controllers\AudienceCommentController;
use App\Modules\Social\Comments\Http\Controllers\CommentRuleController;
use Illuminate\Support\Facades\Route;

/*
| Audience comments: the inbox and its keyword rules. Loaded through `loadTenantRoutes()` (workspace host, entitled to
| Social). Fixed paths come before the `{comment}` routes. Record scope is enforced in the controller, not here.
*/
Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('social/comments')->group(function (): void {
    Route::get('/', [AudienceCommentController::class, 'index'])->middleware('can:social.comments.view')->name('social.comments.index');

    Route::post('bulk', [AudienceCommentController::class, 'bulk'])
        ->middleware(['can:social.comments.view', 'throttle:30,1'])
        ->name('social.comments.bulk');

    // Rules, kill switch and the per-Page connections.
    Route::middleware('can:social.comments.manage_rules')->prefix('rules')->group(function (): void {
        Route::get('/', [CommentRuleController::class, 'index'])->name('social.comments.rules.index');
        Route::post('/', [CommentRuleController::class, 'store'])->middleware('throttle:30,1')->name('social.comments.rules.store');
        Route::post('preview', [CommentRuleController::class, 'preview'])->middleware('throttle:120,1')->name('social.comments.rules.preview');
        Route::put('{rule}', [CommentRuleController::class, 'update'])->where('rule', '[0-9A-Za-z]{26}')->name('social.comments.rules.update');
        Route::put('{rule}/enabled', [CommentRuleController::class, 'toggle'])->where('rule', '[0-9A-Za-z]{26}')->name('social.comments.rules.toggle');
        Route::delete('{rule}', [CommentRuleController::class, 'destroy'])->where('rule', '[0-9A-Za-z]{26}')->name('social.comments.rules.destroy');
    });

    Route::middleware('can:social.comments.manage_rules')->group(function (): void {
        Route::put('automation', [CommentRuleController::class, 'automation'])->name('social.comments.automation');
        Route::put('limits', [CommentRuleController::class, 'limits'])->name('social.comments.limits');
        Route::post('sources/{account}', [CommentRuleController::class, 'enableSource'])
            ->middleware('throttle:10,1')->where('account', '[0-9A-Za-z]{26}')->name('social.comments.sources.enable');
        Route::delete('sources/{account}', [CommentRuleController::class, 'disableSource'])
            ->where('account', '[0-9A-Za-z]{26}')->name('social.comments.sources.disable');
    });

    Route::prefix('{comment}')->where(['comment' => '[0-9A-Za-z]{26}'])->group(function (): void {
        Route::post('read', [AudienceCommentController::class, 'read'])->middleware('can:social.comments.view')->name('social.comments.read');
        Route::post('reply', [AudienceCommentController::class, 'reply'])->middleware(['can:social.comments.reply', 'throttle:60,1'])->name('social.comments.reply');
        Route::put('like', [AudienceCommentController::class, 'like'])->middleware(['can:social.comments.reply', 'throttle:60,1'])->name('social.comments.like');
        Route::put('handled', [AudienceCommentController::class, 'handled'])->middleware('can:social.comments.reply')->name('social.comments.handled');
        Route::post('notes', [AudienceCommentController::class, 'note'])->middleware('can:social.comments.reply')->name('social.comments.notes.store');
        Route::put('hidden', [AudienceCommentController::class, 'hidden'])->middleware(['can:social.comments.moderate', 'throttle:60,1'])->name('social.comments.hidden');
        Route::put('assignee', [AudienceCommentController::class, 'assign'])->middleware('can:social.comments.moderate')->name('social.comments.assign');
        Route::post('tags', [AudienceCommentController::class, 'tag'])->middleware('can:social.comments.moderate')->name('social.comments.tags.store');
        Route::delete('tags', [AudienceCommentController::class, 'untag'])->middleware('can:social.comments.moderate')->name('social.comments.tags.destroy');
        Route::delete('/', [AudienceCommentController::class, 'destroy'])->middleware(['can:social.comments.moderate', 'throttle:30,1'])->name('social.comments.destroy');
    });
});
