<?php

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\Ai\Contracts\VoiceInput;
use App\Core\Identity\Models\User;

/** Bound when the AI app is not installed: no voice input is offered. */
final class NullVoiceInput implements VoiceInput
{
    public function offerFor(User $user): ?array
    {
        return null;
    }
}
