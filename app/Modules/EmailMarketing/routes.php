<?php

declare(strict_types=1);

use App\Modules\EmailMarketing\Http\Controllers\CampaignController;
use App\Modules\EmailMarketing\Http\Controllers\ContentController;
use App\Modules\EmailMarketing\Http\Controllers\ImportController;
use App\Modules\EmailMarketing\Http\Controllers\ListController;
use App\Modules\EmailMarketing\Http\Controllers\ReportController;
use App\Modules\EmailMarketing\Http\Controllers\SequenceController;
use App\Modules\EmailMarketing\Http\Controllers\SettingsController;
use App\Modules\EmailMarketing\Http\Controllers\SubscriberController;
use App\Modules\EmailMarketing\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('email-marketing')->group(function (): void {
    // Campaigns. Fixed paths are declared before the `{campaign}` routes.
    Route::get('campaigns', [CampaignController::class, 'index'])->middleware('can:email_marketing.campaigns.view')->name('email_marketing.campaigns.index');
    Route::get('campaigns/create', [CampaignController::class, 'create'])->middleware('can:email_marketing.campaigns.create')->name('email_marketing.campaigns.create');
    Route::post('campaigns', [CampaignController::class, 'store'])->middleware('can:email_marketing.campaigns.create')->name('email_marketing.campaigns.store');
    Route::post('content/preview', [ContentController::class, 'preview'])
        ->middleware(['can:email_marketing.campaigns.view', 'throttle:60,1'])
        ->name('email_marketing.content.preview');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->middleware('can:email_marketing.campaigns.view')->name('email_marketing.campaigns.show');
    Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->middleware('can:email_marketing.campaigns.edit')->name('email_marketing.campaigns.edit');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->middleware('can:email_marketing.campaigns.edit')->name('email_marketing.campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->middleware('can:email_marketing.campaigns.delete')->name('email_marketing.campaigns.destroy');
    Route::post('campaigns/{campaign}/duplicate', [CampaignController::class, 'duplicate'])->middleware('can:email_marketing.campaigns.create')->name('email_marketing.campaigns.duplicate');
    Route::get('campaigns/{campaign}/deliveries/export', [CampaignController::class, 'export'])
        ->middleware(['can:email_marketing.reports.view', 'throttle:10,1'])
        ->name('email_marketing.campaigns.export');

    Route::middleware('can:email_marketing.campaigns.send')->group(function (): void {
        Route::post('campaigns/{campaign}/schedule', [CampaignController::class, 'schedule'])->middleware('throttle:20,1')->name('email_marketing.campaigns.schedule');
        Route::post('campaigns/{campaign}/unschedule', [CampaignController::class, 'unschedule'])->name('email_marketing.campaigns.unschedule');
        Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('email_marketing.campaigns.pause');
        Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->name('email_marketing.campaigns.resume');
        Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('email_marketing.campaigns.cancel');
        Route::post('campaigns/{campaign}/test', [CampaignController::class, 'test'])->middleware('throttle:10,1')->name('email_marketing.campaigns.test');
    });

    // Templates
    Route::get('templates', [TemplateController::class, 'index'])->middleware('can:email_marketing.templates.view')->name('email_marketing.templates.index');
    Route::middleware('can:email_marketing.templates.manage')->group(function (): void {
        Route::get('templates/create', [TemplateController::class, 'create'])->name('email_marketing.templates.create');
        Route::post('templates', [TemplateController::class, 'store'])->name('email_marketing.templates.store');
        Route::get('templates/{template}/edit', [TemplateController::class, 'edit'])->name('email_marketing.templates.edit');
        Route::put('templates/{template}', [TemplateController::class, 'update'])->name('email_marketing.templates.update');
        Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('email_marketing.templates.destroy');
    });

    // Lists and segments
    Route::get('lists', [ListController::class, 'index'])->middleware('can:email_marketing.lists.view')->name('email_marketing.lists.index');
    Route::get('lists/{list}', [ListController::class, 'show'])->middleware('can:email_marketing.lists.view')->name('email_marketing.lists.show');
    Route::middleware('can:email_marketing.lists.manage')->group(function (): void {
        Route::post('lists', [ListController::class, 'store'])->name('email_marketing.lists.store');
        Route::put('lists/{list}', [ListController::class, 'update'])->name('email_marketing.lists.update');
        Route::delete('lists/{list}', [ListController::class, 'destroy'])->name('email_marketing.lists.destroy');
        Route::post('lists/{list}/segments', [ListController::class, 'storeSegment'])->name('email_marketing.segments.store');
        Route::put('segments/{segment}', [ListController::class, 'updateSegment'])->name('email_marketing.segments.update');
        Route::delete('segments/{segment}', [ListController::class, 'destroySegment'])->name('email_marketing.segments.destroy');
    });

    // Subscribers. Fixed paths first.
    Route::get('subscribers', [SubscriberController::class, 'index'])->middleware('can:email_marketing.subscribers.view')->name('email_marketing.subscribers.index');
    Route::get('subscribers/contacts', [SubscriberController::class, 'contacts'])
        ->middleware(['can:email_marketing.subscribers.manage', 'throttle:60,1'])
        ->name('email_marketing.subscribers.contacts');
    Route::middleware('can:email_marketing.subscribers.manage')->group(function (): void {
        Route::post('lists/{list}/subscribers', [ListController::class, 'addSubscriber'])->name('email_marketing.subscribers.store');
        Route::put('subscriptions/{subscription}', [SubscriberController::class, 'update'])->name('email_marketing.subscriptions.update');
        Route::post('subscriptions/{subscription}/unsubscribe', [SubscriberController::class, 'unsubscribe'])->name('email_marketing.subscriptions.unsubscribe');
        Route::delete('subscriptions/{subscription}', [SubscriberController::class, 'destroy'])->name('email_marketing.subscriptions.destroy');
    });
    Route::get('lists/{list}/export', [ListController::class, 'export'])
        ->middleware(['can:email_marketing.subscribers.export', 'throttle:10,1'])
        ->name('email_marketing.lists.export');

    // Import
    Route::middleware('can:email_marketing.subscribers.import')->group(function (): void {
        Route::get('imports', [ImportController::class, 'index'])->name('email_marketing.imports.index');
        Route::get('imports/template', [ImportController::class, 'template'])->name('email_marketing.imports.template');
        Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:10,1')->name('email_marketing.imports.store');
    });

    // Sequences
    Route::get('sequences', [SequenceController::class, 'index'])->middleware('can:email_marketing.sequences.view')->name('email_marketing.sequences.index');
    Route::middleware('can:email_marketing.sequences.manage')->group(function (): void {
        Route::get('sequences/create', [SequenceController::class, 'create'])->name('email_marketing.sequences.create');
        Route::post('sequences', [SequenceController::class, 'store'])->name('email_marketing.sequences.store');
    });
    Route::get('sequences/{sequence}', [SequenceController::class, 'show'])->middleware('can:email_marketing.sequences.view')->name('email_marketing.sequences.show');
    Route::middleware('can:email_marketing.sequences.manage')->group(function (): void {
        Route::get('sequences/{sequence}/edit', [SequenceController::class, 'edit'])->name('email_marketing.sequences.edit');
        Route::put('sequences/{sequence}', [SequenceController::class, 'update'])->name('email_marketing.sequences.update');
        Route::delete('sequences/{sequence}', [SequenceController::class, 'destroy'])->name('email_marketing.sequences.destroy');
    });
    Route::middleware('can:email_marketing.sequences.activate')->group(function (): void {
        Route::post('sequences/{sequence}/activate', [SequenceController::class, 'activate'])->name('email_marketing.sequences.activate');
        Route::post('sequences/{sequence}/pause', [SequenceController::class, 'pause'])->name('email_marketing.sequences.pause');
    });

    // Reports
    Route::get('reports', [ReportController::class, 'index'])->middleware('can:email_marketing.reports.view')->name('email_marketing.reports.index');

    // Settings
    Route::middleware('can:email_marketing.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('email_marketing.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('email_marketing.settings.update');
    });
});
