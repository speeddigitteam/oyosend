<?php

declare(strict_types=1);

namespace App\Modules\Support\Events;

/** A website visitor started a chat. The payload is the conversation id only: no text, name or address. */
final class LiveChatStarted extends SupportEvent
{
    public static function name(): string
    {
        return 'support.live_chat.started';
    }
}
