<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/**
 * The honest state of AI for the active workspace, as the UI and APIs report it.
 */
enum AiState: string
{
    case Ready = 'ready';
    /** No platform credential exists: nothing can be generated and nothing is simulated. */
    case NotConfigured = 'not_configured';
    /** The provider is failing; the circuit breaker is open. */
    case Unavailable = 'unavailable';
    /** The workspace (or this feature) switched AI off. */
    case Disabled = 'disabled';
    /** The plan's monthly token limit is used up. */
    case LimitReached = 'limit_reached';
    case RateLimited = 'rate_limited';
    case TooLong = 'too_long';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Ready => 200,
            self::NotConfigured, self::Unavailable => 503,
            self::Disabled, self::LimitReached => 403,
            self::RateLimited => 429,
            self::TooLong => 422,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Ready => __('AI is ready.'),
            self::NotConfigured => __('AI is not configured on this platform yet. Ask the platform operator to set it up.'),
            self::Unavailable => __('The AI service is temporarily unavailable. Try again in a minute.'),
            self::Disabled => __('AI is switched off for this workspace.'),
            self::LimitReached => __('This workspace has used its AI allowance for the period.'),
            self::RateLimited => __('Too many AI requests. Wait a moment and try again.'),
            self::TooLong => __('That text is too long for the assistant.'),
        };
    }
}
