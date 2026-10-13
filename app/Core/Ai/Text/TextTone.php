<?php

declare(strict_types=1);

namespace App\Core\Ai\Text;

/** The voice of generated text. A closed list: a tone can never carry free text into a prompt. */
enum TextTone: string
{
    case Neutral = 'neutral';
    case Friendly = 'friendly';
    case Formal = 'formal';
    case Professional = 'professional';
    case Playful = 'playful';
    case Persuasive = 'persuasive';
    case Empathetic = 'empathetic';
}
