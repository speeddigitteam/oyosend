<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation\Triggers;

use App\Modules\WhatsappMarketing\Events\MessageRead;

/** The provider reported that a customer read a message we sent. */
final class MessageReadTrigger extends WhatsappTrigger
{
    public static function key(): string
    {
        return 'whatsapp_marketing.message.read';
    }

    public static function event(): string
    {
        return MessageRead::class;
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['conversation_id', 'message_id'],
            'properties' => [
                'conversation_id' => ['type' => 'string'],
                'message_id' => ['type' => 'string'],
                'contact_id' => ['type' => ['string', 'null']],
                'campaign_id' => ['type' => ['string', 'null']],
            ],
        ];
    }
}
