<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Inbox;

use App\Modules\Social\Messages\Models\DirectConversation;

/**
 * Instagram direct messages of the professional accounts linked to connected Pages in the unified inbox
 * (`social.instagram`, ADR 0031).
 */
final class InstagramInboxSource extends DirectMessageInboxSource
{
    protected function platform(): string
    {
        return DirectConversation::INSTAGRAM;
    }

    protected function label(): string
    {
        return 'Instagram';
    }
}
