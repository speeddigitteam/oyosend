<?php

declare(strict_types=1);

namespace App\Core\Ai\Contracts;

use App\Core\Identity\Models\User;

/**
 * Whether the workspace shell offers voice input to a member (ADR 0038). Speech becomes a transcript the member
 * sees in the command palette or the assistant box; it never runs anything on its own.
 *
 * The `ai` module binds the real implementation; without it the null implementation offers nothing, so no
 * microphone control is shown. The offer is a courtesy to the UI: the transcription route checks again.
 */
interface VoiceInput
{
    /**
     * Limits the browser needs to record a clip, or null when voice is not available to this member in the
     * active workspace (not entitled, no permission, switched off, or no transcription provider).
     *
     * @return array{maxSeconds: int, maxBytes: int}|null
     */
    public function offerFor(User $user): ?array;
}
