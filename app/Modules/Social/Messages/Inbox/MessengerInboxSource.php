<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Inbox;

use App\Modules\Social\Messages\Models\DirectConversation;

/** Facebook Messenger conversations of connected Pages in the unified inbox (`social.messenger`, ADR 0031). */
final class MessengerInboxSource extends DirectMessageInboxSource
{
    protected function platform(): string
    {
        return DirectConversation::MESSENGER;
    }

    protected function label(): string
    {
        return 'Messenger';
    }
}
