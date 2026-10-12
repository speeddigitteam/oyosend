<?php

declare(strict_types=1);

use App\Modules\SmsMarketing\Http\Controllers\AnalyticsController;
use App\Modules\SmsMarketing\Http\Controllers\CampaignController;
use App\Modules\SmsMarketing\Http\Controllers\GroupController;
use App\Modules\SmsMarketing\Http\Controllers\SenderController;
use App\Modules\SmsMarketing\Http\Controllers\SettingsController;
use App\Modules\SmsMarketing\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('sms-marketing')->group(function (): void {
    // Campaigns. Fixed paths are declared before the `{campaign}` routes.
    Route::get('campaigns', [CampaignController::class, 'index'])->middleware('can:sms_marketing.campaigns.view')->name('sms_marketing.campaigns.index');
    Route::get('campaigns/create', [CampaignController::class, 'create'])->middleware('can:sms_marketing.campaigns.create')->name('sms_marketing.campaigns.create');
    Route::post('campaigns', [CampaignController::class, 'store'])->middleware('can:sms_marketing.campaigns.create')->name('sms_marketing.campaigns.store');
    Route::post('campaigns/estimate', [CampaignController::class, 'estimate'])
        ->middleware(['can:sms_marketing.campaigns.create', 'throttle:60,1'])
        ->name('sms_marketing.campaigns.estimate');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->middleware('can:sms_marketing.campaigns.view')->name('sms_marketing.campaigns.show');
    Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->middleware('can:sms_marketing.campaigns.edit')->name('sms_marketing.campaigns.edit');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->middleware('can:sms_marketing.campaigns.edit')->name('sms_marketing.campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->middleware('can:sms_marketing.campaigns.delete')->name('sms_marketing.campaigns.destroy');
    Route::get('campaigns/{campaign}/export', [CampaignController::class, 'export'])
        ->middleware(['can:sms_marketing.campaigns.view', 'throttle:10,1'])
        ->name('sms_marketing.campaigns.export');

    Route::middleware('can:sms_marketing.campaigns.launch')->group(function (): void {
        Route::post('campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->middleware('throttle:20,1')->name('sms_marketing.campaigns.launch');
        Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->name('sms_marketing.campaigns.cancel');
        Route::post('campaigns/{campaign}/retry', [CampaignController::class, 'retry'])->middleware('throttle:20,1')->name('sms_marketing.campaigns.retry');
    });

    // Groups
    Route::get('groups', [GroupController::class, 'index'])->middleware('can:sms_marketing.groups.view')->name('sms_marketing.groups.index');
    Route::get('groups/template', [GroupController::class, 'template'])->middleware('can:sms_marketing.groups.manage')->name('sms_marketing.groups.template');
    Route::post('groups', [GroupController::class, 'store'])->middleware('can:sms_marketing.groups.manage')->name('sms_marketing.groups.store');
    Route::get('groups/{group}', [GroupController::class, 'show'])->middleware('can:sms_marketing.groups.view')->name('sms_marketing.groups.show');
    Route::get('groups/{group}/export', [GroupController::class, 'export'])
        ->middleware(['can:sms_marketing.groups.view', 'throttle:10,1'])
        ->name('sms_marketing.groups.export');
    Route::middleware('can:sms_marketing.groups.manage')->group(function (): void {
        Route::put('groups/{group}', [GroupController::class, 'update'])->name('sms_marketing.groups.update');
        Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('sms_marketing.groups.destroy');
        Route::post('groups/{group}/members', [GroupController::class, 'addMembers'])->name('sms_marketing.groups.members.store');
        Route::delete('groups/{group}/members/{member}', [GroupController::class, 'removeMember'])->name('sms_marketing.groups.members.destroy');
        Route::post('groups/{group}/import', [GroupController::class, 'import'])->middleware('throttle:10,1')->name('sms_marketing.groups.import');
    });

    // Templates
    Route::get('templates', [TemplateController::class, 'index'])->middleware('can:sms_marketing.templates.view')->name('sms_marketing.templates.index');
    Route::middleware('can:sms_marketing.templates.manage')->group(function (): void {
        Route::post('templates/preview', [TemplateController::class, 'preview'])->middleware('throttle:60,1')->name('sms_marketing.templates.preview');
        Route::post('templates', [TemplateController::class, 'store'])->name('sms_marketing.templates.store');
        Route::put('templates/{template}', [TemplateController::class, 'update'])->name('sms_marketing.templates.update');
        Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('sms_marketing.templates.destroy');
    });

    // Sender ids
    Route::get('sender-ids', [SenderController::class, 'index'])->middleware('can:sms_marketing.senders.view')->name('sms_marketing.senders.index');
    Route::middleware('can:sms_marketing.senders.manage')->group(function (): void {
        Route::post('sender-ids', [SenderController::class, 'store'])->name('sms_marketing.senders.store');
        Route::put('sender-ids/{sender}', [SenderController::class, 'update'])->name('sms_marketing.senders.update');
        Route::delete('sender-ids/{sender}', [SenderController::class, 'destroy'])->name('sms_marketing.senders.destroy');
    });

    Route::get('analytics', [AnalyticsController::class, 'index'])->middleware('can:sms_marketing.analytics.view')->name('sms_marketing.analytics.index');

    Route::middleware('can:sms_marketing.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('sms_marketing.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('sms_marketing.settings.update');
    });
});
