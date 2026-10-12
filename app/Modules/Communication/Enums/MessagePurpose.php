<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

enum MessagePurpose: string
{
    /** A message the recipient needs about something they did or own: invoice, receipt, reset. */
    case Transactional = 'transactional';
    /** Anything promotional. Needs recorded opt-in, an unsubscribe link and respects quiet hours. */
    case Marketing = 'marketing';
}
