<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/**
 * The wire protocol an operator-managed AI provider account speaks.
 */
enum AiDriver: string
{
    case Anthropic = 'anthropic';

    /** Any server that implements the OpenAI chat-completions API (OpenAI, Azure-style gateways, OpenRouter, Ollama-like servers). */
    case OpenAiCompatible = 'openai_compatible';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAiCompatible => 'OpenAI-compatible',
        };
    }

    public function requiresKey(): bool
    {
        return $this === self::Anthropic;
    }

    public function requiresBaseUrl(): bool
    {
        return $this === self::OpenAiCompatible;
    }

    /** Whether accounts of this driver can transcribe speech (`POST {base_url}/audio/transcriptions`, ADR 0038). */
    public function supportsTranscription(): bool
    {
        return $this === self::OpenAiCompatible;
    }
}
