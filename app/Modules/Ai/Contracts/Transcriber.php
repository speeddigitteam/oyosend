<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Support\AudioClip;
use App\Modules\Ai\Support\ProviderFailure;
use App\Modules\Ai\Support\ProviderNotConfigured;
use App\Modules\Ai\Support\Transcript;

/**
 * Speech to text (ADR 0038). Separate from {@see AiProvider}: the operator may transcribe with a different
 * service (for example a self-hosted Whisper server) from the one that answers chat. Only `AiGateway` calls it.
 */
interface Transcriber
{
    /** Stable id: `openai_compatible`, `fake`. */
    public function name(): string;

    public function model(): string;

    /** Whether an endpoint and a model exist. Never reveals a key. */
    public function isConfigured(): bool;

    /**
     * @throws ProviderNotConfigured When no endpoint or model exists.
     * @throws ProviderFailure When the provider could not answer (message is generic, never secret).
     */
    public function transcribe(AudioClip $clip): Transcript;
}
