<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation\Triggers;

use App\Modules\WhatsappMarketing\Events\FeedbackReceived;

/** A customer rated a closed conversation. `maximum_rating: 2` starts a workflow only for poor ratings. */
final class FeedbackReceivedTrigger extends WhatsappTrigger
{
    public static function key(): string
    {
        return 'whatsapp_marketing.feedback.received';
    }

    public static function event(): string
    {
        return FeedbackReceived::class;
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'minimum_rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'maximum_rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['feedback_id', 'conversation_id', 'rating'],
            'properties' => [
                'feedback_id' => ['type' => 'string'],
                'conversation_id' => ['type' => 'string'],
                'contact_id' => ['type' => ['string', 'null']],
                'owner_id' => ['type' => ['string', 'null']],
                'rating' => ['type' => 'integer'],
            ],
        ];
    }
}
