<?php

declare(strict_types=1);

namespace App\Core\Ai\Text;

/**
 * What a text task asks for. Extensible: add a case, its prompt in the Ai module and its parser; callers
 * of existing kinds are unaffected.
 */
enum TextKind: string
{
    case Caption = 'caption';
    case Hashtags = 'hashtags';
    case ReplySuggestion = 'reply_suggestion';
    case Sentiment = 'sentiment';
    case Summary = 'summary';
    case Translate = 'translate';
}
