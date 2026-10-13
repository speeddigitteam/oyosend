<?php

declare(strict_types=1);

namespace App\Core\Ai\Text;

/**
 * The honest outcome of a text task. Nothing is simulated: without a provider the state is `not_configured`.
 */
enum TextState: string
{
    /** Text was generated (or replayed for a retried task). */
    case Ok = 'ok';
    /** No platform provider credential exists, or the AI app is not installed. */
    case NotConfigured = 'not_configured';
    /** The workspace switched AI (or drafting) off, or is not entitled to the AI app. */
    case Disabled = 'disabled';
    /** The plan's token allowance for the period is used up. */
    case LimitReached = 'limit_reached';
    /** Not accepted right now: rate limited. Retry later. */
    case Refused = 'refused';
    /** The provider failed or answered nothing usable. */
    case Failed = 'failed';

    public function message(): string
    {
        return match ($this) {
            self::Ok => '',
            self::NotConfigured => __('AI is not configured on this platform yet. Ask the platform operator to set it up.'),
            self::Disabled => __('AI is switched off for this workspace.'),
            self::LimitReached => __('This workspace has used its AI allowance for the period.'),
            self::Refused => __('Too many AI requests. Wait a moment and try again.'),
            self::Failed => __('The AI service is temporarily unavailable. Try again in a minute.'),
        };
    }
}
