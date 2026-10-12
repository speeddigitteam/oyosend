<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/** What an AI call is for; each can be switched off per workspace. */
enum Feature: string
{
    case Chat = 'chat';
    case Drafting = 'drafting';
    case Automation = 'automation';
}
