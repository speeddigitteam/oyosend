<?php

declare(strict_types=1);

use App\Modules\Support\Http\Controllers\CannedController;
use App\Modules\Support\Http\Controllers\KnowledgeBaseController;
use App\Modules\Support\Http\Controllers\QueueController;
use App\Modules\Support\Http\Controllers\ReportController;
use App\Modules\Support\Http\Controllers\SettingsController;
use App\Modules\Support\Http\Controllers\SlaController;
use App\Modules\Support\Http\Controllers\TicketController;
use App\Modules\Support\Http\Controllers\TicketWorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('support')->group(function (): void {
    // Tickets. `create` is declared before the `{ticket}` routes so it is not read as an id.
    Route::get('tickets', [TicketController::class, 'index'])->middleware('can:support.tickets.view')->name('support.tickets.index');
    Route::get('tickets/create', [TicketController::class, 'create'])->middleware('can:support.tickets.create')->name('support.tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->middleware('can:support.tickets.create')->name('support.tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->middleware('can:support.tickets.view')->name('support.tickets.show');
    Route::put('tickets/{ticket}', [TicketController::class, 'update'])->middleware('can:support.tickets.edit')->name('support.tickets.update');
    Route::get('tickets/{ticket}/attachments/{attachment}', [TicketController::class, 'download'])
        ->middleware('can:support.tickets.view')->name('support.tickets.attachments.download');
    Route::get('tickets/{ticket}/canned/{canned}', [TicketController::class, 'cannedPreview'])
        ->middleware('can:support.tickets.reply')->name('support.tickets.canned.preview');

    Route::post('tickets/{ticket}/messages', [TicketWorkflowController::class, 'reply'])
        ->middleware(['can:support.tickets.reply', 'throttle:60,1'])->name('support.tickets.messages.store');
    Route::post('tickets/{ticket}/status', [TicketWorkflowController::class, 'status'])
        ->middleware('can:support.tickets.reply')->name('support.tickets.status');
    Route::post('tickets/{ticket}/assign', [TicketWorkflowController::class, 'assign'])
        ->middleware('can:support.tickets.assign')->name('support.tickets.assign');
    Route::post('tickets/{ticket}/claim', [TicketWorkflowController::class, 'claim'])
        ->middleware('can:support.tickets.assign')->name('support.tickets.claim');
    Route::post('tickets/{ticket}/merge', [TicketWorkflowController::class, 'merge'])
        ->middleware('can:support.tickets.merge')->name('support.tickets.merge');
    Route::post('tickets/{ticket}/split', [TicketWorkflowController::class, 'split'])
        ->middleware('can:support.tickets.merge')->name('support.tickets.split');
    Route::post('tickets/{ticket}/survey', [TicketWorkflowController::class, 'survey'])
        ->middleware(['can:support.tickets.resolve', 'throttle:20,1'])->name('support.tickets.survey');

    // Canned responses.
    Route::get('canned', [CannedController::class, 'index'])->middleware('can:support.canned.view')->name('support.canned.index');
    Route::middleware('can:support.canned.manage')->group(function (): void {
        Route::post('canned', [CannedController::class, 'store'])->name('support.canned.store');
        Route::put('canned/{canned}', [CannedController::class, 'update'])->name('support.canned.update');
        Route::delete('canned/{canned}', [CannedController::class, 'destroy'])->name('support.canned.destroy');
    });

    // Knowledge base.
    Route::get('kb', [KnowledgeBaseController::class, 'index'])->middleware('can:support.kb.view')->name('support.kb.index');
    Route::get('kb/articles/{article}', [KnowledgeBaseController::class, 'show'])->middleware('can:support.kb.view')->name('support.kb.articles.show');
    Route::middleware('can:support.kb.manage')->group(function (): void {
        Route::post('kb/articles', [KnowledgeBaseController::class, 'store'])->name('support.kb.articles.store');
        Route::put('kb/articles/{article}', [KnowledgeBaseController::class, 'update'])->name('support.kb.articles.update');
        Route::post('kb/articles/{article}/versions/{version}/restore', [KnowledgeBaseController::class, 'restore'])
            ->whereNumber('version')->name('support.kb.articles.restore');
        Route::post('kb/categories', [KnowledgeBaseController::class, 'storeCategory'])->name('support.kb.categories.store');
        Route::put('kb/categories/{category}', [KnowledgeBaseController::class, 'updateCategory'])->name('support.kb.categories.update');
        Route::delete('kb/categories/{category}', [KnowledgeBaseController::class, 'destroyCategory'])->name('support.kb.categories.destroy');
    });
    Route::middleware('can:support.kb.publish')->group(function (): void {
        Route::post('kb/articles/{article}/publish', [KnowledgeBaseController::class, 'publish'])->name('support.kb.articles.publish');
        Route::post('kb/articles/{article}/unpublish', [KnowledgeBaseController::class, 'unpublish'])->name('support.kb.articles.unpublish');
        Route::post('kb/articles/{article}/archive', [KnowledgeBaseController::class, 'archive'])->name('support.kb.articles.archive');
    });

    Route::get('reports', [ReportController::class, 'index'])->middleware('can:support.reports.view')->name('support.reports.index');

    // Agents, queues, categories and assignment rules.
    Route::middleware('can:support.queues.manage')->group(function (): void {
        Route::get('queues', [QueueController::class, 'index'])->name('support.queues.index');
        Route::post('queues', [QueueController::class, 'storeQueue'])->name('support.queues.store');
        Route::put('queues/{queue}', [QueueController::class, 'updateQueue'])->name('support.queues.update');
        Route::post('agents', [QueueController::class, 'storeAgent'])->name('support.agents.store');
        Route::put('agents/{agent}', [QueueController::class, 'updateAgent'])->name('support.agents.update');
        Route::post('categories', [QueueController::class, 'storeCategory'])->name('support.categories.store');
        Route::put('categories/{category}', [QueueController::class, 'updateCategory'])->name('support.categories.update');
        Route::post('rules', [QueueController::class, 'storeRule'])->name('support.rules.store');
        Route::put('rules/{rule}', [QueueController::class, 'updateRule'])->name('support.rules.update');
        Route::delete('rules/{rule}', [QueueController::class, 'destroyRule'])->name('support.rules.destroy');
    });

    Route::middleware('can:support.sla.manage')->group(function (): void {
        Route::get('sla', [SlaController::class, 'index'])->name('support.sla.index');
        Route::post('sla/policies', [SlaController::class, 'storePolicy'])->name('support.sla.policies.store');
        Route::put('sla/policies/{policy}', [SlaController::class, 'updatePolicy'])->name('support.sla.policies.update');
        Route::post('sla/calendars', [SlaController::class, 'storeCalendar'])->name('support.sla.calendars.store');
        Route::put('sla/calendars/{calendar}', [SlaController::class, 'updateCalendar'])->name('support.sla.calendars.update');
    });

    Route::middleware('can:support.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('support.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('support.settings.update');
    });
});
