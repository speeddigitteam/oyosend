<?php

declare(strict_types=1);

use App\Modules\WhatsappMarketing\Crm\SegmentCampaignController;
use App\Modules\WhatsappMarketing\Crm\WhatsappCrmController;
use App\Modules\WhatsappMarketing\Http\Controllers\AnalyticsController;
use App\Modules\WhatsappMarketing\Http\Controllers\CampaignController;
use App\Modules\WhatsappMarketing\Http\Controllers\ConversationController;
use App\Modules\WhatsappMarketing\Http\Controllers\FlowController;
use App\Modules\WhatsappMarketing\Http\Controllers\InboxController;
use App\Modules\WhatsappMarketing\Http\Controllers\ListController;
use App\Modules\WhatsappMarketing\Http\Controllers\MediaController;
use App\Modules\WhatsappMarketing\Http\Controllers\NumberController;
use App\Modules\WhatsappMarketing\Http\Controllers\QuickReplyController;
use App\Modules\WhatsappMarketing\Http\Controllers\RichMessageController;
use App\Modules\WhatsappMarketing\Http\Controllers\SettingsController;
use App\Modules\WhatsappMarketing\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Business - {tenant}.APP_DOMAIN/whatsapp
|--------------------------------------------------------------------------
|
| Wrapped by `loadTenantRoutes()` (tenant, `entitled:whatsapp_marketing`). This
| app has no webhook route of its own: provider events arrive on the
| communication app's signature-verified route and reach this app through the
| `ChannelEventListener` contract (docs/WHATSAPP.md).
|
*/

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('whatsapp')->group(function (): void {
    // Shared inbox.
    Route::get('inbox', [InboxController::class, 'index'])->middleware('can:whatsapp_marketing.conversations.view')->name('whatsapp_marketing.inbox.index');
    Route::post('inbox', [InboxController::class, 'store'])
        ->middleware(['can:whatsapp_marketing.conversations.reply', 'throttle:30,1'])->name('whatsapp_marketing.inbox.store');
    Route::get('inbox/{conversation}', [InboxController::class, 'show'])->middleware('can:whatsapp_marketing.conversations.view')->name('whatsapp_marketing.inbox.show');
    Route::post('inbox/{conversation}/read', [InboxController::class, 'read'])->middleware('can:whatsapp_marketing.conversations.view')->name('whatsapp_marketing.inbox.read');
    Route::post('inbox/{conversation}/messages', [ConversationController::class, 'reply'])
        ->middleware(['can:whatsapp_marketing.conversations.reply', 'throttle:60,1'])->name('whatsapp_marketing.inbox.reply');
    Route::post('inbox/{conversation}/templates', [ConversationController::class, 'template'])
        ->middleware(['can:whatsapp_marketing.conversations.reply', 'throttle:30,1'])->name('whatsapp_marketing.inbox.template');
    Route::post('inbox/{conversation}/assign', [ConversationController::class, 'assign'])->middleware('can:whatsapp_marketing.conversations.assign')->name('whatsapp_marketing.inbox.assign');
    Route::post('inbox/{conversation}/claim', [ConversationController::class, 'claim'])->middleware('can:whatsapp_marketing.conversations.assign')->name('whatsapp_marketing.inbox.claim');
    Route::post('inbox/{conversation}/status', [ConversationController::class, 'status'])->middleware('can:whatsapp_marketing.conversations.manage')->name('whatsapp_marketing.inbox.status');
    Route::post('inbox/{conversation}/tags', [ConversationController::class, 'tags'])->middleware('can:whatsapp_marketing.conversations.manage')->name('whatsapp_marketing.inbox.tags');
    Route::post('inbox/{conversation}/contact', [ConversationController::class, 'contact'])->middleware('can:whatsapp_marketing.conversations.manage')->name('whatsapp_marketing.inbox.contact');
    Route::post('inbox/{conversation}/notes', [ConversationController::class, 'addNote'])
        ->middleware(['can:whatsapp_marketing.conversations.reply', 'throttle:30,1'])->name('whatsapp_marketing.inbox.notes.store');
    Route::delete('inbox/{conversation}/notes/{note}', [ConversationController::class, 'removeNote'])->middleware('can:whatsapp_marketing.conversations.view')->name('whatsapp_marketing.inbox.notes.destroy');

    // Rich outbound (ADR 0022): reply buttons, list, call-to-action URL, media and templates with a media
    // header or button variables, all built as WhatsappContent and sent through the gateway (window enforced).
    Route::middleware(['can:whatsapp_marketing.conversations.reply', 'throttle:60,1'])->group(function (): void {
        Route::post('inbox/{conversation}/interactive', [RichMessageController::class, 'interactive'])->name('whatsapp_marketing.inbox.interactive');
        Route::post('inbox/{conversation}/cta', [RichMessageController::class, 'cta'])->name('whatsapp_marketing.inbox.cta');
        Route::post('inbox/{conversation}/media', [RichMessageController::class, 'media'])->name('whatsapp_marketing.inbox.media');
        Route::post('inbox/{conversation}/rich-template', [RichMessageController::class, 'template'])->name('whatsapp_marketing.inbox.rich-template');
    });
    // Serve an inbound media file, downloaded on demand through the platform (tenant-scoped, access-checked).
    Route::get('messages/{message}/media', [MediaController::class, 'show'])->middleware('can:whatsapp_marketing.conversations.view')->name('whatsapp_marketing.messages.media');

    // Templates (mirrors of what Meta approved).
    Route::get('templates', [TemplateController::class, 'index'])->middleware('can:whatsapp_marketing.templates.view')->name('whatsapp_marketing.templates.index');
    Route::middleware('can:whatsapp_marketing.templates.manage')->group(function (): void {
        Route::post('templates', [TemplateController::class, 'store'])->name('whatsapp_marketing.templates.store');
        Route::put('templates/{template}', [TemplateController::class, 'update'])->name('whatsapp_marketing.templates.update');
        Route::post('templates/{template}/status', [TemplateController::class, 'status'])->name('whatsapp_marketing.templates.status');
        Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('whatsapp_marketing.templates.destroy');
    });

    // Quick replies.
    Route::get('quick-replies', [QuickReplyController::class, 'index'])->middleware('can:whatsapp_marketing.quick_replies.view')->name('whatsapp_marketing.quick-replies.index');
    Route::middleware('can:whatsapp_marketing.quick_replies.manage')->group(function (): void {
        Route::post('quick-replies', [QuickReplyController::class, 'store'])->name('whatsapp_marketing.quick-replies.store');
        Route::put('quick-replies/{quickReply}', [QuickReplyController::class, 'update'])->name('whatsapp_marketing.quick-replies.update');
        Route::delete('quick-replies/{quickReply}', [QuickReplyController::class, 'destroy'])->name('whatsapp_marketing.quick-replies.destroy');
    });

    // Lists.
    Route::get('lists', [ListController::class, 'index'])->middleware('can:whatsapp_marketing.lists.view')->name('whatsapp_marketing.lists.index');
    Route::get('lists/{list}', [ListController::class, 'show'])->middleware('can:whatsapp_marketing.lists.view')->name('whatsapp_marketing.lists.show');
    Route::middleware('can:whatsapp_marketing.lists.manage')->group(function (): void {
        Route::post('lists', [ListController::class, 'store'])->name('whatsapp_marketing.lists.store');
        Route::put('lists/{list}', [ListController::class, 'update'])->name('whatsapp_marketing.lists.update');
        Route::delete('lists/{list}', [ListController::class, 'destroy'])->name('whatsapp_marketing.lists.destroy');
        Route::post('lists/{list}/members', [ListController::class, 'addMembers'])->middleware('throttle:30,1')->name('whatsapp_marketing.lists.members.store');
        Route::post('lists/{list}/members/all', [ListController::class, 'addAll'])->middleware('throttle:5,1')->name('whatsapp_marketing.lists.members.all');
        Route::post('lists/{list}/members/rules', [ListController::class, 'addByRules'])->middleware('throttle:10,1')->name('whatsapp_marketing.lists.members.rules');
        Route::delete('lists/{list}/members/{member}', [ListController::class, 'removeMember'])->name('whatsapp_marketing.lists.members.destroy');
    });

    // Campaigns. `create` and `preview` are declared before the `{campaign}` routes so they are not read as ids.
    Route::get('campaigns', [CampaignController::class, 'index'])->middleware('can:whatsapp_marketing.campaigns.view')->name('whatsapp_marketing.campaigns.index');
    Route::get('campaigns/create', [CampaignController::class, 'create'])->middleware('can:whatsapp_marketing.campaigns.manage')->name('whatsapp_marketing.campaigns.create');
    Route::post('campaigns/preview', [CampaignController::class, 'preview'])
        ->middleware(['can:whatsapp_marketing.campaigns.manage', 'throttle:60,1'])->name('whatsapp_marketing.campaigns.preview');
    Route::post('campaigns', [CampaignController::class, 'store'])->middleware('can:whatsapp_marketing.campaigns.manage')->name('whatsapp_marketing.campaigns.store');
    // Broadcast to a CRM segment: declared before `campaigns/{campaign}` so they are not read as ids.
    Route::get('campaigns/segments', [SegmentCampaignController::class, 'index'])->middleware('can:whatsapp_marketing.campaigns.manage')->name('whatsapp_marketing.campaigns.segments');
    Route::post('campaigns/from-segment', [SegmentCampaignController::class, 'store'])->middleware('can:whatsapp_marketing.campaigns.manage')->name('whatsapp_marketing.campaigns.from_segment');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->middleware('can:whatsapp_marketing.campaigns.view')->name('whatsapp_marketing.campaigns.show');
    Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->middleware('can:whatsapp_marketing.campaigns.manage')->name('whatsapp_marketing.campaigns.edit');
    Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->middleware('can:whatsapp_marketing.campaigns.manage')->name('whatsapp_marketing.campaigns.update');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->middleware('can:whatsapp_marketing.campaigns.manage')->name('whatsapp_marketing.campaigns.destroy');
    Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send'])
        ->middleware(['can:whatsapp_marketing.campaigns.send', 'throttle:10,1'])->name('whatsapp_marketing.campaigns.send');
    Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])
        ->middleware(['can:whatsapp_marketing.campaigns.send', 'throttle:10,1'])->name('whatsapp_marketing.campaigns.cancel');

    // Chatbot flows.
    Route::middleware('can:whatsapp_marketing.flows.manage')->group(function (): void {
        Route::get('flows', [FlowController::class, 'index'])->name('whatsapp_marketing.flows.index');
        Route::post('flows', [FlowController::class, 'store'])->name('whatsapp_marketing.flows.store');
        Route::put('flows/{flow}', [FlowController::class, 'update'])->name('whatsapp_marketing.flows.update');
        Route::delete('flows/{flow}', [FlowController::class, 'destroy'])->name('whatsapp_marketing.flows.destroy');
    });

    Route::get('analytics', [AnalyticsController::class, 'index'])->middleware('can:whatsapp_marketing.analytics.view')->name('whatsapp_marketing.analytics.index');

    // CRM bridge: lead-capture settings and a manual sender for the transactional reminder/notification templates.
    Route::get('crm', [WhatsappCrmController::class, 'index'])->middleware('can:whatsapp_marketing.conversations.view')->name('whatsapp_marketing.crm.index');
    Route::get('crm/contacts', [WhatsappCrmController::class, 'contacts'])->middleware('can:whatsapp_marketing.conversations.reply')->name('whatsapp_marketing.crm.contacts');
    Route::post('crm/reminders', [WhatsappCrmController::class, 'sendReminder'])
        ->middleware(['can:whatsapp_marketing.conversations.reply', 'throttle:30,1'])->name('whatsapp_marketing.crm.reminders');
    Route::put('crm/lead-capture', [WhatsappCrmController::class, 'updateLeadCapture'])->middleware('can:whatsapp_marketing.settings.manage')->name('whatsapp_marketing.crm.lead_capture');

    // Settings, connection status and the agent roster.
    Route::middleware('can:whatsapp_marketing.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('whatsapp_marketing.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('whatsapp_marketing.settings.update');
        Route::post('settings/agents', [SettingsController::class, 'enrol'])->name('whatsapp_marketing.agents.store');
        Route::put('settings/agents/{agent}', [SettingsController::class, 'updateAgent'])->name('whatsapp_marketing.agents.update');

        // Business numbers on the WhatsApp connection (ADR 0022): list, add, label, enable/disable, set default.
        Route::get('settings/numbers', [NumberController::class, 'index'])->name('whatsapp_marketing.numbers.index');
        Route::post('settings/numbers', [NumberController::class, 'store'])->name('whatsapp_marketing.numbers.store');
        Route::put('settings/numbers/{number}', [NumberController::class, 'update'])->name('whatsapp_marketing.numbers.update');
        Route::post('settings/numbers/{number}/default', [NumberController::class, 'makeDefault'])->name('whatsapp_marketing.numbers.default');
        Route::delete('settings/numbers/{number}', [NumberController::class, 'destroy'])->name('whatsapp_marketing.numbers.destroy');
    });
});
