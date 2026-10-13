<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Events;

/** A customer rated a closed conversation (1 to 5). Ids and the rating only. */
final class FeedbackReceived extends WhatsappEvent
{
    public static function name(): string
    {
        return 'whatsapp_marketing.feedback.received';
    }
}
