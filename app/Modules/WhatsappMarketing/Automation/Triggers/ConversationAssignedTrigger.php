<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation\Triggers;

use App\Modules\WhatsappMarketing\Events\ConversationAssigned;

/** A conversation was assigned to an agent. */
final class ConversationAssignedTrigger extends WhatsappTrigger
{
    public static function key(): string
    {
        return 'whatsapp_marketing.conversation.assigned';
    }

    public static function event(): string
    {
        return ConversationAssigned::class;
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['conversation_id'],
            'properties' => [
                'conversation_id' => ['type' => 'string'],
                'contact_id' => ['type' => ['string', 'null']],
                'owner_id' => ['type' => ['string', 'null']],
                'previous_owner_id' => ['type' => ['string', 'null']],
                'source' => ['type' => 'string'],
            ],
        ];
    }
}
