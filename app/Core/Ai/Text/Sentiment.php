<?php

declare(strict_types=1);

namespace App\Core\Ai\Text;

/** The classified sentiment of a text. `unknown` is the safe fallback when the model answer is unusable. */
enum Sentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
    case Mixed = 'mixed';
    case Unknown = 'unknown';
}
