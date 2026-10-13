<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Enums;

/** The inbox filters. `open` is "not marked handled yet". */
enum InboxStatus: string
{
    case Open = 'open';
    case Unread = 'unread';
    case Replied = 'replied';
    case Hidden = 'hidden';
    case NeedsAttention = 'needs_attention';
    case Handled = 'handled';
    case Mine = 'mine';
}
