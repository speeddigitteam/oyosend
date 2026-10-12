<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

enum MessageKind: string
{
    case PublicReply = 'public_reply';
    case CustomerReply = 'customer_reply';
    case InternalNote = 'internal_note';

    /** Whether the requester can see it. Internal notes never leave the workspace. */
    public function isPublic(): bool
    {
        return $this !== self::InternalNote;
    }
}
