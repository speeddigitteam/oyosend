<?php

declare(strict_types=1);

use App\Modules\Communication\Http\Controllers\AudienceController;
use App\Modules\Communication\Http\Controllers\InboxController;
use App\Modules\Communication\Http\Controllers\MessageController;
use App\Modules\Communication\Http\Controllers\NotificationController;
use App\Modules\Communication\Http\Controllers\PreferenceController;
use App\Modules\Communication\Http\Controllers\SettingsController;
use App\Modules\Communication\Http\Controllers\TemplateController;
use App\Modules\Communication\Http\Controllers\TimelineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('communication')->group(function (): void {
    // A member's own notifications. Every query is narrowed to the signed-in membership.
    Route::middleware('can:communication.notifications.view')->group(function (): void {
        Route::get('notifications', [NotificationController::class, 'index'])->name('communication.notifications.index');
        Route::get('notifications/summary', [NotificationController::class, 'summary'])->name('communication.notifications.summary');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('communication.notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('communication.notifications.read');
        Route::post('notifications/{notification}/unread', [NotificationController::class, 'unread'])->name('communication.notifications.unread');
        Route::post('notifications/{notification}/archive', [NotificationController::class, 'archive'])->name('communication.notifications.archive');
        Route::get('preferences', [PreferenceController::class, 'index'])->name('communication.preferences.index');
        Route::put('preferences', [PreferenceController::class, 'update'])->name('communication.preferences.update');
    });

    // Unified inbox: each source still decides, by its own permission, what the member sees (ADR 0020).
    Route::get('inbox', [InboxController::class, 'index'])
        ->middleware('can:communication.inbox.view')
        ->name('communication.inbox.index');
    // The list's own refresh: the newest page for the same filters, as JSON, read-only. Its throttle has its own key
    // (the third argument), so a tab left open never uses up the budget of replies and reads.
    Route::get('inbox/poll', [InboxController::class, 'poll'])
        ->middleware(['can:communication.inbox.view', 'throttle:60,1,inbox-poll'])
        ->name('communication.inbox.poll');
    // A reply written in the inbox (ADR 0030). The inbox also checks the source's own view and reply permissions and
    // hands the text to the owning app's action; it never sends anything itself.
    Route::post('inbox/{source}/{id}/reply', [InboxController::class, 'reply'])
        ->middleware(['can:communication.inbox.view', 'throttle:30,1,inbox-reply'])
        ->where(['source' => '[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*', 'id' => '[0-9A-Za-z]{26}'])
        ->name('communication.inbox.reply');
    // The member read the open conversation (ADR 0030): a POST, never a side effect of loading the page. Reply and read
    // each count on their own key, so opening conversations never spends the reply budget.
    Route::post('inbox/{source}/{id}/read', [InboxController::class, 'read'])
        ->middleware(['can:communication.inbox.view', 'throttle:120,1,inbox-read'])
        ->where(['source' => '[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*', 'id' => '[0-9A-Za-z]{26}'])
        ->name('communication.inbox.read');
    // A reply suggestion from the AI text assistant for the composer (ADR 0030, ADR 0024): the same gates as a reply,
    // and it sends, saves and marks nothing. Its own throttle key, so drafting never spends the reply budget.
    Route::post('inbox/{source}/{id}/suggest', [InboxController::class, 'suggest'])
        ->middleware(['can:communication.inbox.view', 'throttle:20,1,inbox-suggest'])
        ->where(['source' => '[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*', 'id' => '[0-9A-Za-z]{26}'])
        ->name('communication.inbox.suggest');

    // Message log and composer. `create` is declared before the `{message}` routes.
    Route::get('messages', [MessageController::class, 'index'])
        ->middleware('can:communication.messages.view')
        ->name('communication.messages.index');
    Route::get('messages/compose', [MessageController::class, 'create'])
        ->middleware('can:communication.messages.send')
        ->name('communication.messages.create');
    Route::post('messages', [MessageController::class, 'store'])
        ->middleware(['can:communication.messages.send', 'throttle:30,1'])
        ->name('communication.messages.store');
    Route::get('messages/{message}', [MessageController::class, 'show'])
        ->middleware('can:communication.messages.view')
        ->name('communication.messages.show');
    Route::post('messages/{message}/retry', [MessageController::class, 'retry'])
        ->middleware('can:communication.messages.send')
        ->name('communication.messages.retry');
    Route::post('messages/{message}/cancel', [MessageController::class, 'cancel'])
        ->middleware('can:communication.messages.send')
        ->name('communication.messages.cancel');

    // Interaction timeline of a shared contact or company.
    Route::middleware('can:communication.timeline.view')->group(function (): void {
        Route::get('contacts/{contact}/timeline', [TimelineController::class, 'contact'])->name('communication.timeline.contact.show');
        Route::get('companies/{company}/timeline', [TimelineController::class, 'company'])->name('communication.timeline.company.show');
    });
    Route::middleware('can:communication.timeline.log')->group(function (): void {
        Route::post('contacts/{contact}/timeline', [TimelineController::class, 'storeContact'])->name('communication.timeline.contact.store');
        Route::post('companies/{company}/timeline', [TimelineController::class, 'storeCompany'])->name('communication.timeline.company.store');
        Route::delete('timeline/{interaction}', [TimelineController::class, 'destroy'])->name('communication.timeline.destroy');
    });

    // Templates.
    Route::get('templates', [TemplateController::class, 'index'])
        ->middleware('can:communication.templates.view')
        ->name('communication.templates.index');
    Route::middleware('can:communication.templates.manage')->group(function (): void {
        Route::post('templates/preview', [TemplateController::class, 'preview'])->name('communication.templates.preview');
        Route::post('templates', [TemplateController::class, 'store'])->name('communication.templates.store');
        Route::put('templates/{template}', [TemplateController::class, 'update'])->name('communication.templates.update');
        Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('communication.templates.destroy');
    });

    // Consent and suppressions.
    Route::get('audience', [AudienceController::class, 'index'])
        ->middleware('can:communication.audience.view')
        ->name('communication.audience.index');
    Route::post('contacts/{contact}/consent', [AudienceController::class, 'storeConsent'])
        ->middleware('can:communication.consent.manage')
        ->name('communication.consent.store');
    Route::middleware('can:communication.suppressions.manage')->group(function (): void {
        Route::post('suppressions', [AudienceController::class, 'storeSuppression'])->name('communication.suppressions.store');
        Route::delete('suppressions/{suppression}', [AudienceController::class, 'destroySuppression'])->name('communication.suppressions.destroy');
    });

    // Settings: sender identity, quiet hours, channel connections (references only, never secrets).
    Route::middleware('can:communication.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('communication.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('communication.settings.update');
        Route::put('settings/channels/{channel}', [SettingsController::class, 'updateChannel'])->name('communication.settings.channels.update');
    });
});
