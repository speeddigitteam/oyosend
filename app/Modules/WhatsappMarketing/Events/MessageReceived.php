<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Events;

/** A customer sent a WhatsApp message. Payload: `conversation_id`, `message_id`, `contact_id`, `owner_id`, `message_type`, `new_conversation`. */
final class MessageReceived extends WhatsappEvent
{
    public static function name(): string
    {
        return 'whatsapp_marketing.message.received';
    }
}
