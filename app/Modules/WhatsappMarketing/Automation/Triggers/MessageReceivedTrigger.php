<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation\Triggers;

use App\Modules\WhatsappMarketing\Events\MessageReceived;

/** A customer sent a WhatsApp message. The text is not part of the output: read it from the conversation. */
final class MessageReceivedTrigger extends WhatsappTrigger
{
    public static function key(): string
    {
        return 'whatsapp_marketing.message.received';
    }

    public static function event(): string
    {
        return MessageReceived::class;
    }

    /** `new_conversation: true` starts a workflow only for a number's first message (lead capture). */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['new_conversation' => ['type' => 'boolean']],
            'additionalProperties' => false,
        ];
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
                'owner_id' => ['type' => ['string', 'null']],
                'message_type' => ['type' => 'string'],
                'new_conversation' => ['type' => 'boolean'],
            ],
        ];
    }
}
