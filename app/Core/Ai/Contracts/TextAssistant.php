<?php

declare(strict_types=1);

namespace App\Core\Ai\Contracts;

use App\Core\Ai\Text\TextResult;
use App\Core\Ai\Text\TextTask;

/**
 * Text generation for other modules (captions, hashtags, reply suggestions, sentiment, summaries, translation)
 * without importing the AI module (ADR 0024).
 *
 * Guarantees, whichever implementation is bound:
 *  - It returns TEXT only. It never changes data, sends anything, moves money or touches access. The caller
 *    shows the text to a person, who decides.
 *  - It does not throw for a refusal: the TextResult state says `not_configured`, `disabled`, `limit_reached`,
 *    `refused` or `failed` with a message safe to show. Only an invalid TextTask (a caller bug) throws, when it
 *    is built.
 *  - It authorizes nothing about the actor: the caller checks its own permission before asking.
 *
 * The AI module binds the real implementation; core binds a null object that answers `not_configured`.
 */
interface TextAssistant
{
    public function generate(TextTask $task): TextResult;
}
