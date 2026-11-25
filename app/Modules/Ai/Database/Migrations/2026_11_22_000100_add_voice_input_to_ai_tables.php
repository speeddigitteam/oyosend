<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voice input (ADR 0038): a workspace switch, off by default, and the operator's choice of which provider
 * account transcribes speech. No audio and no transcript is stored anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->boolean('voice_enabled')->default(false)->after('automation_enabled');
        });

        Schema::table('ai_provider_accounts', function (Blueprint $table): void {
            // The speech-to-text model of an OpenAI-compatible account (`whisper-1`, a self-hosted Whisper model).
            $table->string('transcription_model', 120)->nullable()->after('model');
            // Platform-wide, at most one enabled account: independent of the chat default.
            $table->boolean('is_transcription_default')->default(false)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_accounts', function (Blueprint $table): void {
            $table->dropColumn(['transcription_model', 'is_transcription_default']);
        });

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('voice_enabled');
        });
    }
};
