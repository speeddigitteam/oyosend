<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;

/** What recording one inbound customer message produced. */
final readonly class InboundResult
{
    public function __construct(
        public Message $message,
        public Conversation $conversation,
        public bool $newConversation,
        public bool $newWindow,
    ) {}
}
