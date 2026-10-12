<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Events;

/** The provider reported that a customer read one of our messages. Payload: `conversation_id`, `message_id`, `contact_id`, `campaign_id`. */
final class MessageRead extends WhatsappEvent
{
    public static function name(): string
    {
        return 'whatsapp_marketing.message.read';
    }
}
