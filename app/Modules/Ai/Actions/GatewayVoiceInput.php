<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Ai\Contracts\VoiceInput;
use App\Core\Identity\Models\User;
use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Providers\TranscriberManager;

/**
 * Offers the microphone to a member when voice can actually work (ADR 0038): the workspace is entitled to
 * `ai` and switched voice on, the member holds `ai.voice.use`, and the platform has a transcription provider.
 * A full allowance or an open breaker does not hide the control; the member is told when they use it.
 */
final class GatewayVoiceInput implements VoiceInput
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly AiSettings $settings,
        private readonly TranscriberManager $transcribers,
    ) {}

    public function offerFor(User $user): ?array
    {
        // The permission check includes the entitlement: without the app the settings table is not read.
        if (! $this->access->allows($user, 'ai.voice.use')
            || ! $this->settings->allows(Feature::Voice)
            || ! $this->transcribers->transcriber()->isConfigured()) {
            return null;
        }

        return [
            'maxSeconds' => (int) config('ai.transcription.max_seconds', 30),
            'maxBytes' => (int) config('ai.transcription.max_kilobytes', 2048) * 1024,
        ];
    }
}
