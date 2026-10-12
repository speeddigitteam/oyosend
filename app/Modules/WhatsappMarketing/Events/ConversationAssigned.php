<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Events;

/** A conversation was assigned to an agent. Payload: `conversation_id`, `contact_id`, `owner_id`, `previous_owner_id`, `source`. */
final class ConversationAssigned extends WhatsappEvent
{
    public static function name(): string
    {
        return 'whatsapp_marketing.conversation.assigned';
    }
}
