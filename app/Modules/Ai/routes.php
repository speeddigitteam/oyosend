<?php

declare(strict_types=1);

use App\Modules\Ai\Http\Controllers\AssistantController;
use App\Modules\Ai\Http\Controllers\DraftController;
use App\Modules\Ai\Http\Controllers\ProposalController;
use App\Modules\Ai\Http\Controllers\SettingsController;
use App\Modules\Ai\Http\Controllers\UsageController;
use App\Modules\Ai\Http\Controllers\VoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'auth.session', 'member', 'subscribed'])->prefix('ai')->group(function (): void {
    // Assistant chat. Conversations are private to the member who owns them.
    Route::middleware('can:ai.assistant.use')->group(function (): void {
        Route::get('/', [AssistantController::class, 'index'])->name('ai.assistant.index');
        Route::post('conversations', [AssistantController::class, 'store'])->middleware('throttle:30,1')->name('ai.assistant.conversations.store');
        Route::delete('conversations', [AssistantController::class, 'clear'])->name('ai.assistant.conversations.clear');
        Route::get('conversations/{conversation}', [AssistantController::class, 'show'])->name('ai.assistant.conversations.show');
        Route::post('conversations/{conversation}/messages', [AssistantController::class, 'message'])->middleware('throttle:30,1')->name('ai.assistant.conversations.messages.store');
        Route::delete('conversations/{conversation}', [AssistantController::class, 'destroy'])->name('ai.assistant.conversations.destroy');
        Route::post('proposals/{proposal}/reject', [ProposalController::class, 'reject'])->name('ai.proposals.reject');
    });

    Route::post('proposals/{proposal}/confirm', [ProposalController::class, 'confirm'])
        ->middleware(['can:ai.assistant.use', 'can:ai.proposals.confirm'])->name('ai.proposals.confirm');

    // Drafting for other screens: text only.
    Route::middleware('can:ai.drafts.generate')->group(function (): void {
        Route::get('status', [DraftController::class, 'status'])->name('ai.status');
        Route::post('drafts', [DraftController::class, 'store'])->middleware('throttle:30,1')->name('ai.drafts.store');
    });

    // Voice input (ADR 0038): a clip in, its transcript out. Nothing is stored and nothing runs here.
    Route::post('voice/transcriptions', [VoiceController::class, 'transcribe'])
        ->middleware(['can:ai.voice.use', 'throttle:30,1'])->name('ai.voice.transcribe');

    Route::get('usage', [UsageController::class, 'index'])->middleware('can:ai.usage.view')->name('ai.usage.index');

    Route::middleware('can:ai.settings.manage')->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])->name('ai.settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('ai.settings.update');
    });
});
