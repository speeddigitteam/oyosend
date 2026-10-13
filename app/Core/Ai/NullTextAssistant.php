<?php

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Ai\Text\TextResult;
use App\Core\Ai\Text\TextState;
use App\Core\Ai\Text\TextTask;

/** Bound when the AI app is not installed: honestly not configured, nothing is generated. */
final class NullTextAssistant implements TextAssistant
{
    public function generate(TextTask $task): TextResult
    {
        return TextResult::unavailable(TextState::NotConfigured, $task->kind);
    }
}
